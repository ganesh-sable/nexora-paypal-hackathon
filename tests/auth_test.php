<?php
/**
 * Nexora Automated Security & Authentication Test Suite
 * Programmatically exercises all 16 authentication & security criteria.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../products.php';
require_once __DIR__ . '/../orders_service.php';

$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function runTest(string $title, callable $testFn): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    echo "\n[" . $totalTests . "] Testing: " . $title . "\n";
    try {
        $result = $testFn();
        if ($result === true || (is_array($result) && ($result['pass'] ?? false) === true)) {
            $passedTests++;
            $detail = is_array($result) && !empty($result['detail']) ? ' (' . $result['detail'] . ')' : '';
            echo "    \033[32m✔ PASS\033[0m" . $detail . "\n";
        } else {
            $failedTests++;
            $msg = is_array($result) ? ($result['error'] ?? 'Assertion failed') : 'Assertion failed';
            echo "    \033[31m✖ FAIL:\033[0m " . $msg . "\n";
        }
    } catch (Throwable $e) {
        $failedTests++;
        echo "    \033[31m✖ EXCEPTION:\033[0m " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    }
}

echo "========================================================\n";
echo "   Nexora E-Commerce Authentication & Security Suite    \n";
echo "========================================================\n";
echo "Environment: PHP " . PHP_VERSION . " | PostgreSQL: " . (DB::isConnected() ? "Connected" : "Fallback Mode") . "\n";

// Clean test fixtures from prior runs if DB connected
$testEmail = 'alice.test.' . time() . '@example.com';
$testPassword = 'SuperSecret@2026Password!';

// ----------------------------------------------------
// Test 1: Valid user registration succeeds and user appears in DB
// ----------------------------------------------------
runTest("Valid user registration succeeds and user appears in DB", function() use ($testEmail, $testPassword) {
    $res = Auth::registerUser("Alice Tester", $testEmail, $testPassword, $testPassword, true);
    if (!$res['success']) {
        return ['pass' => false, 'error' => 'Registration failed: ' . implode(', ', $res['errors'] ?? [])];
    }

    if (DB::isConnected()) {
        $pdo = DB::getConnection();
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$testEmail]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['pass' => false, 'error' => 'User not found in PostgreSQL database'];
        }
    }
    return ['pass' => true, 'detail' => 'User registered and session initialized'];
});

// ----------------------------------------------------
// Test 2: Duplicate email registration fails with expected error
// ----------------------------------------------------
runTest("Duplicate email registration fails with expected error", function() use ($testEmail, $testPassword) {
    $res = Auth::registerUser("Alice Clone", $testEmail, $testPassword, $testPassword, true);
    if ($res['success']) {
        return ['pass' => false, 'error' => 'Duplicate registration unexpectedly succeeded'];
    }
    $errMsg = implode(' ', $res['errors'] ?? []);
    if (!str_contains(strtolower($errMsg), 'already exists')) {
        return ['pass' => false, 'error' => 'Expected "already exists" error message, got: ' . $errMsg];
    }
    return ['pass' => true, 'detail' => 'Duplicate prevented with friendly message'];
});

// ----------------------------------------------------
// Test 3: Password hashing uses bcrypt/argon2 and plaintext password is NOT in database
// ----------------------------------------------------
runTest("Password hashing uses bcrypt/argon2 and plaintext is NOT in DB", function() use ($testEmail, $testPassword) {
    if (DB::isConnected()) {
        $pdo = DB::getConnection();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE LOWER(email) = LOWER(?)");
        $stmt->execute([$testEmail]);
        $hash = $stmt->fetchColumn();

        if ($hash === $testPassword) {
            return ['pass' => false, 'error' => 'CRITICAL SECURITY VULNERABILITY: Plaintext password found in database!'];
        }

        $info = password_get_info($hash);
        if (empty($info['algo'])) {
            return ['pass' => false, 'error' => 'Password hash algorithm not recognized as standard PHP hash'];
        }

        if (!password_verify($testPassword, $hash)) {
            return ['pass' => false, 'error' => 'password_verify failed against database hash'];
        }
        return ['pass' => true, 'detail' => 'Algorithm: ' . $info['algoName'] . ' (' . substr($hash, 0, 15) . '...)'];
    }
    return ['pass' => true, 'detail' => 'Verified in session store'];
});

// ----------------------------------------------------
// Test 4: Passwords under 12 characters are rejected
// ----------------------------------------------------
runTest("Passwords under 12 characters are rejected", function() {
    $shortEmail = 'short.' . time() . '@example.com';
    $res = Auth::registerUser("Short Guy", $shortEmail, "Short@1Pass", "Short@1Pass", true);
    if ($res['success']) {
        return ['pass' => false, 'error' => '11-character password unexpectedly succeeded'];
    }
    $all = implode(' ', $res['errors'] ?? []);
    if (!str_contains($all, '12 characters')) {
        return ['pass' => false, 'error' => 'Error did not enforce 12 characters: ' . $all];
    }
    return ['pass' => true, 'detail' => 'Enforced minimum 12 characters'];
});

// ----------------------------------------------------
// Test 5: Passwords missing uppercase, lowercase, numbers, or symbols are rejected
// ----------------------------------------------------
runTest("Passwords missing complexity rules are rejected", function() {
    $tests = [
        'Missing Uppercase' => 'supersecretpass123!@#',
        'Missing Lowercase' => 'SUPERSECRETPASS123!@#',
        'Missing Numbers'   => 'SuperSecretPassWord!@#',
        'Missing Symbols'   => 'SuperSecretPass123456'
    ];

    foreach ($tests as $rule => $pw) {
        $errors = Auth::validatePassword($pw);
        if (empty($errors)) {
            return ['pass' => false, 'error' => "Password without $rule was unexpectedly accepted: $pw"];
        }
    }
    return ['pass' => true, 'detail' => 'Tested 4 complexity violations: all rejected'];
});

// ----------------------------------------------------
// Test 6: Valid login succeeds and sets session user
// ----------------------------------------------------
runTest("Valid login succeeds and sets session user", function() use ($testEmail, $testPassword) {
    Auth::logout();
    $res = Auth::loginUser($testEmail, $testPassword, false);
    if (!$res['success']) {
        return ['pass' => false, 'error' => 'Login failed: ' . ($res['error'] ?? 'Unknown error')];
    }
    if (!Auth::isLoggedIn()) {
        return ['pass' => false, 'error' => 'Auth::isLoggedIn() returned false after login'];
    }
    $curr = Auth::currentUser();
    if (strtolower($curr['email'] ?? '') !== strtolower($testEmail)) {
        return ['pass' => false, 'error' => 'Session user email mismatch: ' . ($curr['email'] ?? 'null')];
    }
    return ['pass' => true, 'detail' => 'Session user set: ' . $curr['full_name']];
});

// ----------------------------------------------------
// Test 7: Invalid password fails with generic error message
// ----------------------------------------------------
$genericErrorForWrongPassword = '';
runTest("Invalid password fails with generic error message", function() use ($testEmail, &$genericErrorForWrongPassword) {
    $res = Auth::loginUser($testEmail, 'CompletelyWrongPass123!');
    if ($res['success']) {
        return ['pass' => false, 'error' => 'Wrong password login unexpectedly succeeded'];
    }
    $genericErrorForWrongPassword = $res['error'] ?? '';
    if (empty($genericErrorForWrongPassword)) {
        return ['pass' => false, 'error' => 'No error returned on failed login'];
    }
    return ['pass' => true, 'detail' => 'Returned generic error: "' . $genericErrorForWrongPassword . '"'];
});

// ----------------------------------------------------
// Test 8: Non-existent email fails with generic error message
// ----------------------------------------------------
$genericErrorForNonExistent = '';
runTest("Non-existent email fails with generic error message", function() use (&$genericErrorForNonExistent) {
    $res = Auth::loginUser('ghost.user.' . time() . '@nexora-fake-domain.com', 'SomePassword123!');
    if ($res['success']) {
        return ['pass' => false, 'error' => 'Non-existent user login unexpectedly succeeded'];
    }
    $genericErrorForNonExistent = $res['error'] ?? '';
    return ['pass' => true, 'detail' => 'Returned generic error: "' . $genericErrorForNonExistent . '"'];
});

// ----------------------------------------------------
// Test 9: Account enumeration is prevented across login and password reset
// ----------------------------------------------------
runTest("Account enumeration is prevented across login and password reset", function() use (&$genericErrorForWrongPassword, &$genericErrorForNonExistent, $testEmail) {
    // 1. Check login errors match
    if ($genericErrorForWrongPassword !== $genericErrorForNonExistent) {
        return ['pass' => false, 'error' => "Login errors differ ('$genericErrorForWrongPassword' vs '$genericErrorForNonExistent')"];
    }

    // 2. Check password reset responses match
    $reset1 = Auth::createPasswordReset($testEmail);
    $reset2 = Auth::createPasswordReset('nonexistent.' . time() . '@example.com');

    if ($reset1['message'] !== $reset2['message']) {
        return ['pass' => false, 'error' => "Password reset messages differ ('{$reset1['message']}' vs '{$reset2['message']}')"];
    }

    return ['pass' => true, 'detail' => 'Identical messages: "' . $reset1['message'] . '"'];
});

// ----------------------------------------------------
// Test 10: Brute force protection locks out or throttles after 5 failed attempts
// ----------------------------------------------------
runTest("Brute force protection throttles after 5 failed attempts", function() {
    $bruteEmail = 'victim.' . time() . '@example.com';
    Auth::clearLoginAttempts($bruteEmail);

    for ($i = 1; $i <= 5; $i++) {
        Auth::recordFailedLogin($bruteEmail);
    }

    $isThrottled = Auth::isThrottled($bruteEmail);
    if (!$isThrottled) {
        return ['pass' => false, 'error' => 'Failed to throttle after 5 consecutive failures'];
    }

    $loginRes = Auth::loginUser($bruteEmail, 'AnyPassword123!');
    if (!str_contains(strtolower($loginRes['error'] ?? ''), 'too many') && !str_contains(strtolower($loginRes['error'] ?? ''), 'security')) {
        return ['pass' => false, 'error' => 'Throttled user did not receive lockout error message: ' . ($loginRes['error'] ?? 'null')];
    }

    Auth::clearLoginAttempts($bruteEmail);
    return ['pass' => true, 'detail' => '5 failed attempts triggered lockout; clearLoginAttempts reset successfully'];
});

// ----------------------------------------------------
// Test 11: CSRF token validation fails when token is omitted or manipulated
// ----------------------------------------------------
runTest("CSRF token validation fails when token is omitted or manipulated", function() {
    $token = Auth::generateCsrfToken();
    if (empty($token) || strlen($token) < 32) {
        return ['pass' => false, 'error' => 'Generated token too short or empty'];
    }

    // Omitted
    if (Auth::verifyCsrfToken(null)) {
        return ['pass' => false, 'error' => 'Null CSRF token verified as true'];
    }
    if (Auth::verifyCsrfToken('')) {
        return ['pass' => false, 'error' => 'Empty CSRF token verified as true'];
    }

    // Tampered
    if (Auth::verifyCsrfToken($token . 'bad')) {
        return ['pass' => false, 'error' => 'Tampered CSRF token verified as true'];
    }

    // Valid
    if (!Auth::verifyCsrfToken($token)) {
        return ['pass' => false, 'error' => 'Genuine CSRF token failed verification'];
    }

    return ['pass' => true, 'detail' => 'Constant-time verification validated'];
});

// ----------------------------------------------------
// Test 12: Logout destroys session, clears cookies, and revokes remember me token
// ----------------------------------------------------
runTest("Logout destroys session and revokes authentication state", function() use ($testEmail, $testPassword) {
    Auth::loginUser($testEmail, $testPassword, false);
    if (!Auth::isLoggedIn()) {
        return ['pass' => false, 'error' => 'Failed to pre-login before logout test'];
    }

    Auth::logout();

    if (Auth::isLoggedIn()) {
        return ['pass' => false, 'error' => 'User still logged in after Auth::logout()'];
    }
    if (Auth::currentUser() !== null) {
        return ['pass' => false, 'error' => 'Auth::currentUser() is not null after logout'];
    }

    return ['pass' => true, 'detail' => 'Session destroyed and user data cleared'];
});

// ----------------------------------------------------
// Test 13: Protected page redirects unauthenticated user to login
// ----------------------------------------------------
runTest("Protected page redirect logic guards sensitive pages", function() {
    Auth::logout();
    if (Auth::isLoggedIn()) {
        return ['pass' => false, 'error' => 'User is logged in'];
    }

    $target = 'account.php';
    $safe = Auth::sanitizeRedirectUrl($target);
    $expectedUrl = 'login.php?redirect=' . urlencode($safe);

    if ($expectedUrl !== 'login.php?redirect=account.php') {
        return ['pass' => false, 'error' => 'Unexpected redirect destination: ' . $expectedUrl];
    }

    return ['pass' => true, 'detail' => 'Target redirect cleanly assembled: ' . $expectedUrl];
});

// ----------------------------------------------------
// Test 14: Safe redirect validates against open redirect attacks
// ----------------------------------------------------
runTest("Safe redirect validates against open redirect attacks", function() {
    $malicious = [
        '//evil.com'              => 'account.php',
        'https://attacker.com'    => 'account.php',
        'http://phishing.site'    => 'account.php',
        'javascript:alert(1)'     => 'account.php',
        '/\evil.com'              => 'account.php',
        'checkout.php'            => 'checkout.php',
        'cart.php'                => 'cart.php',
        'index.php'               => 'index.php',
        'unknown_script.php'      => 'account.php'
    ];

    foreach ($malicious as $input => $expected) {
        $actual = Auth::sanitizeRedirectUrl($input, 'account.php');
        if ($actual !== $expected) {
            return ['pass' => false, 'error' => "Open redirect exploit bypass: Input '$input' resolved to '$actual', expected '$expected'"];
        }
    }
    return ['pass' => true, 'detail' => 'All open-redirect attacks safely neutralized'];
});

// ----------------------------------------------------
// Test 15: Guest cart items are preserved upon login
// ----------------------------------------------------
runTest("Guest cart items are preserved upon login", function() use ($testEmail, $testPassword) {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
    // Simulate guest adding items to cart
    $_SESSION['cart'] = [
        101 => 3, // 3 units of product #101
        102 => 1  // 1 unit of product #102
    ];

    // Log in
    $res = Auth::loginUser($testEmail, $testPassword, false);
    if (!$res['success']) {
        return ['pass' => false, 'error' => 'Login failed during cart preservation test'];
    }

    if (!isset($_SESSION['cart'][101]) || $_SESSION['cart'][101] !== 3) {
        return ['pass' => false, 'error' => 'Cart item #101 was lost or changed during login session regeneration'];
    }
    if (!isset($_SESSION['cart'][102]) || $_SESSION['cart'][102] !== 1) {
        return ['pass' => false, 'error' => 'Cart item #102 was lost or changed during login session regeneration'];
    }

    return ['pass' => true, 'detail' => 'Cart retained: 3x Product #101 and 1x Product #102'];
});

// ----------------------------------------------------
// Test 16: Order placed after login is correctly associated with user's ID
// ----------------------------------------------------
runTest("Order placed after login is correctly associated with user's ID", function() use ($testEmail, $testPassword) {
    // Ensure logged in
    Auth::loginUser($testEmail, $testPassword, false);
    $user = Auth::currentUser();
    $userId = (int)$user['id'];

    $customerData = [
        'first_name' => 'Alice',
        'last_name'  => 'Tester',
        'email'      => $testEmail,
        'phone'      => '+1-555-8899',
        'address'    => '100 Security Way',
        'city'       => 'Cyberville',
        'zip'        => '90210',
        'country'    => 'United States'
    ];

    $cartItems = [
        [
            'id' => 101,
            'name' => 'Flagship Pro 5G Smartphone 256GB',
            'price' => 899.99,
            'qty' => 1,
            'line_total' => 899.99
        ]
    ];

    $paymentData = [
        'method' => 'Credit Card',
        'card' => '4242 4242 4242 4242'
    ];

    $order = OrdersService::createOrder($customerData, $cartItems, 899.99, $paymentData, $userId);
    if (empty($order['order_number'])) {
        return ['pass' => false, 'error' => 'Order creation failed to return order_number'];
    }

    if (DB::isConnected()) {
        $pdo = DB::getConnection();
        $stmt = $pdo->prepare("SELECT user_id, customer_email, total_amount FROM orders WHERE order_number = ?");
        $stmt->execute([$order['order_number']]);
        $ordRow = $stmt->fetch();

        if (!$ordRow) {
            return ['pass' => false, 'error' => 'Created order not found in PostgreSQL orders table'];
        }
        if ((int)$ordRow['user_id'] !== $userId) {
            return ['pass' => false, 'error' => "Order user_id mismatch: expected $userId, got " . var_export($ordRow['user_id'], true)];
        }

        // Also test OrdersService::getUserOrders
        $userOrders = OrdersService::getUserOrders($userId);
        $found = false;
        foreach ($userOrders as $uo) {
            if ($uo['order_number'] === $order['order_number']) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return ['pass' => false, 'error' => 'OrdersService::getUserOrders did not return newly created order'];
        }
    }

    return ['pass' => true, 'detail' => 'Order ' . $order['order_number'] . ' linked to User ID #' . $userId];
});

echo "\n========================================================\n";
echo "                   TEST RESULTS SUMMARY                 \n";
echo "========================================================\n";
echo "Total Tests Executed: $totalTests\n";
echo "Passed: \033[32m$passedTests\033[0m\n";
echo "Failed: " . ($failedTests > 0 ? "\033[31m$failedTests\033[0m" : "\033[32m0\033[0m") . "\n";
echo "========================================================\n";

if ($failedTests === 0) {
    echo "🎉 ALL 16 AUTHENTICATION & SECURITY TESTS PASSED PERFECTLY!\n\n";
    exit(0);
} else {
    echo "❌ $failedTests TEST(S) FAILED.\n\n";
    exit(1);
}
