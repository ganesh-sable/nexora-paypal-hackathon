<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/products.php';

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$action     = $_POST['action'] ?? $_GET['action'] ?? '';
$product_id = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

// Validate product exists
if ($product_id && !get_product_by_id($product_id)) {
    header('Location: index.php');
    exit;
}

switch ($action) {
    case 'add':
        if ($product_id) {
            if (isset($_SESSION['cart'][$product_id])) {
                $_SESSION['cart'][$product_id]++;
            } else {
                $_SESSION['cart'][$product_id] = 1;
            }
        }
        header('Location: cart.php');
        exit;

    case 'increase':
        if ($product_id && isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]++;
        }
        header('Location: cart.php');
        exit;

    case 'decrease':
        if ($product_id && isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]--;
            if ($_SESSION['cart'][$product_id] <= 0) {
                unset($_SESSION['cart'][$product_id]);
            }
        }
        header('Location: cart.php');
        exit;

    case 'remove':
        if ($product_id && isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
        }
        header('Location: cart.php');
        exit;

    case 'clear':
        $_SESSION['cart'] = [];
        header('Location: cart.php');
        exit;

    default:
        header('Location: index.php');
        exit;
}
?>
