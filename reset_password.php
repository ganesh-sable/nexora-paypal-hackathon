<?php
$page_title = 'Set New Password';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$validToken = Auth::verifyPasswordResetToken($token);

$error = null;
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } elseif (!$validToken) {
        $error = 'This password reset link is invalid or has expired. Please request a new one.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        $res = Auth::resetPassword($token, $password, $confirmPassword);
        if ($res['success']) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash_success'] = 'Your password has been successfully updated! You can now sign in with your new credentials.';
            header('Location: login.php');
            exit;
        } else {
            $error = $res['error'];
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <span class="auth-badge">🔒 Secure Reset</span>
            <h1 class="auth-title">Create New Password</h1>
            <p class="auth-subtitle">
                <?php if ($validToken): ?>
                    Resetting password for <strong><?php echo htmlspecialchars($validToken['email']); ?></strong>
                <?php else: ?>
                    Please enter your new 12+ character password.
                <?php endif; ?>
            </p>
        </div>

        <?php if (!$validToken && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'): ?>
            <div class="alert alert-danger" role="alert">
                <div class="alert-icon">⚠️</div>
                <div class="alert-content">
                    <strong>Invalid or Expired Link</strong>
                    <div style="margin-top:0.35rem;">The password reset token is missing, invalid, or has expired.</div>
                </div>
            </div>
            <div style="text-align:center;margin-top:1.5rem;">
                <a href="forgot_password.php" class="btn btn-primary">Request New Reset Link</a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                    <div class="alert-icon">⚠️</div>
                    <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <form action="reset_password.php" method="POST" class="auth-form" novalidate>
                <?php echo Auth::csrfField(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                <div class="form-group">
                    <label for="password">New Password <span class="required">*</span></label>
                    <div class="input-with-icon">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="password" name="password" required 
                               placeholder="At least 12 characters" 
                               autocomplete="new-password" autofocus>
                        <button type="button" class="btn-toggle-pw" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">👁️</button>
                    </div>

                    <div class="password-meter-wrapper" id="passwordMeter">
                        <div class="password-meter-bar">
                            <div class="password-meter-fill" id="meterFill"></div>
                        </div>
                        <span class="password-meter-text" id="meterText">Password strength: too short</span>
                        <ul class="password-rules-list" id="passwordRules">
                            <li id="rule-length">● At least 12 characters</li>
                            <li id="rule-upper">● At least one uppercase letter (A-Z)</li>
                            <li id="rule-lower">● At least one lowercase letter (a-z)</li>
                            <li id="rule-number">● At least one numeric digit (0-9)</li>
                            <li id="rule-symbol">● At least one special symbol (!@#$%^&*)</li>
                        </ul>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
                    <div class="input-with-icon">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="confirm_password" name="confirm_password" required 
                               placeholder="Re-enter your new password" 
                               autocomplete="new-password">
                        <button type="button" class="btn-toggle-pw" onclick="togglePasswordVisibility('confirm_password', this)" aria-label="Toggle password visibility">👁️</button>
                    </div>
                    <div id="matchMessage" class="field-feedback"></div>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:1.25rem;">
                    <span>Update Password</span>
                    <span class="btn-arrow">→</span>
                </button>
            </form>

        <?php endif; ?>

        <div class="auth-footer">
            <p><a href="login.php" class="text-link">← Return to Sign In</a></p>
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

document.addEventListener('DOMContentLoaded', function() {
    const pwInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    if (!pwInput || !confirmInput) return;

    const meterFill = document.getElementById('meterFill');
    const meterText = document.getElementById('meterText');
    const matchMsg = document.getElementById('matchMessage');

    const rules = {
        length: document.getElementById('rule-length'),
        upper: document.getElementById('rule-upper'),
        lower: document.getElementById('rule-lower'),
        number: document.getElementById('rule-number'),
        symbol: document.getElementById('rule-symbol')
    };

    function evaluatePassword(pw) {
        let score = 0;
        const checks = {
            length: pw.length >= 12,
            upper: /[A-Z]/.test(pw),
            lower: /[a-z]/.test(pw),
            number: /[0-9]/.test(pw),
            symbol: /[^A-Za-z0-9]/.test(pw)
        };

        for (const [key, passed] of Object.entries(checks)) {
            if (passed) {
                score++;
                rules[key].classList.add('passed');
            } else {
                rules[key].classList.remove('passed');
            }
        }

        const pct = (score / 5) * 100;
        meterFill.style.width = pct + '%';

        if (score === 0) {
            meterFill.className = 'password-meter-fill';
            meterText.textContent = 'Password strength: enter password';
        } else if (score < 3) {
            meterFill.className = 'password-meter-fill weak';
            meterText.textContent = 'Password strength: Weak';
        } else if (score < 5) {
            meterFill.className = 'password-meter-fill medium';
            meterText.textContent = 'Password strength: Moderate';
        } else {
            meterFill.className = 'password-meter-fill strong';
            meterText.textContent = 'Password strength: Strong & Secure';
        }

        checkMatch();
    }

    function checkMatch() {
        const pw = pwInput.value;
        const cpw = confirmInput.value;
        if (!cpw) {
            matchMsg.textContent = '';
            matchMsg.className = 'field-feedback';
            return;
        }
        if (pw === cpw) {
            matchMsg.textContent = '✓ Passwords match';
            matchMsg.className = 'field-feedback success';
        } else {
            matchMsg.textContent = '✕ Passwords do not match';
            matchMsg.className = 'field-feedback error';
        }
    }

    pwInput.addEventListener('input', () => evaluatePassword(pwInput.value));
    confirmInput.addEventListener('input', checkMatch);
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
