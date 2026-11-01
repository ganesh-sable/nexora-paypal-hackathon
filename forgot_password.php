<?php
$page_title = 'Forgot Password';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$message = null;
$error = null;
$simulatedLink = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (empty($email)) {
            $error = 'Please enter your email address.';
        } else {
            $result = Auth::createPasswordReset($email);
            $message = $result['message'];
            if (!empty($result['simulation_token'])) {
                $simulatedLink = 'reset_password.php?token=' . urlencode($result['simulation_token']);
            }
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <span class="auth-badge">🔑 Password Recovery</span>
            <h1 class="auth-title">Reset Your Password</h1>
            <p class="auth-subtitle">Enter your registered email address and we will generate a secure reset link.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success" role="alert">
                <div class="alert-icon">✓</div>
                <div class="alert-content">
                    <strong>Instructions Sent:</strong>
                    <div><?php echo htmlspecialchars($message); ?></div>
                </div>
            </div>

            <?php if ($simulatedLink): ?>
                <div class="alert alert-warning" style="margin-top:1rem;">
                    <div class="alert-icon">🚀</div>
                    <div class="alert-content">
                        <strong>Developer & Demo Simulation:</strong>
                        <div style="font-size:0.85rem;margin:0.25rem 0;">A secure reset token was generated. Click below to test password reset:</div>
                        <a href="<?php echo htmlspecialchars($simulatedLink); ?>" class="btn btn-primary btn-sm" style="margin-top:0.5rem;display:inline-block;">Proceed to Reset Password Page →</a>
                    </div>
                </div>
            <?php endif; ?>

            <div style="margin-top:1.5rem;text-align:center;">
                <a href="login.php" class="text-link">← Return to Sign In</a>
            </div>

        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <div class="alert-icon">⚠️</div>
                    <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <form action="forgot_password.php" method="POST" class="auth-form" novalidate>
                <?php echo Auth::csrfField(); ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-with-icon">
                        <span class="input-icon">✉️</span>
                        <input type="email" id="email" name="email" required 
                               placeholder="you@example.com" 
                               autocomplete="email" autofocus>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:1rem;">
                    <span>Send Reset Instructions</span>
                    <span class="btn-arrow">→</span>
                </button>
            </form>

            <div class="auth-footer">
                <p>Remembered your password? <a href="login.php" class="text-link font-semibold">Back to Sign In</a></p>
            </div>

        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
