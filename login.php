<?php
$page_title = 'Sign In';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$redirect = Auth::sanitizeRedirectUrl($_GET['redirect'] ?? $_POST['redirect'] ?? 'account.php');

if (Auth::isLoggedIn()) {
    header('Location: ' . $redirect);
    exit;
}

$error = null;
$email = '';
$isThrottled = false;

// Check flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid or expired security token. Please refresh the page and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rememberMe = isset($_POST['remember_me']);

        $res = Auth::loginUser($email, $password, $rememberMe);
        if ($res['success']) {
            $_SESSION['flash_success'] = 'Welcome back, ' . htmlspecialchars($res['user']['full_name']) . '!';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $res['error'];
            $isThrottled = Auth::isThrottled($email);
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <span class="auth-badge">🔐 Nexora Auth</span>
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your account to manage orders, track deliveries, and complete checkout.</p>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="alert alert-success" role="alert">
                <div class="alert-icon">✓</div>
                <div class="alert-content"><?php echo htmlspecialchars($flashSuccess); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert <?php echo $isThrottled ? 'alert-warning' : 'alert-danger'; ?>" role="alert">
                <div class="alert-icon"><?php echo $isThrottled ? '⏳' : '⚠️'; ?></div>
                <div class="alert-content">
                    <strong><?php echo $isThrottled ? 'Security Lockout' : 'Authentication Failed'; ?>:</strong>
                    <div style="margin-top:0.25rem;"><?php echo htmlspecialchars($error); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <form action="login.php<?php echo $redirect !== 'account.php' ? '?redirect=' . urlencode($redirect) : ''; ?>" method="POST" class="auth-form" novalidate>
            <?php echo Auth::csrfField(); ?>
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-with-icon">
                    <span class="input-icon">✉️</span>
                    <input type="email" id="email" name="email" required 
                           placeholder="you@example.com" 
                           value="<?php echo htmlspecialchars($email); ?>" 
                           autocomplete="email" autofocus>
                </div>
            </div>

            <div class="form-group">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.4rem;">
                    <label for="password" style="margin-bottom:0;">Password</label>
                    <a href="forgot_password.php" class="text-link" style="font-size:0.85rem;">Forgot password?</a>
                </div>
                <div class="input-with-icon">
                    <span class="input-icon">🔒</span>
                    <input type="password" id="password" name="password" required 
                           placeholder="Enter your password" 
                           autocomplete="current-password">
                    <button type="button" class="btn-toggle-pw" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">👁️</button>
                </div>
            </div>

            <div class="form-row-between">
                <label class="custom-checkbox">
                    <input type="checkbox" name="remember_me" id="remember_me" value="1">
                    <span class="checkbox-box"></span>
                    <span class="checkbox-label">Remember me for 30 days</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:1.25rem;">
                <span>Sign In to Account</span>
                <span class="btn-arrow">→</span>
            </button>
        </form>

        <div class="auth-footer">
            <p>Don't have an account yet? <a href="signup.php<?php echo $redirect !== 'account.php' ? '?redirect=' . urlencode($redirect) : ''; ?>" class="text-link font-semibold">Create an account</a></p>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
    } else {
        input.type = 'password';
        btn.textContent = '👁️';
    }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
