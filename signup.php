<?php
$page_title = 'Create Account';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$redirect = Auth::sanitizeRedirectUrl($_GET['redirect'] ?? $_POST['redirect'] ?? 'account.php');

if (Auth::isLoggedIn()) {
    header('Location: ' . $redirect);
    exit;
}

$errors = [];
$fullName = '';
$email = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid or expired security token. Please refresh and try again.';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $agreeTerms = isset($_POST['agree_terms']);

        $res = Auth::registerUser($fullName, $email, $password, $confirmPassword, $agreeTerms);
        if ($res['success']) {
            $_SESSION['flash_success'] = 'Welcome to Nexora, ' . htmlspecialchars($res['user']['full_name']) . '! Your account has been created.';
            header('Location: ' . $redirect);
            exit;
        } else {
            $errors = $res['errors'];
        }
    }
}

require_once __DIR__ . '/header.php';
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <span class="auth-badge">✨ Secure Registration</span>
            <h1 class="auth-title">Join Nexora</h1>
            <p class="auth-subtitle">Create your personal shopping account for express checkout, order history and live parcel tracking.</p>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <div class="alert-icon">⚠️</div>
                <div class="alert-content">
                    <strong>Please correct the following:</strong>
                    <ul style="margin: 0.35rem 0 0 1.25rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <form action="signup.php<?php echo $redirect !== 'account.php' ? '?redirect=' . urlencode($redirect) : ''; ?>" method="POST" class="auth-form" id="signupForm" novalidate>
            <?php echo Auth::csrfField(); ?>
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">

            <div class="form-group">
                <label for="full_name">Full Name <span class="required">*</span></label>
                <div class="input-with-icon">
                    <span class="input-icon">👤</span>
                    <input type="text" id="full_name" name="full_name" required 
                           placeholder="e.g. Eleanor Vance" 
                           value="<?php echo htmlspecialchars($fullName); ?>" 
                           autocomplete="name" maxlength="150">
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address <span class="required">*</span></label>
                <div class="input-with-icon">
                    <span class="input-icon">✉️</span>
                    <input type="email" id="email" name="email" required 
                           placeholder="you@example.com" 
                           value="<?php echo htmlspecialchars($email); ?>" 
                           autocomplete="email">
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <div class="input-with-icon">
                    <span class="input-icon">🔒</span>
                    <input type="password" id="password" name="password" required 
                           placeholder="At least 12 characters" 
                           autocomplete="new-password">
                    <button type="button" class="btn-toggle-pw" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password visibility">👁️</button>
                </div>
                
                <!-- Password Strength & Policy Indicator -->
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
                <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                <div class="input-with-icon">
                    <span class="input-icon">🔒</span>
                    <input type="password" id="confirm_password" name="confirm_password" required 
                           placeholder="Re-enter your password" 
                           autocomplete="new-password">
                    <button type="button" class="btn-toggle-pw" onclick="togglePasswordVisibility('confirm_password', this)" aria-label="Toggle password visibility">👁️</button>
                </div>
                <div id="matchMessage" class="field-feedback"></div>
            </div>

            <div class="form-group checkbox-group">
                <label class="custom-checkbox">
                    <input type="checkbox" name="agree_terms" id="agree_terms" value="1" required>
                    <span class="checkbox-box"></span>
                    <span class="checkbox-label">
                        I agree to the <a href="#" onclick="alert('Nexora Terms: All purchases and deliveries are backed by our 100% satisfaction guarantee.'); return false;" class="text-link">Terms of Service</a> and <a href="#" onclick="alert('Nexora Privacy: Your personal data and passwords are encrypted and never sold.'); return false;" class="text-link">Privacy Policy</a>.
                    </span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" id="submitBtn">
                <span>Create Nexora Account</span>
                <span class="btn-arrow">→</span>
            </button>
        </form>

        <div class="auth-footer">
            <p>Already have an account? <a href="login.php<?php echo $redirect !== 'account.php' ? '?redirect=' . urlencode($redirect) : ''; ?>" class="text-link font-semibold">Sign In here</a></p>
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
