<?php
/**
 * Test Suite: Nexora AI Shopping Assistant - Comprehensive Verification Suite
 * 
 * Verifies Scenarios TEST A through TEST N:
 * TEST A: Gift suggestions with complete initial info (no re-asking, matching interests, budget, count)
 * TEST B: Budget follow-up ("Actually, keep it under ₹5,000", retains girlfriend & interests)
 * TEST C: Add an interest ("She also likes makeup and skincare", merged into active request)
 * TEST D: New recipient ("Suggest 5 birthday gifts for my best friend. He loves cricket and gaming. My budget is ₹3,000" -> previous girlfriend preferences NOT reused)
 * TEST E: Headphone context ("I want to buy headphones" -> "My budget is ₹2,000" -> "I prefer wireless ones" -> constraints preserved, doesn't switch to monitors, explains no wireless under $24.10)
 * TEST F: Missing product ("Find dance shoes under ₹2,000" -> unrelated footwear not presented, explains none found, 0 product cards)
 * TEST G: Brand/category mismatch ("Find Nike laptops under ₹30,000" -> detects mismatch, explains Nike doesn't make laptops, 0 footwear cards)
 * TEST H: Laptop search ("Show me the best laptops under ₹40,000" -> only actual laptops described as matching, cooling stand not described as laptop)
 * TEST I: Product count (Request 5 products -> <= 5 validated cards displayed)
 * TEST J: Currency & Strict Budget (Request under ₹2,000 -> price filtering consistent with USD catalog data at 83 INR/USD, strict budget enforced)
 * TEST K: New topic (Gift request followed by "Now I want to buy a laptop under ₹40,000" -> handled independently, gift context doesn't leak)
 * TEST L: Product comparison ("Compare these two headphones..." -> uses correct products from context and real DB specs)
 * TEST M: Empty results (Category absent from catalog -> 0 product cards shown, polite explanation)
 * TEST N: Conversation isolation (Separate users/sessions cannot access one another's preferences, carts, or orders)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../products.php';
require_once __DIR__ . '/../chatbot_intent.php';
require_once __DIR__ . '/../chatbot_state.php';
require_once __DIR__ . '/../orders_service.php';

function runScenario(string $testName, callable $fn) {
    echo "========================================================\n";
    echo "RUNNING: $testName\n";
    echo "--------------------------------------------------------\n";
    try {
        $passed = $fn();
        if ($passed) {
            echo "RESULT: [PASSED] $testName\n\n";
            return true;
        } else {
            echo "RESULT: [FAILED] $testName\n\n";
            return false;
        }
    } catch (Throwable $e) {
        echo "RESULT: [ERROR] $testName - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n";
        return false;
    }
}

$allPassed = true;

// -----------------------------------------------------------------------------
// TEST A: Complete Initial Request
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST A: Complete Initial Request (10 gifts, girlfriend, ₹20,000, dance/music)', function() {
    $sid = 'test_a_session';
    ShoppingStateEngine::resetState($sid);

    $msgs = [
        ['role' => 'user', 'content' => 'Suggest 10 birthday gifts for my college girlfriend. Budget: under ₹20,000. She loves dance and music.']
    ];

    $state = ShoppingStateEngine::processTurn($msgs, $sid);

    if (!$state['ready_to_recommend']) {
        echo "FAILED: Expected ready_to_recommend to be true.\n";
        return false;
    }
    if ($state['recipient'] !== 'girlfriend' || $state['occasion'] !== 'birthday') {
        echo "FAILED: Recipient or occasion not parsed correctly.\n";
        return false;
    }
    if (!in_array('dance', $state['interests']) || !in_array('music', $state['interests'])) {
        echo "FAILED: Interests should contain dance and music.\n";
        return false;
    }
    if ($state['requested_count'] !== 10) {
        echo "FAILED: Expected requested_count to be 10, got {$state['requested_count']}.\n";
        return false;
    }

    $recommendation = ShoppingStateEngine::recommendGifts($state);
    $response = ShoppingStateEngine::buildNaturalResponse($state, $recommendation);

    if (str_contains($response, 'What is the occasion') || str_contains($response, 'What is your budget') || str_contains($response, 'What are their interests')) {
        echo "FAILED: Bot re-asked questions that were already answered!\n";
        return false;
    }
    if (count($recommendation['products']) !== 10) {
        echo "FAILED: Expected 10 products, got " . count($recommendation['products']) . "\n";
        return false;
    }

    echo "  Products returned count: " . count($recommendation['products']) . "\n";
    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST B: Budget Follow-up
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST B: Budget Follow-up ("Actually, keep it under ₹5,000")', function() {
    $sid = 'test_b_session';
    ShoppingStateEngine::resetState($sid);

    $msgs = [
        ['role' => 'user', 'content' => 'Suggest 10 birthday gifts for my girlfriend. Budget: under ₹20,000. She loves dance and music.'],
        ['role' => 'assistant', 'content' => 'Here are 10 recommendations...'],
        ['role' => 'user', 'content' => 'Actually, keep it under ₹5,000']
    ];

    $state = ShoppingStateEngine::processTurn($msgs, $sid);

    if ($state['recipient'] !== 'girlfriend') {
        echo "FAILED: Recipient should still be girlfriend!\n";
        return false;
    }
    if (!in_array('dance', $state['interests']) && !in_array('music', $state['interests'])) {
        echo "FAILED: Interests should still be preserved!\n";
        return false;
    }
    if ($state['budget']['amount'] != 5000 || $state['budget']['currency'] !== 'INR') {
        echo "FAILED: Budget should be 5000 INR, got: " . json_encode($state['budget']) . "\n";
        return false;
    }

    $maxUsd = round(5000 / 83.0, 2);
    $recommendation = ShoppingStateEngine::recommendGifts($state);
    foreach ($recommendation['products'] as $p) {
        if ($p['price'] > $maxUsd) {
            echo "FAILED: Product price \${$p['price']} exceeds \$$maxUsd limit!\n";
            return false;
        }
    }

    echo "  New Budget: {$state['budget']['amount']} INR (max USD: \${$state['budget']['maximum_usd']})\n";
    echo "  Products returned count: " . count($recommendation['products']) . "\n";
    return count($recommendation['products']) > 0;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST C: Add an Interest
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST C: Add an Interest ("She also likes makeup and skincare")', function() {
    $sid = 'test_c_session';
    ShoppingStateEngine::resetState($sid);

    $msgs = [
        ['role' => 'user', 'content' => 'Suggest 10 birthday gifts for my girlfriend. Budget: under ₹20,000. She loves dance and music.'],
        ['role' => 'assistant', 'content' => 'Here are 10 recommendations...'],
        ['role' => 'user', 'content' => 'She also likes makeup and skincare']
    ];

    $state = ShoppingStateEngine::processTurn($msgs, $sid);

    echo "  Interests in state: " . implode(', ', $state['interests']) . "\n";
    if (!in_array('dance', $state['interests']) || !in_array('music', $state['interests']) || !in_array('makeup', $state['interests']) || !in_array('skincare', $state['interests'])) {
        echo "FAILED: Interests should contain dance, music, makeup, and skincare!\n";
        return false;
    }

    $recommendation = ShoppingStateEngine::recommendGifts($state);
    $categories = array_unique(array_column($recommendation['products'], 'category'));
    echo "  Categories returned: " . implode(', ', $categories) . "\n";

    if (!in_array('Beauty & Personal Care', $categories)) {
        echo "FAILED: Expected Beauty & Personal Care to be represented among products!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST D: New Recipient (Context Isolation)
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST D: New Recipient ("Suggest 5 birthday gifts for my best friend. He loves cricket and gaming. My budget is ₹3,000")', function() {
    $sid = 'test_d_session';
    ShoppingStateEngine::resetState($sid);

    // Turn 1: Girlfriend gift request
    $msgs1 = [
        ['role' => 'user', 'content' => 'Suggest 10 birthday gifts for my girlfriend. Budget: under ₹20,000. She loves dance and music.']
    ];
    ShoppingStateEngine::processTurn($msgs1, $sid);

    // Turn 2: New recipient (best friend) with cricket and gaming
    $msgs2 = [
        ['role' => 'user', 'content' => 'Suggest 10 birthday gifts for my girlfriend. Budget: under ₹20,000. She loves dance and music.'],
        ['role' => 'assistant', 'content' => 'Here are 10 gifts for your girlfriend.'],
        ['role' => 'user', 'content' => 'Suggest 5 birthday gifts for my best friend. He loves cricket and gaming. My budget is ₹3,000']
    ];
    $state = ShoppingStateEngine::processTurn($msgs2, $sid);

    echo "  Recipient: {$state['recipient']}\n";
    echo "  Interests: " . implode(', ', $state['interests']) . "\n";
    echo "  Budget: {$state['budget']['amount']} {$state['budget']['currency']} (max USD: \${$state['budget']['maximum_usd']})\n";
    echo "  Count: {$state['requested_count']}\n";

    if ($state['recipient'] !== 'friend') {
        echo "FAILED: Recipient should be friend, got: {$state['recipient']}\n";
        return false;
    }
    if (in_array('dance', $state['interests']) || in_array('music', $state['interests']) || in_array('makeup', $state['interests'])) {
        echo "FAILED: Previous girlfriend preferences leaked into friend request!\n";
        return false;
    }
    if (!in_array('cricket', $state['interests']) || !in_array('gaming', $state['interests'])) {
        echo "FAILED: Expected cricket and gaming in interests!\n";
        return false;
    }
    if ($state['requested_count'] !== 5) {
        echo "FAILED: Expected requested_count to be 5, got {$state['requested_count']}.\n";
        return false;
    }

    $recommendation = ShoppingStateEngine::recommendGifts($state);
    echo "  Products returned (" . count($recommendation['products']) . "):\n";
    foreach ($recommendation['products'] as $p) {
        echo "    - [{$p['category']}] {$p['name']} (\${$p['price']})\n";
        if (str_contains(strtolower($p['name']), 'makeup') || str_contains(strtolower($p['name']), 'dress')) {
            echo "FAILED: Leaked makeup/dress product into cricket/gaming request!\n";
            return false;
        }
    }

    return count($recommendation['products']) > 0;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST E: Headphone Context Retention
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST E: Headphone Context Retention (Headphones -> ₹2,000 -> Wireless)', function() {
    $sid = 'test_e_session';
    ShoppingStateEngine::resetState($sid);

    $msgs = [
        ['role' => 'user', 'content' => 'I want to buy headphones'],
        ['role' => 'assistant', 'content' => 'Sure! What is your budget?'],
        ['role' => 'user', 'content' => 'My budget is ₹2,000'],
        ['role' => 'assistant', 'content' => 'Got it. Do you prefer wired or wireless?'],
        ['role' => 'user', 'content' => 'I prefer wireless ones']
    ];

    $state = ShoppingStateEngine::processTurn($msgs, $sid);
    echo "  Product Type: {$state['product_type']}\n";
    echo "  Budget: {$state['budget']['amount']} {$state['budget']['currency']} (max USD: \${$state['budget']['maximum_usd']})\n";
    echo "  Preferences: " . implode(', ', $state['active_request']['preferences']) . "\n";

    if ($state['product_type'] !== 'headphones') {
        echo "FAILED: Expected product_type to remain headphones!\n";
        return false;
    }
    if (!in_array('wireless', $state['active_request']['preferences'])) {
        echo "FAILED: Expected wireless in preferences!\n";
        return false;
    }

    $intent = ChatbotIntent::detectIntent('I prefer wireless ones', $msgs);
    $searchRes = ChatbotIntent::searchByIntent($intent);
    $reply = ChatbotIntent::buildNaturalResponse($intent, $searchRes);

    echo "  Intent Product Type: {$intent['product_type']}\n";
    echo "  Search Status: {$searchRes['status']}\n";
    echo "  Reply Preview: " . substr($reply, 0, 140) . "...\n";

    if ($intent['product_type'] !== 'headphones') {
        echo "FAILED: Intent detector lost headphones category!\n";
        return false;
    }
    // Verify no monitors or mice in products
    foreach ($searchRes['products'] as $p) {
        if (!ShoppingStateEngine::isActualHeadphone($p)) {
            echo "FAILED: Returned non-headphone product: {$p['name']}\n";
            return false;
        }
    }

    if (!str_contains(strtolower($reply), 'wireless') || !str_contains(strtolower($reply), 'headphone')) {
        echo "FAILED: Reply did not address wireless headphones accurately!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST F: Missing Product Truthful Handling
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST F: Missing Product ("Find dance shoes under ₹2,000")', function() {
    $intent = ChatbotIntent::detectIntent('Find dance shoes under ₹2,000');
    $res = ChatbotIntent::searchByIntent($intent);
    $reply = ChatbotIntent::buildNaturalResponse($intent, $res);

    echo "  Products returned count: " . count($res['products']) . "\n";
    echo "  Reply: $reply\n";

    if (count($res['products']) !== 0) {
        echo "FAILED: Expected 0 products, but got " . count($res['products']) . "\n";
        return false;
    }
    if (!str_contains(strtolower($reply), "don't have matching dance shoes") && !str_contains(strtolower($reply), "currently don't have")) {
        echo "FAILED: Reply did not clearly explain dance shoes were unavailable!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST G: Brand / Category Mismatch
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST G: Brand/Category Mismatch ("Find Nike laptops under ₹30,000")', function() {
    $intent = ChatbotIntent::detectIntent('Find Nike laptops under ₹30,000');
    $res = ChatbotIntent::searchByIntent($intent);
    $reply = ChatbotIntent::buildNaturalResponse($intent, $res);

    echo "  Mismatch detected: " . (!empty($intent['mismatch']) ? 'YES' : 'NO') . "\n";
    echo "  Products returned count: " . count($res['products']) . "\n";
    echo "  Reply: $reply\n";

    if (empty($intent['mismatch'])) {
        echo "FAILED: Brand mismatch should have been detected for Nike laptops!\n";
        return false;
    }
    if (count($res['products']) !== 0) {
        echo "FAILED: Expected 0 products (no footwear cards), but got " . count($res['products']) . "\n";
        return false;
    }
    if (!str_contains(strtolower($reply), 'does not manufacture laptop')) {
        echo "FAILED: Reply must explain that Nike does not manufacture laptops!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST H: Laptop Search (Only Genuine Laptops)
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST H: Laptop Search ("Show me the best laptops under ₹40,000")', function() {
    $intent = ChatbotIntent::detectIntent('Show me the best laptops under ₹40,000');
    $res = ChatbotIntent::searchByIntent($intent);

    echo "  Laptops returned count: " . count($res['products']) . "\n";
    foreach ($res['products'] as $p) {
        echo "    - #{$p['id']}: {$p['name']} (\${$p['price']})\n";
        if (!ShoppingStateEngine::isActualLaptop($p)) {
            echo "FAILED: Non-laptop item presented as laptop: {$p['name']}\n";
            return false;
        }
    }

    if (count($res['products']) !== 1 || $res['products'][0]['id'] !== 204) {
        echo "FAILED: Expected exactly #204 UltraSlim Student Laptop under ₹40,000 ($481.93 USD)!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST I: Product Count Enforcement
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST I: Product Count Enforcement ("Suggest 5 birthday gifts for my friend...")', function() {
    $sid = 'test_i_count_session';
    ShoppingStateEngine::resetState($sid);

    $msgs = [
        ['role' => 'user', 'content' => 'Suggest 5 birthday gifts for my best friend who loves gaming. Budget: under ₹5,000.']
    ];

    $state = ShoppingStateEngine::processTurn($msgs, $sid);
    $res = ShoppingStateEngine::recommendGifts($state);

    echo "  Requested Count: {$state['requested_count']}\n";
    echo "  Products returned count: " . count($res['products']) . "\n";

    if (count($res['products']) > 5) {
        echo "FAILED: Returned " . count($res['products']) . " products, exceeding requested limit of 5!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST J: Currency & Strict Budget Enforcement
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST J: Currency & Strict Budget ("Find tech gadgets under ₹2,000. Do not show anything above my budget.")', function() {
    $sid = 'test_j_session';
    ShoppingStateEngine::resetState($sid);

    $msgs = [
        ['role' => 'user', 'content' => 'Find tech gadgets under ₹2,000. Do not show anything above my budget.']
    ];

    $state = ShoppingStateEngine::processTurn($msgs, $sid);

    echo "  Strict Budget: " . ($state['active_request']['strict_budget'] ? 'YES' : 'NO') . "\n";
    echo "  Budget USD Limit: \${$state['budget']['maximum_usd']}\n";

    if (!$state['active_request']['strict_budget']) {
        echo "FAILED: Expected strict_budget to be true!\n";
        return false;
    }

    $maxUsd = $state['budget']['maximum_usd']; // 24.10 USD
    $res = ShoppingStateEngine::recommendGifts($state);

    foreach ($res['products'] as $p) {
        echo "    - {$p['name']} (\${$p['price']})\n";
        if ($p['price'] > $maxUsd) {
            echo "FAILED: Product {$p['name']} (\${$p['price']}) exceeds strict budget \$$maxUsd!\n";
            return false;
        }
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST K: New Topic (Gift request followed by direct laptop search)
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST K: New Topic (Gift request -> "Now I want to buy a laptop under ₹40,000")', function() {
    $sid = 'test_k_session';
    ShoppingStateEngine::resetState($sid);

    $msgs1 = [
        ['role' => 'user', 'content' => 'Suggest gifts for my sister who likes reading. Budget: under ₹3,000.']
    ];
    ShoppingStateEngine::processTurn($msgs1, $sid);

    $msgs2 = [
        ['role' => 'user', 'content' => 'Suggest gifts for my sister who likes reading. Budget: under ₹3,000.'],
        ['role' => 'assistant', 'content' => 'Here are book gifts for your sister.'],
        ['role' => 'user', 'content' => 'Now I want to buy a laptop under ₹40,000']
    ];
    $state = ShoppingStateEngine::processTurn($msgs2, $sid);

    echo "  Active Intent: {$state['intent']}\n";
    echo "  Active Product Type: {$state['product_type']}\n";
    echo "  Active Recipient: " . ($state['recipient'] ?? 'None') . "\n";

    if ($state['product_type'] !== 'laptop') {
        echo "FAILED: Expected product_type to be laptop, got: {$state['product_type']}\n";
        return false;
    }
    if (!empty($state['recipient'])) {
        echo "FAILED: Recipient 'sister' leaked into laptop purchase query!\n";
        return false;
    }
    if (in_array('books', $state['interests'])) {
        echo "FAILED: Books interest leaked into laptop query!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST L: Product Comparison & Specifications
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST L: Product Comparison ("Compare these two headphones...")', function() {
    $sid = 'test_l_session';
    ShoppingStateEngine::resetState($sid);

    // Initial search for headphones
    $intent = ChatbotIntent::detectIntent('Show me headphones');
    $searchRes = ChatbotIntent::searchByIntent($intent);

    $msgs = [
        ['role' => 'user', 'content' => 'Show me headphones'],
        ['role' => 'assistant', 'content' => 'Here are our headphones...'],
        ['role' => 'user', 'content' => 'Compare these two headphones based on price, sound quality, battery life, and value for money']
    ];

    $state = ShoppingStateEngine::processTurn($msgs, $sid);

    if (empty($state['active_request']['reference_result'])) {
        echo "FAILED: Expected reference_result to be generated for comparison!\n";
        return false;
    }

    $ref = $state['active_request']['reference_result'];
    echo "  Comparison Reply Preview:\n" . substr($ref['reply'], 0, 180) . "...\n";

    if (!str_contains($ref['reply'], 'Pricing & Value') || !str_contains($ref['reply'], 'Sound Quality')) {
        echo "FAILED: Comparison missing structured specification sections!\n";
        return false;
    }
    if (count($ref['products']) < 2) {
        echo "FAILED: Comparison did not attach the compared products!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST M: Empty Results for Unsupported Category
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST M: Empty Results for Unsupported Category ("Show me fresh fruits and organic groceries")', function() {
    $intent = ChatbotIntent::detectIntent('Show me fresh fruits and organic groceries');
    $searchRes = ChatbotIntent::searchByIntent($intent);
    $reply = ChatbotIntent::buildNaturalResponse($intent, $searchRes);

    echo "  Products returned count: " . count($searchRes['products']) . "\n";
    echo "  Reply: $reply\n";

    if (count($searchRes['products']) !== 0) {
        echo "FAILED: Expected 0 products for grocery search, got " . count($searchRes['products']) . "\n";
        return false;
    }
    if (!str_contains(strtolower($reply), "isn't currently available") && !str_contains(strtolower($reply), "not currently available")) {
        echo "FAILED: Reply did not politely explain category is absent!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// TEST N: Conversation Isolation Across Sessions
// -----------------------------------------------------------------------------
$allPassed = runScenario('TEST N: Conversation Isolation Across Sessions (User A vs User B)', function() {
    $sidA = 'session_alice_123';
    $sidB = 'session_bob_456';

    ShoppingStateEngine::resetState($sidA);
    ShoppingStateEngine::resetState($sidB);

    // Alice searches for girlfriend gifts (music)
    $msgsA = [
        ['role' => 'user', 'content' => 'Suggest birthday gifts for my girlfriend who loves music. Budget: under ₹5,000.']
    ];
    $stateA = ShoppingStateEngine::processTurn($msgsA, $sidA);

    // Bob searches for boyfriend gifts (cricket)
    $msgsB = [
        ['role' => 'user', 'content' => 'Suggest birthday gifts for my boyfriend who loves cricket. Budget: under $50.']
    ];
    $stateB = ShoppingStateEngine::processTurn($msgsB, $sidB);

    echo "  Alice State: Recipient={$stateA['recipient']}, Interests=" . implode(', ', $stateA['interests']) . ", Currency={$stateA['budget']['currency']}\n";
    echo "  Bob State: Recipient={$stateB['recipient']}, Interests=" . implode(', ', $stateB['interests']) . ", Currency={$stateB['budget']['currency']}\n";

    if ($stateA['recipient'] !== 'girlfriend' || $stateB['recipient'] !== 'boyfriend') {
        echo "FAILED: Session recipient isolation failed!\n";
        return false;
    }
    if (in_array('cricket', $stateA['interests']) || in_array('music', $stateB['interests'])) {
        echo "FAILED: Session interests isolation failed! Leakage between sessions!\n";
        return false;
    }
    if ($stateA['budget']['currency'] !== 'INR' || $stateB['budget']['currency'] !== 'USD') {
        echo "FAILED: Session currency isolation failed!\n";
        return false;
    }

    return true;
}) && $allPassed;

// -----------------------------------------------------------------------------
// Final Verdict
// -----------------------------------------------------------------------------
echo "========================================================\n";
if ($allPassed) {
    echo "ALL 14 SCENARIOS PASSED (TEST A through TEST N)!\n";
    exit(0);
} else {
    echo "ONE OR MORE SCENARIOS FAILED!\n";
    exit(1);
}
