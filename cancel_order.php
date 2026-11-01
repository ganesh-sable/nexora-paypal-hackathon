<?php
/**
 * Nexora - Order Cancellation Handler
 * 
 * Secure POST endpoint to cancel an eligible order:
 * - Requires user authentication
 * - Enforces CSRF protection
 * - Validates order ownership
 * - Checks cancellation eligibility (disallow dispatched/delivered)
 * - Triggers PayPal REST refund if order was paid
 * - Updates delivery tracking & timeline to Cancelled
 * - Logs audit timestamp and customer cancellation reason
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/orders_service.php';

Auth::requireLogin('account.php');
$currentUser = Auth::currentUser();

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if ($isAjax) {
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Method not allowed. Use POST.']);
        exit;
    }
    header('Location: account.php');
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
if (!Auth::verifyCsrfToken($csrfToken)) {
    $msg = 'Invalid or expired security token. Please try again.';
    if ($isAjax) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg]);
        exit;
    }
    $_SESSION['flash_error'] = $msg;
    header('Location: account.php');
    exit;
}

$orderIdentifier = trim($_POST['order_id'] ?? ($_POST['order_number'] ?? ''));
$reason = trim($_POST['cancel_reason'] ?? '');
$redirect = trim($_POST['redirect_to'] ?? 'account.php');

// Sanitize redirect target to prevent open redirect
if (!str_starts_with($redirect, 'account.php') && !str_starts_with($redirect, 'track_delivery.php') && !str_starts_with($redirect, 'checkout.php')) {
    $redirect = 'account.php';
}

if (empty($orderIdentifier)) {
    $msg = 'Order identifier is required for cancellation.';
    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => $msg]);
        exit;
    }
    $_SESSION['flash_error'] = $msg;
    header('Location: ' . $redirect);
    exit;
}

$result = OrdersService::cancelOrder($orderIdentifier, (int)$currentUser['id'], $reason, false);

if ($isAjax) {
    header('Content-Type: application/json');
    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(400);
        echo json_encode($result);
    }
    exit;
}

if ($result['success']) {
    $_SESSION['flash_success'] = $result['message'];
} else {
    $_SESSION['flash_error'] = $result['error'] ?? 'Unable to cancel this order.';
}

header('Location: ' . $redirect);
exit;
