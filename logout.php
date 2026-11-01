<?php
/**
 * Dedicated Logout Action
 * Destroys session, deletes session & remember cookies, and redirects.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        header('Location: index.php');
        exit;
    }

    Auth::logout();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_success'] = 'You have been securely signed out of Nexora.';
    header('Location: login.php');
    exit;
}

// If accessed via GET directly, provide an interactive confirmation page with CSRF token
$page_title = 'Sign Out';
require_once __DIR__ . '/header.php';
?>

<div class="auth-container">
    <div class="auth-card" style="text-align:center;">
        <div class="emoji" style="font-size:3rem;margin-bottom:1rem;">👋</div>
        <h1 class="auth-title">Sign Out of Nexora</h1>
        <p class="auth-subtitle">Are you sure you want to end your current session?</p>

        <form action="logout.php" method="POST" style="margin-top:1.5rem;display:flex;gap:1rem;justify-content:center;">
            <?php echo Auth::csrfField(); ?>
            <button type="submit" class="btn btn-danger" style="padding:0.75rem 1.75rem;">Yes, Sign Out</button>
            <a href="index.php" class="btn btn-outline" style="padding:0.75rem 1.75rem;">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
