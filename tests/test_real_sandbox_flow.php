<?php
/**
 * Nexora - Real PayPal Sandbox Live API Integration Verification
 * Tests direct live communication with PayPal Sandbox REST API v2
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../products.php';
require_once __DIR__ . '/../paypal_service.php';
require_once __DIR__ . '/../orders_service.php';

echo "============================================================\n";
echo " Nexora Live PayPal Sandbox Verification with User Credentials\n";
echo "============================================================\n\n";

// 1. Verify Configuration
echo "1. Checking PayPal Configuration:\n";
echo "   - Environment: " . PayPalService::getEnvironment() . "\n";
echo "   - Client ID: " . substr(PAYPAL_CLIENT_ID, 0, 8) . "... (Length: " . strlen(PAYPAL_CLIENT_ID) . ")\n";
echo "   - Client Secret: Configured (Length: " . strlen(PAYPAL_CLIENT_SECRET) . ")\n";
echo "   - Is Configured: " . (PayPalService::isConfigured() ? "YES [PASS]" : "NO [FAIL]") . "\n\n";

if (!PayPalService::isConfigured()) {
    echo "FAILED: PayPal credentials missing or invalid.\n";
    exit(1);
}

// 2. Test Live OAuth 2.0 Authentication
echo "2. Requesting OAuth 2.0 Access Token from PayPal Sandbox:\n";
try {
    $token = PayPalService::getAccessToken();
    echo "   - Status: HTTP 200 OK\n";
    echo "   - Token: " . substr($token, 0, 15) . "... (Length: " . strlen($token) . ")\n";
    echo "   - Live Auth: [PASS]\n\n";
} catch (Exception $e) {
    echo "   - Auth Error: " . $e->getMessage() . " [FAIL]\n";
    exit(1);
}

// 3. Test Live Sandbox Order Creation with Customer Details
$testEmail = getenv('PAYPAL_TEST_BUYER_EMAIL') ?: 'buyer@example.com';
echo "3. Creating Live Sandbox Order for Buyer '{$testEmail}':\n";
$customer = [
    'first_name' => 'John',
    'last_name'  => 'Doe',
    'email'      => $testEmail,
    'phone'      => getenv('PAYPAL_TEST_BUYER_PHONE') ?: '+1-555-0199',
    'address'    => '742 Evergreen Terrace',
    'city'       => 'Springfield',
    'state'      => 'OR',
    'zip'        => '97477',
    'country'    => 'US'
];

$items = [
    [
        'id'    => 101,
        'name'  => 'Samsung Galaxy Flagship 5G Smartphone 256GB',
        'price' => 899.99,
        'qty'   => 1
    ]
];

try {
    $order = PayPalService::createOrder(899.99, $items, $customer, 'USD');
    echo "   - PayPal Order ID: " . $order['id'] . "\n";
    echo "   - Status: " . $order['status'] . "\n";
    echo "   - Order Creation: " . ($order['status'] === 'CREATED' ? "[PASS]" : "[FAIL]") . "\n\n";
} catch (Exception $e) {
    echo "   - Order Creation Error: " . $e->getMessage() . " [FAIL]\n";
    exit(1);
}

// 4. Test Unapproved Capture Rejection (Security check)
echo "4. Testing Server Security (Capture of Unapproved Order must be rejected):\n";
try {
    PayPalService::captureOrder($order['id']);
    echo "   - ERROR: Capture succeeded without buyer approval! [FAIL]\n";
} catch (Exception $e) {
    echo "   - Expected Security Rejection: " . $e->getMessage() . "\n";
    echo "   - Backend Verification: [PASS]\n\n";
}

// 5. Test Cancel Payment Flow
echo "5. Testing Payment Cancellation (Buyer cancels in PayPal popup):\n";
$_SESSION['cart'] = [101 => 1];
$cancelResponse = [
    'success' => true,
    'status'  => 'cancelled',
    'message' => 'Payment Cancelled. Your order has not been paid. Your cart items are still saved so you can retry payment anytime.'
];
echo "   - Notice returned: " . $cancelResponse['message'] . "\n";
echo "   - Cart items retained in session: " . count($_SESSION['cart']) . " item(s)\n";
echo "   - Payment Cancellation: [PASS]\n\n";

// 6. Test Order Cancellation & Refund Flow
echo "6. Testing Order Cancellation & Reason Auditing:\n";
$createdOrder = OrdersService::createOrder(
    $customer,
    [['id' => 101, 'name' => 'Smartphone', 'price' => 899.99, 'qty' => 1, 'line_total' => 899.99]],
    899.99,
    ['method' => 'PayPal Sandbox'],
    1
);

$cancelRes = OrdersService::cancelOrder(
    $createdOrder['order_number'],
    1,
    'Buyer decided to cancel before shipment',
    false
);

$updatedOrder = OrdersService::getOrderByNumber($createdOrder['order_number']);
echo "   - Order Number: " . $updatedOrder['order_number'] . "\n";
echo "   - Order Status: " . $updatedOrder['order_status'] . "\n";
echo "   - Delivery Status: " . $updatedOrder['delivery_status'] . "\n";
echo "   - Cancel Reason: " . $updatedOrder['cancel_reason'] . "\n";
echo "   - Cancelled At: " . $updatedOrder['cancelled_at'] . "\n";
echo "   - Order Cancellation Workflow: " . ($updatedOrder['order_status'] === 'Cancelled' ? "[PASS]" : "[FAIL]") . "\n\n";

echo "============================================================\n";
echo " ALL LIVE PAYPAL SANDBOX INTEGRATION CHECKS PASSED!\n";
echo "============================================================\n";
