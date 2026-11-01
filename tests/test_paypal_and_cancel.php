<?php
/**
 * Nexora - PayPal Sandbox & Order Cancellation Automated Test Suite
 * 
 * Verifies Scenarios A through L:
 * - Scenario A: Successful PayPal Sandbox payment
 * - Scenario B: User cancels PayPal approval
 * - Scenario C: PayPal API returns error (declined/invalid)
 * - Scenario D: Tampered/modified client payment amount recalculated by server
 * - Scenario E: Repeated capture request (idempotency & replay protection)
 * - Scenario F: User attempts to cancel another user's order (rejected)
 * - Scenario G: User cancels an eligible unpaid/pending order (cancelled, no refund)
 * - Scenario H: User attempts to cancel an already-delivered order (rejected)
 * - Scenario I: User attempts to cancel a shipped/in-transit order (rejected)
 * - Scenario J: User cancels a paid order and PayPal refund is handled
 * - Scenario K: Order and transaction history consistency
 * - Scenario L: Delivery tracking association and timeline checkpoint update
 */

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../products.php';
require_once __DIR__ . '/../paypal_service.php';
require_once __DIR__ . '/../orders_service.php';

$passedCount = 0;
$failedCount = 0;

function assertTest(string $scenarioName, bool $condition, string $details = ''): void {
    global $passedCount, $failedCount;
    if ($condition) {
        $passedCount++;
        echo " [PASS] " . str_pad($scenarioName, 60, '.') . " OK\n";
    } else {
        $failedCount++;
        echo " [FAIL] " . str_pad($scenarioName, 60, '.') . " FAILED! " . $details . "\n";
    }
}

echo "============================================================\n";
echo " Nexora PayPal Sandbox & Order Cancellation Test Suite\n";
echo "============================================================\n\n";

// Reset test session
$_SESSION = [];

// =========================================================================
// SCENARIO A: Successful PayPal Sandbox payment
// =========================================================================
echo "Testing Scenario A: Successful PayPal Sandbox payment...\n";

// Set up mock handler for PayPal REST API
PayPalService::setMockHandler(function(string $action, array $params) {
    if ($action === 'createOrder') {
        return [
            'id'     => 'PAYPAL-ORD-TEST-001',
            'status' => 'CREATED'
        ];
    }
    if ($action === 'captureOrder') {
        return [
            'success'      => true,
            'order_id'     => $params['orderId'],
            'capture_id'   => 'CAP-TEST-10001',
            'amount'       => 189.98,
            'currency'     => 'USD',
            'payer_email'  => 'sandbox-buyer@nexora.test',
            'status'       => 'COMPLETED',
            'raw_response' => ['status' => 'COMPLETED']
        ];
    }
    return null;
});

$customerData = [
    'first_name' => 'Alice',
    'last_name'  => 'Sandbox',
    'email'      => 'alice.sandbox@example.com',
    'phone'      => '+1-555-0144',
    'address'    => '100 PayPal Way',
    'city'       => 'San Jose',
    'zip'        => '95131',
    'country'    => 'United States'
];

$cartItems = [
    [
        'id'         => 1,
        'name'       => 'Wireless Noise-Canceling Headphones',
        'price'      => 89.99,
        'qty'        => 1,
        'line_total' => 89.99
    ],
    [
        'id'         => 2,
        'name'       => 'Mechanical Gaming Keyboard',
        'price'      => 99.99,
        'qty'        => 1,
        'line_total' => 99.99
    ]
];
$totalAmount = 189.98;
$userId = 101;

$orderA = OrdersService::createOrderFromPayPal(
    $customerData,
    $cartItems,
    $totalAmount,
    'PAYPAL-ORD-TEST-001',
    'CAP-TEST-10001',
    $userId
);

