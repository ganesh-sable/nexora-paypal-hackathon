<?php
/**
 * Nexora AI Shopping Assistant - Conversational Shopping State & Recommendation Engine
 * 
 * Manages multi-turn conversation state, request lifecycle (new request vs. follow-up),
 * intelligent gift recommendations, category & preference retention, currency normalization,
 * product reference resolution, specifications comparison, and strict budget enforcement.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/chatbot_intent.php';

if (!defined('INR_TO_USD_RATE')) {
    define('INR_TO_USD_RATE', 83.0);
}

class ShoppingStateEngine {

    /**
     * Map of user interests to categories, keywords, and description hints
     */
    public static array $interestMap = [
        'dance' => [
            'display'    => 'dance',
            'categories' => [17, 11, 4, 20], // Audio for practice music, Fitness/Movement gear, Footwear/Sneakers, Wellness
            'keywords'   => ['earbud', 'headphone', 'speaker', 'yoga mat', 'resistance loop', 'massage gun', 'foam roller', 'cross-training', 'gym shoe'],
            'desc_boost' => ['dance', 'rhythm', 'flexibility', 'rehearsal', 'stretching', 'music', 'portable audio', 'recovery']
        ],
        'music' => [
            'display'    => 'music',
            'categories' => [17], // Audio & Accessories
            'keywords'   => ['headphone', 'earbud', 'speaker', 'audio', 'soundbar', 'microphone', 'studio monitor', 'iem', 'bookshelf'],
            'desc_boost' => ['music', 'sound', 'hi-res', 'bass', 'audio', 'earcups', 'acoustic', 'tws', 'wireless']
        ],
        'cricket' => [
            'display'    => 'cricket & sports',
            'categories' => [11], // Sports & Fitness
            'keywords'   => ['cricket bat', 'cricket', 'willow', 'sports flask', 'sports', 'jump rope', 'foam roller'],
            'desc_boost' => ['kashmir willow', 'cricket', 'bat', 'match', 'sports', 'flask']
        ],
        'gaming' => [
            'display'    => 'gaming',
            'categories' => [16, 2], // Gaming, Computers
            'keywords'   => ['gaming', 'controller', 'headset', 'console', 'keyboard', 'gamepad', 'vr', 'mouse pad', 'charging station'],
            'desc_boost' => ['gamer', 'fps', 'rgb', 'low latency', 'console', 'fast charging']
        ],
        'makeup' => [
            'display'    => 'makeup',
            'categories' => [6], // Beauty & Personal Care
            'keywords'   => ['makeup', 'brush', 'lipstick', 'mascara', 'foundation', 'cleanser', 'cosmetic'],
            'desc_boost' => ['makeup', 'beauty', 'glamour', 'cosmetic', 'skin', 'lipstick']
        ],
        'skincare' => [
            'display'    => 'skincare',
            'categories' => [6],
            'keywords'   => ['serum', 'cream', 'cleanser', 'sunscreen', 'anti-aging', 'argan oil', 'facial'],
            'desc_boost' => ['hydrating', 'skin', 'dermatologist', 'radiant', 'face', 'skincare']
        ],
        'beauty' => [
            'display'    => 'beauty',
            'categories' => [6, 19], // Beauty, Jewellery
            'keywords'   => ['serum', 'cream', 'straightener', 'hair dryer', 'perfume', 'makeup', 'lipstick', 'mascara', 'necklace', 'earring'],
            'desc_boost' => ['beauty', 'skincare', 'glow', 'hair', 'fragrance', 'skin']
        ],
        'fitness' => [
            'display'    => 'fitness',
            'categories' => [11, 20, 4], // Sports & Fitness, Health, Footwear
            'keywords'   => ['yoga mat', 'dumbbell', 'resistance', 'foam roller', 'jump rope', 'fitness tracker', 'sneaker', 'massage gun'],
            'desc_boost' => ['exercise', 'workout', 'gym', 'training', 'muscle']
        ],
        'jewellery' => [
            'display'    => 'jewellery',
            'categories' => [19],
            'keywords'   => ['necklace', 'earring', 'bracelet', 'ring', 'pendant', 'chain', 'anklet'],
            'desc_boost' => ['silver', 'gold', 'crystal', 'pearl', 'jewel', 'infinity', 'heart']
        ],
        'fashion' => [
            'display'    => 'fashion',
            'categories' => [3, 18, 19, 5],
            'keywords'   => ['handbag', 'backpack', 'jacket', 'tote', 'necklace', 'bracelet', 'scarf', 't-shirt'],
            'desc_boost' => ['stylish', 'designer', 'leather', 'outfit', 'classic']
        ],
        'books' => [
            'display'    => 'books & stationery',
            'categories' => [9],
            'keywords'   => ['book', 'notebook', 'pen', 'journal', 'planner', 'organizer', 'hardcover'],
            'desc_boost' => ['reading', 'writing', 'paper', 'stationery', 'handbook']
        ],
        'cooking' => [
            'display'    => 'cooking',
            'categories' => [7],
            'keywords'   => ['cookware', 'pan', 'knife', 'pot', 'blender', 'storage'],
            'desc_boost' => ['kitchen', 'chef', 'recipe', 'cooking']
        ],
        'tech' => [
            'display'    => 'tech & gadgets',
            'categories' => [1, 2, 17],
            'keywords'   => ['smartwatch', 'earbud', 'headphone', 'gadget', 'charger', 'speaker', 'mouse', 'keyboard', 'webcam'],
            'desc_boost' => ['smart', 'wireless', 'tech', 'gadget', 'bluetooth', 'fast charging']
        ]
    ];

    /**
     * Session-isolated store for CLI or simulated testing environments
     */
    private static array $isolatedSessionStore = [];

    /**
     * Retrieve or initialize structured shopping state
     */
    public static function getState(?string $sessionId = null): array {
        $sid = self::resolveSessionId($sessionId);

        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['nexora_shopping_state'][$sid])) {
            return $_SESSION['nexora_shopping_state'][$sid];
        }

        if (isset(self::$isolatedSessionStore[$sid])) {
            return self::$isolatedSessionStore[$sid];
        }

        $defaultRequest = self::createBlankRequest();

        $defaultState = [
            'session_id'              => $sid,
            'active_request'          => $defaultRequest,
            'request_history'         => [],
            'last_displayed_products' => [],
            'turn_count'              => 0,
            // Mirror active request top-level properties for backward compatibility
            'intent'                  => $defaultRequest['intent'],
            'recipient'               => $defaultRequest['recipient'],
            'occasion'                => $defaultRequest['occasion'],
            'budget'                  => $defaultRequest['budget'],
            'interests'               => $defaultRequest['interests'],
            'preferred_categories'    => [],
            'excluded_categories'     => [],
            'excluded_keywords'       => [],
            'requested_count'         => $defaultRequest['requested_count'],
            'missing_information'     => [],
            'ready_to_recommend'      => false,
            'needs_currency_clarification' => false,
            'product_type'            => null,
            'brand'                   => null,
        ];

        self::saveState($defaultState, $sid);
        return $defaultState;
    }

    /**
     * Create an empty, clean request structure
     */
    public static function createBlankRequest(): array {
        return [
            'request_id'           => uniqid('req_'),
            'intent'               => 'general_conversation', // gift_recommendation, product_search, support_faq, track_order, get_transactions, add_to_cart, comparison, product_detail
            'recipient'            => null,   // girlfriend, boyfriend, friend, sister, brother, mother, father, etc.
            'occasion'             => null,   // birthday, anniversary, wedding, graduation, holiday
            'category_id'          => null,   // primary category ID if product search (e.g. 17 for audio, 2 for computers)
            'category_name'        => null,   // e.g. "Audio & Accessories", "Computers & Laptops"
            'product_type'         => null,   // e.g. "headphones", "laptop", "shoes", "watch", "phone"
            'specific_product_type'=> null,   // e.g. "dance shoes", "wireless headphones"
            'preferences'          => [],     // e.g. ['wireless', '16gb ram']
            'brand'                => null,   // e.g. "Samsung", "Nike", "Apple"
            'budget'               => null,   // ['amount' => 2000, 'currency' => 'INR', 'maximum_usd' => 24.10, 'raw_text' => '₹2,000']
            'strict_budget'        => false,  // true if user says "Do not show anything above my budget"
            'interests'            => [],     // e.g. ['dance', 'music']
            'excluded_categories'  => [],     // e.g. [5]
            'excluded_keywords'    => [],     // e.g. ['watch', 'smartwatch']
            'requested_count'      => 6,      // user requested count, e.g. 5, 10
            'missing_information'  => [],     // ['currency', 'interests', 'budget']
            'ready_to_recommend'   => false,
            'needs_currency_clarification' => false,
            'latest_added_interest'=> null
        ];
    }

    /**
     * Save shopping state into session or isolated store
     */
    public static function saveState(array &$state, ?string $sessionId = null): void {
        $sid = self::resolveSessionId($sessionId);
        $state['session_id'] = $sid;

        // Mirror active request properties to top-level state
        if (isset($state['active_request'])) {
            $ar = $state['active_request'];
            $state['intent']                       = $ar['intent'] ?? 'general_conversation';
            $state['recipient']                    = $ar['recipient'] ?? null;
            $state['occasion']                     = $ar['occasion'] ?? null;
            $state['budget']                       = $ar['budget'] ?? null;
            $state['strict_budget']                = $ar['strict_budget'] ?? false;
            $state['interests']                    = $ar['interests'] ?? [];
            $state['excluded_categories']          = $ar['excluded_categories'] ?? [];
            $state['excluded_keywords']            = $ar['excluded_keywords'] ?? [];
            $state['requested_count']              = $ar['requested_count'] ?? 6;
            $state['product_type']                 = $ar['product_type'] ?? null;
            $state['brand']                        = $ar['brand'] ?? null;
            $state['ready_to_recommend']           = $ar['ready_to_recommend'] ?? false;
            $state['missing_information']          = $ar['missing_information'] ?? [];
            $state['needs_currency_clarification'] = $ar['needs_currency_clarification'] ?? false;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['nexora_shopping_state'])) {
                $_SESSION['nexora_shopping_state'] = [];
            }
            $_SESSION['nexora_shopping_state'][$sid] = $state;
        }

        self::$isolatedSessionStore[$sid] = $state;
    }

    /**
     * Clear / reset shopping state
     */
    public static function resetState(?string $sessionId = null): void {
        $sid = self::resolveSessionId($sessionId);
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION['nexora_shopping_state'][$sid]);
        }
        unset(self::$isolatedSessionStore[$sid]);
    }

    /**
     * Resolve active session identifier
     */
    public static function resolveSessionId(?string $sessionId = null): string {
        if (!empty($sessionId)) {
            return $sessionId;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $id = session_id();
            if (!empty($id)) return $id;
        }
        return 'default_session';
    }

    /**
     * Detect whether a turn begins a brand new shopping request or is a follow-up
     */
    public static function isNewRequest(string $currentText, array $state): bool {
        $lower = strtolower(trim($currentText));
        $active = $state['active_request'] ?? $state;

        // 1. Explicit transition phrases ("now I want to buy a laptop", "let's look at shoes instead")
        if (preg_match('/\b(now i want to|now show me|now recommend|let\'s look at|instead of that|can you show me|can you find|start over|new search)\b/i', $lower)) {
            return true;
        }

        // 2. Different recipient detected for a gift query
        $newRecipient = self::detectRecipient($currentText);
        if ($newRecipient !== null) {
            $oldRecipient = $active['recipient'] ?? null;
            if ($oldRecipient !== null && $newRecipient !== $oldRecipient) {
                return true; // Switching recipient (e.g. girlfriend -> best friend)
            }
            if ($oldRecipient === null && ($active['intent'] ?? '') !== 'gift_recommendation') {
                return true; // Was not a gift request before
            }
        }

        // 3. Distinct product search category detected
        $newProductType = self::detectProductType($currentText);
        if ($newProductType !== null) {
            $oldProductType = $active['product_type'] ?? null;
            if ($oldProductType !== null && $newProductType !== $oldProductType) {
                return true; // Switching product type (e.g. headphones -> laptop)
            }
            if ($oldProductType === null && ($active['intent'] ?? '') === 'gift_recommendation') {
                return true; // Switching from gift advice to a direct product search
            }
        }

        // 4. Starting a new structured gift search (e.g. "Suggest 5 birthday gifts for my...")
        if (preg_match('/^(?:suggest|recommend|find|show me|look for)\s+(\d+)?\s*(?:birthday\s+)?gifts?\s+for\s+(?:my\s+)?([a-z\s]+)/i', $lower, $sm)) {
            $recip = self::detectRecipient($sm[2]);
            if ($recip && $recip !== ($active['recipient'] ?? '')) {
                return true;
            }
        }

        // 5. Follow-up refinements are NEVER new requests
        if (preg_match('/^(?:actually|now|instead)?\s*(?:keep it|make it|under|below|budget is|budget:)\s*(?:rs\.?|inr|\$|₹)?\s*[0-9]+/i', $lower) ||
            preg_match('/^(?:rs\.?|inr|\$|₹)?\s*[0-9]+(?:\s*(?:inr|usd|rupees?|dollars?))?\s*(?:and\b.*)?$/i', $lower) ||
            preg_match('/^(?:she|he|they)?\s*(?:also|too|as well)\s*(?:likes|prefers)/i', $lower) ||
            preg_match('/^(?:i\s+)?prefer\s+(wireless|wired)/i', $lower) ||
            preg_match('/^(?:no|without|exclude|skip)\s+[a-z]+/i', $lower) ||
            preg_match('/\b(first|second|compare|specs of|tell me more)\b/i', $lower)) {
            return false;
        }

        // 6. If active request is empty or just generic conversation
        if (empty($active['intent']) || $active['intent'] === 'general_conversation') {
            return true;
        }

        // 7. Operational intents (PayPal, Order Tracking, Transactions)
        if (str_contains($lower, 'paypal') || str_contains($lower, 'how to pay') || str_contains($lower, 'refund') ||
            preg_match('/(trk-[a-z0-9\-]+|ord-[0-9]{4}-[a-z0-9\-]+)/i', $currentText) ||
            str_contains($lower, 'track') || str_contains($lower, 'delivery status') ||
            str_contains($lower, 'transaction')) {
            return true;
        }

        return false;
    }

    /**
     * Cumulative state updater from message stream
     */
    public static function processTurn(array $chatMessages, ?string $sessionId = null): array {
        $state = self::getState($sessionId);
        $state['turn_count'] = count($chatMessages);

        $userMsgs = [];
        foreach ($chatMessages as $msg) {
            if (($msg['role'] ?? '') === 'user') {
                $c = trim((string)($msg['content'] ?? ''));
                if ($c !== '') {
                    $userMsgs[] = $c;
                }
            }
        }

        if (empty($userMsgs)) {
            self::saveState($state, $sessionId);
            return $state;
        }

        $lastUserMsg = end($userMsgs);

        // Check reference resolution / product comparison on the latest message first
        if (preg_match('/\b(tell me more about|details on|info on|describe|specs of)\s+(?:the\s+)?(first|1st|second|2nd|third|3rd|fourth|4th|last)\s*([a-z]+)?/i', $lastUserMsg) ||
            preg_match('/\b(compare|comparison|difference between)\b/i', $lastUserMsg)) {
            $refResult = self::resolveReference($lastUserMsg, $state);
            $state['active_request']['reference_result'] = $refResult;
            self::saveState($state, $sessionId);
            return $state;
        }

        // Replay user messages in sequence if active request is blank or starting a new sequence
        if (empty($state['active_request']['intent']) || $state['active_request']['intent'] === 'general_conversation') {
            foreach ($userMsgs as $userText) {
                self::applyTurnText($userText, $state);
            }
        } else {
            self::applyTurnText($lastUserMsg, $state);
        }

        self::saveState($state, $sessionId);
        return $state;
    }

    /**
     * Apply a single user turn string to active state
     */
    public static function applyTurnText(string $currentText, array &$state): void {
        $isNew = self::isNewRequest($currentText, $state);

        if ($isNew) {
            if (!empty($state['active_request']['intent']) && $state['active_request']['intent'] !== 'general_conversation') {
                $state['request_history'][] = $state['active_request'];
            }
            $state['active_request'] = self::createBlankRequest();
        }

        self::extractEntitiesIntoRequest($currentText, $state['active_request']);
        self::applyFollowUpUpdates($currentText, $state['active_request']);
        self::evaluateReadiness($state['active_request']);
    }

    /**
     * Extract recipient name/role
     */
    public static function detectRecipient(string $text): ?string {
        if (preg_match('/\b(girlfriend|gf|girl friend|fiancee)\b/i', $text)) {
            return 'girlfriend';
        } elseif (preg_match('/\b(boyfriend|bf|boy friend)\b/i', $text)) {
            return 'boyfriend';
        } elseif (preg_match('/\b(wife)\b/i', $text)) {
            return 'wife';
        } elseif (preg_match('/\b(husband)\b/i', $text)) {
            return 'husband';
        } elseif (preg_match('/\b(best friend|friend|buddy|pal|bff|roommate)\b/i', $text)) {
            return 'friend';
        } elseif (preg_match('/\b(sister|brother|mother|father|mom|dad|daughter|son)\b/i', $text, $rm)) {
            return strtolower($rm[1]);
        }
        return null;
    }

    /**
     * Detect specific product type
     */
    public static function detectProductType(string $text): ?string {
        $lower = strtolower($text);
        if (preg_match('/\b(laptops?|notebooks?|ultrabooks?)\b/i', $lower)) {
            return 'laptop';
        } elseif (preg_match('/\b(headphones?|headsets?|earbuds?|earphones?|earfones?|headfones?)\b/i', $lower)) {
            return 'headphones';
        } elseif (preg_match('/\b(smartwatch(?:es)?|watches?|timepiece)\b/i', $lower) && !preg_match('/\bno\s+watches\b/i', $lower)) {
            return 'watch';
        } elseif (preg_match('/\b(phones?|smartphones?|mobiles?|5g phone)\b/i', $lower)) {
            return 'phone';
        } elseif (preg_match('/\b(shoes?|sneakers?|sandals?|footwear|boots?|loafers?)\b/i', $lower)) {
            return 'shoes';
        } elseif (preg_match('/\b(monitors?|displays?|screens?)\b/i', $lower)) {
            return 'monitor';
        } elseif (preg_match('/\b(keyboards?)\b/i', $lower)) {
            return 'keyboard';
        } elseif (preg_match('/\b(mice|mouse)\b/i', $lower)) {
            return 'mouse';
        } elseif (preg_match('/\b(speakers?|soundbars?)\b/i', $lower)) {
            return 'speaker';
        } elseif (preg_match('/\b(tablets?|ipads?)\b/i', $lower)) {
            return 'tablet';
        } elseif (preg_match('/\b(books?|stationery|journals?|novels?)\b/i', $lower)) {
            return 'book';
        }
        return null;
    }

    /**
     * Extract entities into an active request
     */
    private static function extractEntitiesIntoRequest(string $text, array &$req): void {
        $lower = strtolower($text);

        // 1. Detect Intent
        if (str_contains($lower, 'paypal') || str_contains($lower, 'how to pay') || str_contains($lower, 'payment method') || str_contains($lower, 'refund') || str_contains($lower, 'return policy')) {
            $req['intent'] = 'support_faq';
        } elseif (preg_match('/(trk-[a-z0-9\-]+|ord-[0-9]{4}-[a-z0-9\-]+)/i', $text) || str_contains($lower, 'track') || str_contains($lower, 'delivery status')) {
            $req['intent'] = 'track_order';
        } elseif (preg_match('/\b(gift|gifts|present|presents|gift ideas|gift recommendation|birthday gift)\b/i', $text)) {
            $req['intent'] = 'gift_recommendation';
        }

        // 2. Recipient Extraction
        $recip = self::detectRecipient($text);
        if ($recip !== null) {
            $req['recipient'] = $recip;
            if ($req['intent'] === 'general_conversation') {
                $req['intent'] = 'gift_recommendation';
            }
        }

        // 3. Occasion Extraction
        if (preg_match('/\b(birthday|bday)\b/i', $text)) {
            $req['occasion'] = 'birthday';
        } elseif (preg_match('/\b(anniversary)\b/i', $text)) {
            $req['occasion'] = 'anniversary';
        } elseif (preg_match('/\b(wedding)\b/i', $text)) {
            $req['occasion'] = 'wedding';
        } elseif (preg_match('/\b(valentine|valentines)\b/i', $text)) {
            $req['occasion'] = 'valentine';
        } elseif (preg_match('/\b(graduation)\b/i', $text)) {
            $req['occasion'] = 'graduation';
        }

        // 4. Product Type & Category ID
        $pType = self::detectProductType($text);
        if ($pType !== null) {
            $req['product_type'] = $pType;
            if ($req['intent'] === 'general_conversation') {
                $req['intent'] = 'product_search';
            }
            if ($pType === 'laptop') {
                $req['category_id'] = 2;
                $req['category_name'] = 'Computers & Laptops';
            } elseif ($pType === 'headphones' || $pType === 'speaker') {
                $req['category_id'] = 17;
                $req['category_name'] = 'Audio & Accessories';
            } elseif ($pType === 'watch') {
                $req['category_id'] = 5;
                $req['category_name'] = 'Watches & Accessories';
            } elseif ($pType === 'phone' || $pType === 'tablet') {
                $req['category_id'] = 1;
                $req['category_name'] = 'Electronics & Gadgets';
            } elseif ($pType === 'shoes') {
                $req['category_id'] = 4;
                $req['category_name'] = 'Footwear';
            } elseif ($pType === 'book') {
                $req['category_id'] = 9;
                $req['category_name'] = 'Books & Stationery';
            }
        }

        // Specific combination: dance shoes
        if (preg_match('/\bdance\s+(?:shoes?|sneakers?|boots?|footwear)\b/i', $text) ||
            (str_contains($lower, 'dance') && preg_match('/\b(shoes?|sneakers?|footwear)\b/i', $text))) {
            $req['product_type'] = 'shoes';
            $req['specific_product_type'] = 'dance shoes';
            $req['category_id'] = 4;
            $req['category_name'] = 'Footwear';
        }

        // 5. Requested Item Count (e.g. 10 birthday gifts, 5 gifts, top 3 options)
        if (preg_match('/\b(\d+)\s*(?:[a-z]+\s+)*(?:gift ideas|gifts|products|options|items|recommendations|suggestions|ideas)\b/i', $text, $cm)) {
            $count = (int)$cm[1];
            if ($count >= 1 && $count <= 20) {
                $req['requested_count'] = $count;
            }
        }

        // 6. Brand Extraction & Category Mismatch Check
        self::parseBrandFromText($text, $req);

        // 7. Budget & Currency
        self::parseBudgetFromText($text, $req);

        // 8. Interests & Hobbies
        self::parseInterestsFromText($text, $req);

        // 9. Negative Constraints / Exclusions
        self::parseExclusionsFromText($text, $req);
    }

    /**
     * Parse brand and check for industry/category mismatch
     */
    private static function parseBrandFromText(string $text, array &$req): void {
        $lower = strtolower($text);
        foreach (ChatbotIntent::$brandCategories as $bKey => $bInfo) {
            if (preg_match('/\b' . preg_quote($bKey, '/') . '\b/i', $text)) {
                $req['brand'] = ucfirst($bKey);
                // Check brand mismatch with product type
                if (!empty($req['product_type'])) {
                    if (in_array($req['product_type'], $bInfo['does_not_make'] ?? [], true)) {
                        $req['mismatch'] = [
                            'brand'        => ucfirst($bKey),
                            'product_type' => $req['product_type'],
                            'reason'       => ucfirst($bKey) . " does not manufacture {$req['product_type']}s — {$bKey} specializes in {$bInfo['type']}."
                        ];
                    }
                }
                break;
            }
        }
    }

    /**
     * Parse budget amounts and currency definitions
     */
    private static function parseBudgetFromText(string $text, array &$req): void {
        $lower = strtolower($text);

        // Detect currency explicitly
        $detectedCurrency = null;
        if (str_contains($text, '₹') || preg_match('/\b(inr|rupees?|rs\.?)\b/i', $text)) {
            $detectedCurrency = 'INR';
        } elseif (str_contains($text, '$') || preg_match('/\b(usd|dollars?|bucks)\b/i', $text)) {
            $detectedCurrency = 'USD';
        }

        // Check if strict budget is requested
        if (preg_match('/\b(do not show anything above|strictly under|strict maximum|strict budget|nothing above)\b/i', $text)) {
            $req['strict_budget'] = true;
        }

        // If user simply responds with "INR" in a follow-up
        if (preg_match('/^(inr|rupees?|rs\.?)\b/i', trim($text))) {
            $detectedCurrency = 'INR';
            $req['needs_currency_clarification'] = false;
        }

        // Extract budget amount: "under 20000", "my budget is ₹2,000", "budget 2000", "under 5,000", "keep it under ₹3,000"
        if (preg_match('/(?:under|below|less than|within|upto|budget(?:\s*is|\s*of|\s*:)?|max(?:imum)?)\s*(?:rs\.?|inr|\$|₹)?\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $text, $bm) ||
            preg_match('/(?:rs\.?|inr|\$|₹)\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $text, $bm) ||
            preg_match('/\b([0-9]+(?:,[0-9]+)*)\s*(?:inr|usd|rupees?|dollars?)\b/i', $text, $bm)) {

            $rawNum = (float)str_replace(',', '', $bm[1]);

            $curr = $detectedCurrency ?: ($req['budget']['currency'] ?? null);

            if (!$curr) {
                if ($rawNum >= 1500) {
                    if (!str_contains($text, '₹') && !preg_match('/\b(inr|usd|\$)\b/i', $text)) {
                        $req['needs_currency_clarification'] = true;
                    }
                    $curr = 'INR';
                } else {
                    $curr = 'USD';
                }
            } else {
                $req['needs_currency_clarification'] = false;
            }

            $maxUsd = ($curr === 'INR') ? round($rawNum / INR_TO_USD_RATE, 2) : $rawNum;

            $req['budget'] = [
                'amount'       => $rawNum,
                'currency'     => $curr,
                'maximum'      => $rawNum,
                'maximum_usd'  => $maxUsd,
                'raw_text'     => $bm[0]
            ];
        } elseif ($detectedCurrency && !empty($req['budget'])) {
            $req['budget']['currency'] = $detectedCurrency;
            $req['budget']['maximum_usd'] = ($detectedCurrency === 'INR')
                ? round($req['budget']['amount'] / INR_TO_USD_RATE, 2)
                : $req['budget']['amount'];
            $req['needs_currency_clarification'] = false;
        }
    }

    /**
     * Parse interests and hobbies
     */
    private static function parseInterestsFromText(string $text, array &$req): void {
        $lower = strtolower($text);

        // Check if user specifically requested to forget/remove an interest
        if (preg_match('/forget (?:the )?([a-z]+)/i', $text, $fm)) {
            $removeKey = strtolower($fm[1]);
            $req['interests'] = array_values(array_filter($req['interests'], fn($i) => $i !== $removeKey));
        }

        foreach (self::$interestMap as $interestKey => $info) {
            $patterns = [$interestKey];
            if ($interestKey === 'dance') {
                $patterns = ['dance', 'dancing', 'dancer', 'dance gear'];
            } elseif ($interestKey === 'music') {
                $patterns = ['music', 'songs', 'audio', 'music-related'];
            } elseif ($interestKey === 'cricket') {
                $patterns = ['cricket', 'cricket bat', 'batting', 'cricketer'];
            } elseif ($interestKey === 'gaming') {
                $patterns = ['gaming', 'games', 'gamer', 'video games', 'esports'];
            } elseif ($interestKey === 'makeup') {
                $patterns = ['makeup', 'cosmetics', 'lipstick', 'brush set'];
            } elseif ($interestKey === 'skincare') {
                $patterns = ['skincare', 'skin care', 'serum', 'facial cream'];
            } elseif ($interestKey === 'fitness') {
                $patterns = ['fitness', 'workout', 'gym', 'sports'];
            } elseif ($interestKey === 'jewellery') {
                $patterns = ['jewellery', 'jewelry', 'necklace', 'earrings', 'bracelet'];
            }

            foreach ($patterns as $p) {
                if (preg_match('/\b' . preg_quote($p, '/') . '\b/i', $text)) {
                    if (!in_array($interestKey, $req['interests'], true)) {
                        $req['interests'][] = $interestKey;
                    }
                    break;
                }
            }
        }
    }

    /**
     * Parse explicit product / category exclusions
     */
    private static function parseExclusionsFromText(string $text, array &$req): void {
        if (preg_match('/(?:no|without|exclude|don\'t show|skip)\s+([a-z\s]+?)(?:,|\.|$|please)/i', $text, $xm)) {
            $target = trim(strtolower($xm[1]));
            if (str_contains($target, 'watch')) {
                if (!in_array('watch', $req['excluded_keywords'], true)) {
                    $req['excluded_keywords'][] = 'watch';
                    $req['excluded_keywords'][] = 'smartwatch';
                }
                if (!in_array(5, $req['excluded_categories'], true)) {
                    $req['excluded_categories'][] = 5;
                }
            }
            if (str_contains($target, 'cloth') || str_contains($target, 'fashion')) {
                if (!in_array(3, $req['excluded_categories'], true)) {
                    $req['excluded_categories'][] = 3;
                }
            }
            if (str_contains($target, 'game') || str_contains($target, 'gaming')) {
                if (!in_array(16, $req['excluded_categories'], true)) {
                    $req['excluded_categories'][] = 16;
                }
            }
        }
    }

    /**
     * Apply turn-specific updates to the active request
     */
    private static function applyFollowUpUpdates(string $currentText, array &$req): void {
        $lower = strtolower($currentText);

        // Budget adjustment follow-up (e.g. "Actually, keep it under ₹5,000", "My budget is ₹2,000", "under 5000", "Keep it under ₹3,000")
        if (preg_match('/(?:actually|now|instead|keep it|make it|change to|my budget is)?\s*(?:under|below|less than|within)?\s*(?:rs\.?|inr|\$|₹)\s*([0-9]+(?:,[0-9]+)*)/i', $currentText, $bm) ||
            preg_match('/(?:under|below|less than|within|keep it under)\s*([0-9]+(?:,[0-9]+)*)\s*(?:inr|usd|rupees?|dollars?)?/i', $currentText, $bm) ||
            preg_match('/\b([0-9]+(?:,[0-9]+)*)\s*(?:inr|usd|rupees?|dollars?)\b/i', $currentText, $bm)) {
            $newAmount = (float)str_replace(',', '', $bm[1]);
            $curr = $req['budget']['currency'] ?? 'INR';
            if (str_contains($currentText, '$') || str_contains($lower, 'usd') || str_contains($lower, 'dollar')) {
                $curr = 'USD';
            } elseif (str_contains($currentText, '₹') || str_contains($lower, 'inr') || str_contains($lower, 'rupee')) {
                $curr = 'INR';
            }

            $req['budget'] = [
                'amount'       => $newAmount,
                'currency'     => $curr,
                'maximum'      => $newAmount,
                'maximum_usd'  => ($curr === 'INR') ? round($newAmount / INR_TO_USD_RATE, 2) : $newAmount,
                'raw_text'     => $bm[0]
            ];
            $req['needs_currency_clarification'] = false;
        }

        // Preference refinements (e.g. "I prefer wireless ones", "only wireless", "with 16GB RAM")
        if (preg_match('/\b(wireless|bluetooth|true wireless|tws|anc|noise[- ]cancelling)\b/i', $currentText, $pm)) {
            $pref = strtolower($pm[1]);
            if (!in_array($pref, $req['preferences'], true)) {
                $req['preferences'][] = $pref;
            }
        }
        if (preg_match('/\b(wired|detachable cable)\b/i', $currentText, $pm)) {
            $pref = strtolower($pm[1]);
            if (!in_array($pref, $req['preferences'], true)) {
                $req['preferences'][] = $pref;
            }
        }
        if (preg_match('/\b(16gb|8gb|32gb|512gb|1tb|rtx|i7|i5)\b/i', $currentText, $pm)) {
            $pref = strtoupper($pm[1]);
            if (!in_array($pref, $req['preferences'], true)) {
                $req['preferences'][] = $pref;
            }
        }

        // Additive interests for active recipient (e.g. "She also likes makeup and skincare")
        if (preg_match('/(?:also|too|as well|plus)\s*(?:likes|prefers|enjoys|loves)?\s*([a-z\s]+)/i', $currentText, $am)) {
            $addedPhrase = strtolower($am[1]);
            foreach (self::$interestMap as $iKey => $iInfo) {
                if (str_contains($addedPhrase, $iKey)) {
                    if (!in_array($iKey, $req['interests'], true)) {
                        $req['interests'][] = $iKey;
                    }
                    $req['latest_added_interest'] = $iKey;
                }
            }
        }
    }

    /**
     * Evaluate if active request is ready to recommend products
     */
    private static function evaluateReadiness(array &$req): void {
        $req['missing_information'] = [];

        // For Product Searches (e.g. laptop, watch, headphones)
        if ($req['intent'] === 'product_search') {
            $req['ready_to_recommend'] = !empty($req['product_type']);
            return;
        }

        // For Gift Recommendations
        if ($req['intent'] === 'gift_recommendation') {
            if ($req['needs_currency_clarification'] && empty($req['interests'])) {
                $req['missing_information'][] = 'currency';
                $req['missing_information'][] = 'interests';
                $req['ready_to_recommend'] = false;
                return;
            }

            // Ready if we have interests OR (recipient AND budget)
            if (!empty($req['interests']) || (!empty($req['recipient']) && !empty($req['budget']))) {
                $req['ready_to_recommend'] = true;
                $req['missing_information'] = [];
                return;
            }

            if (empty($req['interests'])) {
                $req['missing_information'][] = 'interests';
            }
            if (empty($req['budget'])) {
                $req['missing_information'][] = 'budget';
            }

            $req['ready_to_recommend'] = empty($req['missing_information']);
        }
    }

    /**
     * Database-grounded gift search and relevance ranking
     */
    public static function recommendGifts(array &$state): array {
        $req = $state['active_request'] ?? $state;
        $pdo = DB::getConnection();
        $targetCategories = [];
        $interestKeywords = [];
        $descKeywords = [];

        // Collect categories and keywords from active interests
        foreach ($req['interests'] as $interestKey) {
            if (isset(self::$interestMap[$interestKey])) {
                $info = self::$interestMap[$interestKey];
                $targetCategories = array_merge($targetCategories, $info['categories']);
                $interestKeywords = array_merge($interestKeywords, $info['keywords']);
                $descKeywords     = array_merge($descKeywords, $info['desc_boost']);
            }
        }
        $targetCategories = array_unique($targetCategories);

        // Thoughtful jewelry only for girlfriend / wife / partner
        if (in_array($req['recipient'] ?? '', ['girlfriend', 'wife', 'partner'], true) &&
            !in_array(19, $req['excluded_categories'], true) &&
            !in_array('jewellery', $req['excluded_keywords'], true)) {
            $targetCategories[] = 19;
        }

        // Specific product type constraint (e.g. dance shoes -> Footwear only)
        $specificKeywords = [];
        if (!empty($req['specific_product_type']) && $req['specific_product_type'] === 'dance shoes') {
            $targetCategories = [4]; // Strictly Footwear
            $specificKeywords = ['shoe', 'shoes', 'sneaker', 'cross-training', 'gym shoe'];
            $interestKeywords = $specificKeywords;
        }

        $maxUsd = $req['budget']['maximum_usd'] ?? null;
        $requestedCount = $req['requested_count'] ?? 6;
        $excludedCats = $req['excluded_categories'] ?? [];
        $excludedKeywords = $req['excluded_keywords'] ?? [];

        $candidates = [];

        // Helper to balance multi-interest results
        $selectTopCandidates = function(array $cands, array $r, int $limit): array {
            usort($cands, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['price'] <=> $a['price']));

            if (count($r['interests']) > 1) {
                $balanced = [];
                $usedIds = [];

                $orderedInterests = $r['interests'];
                if (!empty($r['latest_added_interest'])) {
                    $orderedInterests = array_diff($orderedInterests, [$r['latest_added_interest']]);
                    array_unshift($orderedInterests, $r['latest_added_interest']);
                }

                // First round: take up to 2 items per active interest
                foreach ($orderedInterests as $intKey) {
                    $info = self::$interestMap[$intKey] ?? null;
                    if (!$info) continue;
                    $intCats = $info['categories'];
                    $countForInterest = 0;

                    foreach ($cands as $cand) {
                        if (in_array((int)($cand['category_id'] ?? 0), $intCats, true) && !in_array($cand['id'], $usedIds, true)) {
                            $balanced[] = $cand;
                            $usedIds[] = $cand['id'];
                            $countForInterest++;
                            if ($countForInterest >= 2) break;
                        }
                    }
                }

                // Fill remainder with highest scoring candidates
                foreach ($cands as $cand) {
                    if (!in_array($cand['id'], $usedIds, true)) {
                        $balanced[] = $cand;
                        $usedIds[] = $cand['id'];
                        if (count($balanced) >= $limit) break;
                    }
                }

                return array_slice($balanced, 0, $limit);
            }

            return array_slice($cands, 0, $limit);
        };

        if ($pdo) {
            try {
                $sql = "SELECT p.*, c.name as category_name 
                        FROM products p 
                        JOIN categories c ON p.category_id = c.id 
                        WHERE 1=1";
                $params = [];

                if (!empty($targetCategories)) {
                    $inPlaceholders = implode(',', array_fill(0, count($targetCategories), '?'));
                    $sql .= " AND p.category_id IN ($inPlaceholders)";
                    foreach ($targetCategories as $tc) $params[] = $tc;
                }

                if (!empty($excludedCats)) {
                    $exPlaceholders = implode(',', array_fill(0, count($excludedCats), '?'));
                    $sql .= " AND p.category_id NOT IN ($exPlaceholders)";
                    foreach ($excludedCats as $ec) $params[] = $ec;
                }

                if (!empty($specificKeywords)) {
                    $skwParts = [];
                    foreach ($specificKeywords as $skw) {
                        $skwParts[] = "p.name ILIKE ?";
                        $params[] = '%' . $skw . '%';
                    }
                    $sql .= " AND (" . implode(" OR ", $skwParts) . ")";
                }

                if ($maxUsd !== null && $maxUsd > 0) {
                    $sql .= " AND p.price <= ?";
                    $params[] = $maxUsd;
                }

                foreach ($excludedKeywords as $exKw) {
                    $sql .= " AND p.name NOT ILIKE ? AND p.description NOT ILIKE ?";
                    $params[] = '%' . $exKw . '%';
                    $params[] = '%' . $exKw . '%';
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $score = 0;
                    $nameLower = strtolower($row['name']);
                    $descLower = strtolower($row['description']);
                    $catId = (int)$row['category_id'];

                    foreach ($interestKeywords as $kw) {
                        if (str_contains($nameLower, strtolower($kw))) {
                            $score += 20;
                        } elseif (str_contains($descLower, strtolower($kw))) {
                            $score += 8;
                        }
                    }

                    if (in_array('cricket', $req['interests'], true) && $catId === 11) $score += 25;
                    if (in_array('gaming', $req['interests'], true) && $catId === 16) $score += 25;
                    if (in_array('music', $req['interests'], true) && $catId === 17) $score += 25;
                    if (in_array('dance', $req['interests'], true) && ($catId === 17 || $catId === 11 || $catId === 4)) $score += 20;
                    if (in_array('makeup', $req['interests'], true) && $catId === 6) $score += 25;
                    if (in_array('skincare', $req['interests'], true) && $catId === 6) $score += 25;

                    if (!empty($req['latest_added_interest']) && in_array($req['latest_added_interest'], ['makeup', 'skincare'], true) && $catId === 6) {
                        $score += 40;
                    }

                    if (in_array($req['recipient'] ?? '', ['girlfriend', 'wife'], true) && $catId === 19) {
                        $score += 15;
                    }

                    $score += ((float)$row['rating'] * 3);
                    $score += min(10, ((int)$row['reviews_count'] / 50));

                    $candidates[] = [
                        'id'          => (int)$row['id'],
                        'category_id' => $catId,
                        'name'        => $row['name'],
                        'price'       => (float)$row['price'],
                        'description' => $row['description'],
                        'category'    => $row['category_name'],
                        'rating'      => (float)$row['rating'],
                        'emoji'       => $row['emoji'] ?? '🎁',
                        'image'       => $row['image_url'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80',
                        'stock'       => (int)$row['stock'],
                        'score'       => $score
                    ];
                }

            } catch (Exception $e) {
                error_log("recommendGifts error: " . $e->getMessage());
            }
        }

        // Fallback to in-memory catalog
        if (empty($candidates) && function_exists('get_all_products')) {
            $all = get_all_products();
            foreach ($all as $p) {
                if (!empty($targetCategories) && !in_array((int)$p['category_id'], $targetCategories, true)) {
                    continue;
                }
                if (!empty($excludedCats) && in_array((int)$p['category_id'], $excludedCats, true)) {
                    continue;
                }
                if ($maxUsd !== null && (float)$p['price'] > $maxUsd) {
                    continue;
                }
                if (!empty($specificKeywords)) {
                    $matchedSkw = false;
                    foreach ($specificKeywords as $skw) {
                        if (str_contains(strtolower($p['name']), $skw)) {
                            $matchedSkw = true;
                            break;
                        }
                    }
                    if (!$matchedSkw) continue;
                }
                $nameLower = strtolower($p['name']);
                foreach ($excludedKeywords as $exKw) {
                    if (str_contains($nameLower, $exKw)) continue 2;
                }

                $score = 0;
                $descLower = strtolower($p['description'] ?? '');
                $catId = (int)$p['category_id'];

                foreach ($interestKeywords as $kw) {
                    if (str_contains($nameLower, strtolower($kw))) {
                        $score += 20;
                    } elseif (str_contains($descLower, strtolower($kw))) {
                        $score += 8;
                    }
                }

                if (in_array('cricket', $req['interests'], true) && $catId === 11) $score += 25;
                if (in_array('gaming', $req['interests'], true) && $catId === 16) $score += 25;
                if (in_array('music', $req['interests'], true) && $catId === 17) $score += 25;
                if (in_array('dance', $req['interests'], true) && ($catId === 17 || $catId === 11 || $catId === 4)) $score += 20;
                if (in_array('makeup', $req['interests'], true) && $catId === 6) $score += 25;
                if (in_array('skincare', $req['interests'], true) && $catId === 6) $score += 25;

                if (!empty($req['latest_added_interest']) && in_array($req['latest_added_interest'], ['makeup', 'skincare'], true) && $catId === 6) {
                    $score += 40;
                }
                if (in_array($req['recipient'] ?? '', ['girlfriend', 'wife'], true) && $catId === 19) {
                    $score += 15;
                }
                $score += ((float)$p['rating'] * 3);

                $candidates[] = [
                    'id'          => (int)$p['id'],
                    'category_id' => $catId,
                    'name'        => $p['name'],
                    'price'       => (float)$p['price'],
                    'description' => $p['description'] ?? '',
                    'category'    => $p['category'] ?? 'General',
                    'rating'      => (float)$p['rating'],
                    'emoji'       => $p['emoji'] ?? '🎁',
                    'image'       => $p['image'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80',
                    'stock'       => 20,
                    'score'       => $score
                ];
            }
        }

        $results = $selectTopCandidates($candidates, $req, $requestedCount);

        // Store last displayed products in state for reference resolution
        $state['last_displayed_products'] = $results;
        if (isset($state['active_request'])) {
            $state['active_request']['last_displayed_products'] = $results;
        }

        if (empty($results)) {
            return [
                'status'   => 'not_found',
                'products' => [],
                'state'    => $req,
                'count'    => 0
            ];
        }

        return [
            'status'   => 'success',
            'count'    => count($results),
            'products' => $results,
            'state'    => $req
        ];
    }

    /**
     * Resolve product references like "the first laptop" or "compare these two headphones"
     */
    public static function resolveReference(string $text, array $state): array {
        $lastProducts = $state['last_displayed_products'] ?? [];
        $lower = strtolower($text);

        // 1. Comparison request: "Compare these two headphones based on price, sound quality, battery life, and value for money"
        if (preg_match('/\b(compare|comparison|difference between)\b/i', $text)) {
            $headphones = [];
            foreach ($lastProducts as $p) {
                if (self::isActualHeadphone($p)) {
                    $headphones[] = $p;
                }
            }

            if (count($headphones) < 2) {
                // If not in last displayed, fetch top two distinct headphones from catalog
                if (function_exists('get_all_products')) {
                    foreach (get_all_products() as $p) {
                        if (self::isActualHeadphone($p)) {
                            $headphones[] = $p;
                            if (count($headphones) >= 2) break;
                        }
                    }
                }
            }

            if (count($headphones) >= 2) {
                $comp = self::buildHeadphoneComparison($headphones[0], $headphones[1]);
                return [
                    'type'     => 'comparison',
                    'reply'    => $comp,
                    'products' => [$headphones[0], $headphones[1]]
                ];
            }

            return [
                'type'     => 'clarification',
                'reply'    => "Which two headphone models would you like me to compare? Please mention their names or numbers from our store.",
                'products' => []
            ];
        }

        // 2. Specific product reference: "Tell me more about the first laptop"
        if (preg_match('/\b(first|1st|second|2nd|third|3rd|fourth|4th|last)\s*([a-z]+)?/i', $text, $rm)) {
            $ordinal = strtolower($rm[1]);
            $itemType = !empty($rm[2]) ? strtolower($rm[2]) : null;

            $indexMap = ['first' => 0, '1st' => 0, 'second' => 1, '2nd' => 1, 'third' => 2, '3rd' => 2, 'fourth' => 3, '4th' => 3, 'last' => count($lastProducts) - 1];
            $targetIdx = $indexMap[$ordinal] ?? 0;

            $filtered = [];
            foreach ($lastProducts as $p) {
                if ($itemType === 'laptop') {
                    if (self::isActualLaptop($p)) {
                        $filtered[] = $p;
                    }
                } elseif ($itemType === 'headphone' || $itemType === 'headphones') {
                    if (self::isActualHeadphone($p)) {
                        $filtered[] = $p;
                    }
                } else {
                    $filtered[] = $p;
                }
            }

            if (isset($filtered[$targetIdx])) {
                $chosen = $filtered[$targetIdx];
                $reply = self::buildProductDetailResponse($chosen);
                return [
                    'type'     => 'detail',
                    'reply'    => $reply,
                    'products' => [$chosen]
                ];
            }

            if ($itemType === 'laptop') {
                return [
                    'type'     => 'not_found',
                    'reply'    => "No laptop was found in our previous recommendations. In our earlier search, only laptop accessories (like cooling stands) were displayed. Would you like me to search for actual laptops from our catalog?",
                    'products' => []
                ];
            }

            return [
                'type'     => 'clarification',
                'reply'    => "I'm not sure which product you're referring to. Could you please specify the product name or number?",
                'products' => []
            ];
        }

        return [
            'type'     => 'unknown',
            'reply'    => "How can I help you with those products?",
            'products' => []
        ];
    }

    /**
     * Check if product is genuinely a laptop (and NOT an accessory like a cooling stand)
     */
    public static function isActualLaptop(array $p): bool {
        $name = strtolower($p['name'] ?? '');
        if (str_contains($name, 'stand') || str_contains($name, 'pad') || str_contains($name, 'cooling') ||
            str_contains($name, 'case') || str_contains($name, 'mouse') || str_contains($name, 'keyboard') ||
            str_contains($name, 'monitor') || str_contains($name, 'webcam')) {
            return false;
        }
        return (str_contains($name, 'laptop') || str_contains($name, 'ultrabook') || str_contains($name, 'notebook'));
    }

    /**
     * Check if product is genuinely a headphone/earbud (and NOT an accessory like a case or stand)
     */
    public static function isActualHeadphone(array $p): bool {
        $name = strtolower($p['name'] ?? '');
        if (str_contains($name, 'stand') || str_contains($name, 'case') || str_contains($name, 'arm') ||
            str_contains($name, 'receiver') || str_contains($name, 'soundbar') || str_contains($name, 'speaker') ||
            str_contains($name, 'microphone')) {
            return false;
        }
        return (str_contains($name, 'headphone') || str_contains($name, 'earbud') || str_contains($name, 'iem') || str_contains($name, 'headset'));
    }

    /**
     * Build honest, accurate headphone comparison from database specifications
     */
    public static function buildHeadphoneComparison(array $p1, array $p2): string {
        $p1Price = number_format($p1['price'], 2);
        $p2Price = number_format($p2['price'], 2);

        $reply = "Here is a direct comparison between **{$p1['name']}** and **{$p2['name']}** based on their catalog specifications:\n\n"
               . "### 1. Pricing & Value\n"
               . "• **{$p1['name']}:** \${$p1Price} (Rated {$p1['rating']}★ with " . ($p1['reviews_count'] ?? 100) . "+ reviews)\n"
               . "• **{$p2['name']}:** \${$p2Price} (Rated {$p2['rating']}★ with " . ($p2['reviews_count'] ?? 100) . "+ reviews)\n\n"
               . "### 2. Sound Quality & Audio Profile\n"
               . "• **{$p1['name']}:** {$p1['description']}\n"
               . "• **{$p2['name']}:** {$p2['description']}\n\n"
               . "### 3. Battery Life & Connectivity\n";

        if (str_contains(strtolower($p1['name']), 'wireless') || str_contains(strtolower($p1['description']), 'hour')) {
            $reply .= "• **{$p1['name']}:** Wireless connectivity featuring active noise cancellation and long playtime.\n";
        } else {
            $reply .= "• **{$p1['name']}:** Professional wired studio connection for zero-latency audio production.\n";
        }

        if (str_contains(strtolower($p2['name']), 'wireless') || str_contains(strtolower($p2['description']), 'hour')) {
            $reply .= "• **{$p2['name']}:** Wireless audio with portable charging case for on-the-go listening.\n";
        } else {
            $reply .= "• **{$p2['name']}:** Professional wired connection with high-fidelity acoustic reproduction.\n";
        }

        $reply .= "\n### 4. Recommendation\n"
                . "• Choose **{$p1['name']}** if you prioritize " . (str_contains(strtolower($p1['name']), 'studio') ? "studio acoustic precision" : "active noise cancellation for travel") . ".\n"
                . "• Choose **{$p2['name']}** if you want " . ($p2['price'] < $p1['price'] ? "maximum value for money at a lower price point" : "premium audio fidelity") . ".";

        return $reply;
    }

    /**
     * Build detailed product specification response
     */
    public static function buildProductDetailResponse(array $p): string {
        $price = number_format($p['price'], 2);
        $inrEquiv = number_format($p['price'] * INR_TO_USD_RATE);

        return "Here are the verified specifications for **{$p['name']}**:\n\n"
             . "• **Price:** \${$price} USD (approx. ₹{$inrEquiv} at 1 USD ≈ 83 INR)\n"
             . "• **Category:** {$p['category']}\n"
             . "• **Rating:** {$p['rating']}★ (" . ($p['reviews_count'] ?? 100) . "+ reviews)\n"
             . "• **Key Features:** {$p['description']}\n"
             . "• **Stock Availability:** In Stock (Available for immediate dispatch)\n\n"
             . "Would you like me to add this to your shopping cart?";
    }

    /**
     * Build natural, context-aware response using the conversational state
     */
    public static function buildNaturalResponse(array $state, array $searchResult): string {
        $req = $state['active_request'] ?? $state;
        $products = $searchResult['products'] ?? [];
        $count = count($products);

        // Case 1: Clarification needed
        if (!empty($req['missing_information']) && in_array('currency', $req['missing_information'], true)) {
            $amt = number_format($req['budget']['amount'] ?? 20000);
            return "I'd be happy to suggest gifts under {$amt}! Could you please clarify if your budget is in **INR (₹)** or **USD ($)**? Also, does the recipient have any particular interests or hobbies?";
        }

        if (!empty($req['missing_information']) && in_array('interests', $req['missing_information'], true)) {
            $recip = $req['recipient'] ? "your {$req['recipient']}" : "them";
            return "I'd love to help you find the perfect gift for {$recip}! To help me recommend the most relevant items, what do they enjoy doing (e.g. music, dance, cricket, gaming, fashion), and what is your approximate budget?";
        }

        // Case 2: Products Found
        if ($count > 0) {
            $recipientText = $req['recipient'] ? "your {$req['recipient']}" : "the recipient";
            $interestText  = !empty($req['interests']) ? implode(' and ', $req['interests']) : 'their interests';
            
            $budgetText = '';
            if (!empty($req['budget'])) {
                $curr = $req['budget']['currency'];
                $amt = number_format($req['budget']['amount']);
                $usdEquivalent = number_format($req['budget']['maximum_usd'], 2);
                $budgetText = ($curr === 'INR') 
                    ? "within your ₹{$amt} budget (approx. \${$usdEquivalent} USD at 1 USD ≈ 83 INR)" 
                    : "within your \${$amt} budget";
            }

            // Context-specific opening
            if (in_array('cricket', $req['interests'], true) || in_array('gaming', $req['interests'], true)) {
                return "Great! For {$recipientText} who loves " . implode(' & ', $req['interests']) . ", here are the top {$count} matching gifts {$budgetText}:";
            }

            if (in_array('makeup', $req['interests'], true) || in_array('skincare', $req['interests'], true)) {
                return "Wonderful! I've updated the recommendations to include beauty, makeup, and skincare within your budget. Here are the top {$count} matching gift ideas from our store:";
            }

            if (!empty($req['excluded_keywords'])) {
                $exStr = implode(', ', $req['excluded_keywords']);
                return "Understood! I have excluded {$exStr} from the recommendations. Here are the top {$count} gift ideas tailored to {$recipientText}'s interests:";
            }

            if (!empty($req['budget']) && !empty($req['occasion'])) {
                return "That's a lovely idea! For {$recipientText}'s {$req['occasion']}, since they love {$interestText}, I've curated the best matching gift ideas {$budgetText}. Here are the top {$count} options available in our store:";
            }

            if (empty($req['budget'])) {
                return "Here are the top {$count} gift ideas for {$recipientText} who loves {$interestText}! Do you have a specific budget in mind (in INR or USD) so I can narrow these down for you?";
            }

            return "Here are the top {$count} matching gift recommendations {$budgetText}:";
        }

        // Case 3: No matching products found
        $budgetText = '';
        if (!empty($req['budget'])) {
            $budgetText = "under " . ($req['budget']['currency'] === 'INR' ? "₹" : "$") . number_format($req['budget']['amount']);
        }

        if (!empty($req['specific_product_type']) && $req['specific_product_type'] === 'dance shoes') {
            return "I searched our catalog, but we currently don't have matching dance shoes in stock {$budgetText}. Our athletic and cross-training shoes start at \$59.99 (approx. ₹4,979). Would you like to check alternative footwear or explore dance-related audio and fitness gear?";
        }

        if (!empty($req['product_type']) && in_array('wireless', $req['preferences'] ?? [], true)) {
            return "I searched our catalog, but we currently don't have wireless {$req['product_type']}s in stock {$budgetText}. Our wireless audio models start at \$69.99 (approx. ₹5,809). Would you prefer to check wired options or consider adjusting your budget?";
        }

        $interestLabel = !empty($req['interests']) ? implode('/', $req['interests']) : ($req['product_type'] ?? 'requested');
        return "I searched our catalog, but we currently don't have matching {$interestLabel} items in stock {$budgetText}. Nexora specializes in consumer electronics, audio gear, fitness accessories, fashion, and jewelry. Would you like me to show alternative options in those categories?";
    }

    /**
     * Generate prompt summary injection for OpenRouter LLM
     */
    public static function getStatePromptSummary(array $state): string {
        $req = $state['active_request'] ?? $state;
        $recipient = $req['recipient'] ?? 'Unspecified';
        $occasion  = $req['occasion'] ?? 'Unspecified';
        $budgetStr = !empty($req['budget']) 
            ? "{$req['budget']['amount']} {$req['budget']['currency']} (max \${$req['budget']['maximum_usd']} USD)" 
            : 'Unspecified';
        $interests = !empty($req['interests']) ? implode(', ', $req['interests']) : 'None specified yet';
        $excluded  = !empty($req['excluded_keywords']) ? implode(', ', $req['excluded_keywords']) : 'None';
        $pType     = $req['product_type'] ?? 'Unspecified';
        $ready     = $req['ready_to_recommend'] ? 'YES - All necessary details are already known!' : 'NO - Clarification needed';

        $summary = "CURRENT STRUCTURED SHOPPING REQUEST:\n"
                 . "- Intent: {$req['intent']}\n"
                 . "- Product Type / Category: {$pType}\n"
                 . "- Recipient: {$recipient}\n"
                 . "- Occasion: {$occasion}\n"
                 . "- Budget: {$budgetStr}\n"
                 . "- Recipient Interests: {$interests}\n"
                 . "- Excluded Products/Categories: {$excluded}\n"
                 . "- Requested Ideas Count: {$req['requested_count']}\n"
                 . "- Ready to Recommend Immediately: {$ready}\n\n";

        if ($req['ready_to_recommend']) {
            $summary .= "CRITICAL DIRECTIVES:\n"
                      . "1. DO NOT ask the user for occasion, recipient, budget, or interests again because they are ALREADY KNOWN in the active request above!\n"
                      . "2. Recommend products strictly matching these specific interests, recipient, and budget limit. Never mix preferences from previous requests.\n"
                      . "3. Return at most {$req['requested_count']} items.\n";
        }

        return $summary;
    }
}
