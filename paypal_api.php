<?php
/**
 * Nexora - PayPal AJAX API Endpoint
 * 
 * Handles client-side PayPal SDK interactions:
 * - create-order: Creates an order on PayPal v2 REST API with trusted server-calculated total
 * - capture-order: Verifies and captures payment, records order, delivery & transaction
 * - cancel-approval: Handles user cancellation in the PayPal pop-up window
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/paypal_service.php';
require_once __DIR__ . '/orders_service.php';

// Accept JSON input or standard POST
$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true) ?: [];
$requestData = array_merge($_POST, $jsonInput);

$action = trim($_GET['action'] ?? ($requestData['action'] ?? ''));

try {
    // 1. Action: Cancel Approval (Buyer closed or cancelled the PayPal modal)
    if ($action === 'cancel-approval') {
        echo json_encode([
            'success' => true,
            'status'  => 'cancelled',
            'message' => 'PayPal approval was cancelled. Your shopping cart has been preserved.'
        ]);
        exit;
    }

    // Authentication check for financial transactions
    if (!Auth::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'You must be signed in to complete checkout with PayPal.']);
        exit;
    }

    $currentUser = Auth::currentUser();

    // CSRF verification
    $csrfToken = $requestData['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Auth::verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Security token invalid or expired. Please refresh and try again.']);
        exit;
    }

    // 2. Action: Create PayPal Order
    if ($action === 'create-order') {
        $cart = $_SESSION['cart'] ?? [];
        if (empty($cart)) {
            http_response_code(400);
            echo json_encode(['error' => 'Your cart is empty.']);
            exit;
        }

        // Trust only server-side catalog prices - never trust client input
        $subtotal = 0.0;
        $validItems = [];
        foreach ($cart as $id => $qty) {
            $qty = (int)$qty;
            if ($qty <= 0) continue;
            $p = get_product_by_id((int)$id);
            if ($p) {
                $lineTotal = round((float)$p['price'] * $qty, 2);
                $subtotal += $lineTotal;
                $validItems[] = [
                    'id'         => $p['id'],
                    'name'       => $p['name'],
                    'price'      => (float)$p['price'],
                    'qty'        => $qty,
                    'line_total' => $lineTotal
                ];
            }
        }

        if (empty($validItems) || $subtotal <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'No valid products in cart.']);
            exit;
        }

        $order = PayPalService::createOrder($subtotal, $validItems, ['user_id' => $currentUser['id']], 'USD');

        echo json_encode([
            'id'     => $order['id'],
            'status' => $order['status'],
            'amount' => $subtotal
        ]);
        exit;
    }

    // 3. Action: Capture PayPal Order
    if ($action === 'capture-order') {
        $paypalOrderId = trim($requestData['orderID'] ?? ($requestData['paypal_order_id'] ?? ''));
        if (empty($paypalOrderId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required PayPal Order ID.']);
            exit;
        }

        // Capture via PayPal REST API
        $captureResult = PayPalService::captureOrder($paypalOrderId);

        if (!$captureResult['success'] || $captureResult['status'] !== 'COMPLETED') {
            http_response_code(400);
            echo json_encode(['error' => 'PayPal payment was not completed. Status: ' . ($captureResult['status'] ?? 'UNKNOWN')]);
            exit;
        }

        // Prepare customer data from request or profile
        $nameParts = explode(' ', trim($currentUser['full_name'] ?? 'Guest Customer'), 2);
        $customerData = [
            'first_name' => trim($requestData['first_name'] ?? ($nameParts[0] ?? 'John')),
            'last_name'  => trim($requestData['last_name'] ?? ($nameParts[1] ?? 'Doe')),
            'email'      => trim($requestData['email'] ?? ($currentUser['email'] ?? 'customer@example.com')),
            'phone'      => trim($requestData['phone'] ?? '+1-555-0199'),
            'address'    => trim($requestData['address'] ?? '742 Evergreen Terrace'),
            'city'       => trim($requestData['city'] ?? 'Springfield'),
            'state'      => trim($requestData['state'] ?? 'OR'),
            'zip'        => trim($requestData['zip'] ?? '97477'),
            'country'    => trim($requestData['country'] ?? 'United States')
        ];

        // Recompute cart items strictly from session & catalog
        $cart = $_SESSION['cart'] ?? [];
        $subtotal = 0.0;
        $cartItems = [];
        foreach ($cart as $id => $qty) {
            $qty = (int)$qty;
            if ($qty <= 0) continue;
            $p = get_product_by_id((int)$id);
            if ($p) {
                $lineTotal = round((float)$p['price'] * $qty, 2);
                $subtotal += $lineTotal;
                $p['qty'] = $qty;
                $p['line_total'] = $lineTotal;
                $cartItems[] = $p;
            }
        }

        // If cart is somehow empty in session during capture (e.g. race condition/duplicate call),
        // check if duplicate capture was already processed
        if (empty($cartItems)) {
            $subtotal = (float)$captureResult['amount'];
        }

        // Create order with delivery tracking & transaction history
        $createdOrder = OrdersService::createOrderFromPayPal(
            $customerData,
            $cartItems,
            $subtotal,
            $paypalOrderId,
            $captureResult['capture_id'],
            (int)$currentUser['id']
        );

        // Clear user's cart upon verified payment
        $_SESSION['cart'] = [];

        echo json_encode([
            'success'      => true,
            'order_number' => $createdOrder['order_number'],
            'capture_id'   => $captureResult['capture_id'],
            'amount'       => $captureResult['amount'],
            'redirect_url' => 'checkout.php?order_success=' . urlencode($createdOrder['order_number'])
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action: ' . htmlspecialchars($action)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => $e->getMessage(),
        'success' => false
    ]);
}