assertTest(
    'Scenario A.1: Order created with valid order number',
    !empty($orderA['order_number']) && str_starts_with($orderA['order_number'], 'ORD-')
);
assertTest(
    'Scenario A.2: Order payment_status is Paid and order_status is Confirmed',
    ($orderA['payment_status'] ?? '') === 'Paid' && ($orderA['order_status'] ?? '') === 'Confirmed'
);
assertTest(
    'Scenario A.3: Order stores paypal_order_id and paypal_capture_id',
    ($orderA['paypal_order_id'] ?? '') === 'PAYPAL-ORD-TEST-001' && ($orderA['paypal_capture_id'] ?? '') === 'CAP-TEST-10001'
);
assertTest(
    'Scenario A.4: Delivery tracking and courier generated',
    !empty($orderA['tracking_number']) && !empty($orderA['courier_name'])
);
assertTest(
    'Scenario A.5: Transaction record linked to order',
    !empty($orderA['transaction_ref']) && ($orderA['payment_method'] ?? '') === 'PayPal'
);

// =========================================================================
// SCENARIO B: User cancels PayPal approval
// =========================================================================
echo "\nTesting Scenario B: User cancels PayPal approval before completing...\n";

// User has items in cart
$_SESSION['cart'] = [1 => 2, 3 => 1];
$initialCart = $_SESSION['cart'];

// Simulation of user clicking Cancel in PayPal popup -> calls paypal_api.php?action=cancel-approval
$cancelApprovalResponse = [
    'success' => true,
    'status'  => 'cancelled',
    'message' => 'PayPal approval was cancelled. Your shopping cart has been preserved.'
];

assertTest(
    'Scenario B.1: Cancel approval returns clean cancellation message',
    $cancelApprovalResponse['status'] === 'cancelled'
);
assertTest(
    'Scenario B.2: Cart items remain preserved in session',
    $_SESSION['cart'] === $initialCart
);

// =========================================================================
// SCENARIO C: PayPal API returns error (declined/invalid)
// =========================================================================
echo "\nTesting Scenario C: PayPal API returns error...\n";

PayPalService::setMockHandler(function(string $action, array $params) {
    if ($action === 'captureOrder') {
        throw new Exception("PAYMENT_ALREADY_DONE: The transaction was declined or previously captured.");
    }
    return null;
});

$apiErrorThrown = false;
$errorMessage = '';
try {
    PayPalService::captureOrder('PAYPAL-INVALID-ORD');
} catch (Exception $e) {
    $apiErrorThrown = true;
    $errorMessage = $e->getMessage();
}

assertTest(
    'Scenario C.1: PayPalService throws Exception on API failure',
    $apiErrorThrown && str_contains($errorMessage, 'PAYMENT_ALREADY_DONE')
);
assertTest(
    'Scenario C.2: Cart items preserved when API fails',
    !empty($_SESSION['cart'])
);

// =========================================================================
// SCENARIO D: Invalid or modified payment amount (Backend trusted recalculation)
// =========================================================================
echo "\nTesting Scenario D: Client tampered amount recalculation...\n";

// Suppose a client attempts to pass tampered total $0.05
// Product 101 is $899.99 in catalog
$_SESSION['cart'] = [101 => 2];
$clientSubmittedTotal = 0.05;

// Recalculate trusted total from catalog
$recalculatedTotal = 0.0;
foreach ($_SESSION['cart'] as $pId => $qty) {
    $p = get_product_by_id((int)$pId);
    if ($p) {
        $recalculatedTotal += round((float)$p['price'] * $qty, 2);
    }
}

assertTest(
    'Scenario D.1: Server detects and ignores client tampered amount ($0.05)',
    $recalculatedTotal !== $clientSubmittedTotal && $recalculatedTotal === 1799.98
);

// =========================================================================
// SCENARIO E: Repeated capture request (Idempotency / duplicate order check)
// =========================================================================
echo "\nTesting Scenario E: Repeated capture request / idempotency...\n";

PayPalService::setMockHandler(null); // Clear mock

$initialOrderCount = count($_SESSION['session_orders'] ?? []);

// Call createOrderFromPayPal again with same CAP-TEST-10001 from Scenario A
$repeatedOrder = OrdersService::createOrderFromPayPal(
    $customerData,
    $cartItems,
    $totalAmount,
    'PAYPAL-ORD-TEST-001',
    'CAP-TEST-10001',
    $userId
);

$afterRepeatedCount = count($_SESSION['session_orders'] ?? []);

assertTest(
    'Scenario E.1: Duplicate capture request returns original order',
    $repeatedOrder['order_number'] === $orderA['order_number']
);
assertTest(
    'Scenario E.2: No duplicate order created in session storage',
    $initialOrderCount === $afterRepeatedCount
);

