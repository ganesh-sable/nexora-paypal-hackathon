<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/db.php';

// Calculate cart count
$cart_count = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $qty) {
        $cart_count += $qty;
    }
}
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' | ' : ''; ?>Nexora</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<header class="header">
    <div class="header-inner">
        <a href="index.php" class="logo">
            <span class="logo-icon">🛍️</span>
            <span>Nexora</span>
        </a>
        <nav class="nav">
            <a href="index.php" class="<?php echo $currentPage === 'index.php' ? 'active' : ''; ?>">
                🛍️ Products
            </a>
            <a href="track_delivery.php" class="<?php echo $currentPage === 'track_delivery.php' ? 'active' : ''; ?>">
                🚚 Delivery Tracking
            </a>
            <a href="transactions.php" class="<?php echo $currentPage === 'transactions.php' ? 'active' : ''; ?>">
                💳 Transactions
            </a>
            <a href="cart.php" class="cart-btn <?php echo $currentPage === 'cart.php' ? 'active' : ''; ?>">
                🛒 Cart
                <?php if ($cart_count > 0): ?>
                    <span class="cart-count"><?php echo $cart_count; ?></span>
                <?php endif; ?>
            </a>
        </nav>
    </div>
</header>
<main class="main">
