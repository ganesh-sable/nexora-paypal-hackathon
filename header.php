<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Calculate cart count
$cart_count = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $qty) {
        $cart_count += $qty;
    }
}
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$isUserLoggedIn = Auth::isLoggedIn();
$currentUser = Auth::currentUser();
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

            <div class="nav-divider"></div>

            <?php if ($isUserLoggedIn && $currentUser): ?>
                <a href="account.php" class="nav-user-pill <?php echo $currentPage === 'account.php' ? 'active' : ''; ?>" title="View Account Dashboard">
                    <span class="user-avatar-mini">👤</span>
                    <span class="user-greeting"><?php echo htmlspecialchars(explode(' ', trim($currentUser['full_name']))[0]); ?></span>
                </a>
                <form action="logout.php" method="POST" class="nav-logout-form">
                    <?php echo Auth::csrfField(); ?>
                    <button type="submit" class="nav-logout-btn" title="Sign Out">Sign Out</button>
                </form>
            <?php else: ?>
                <a href="login.php" class="nav-auth-link <?php echo $currentPage === 'login.php' ? 'active' : ''; ?>">
                    Sign In
                </a>
                <a href="signup.php" class="btn btn-primary btn-sm nav-signup-btn <?php echo $currentPage === 'signup.php' ? 'active' : ''; ?>">
                    Sign Up
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="main">