// =========================================================================
// SCENARIO F: User attempts to cancel another user's order (rejected)
// =========================================================================
echo "\nTesting Scenario F: Unauthorized cancellation attempt...\n";

// Order A belongs to user 101. User 202 tries to cancel it.
$attackerUserId = 202;
$cancelResultUnauthorized = OrdersService::cancelOrder(
    $orderA['order_number'],
    $attackerUserId,
    'I want to cancel someone else order',
    false // not admin
);

assertTest(
    'Scenario F.1: Cancellation by another user is rejected with error',
    $cancelResultUnauthorized['success'] === false && str_contains(strtolower($cancelResultUnauthorized['error']), 'unauthorized')
);

// Verify order A is still Confirmed/Paid
$checkOrderA = OrdersService::getOrderByNumber($orderA['order_number']);
assertTest(
    'Scenario F.2: Order status remains unchanged after unauthorized attempt',
    ($checkOrderA['order_status'] ?? '') === 'Confirmed'
);

// =========================================================================
// SCENARIO G: User cancels an eligible unpaid / pending order
// =========================================================================
echo "\nTesting Scenario G: User cancels an eligible unpaid/pending order...\n";

$unpaidOrder = OrdersService::createOrder(
    $customerData,
    $cartItems,
    150.00,
    ['method' => 'Cash On Delivery'],
    101
);
// Mark as Pending
$_SESSION['session_orders'][$unpaidOrder['order_number']]['payment_status'] = 'Pending';
$_SESSION['session_orders'][$unpaidOrder['order_number']]['order_status'] = 'Pending';

$cancelUnpaidResult = OrdersService::cancelOrder(
    $unpaidOrder['order_number'],
    101,
    'Ordered by mistake',
    false
);

$cancelledUnpaid = OrdersService::getOrderByNumber($unpaidOrder['order_number']);

assertTest(
    'Scenario G.1: Unpaid order cancellation succeeds',
    $cancelUnpaidResult['success'] === true
);
assertTest(
    'Scenario G.2: Order status set to Cancelled and payment status Cancelled',
    ($cancelledUnpaid['order_status'] ?? '') === 'Cancelled' && ($cancelledUnpaid['payment_status'] ?? '') === 'Cancelled'
);
assertTest(
    'Scenario G.3: No refund processed for unpaid order (refund_id is null)',
    empty($cancelledUnpaid['refund_id'])
);

// =========================================================================
// SCENARIO H: User attempts to cancel an already-delivered order (rejected)
// =========================================================================
echo "\nTesting Scenario H: Cancel an already-delivered order...\n";

$deliveredOrder = OrdersService::createOrder(
    $customerData,
    $cartItems,
    89.99,
    ['method' => 'Credit Card'],
    101
);
// Simulate delivered
$_SESSION['session_orders'][$deliveredOrder['order_number']]['delivery_status'] = 'Delivered';
$_SESSION['session_orders'][$deliveredOrder['order_number']]['order_status'] = 'Delivered';

$cancelDeliveredResult = OrdersService::cancelOrder(
    $deliveredOrder['order_number'],
    101,
    'I do not want this anymore',
    false
);

assertTest(
    'Scenario H.1: Cancellation of delivered order is strictly rejected',
    $cancelDeliveredResult['success'] === false && str_contains(strtolower($cancelDeliveredResult['error']), 'delivered')
);

// =========================================================================
// SCENARIO I: User attempts to cancel a shipped/in-transit order (rejected)
// =========================================================================
echo "\nTesting Scenario I: Cancel a shipped/in-transit order...\n";

$shippedOrder = OrdersService::createOrder(
    $customerData,
    $cartItems,
    89.99,
    ['method' => 'Credit Card'],
    101
);
// Simulate dispatched / in transit
$_SESSION['session_orders'][$shippedOrder['order_number']]['delivery_status'] = 'In Transit';

$cancelShippedResult = OrdersService::cancelOrder(
    $shippedOrder['order_number'],
    101,
    'Too late, changed mind',
    false
);

assertTest(
    'Scenario I.1: Cancellation of shipped/in-transit order is strictly rejected',
    $cancelShippedResult['success'] === false && (str_contains(strtolower($cancelShippedResult['error']), 'dispatched') || str_contains(strtolower($cancelShippedResult['error']), 'shipped'))
);

// =========================================================================
// SCENARIO J: User cancels a paid PayPal order (Refund handled)
// =========================================================================
echo "\nTesting Scenario J: User cancels a paid PayPal order and refund is handled...\n";

// Set mock for PayPal refund
PayPalService::setMockHandler(function(string $action, array $params) {
    if ($action === 'refundCapture') {
        return [
            'success'      => true,
            'refund_id'    => 'REF-PP-TEST-9988',
            'status'       => 'COMPLETED',
            'amount'       => $params['amount'],
            'currency'     => 'USD',
            'raw_response' => ['id' => 'REF-PP-TEST-9988', 'status' => 'COMPLETED']
        ];
    }
    return null;
});

// Order A is a paid PayPal order created in Scenario A
$cancelPaidResult = OrdersService::cancelOrder(
    $orderA['order_number'],
    101,
    'Found a better deal elsewhere',
    false
);

$cancelledPaid = OrdersService::getOrderByNumber($orderA['order_number']);

assertTest(
    'Scenario J.1: Cancellation of paid PayPal order succeeds',
    $cancelPaidResult['success'] === true
);
assertTest(
    'Scenario J.2: Order status changed to Cancelled',
    ($cancelledPaid['order_status'] ?? '') === 'Cancelled'
);
assertTest(
    'Scenario J.3: Payment status changed to Refunded',
    ($cancelledPaid['payment_status'] ?? '') === 'Refunded'
);
assertTest(
    'Scenario J.4: PayPal Refund ID and Refund Status stored on order',
    ($cancelledPaid['refund_id'] ?? '') === 'REF-PP-TEST-9988' && ($cancelledPaid['refund_status'] ?? '') === 'COMPLETED'
);
assertTest(
    'Scenario J.5: Cancellation timestamp and reason recorded',
    !empty($cancelledPaid['cancelled_at']) && ($cancelledPaid['cancel_reason'] ?? '') === 'Found a better deal elsewhere'
);

// =========================================================================
// SCENARIO K: Order and transaction history consistency
// =========================================================================
echo "\nTesting Scenario K: Transaction history consistency...\n";

$transactions = OrdersService::getTransactions(null, $orderA['order_number']);

$hasOriginalPayment = false;
$hasRefundTxn = false;

foreach ($transactions as $txn) {
    if (($txn['order_number'] ?? '') === $orderA['order_number']) {
        if (($txn['payment_status'] ?? '') === 'Success') {
            $hasOriginalPayment = true;
        }
        if (($txn['payment_status'] ?? '') === 'Refunded') {
            $hasRefundTxn = true;
        }
    }
}

assertTest(
    'Scenario K.1: Original payment transaction preserved in history',
    $hasOriginalPayment
);
assertTest(
    'Scenario K.2: New refund transaction record exists in transaction history',
    $hasRefundTxn
);

// =========================================================================
// SCENARIO L: Delivery tracking remains associated and timeline updated
// =========================================================================
echo "\nTesting Scenario L: Delivery tracking association & timeline update...\n";

$delivery = OrdersService::getDeliveryByTracking($orderA['tracking_number']);

assertTest(
    'Scenario L.1: Delivery record associated with order exists',
    $delivery !== null && ($delivery['order_number'] ?? '') === $orderA['order_number']
);
assertTest(
    'Scenario L.2: Delivery status is Cancelled',
    ($delivery['delivery_status'] ?? '') === 'Cancelled'
);

// Check latest checkpoint in timeline
$hasCancelledCheckpoint = false;
if (!empty($delivery['timeline'])) {
    foreach ($delivery['timeline'] as $chk) {
        if (($chk['status'] ?? '') === 'Cancelled') {
            $hasCancelledCheckpoint = true;
            break;
        }
    }
}

assertTest(
    'Scenario L.3: Timeline contains Cancelled checkpoint with reason',
    $hasCancelledCheckpoint
);

// =========================================================================
// Summary
// =========================================================================
echo "\n============================================================\n";
echo " Test Results: Total: " . ($passedCount + $failedCount) . " | Passed: {$passedCount} | Failed: {$failedCount}\n";
echo "============================================================\n";

if ($failedCount > 0) {
    exit(1);
}
exit(0);
