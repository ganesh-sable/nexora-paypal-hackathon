<?php
/**
 * Nexora Core Authentication & Security Service
 * Handles Registration, Login, Session Management, CSRF Protection,
 * Rate Limiting, Password Reset, and Access Control.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

class Auth {

    const MIN_PASSWORD_LENGTH = 12;
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_WINDOW_MINUTES = 15;
    const RESET_TOKEN_LIFETIME_HOURS = 1;

    // Allowed local redirect destinations to prevent open redirect vulnerabilities
    private static array $allowedRedirects = [
        'checkout.php',
        'cart.php',
        'account.php',
        'index.php',
        'track_delivery.php',
        'transactions.php'
    ];

    /**
     * Generate or return active CSRF token
     */
    public static function generateCsrfToken(): string {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token using constant-time comparison
     */
    public static function verifyCsrfToken(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Render HTML hidden input with CSRF token
     */
    public static function csrfField(): string {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Validate password complexity
     */
    public static function validatePassword(string $password): array {
        $errors = [];
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters long.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter (A-Z).';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter (a-z).';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one numeric digit (0-9).';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors[] = 'Password must contain at least one special character (!@#$%^&* etc.).';
        }
        return $errors;
    }

    /**
     * Sanitize and normalize email address
     */
    public static function normalizeEmail(string $email): string {
        return trim(strtolower($email));
    }

    /**
     * Get client IP address safely
     */
    public static function getClientIp(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    /**
     * Check if client or email is rate-limited
     */
    public static function isThrottled(string $email): bool {
        $email = self::normalizeEmail($email);
        $ip = self::getClientIp();

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM login_attempts 
                    WHERE (ip_address = ? OR LOWER(email) = ?) 
                      AND attempted_at >= (NOW() - INTERVAL '15 minutes')
                ");
                $stmt->execute([$ip, $email]);
                $count = (int)$stmt->fetchColumn();
                return $count >= self::MAX_LOGIN_ATTEMPTS;
            } catch (Exception $e) {
                // Fall back to session check
            }
        }

        // Session-based fallback throttling
        $attempts = $_SESSION['login_attempts'] ?? [];
        $cutoff = time() - (self::LOCKOUT_WINDOW_MINUTES * 60);
        $recent = 0;
        foreach ($attempts as $att) {
            if ($att['time'] >= $cutoff && ($att['ip'] === $ip || $att['email'] === $email)) {
                $recent++;
            }
        }
        return $recent >= self::MAX_LOGIN_ATTEMPTS;
    }

    /**
     * Record a failed login attempt
     */
    public static function recordFailedLogin(string $email): void {
        $email = self::normalizeEmail($email);
        $ip = self::getClientIp();

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email, attempted_at) VALUES (?, ?, NOW())");
                $stmt->execute([$ip, $email]);
            } catch (Exception $e) {
                // Ignore failure
            }
        }

        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
        $_SESSION['login_attempts'][] = [
            'ip'    => $ip,
            'email' => $email,
            'time'  => time()
        ];
    }

    /**
     * Clear failed login attempts after successful authentication
     */
    public static function clearLoginAttempts(string $email): void {
        $email = self::normalizeEmail($email);
        $ip = self::getClientIp();

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR LOWER(email) = ?");
                $stmt->execute([$ip, $email]);
            } catch (Exception $e) {
                // Ignore
            }
        }

        if (isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = array_filter($_SESSION['login_attempts'], function($att) use ($ip, $email) {
                return $att['ip'] !== $ip && $att['email'] !== $email;
            });
        }
    }

    /**
     * Register a new user
     */
    public static function registerUser(string $fullName, string $email, string $password, string $confirmPassword, bool $agreeTerms): array {
        $errors = [];
        $fullName = trim($fullName);
        $email = self::normalizeEmail($email);

        if (empty($fullName)) {
            $errors[] = 'Full name is required.';
        } elseif (strlen($fullName) < 2 || strlen($fullName) > 150) {
            $errors[] = 'Full name must be between 2 and 150 characters.';
        }

        if (empty($email)) {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please provide a valid email address.';
        }

        if ($password !== $confirmPassword) {
            $errors[] = 'Passwords do not match.';
        } else {
            $pwErrors = self::validatePassword($password);
            $errors = array_merge($errors, $pwErrors);
        }

        if (!$agreeTerms) {
            $errors[] = 'You must agree to the Terms of Service and Privacy Policy.';
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        // Check for existing user (parameterized query)
        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    return [
                        'success' => false,
                        'errors' => ['An account with this email address already exists. Please log in.']
                    ];
                }

                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $insertStmt = $pdo->prepare("
                    INSERT INTO users (full_name, email, password_hash, role, is_active, created_at, updated_at)
                    VALUES (?, ?, ?, 'customer', TRUE, NOW(), NOW())
                    RETURNING id, full_name, email, role, created_at
                ");
                $insertStmt->execute([$fullName, $email, $passwordHash]);
                $user = $insertStmt->fetch();

                if ($user) {
                    self::createSession($user);
                    return ['success' => true, 'user' => $user];
                }
            } catch (Exception $e) {
                error_log("Registration error: " . $e->getMessage());
                return [
                    'success' => false,
                    'errors' => ['An error occurred while creating your account. Please try again.']
                ];
            }
        }

        // Session-based fallback store (for offline mode)
        if (!isset($_SESSION['mock_users'])) {
            $_SESSION['mock_users'] = [];
        }
        foreach ($_SESSION['mock_users'] as $u) {
            if (strtolower($u['email']) === $email) {
                return [
                    'success' => false,
                    'errors' => ['An account with this email address already exists. Please log in.']
                ];
            }
        }

        $userId = count($_SESSION['mock_users']) + 1;
        $mockUser = [
            'id'            => $userId,
            'full_name'     => $fullName,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => 'customer',
            'created_at'    => date('Y-m-d H:i:s')
        ];
        $_SESSION['mock_users'][] = $mockUser;

        self::createSession($mockUser);
        return ['success' => true, 'user' => $mockUser];
    }

    /**
     * Authenticate and log in user
     */
    public static function loginUser(string $email, string $password, bool $rememberMe = false): array {
        $email = self::normalizeEmail($email);

        if (empty($email) || empty($password)) {
            return [
                'success' => false,
                'error' => 'Please enter both email address and password.'
            ];
        }

        if (self::isThrottled($email)) {
            return [
                'success' => false,
                'error' => 'Too many failed login attempts. For security, please try again in 15 minutes.'
            ];
        }

        $user = null;

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("
                    SELECT id, full_name, email, password_hash, role, is_active, created_at 
                    FROM users 
                    WHERE LOWER(email) = ? 
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
            } catch (Exception $e) {
                error_log("Login DB error: " . $e->getMessage());
            }
        } else {
            // Mock store check
            $mockUsers = $_SESSION['mock_users'] ?? [];
            foreach ($mockUsers as $m) {
                if (strtolower($m['email']) === $email) {
                    $user = $m;
                    break;
                }
            }
        }

        // Generic error message to prevent account enumeration
        $genericError = 'Invalid email or password.';

        if (!$user) {
            self::recordFailedLogin($email);
            return ['success' => false, 'error' => $genericError];
        }

        if (isset($user['is_active']) && !$user['is_active']) {
            return [
                'success' => false,
                'error' => 'This account has been deactivated. Please contact customer support.'
            ];
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::recordFailedLogin($email);
            return ['success' => false, 'error' => $genericError];
        }

        // Rehash password if algorithm options improved
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            if (DB::isConnected()) {
                try {
                    $upStmt = DB::getConnection()->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $upStmt->execute([$newHash, $user['id']]);
                } catch (Exception $e) {}
            }
        }

        // Authentication succeeded: clear rate limiting and create fresh session
        self::clearLoginAttempts($email);
        self::createSession($user);

        // Handle Remember Me token if requested
        if ($rememberMe) {
            self::createRememberToken((int)$user['id']);
        }

        return ['success' => true, 'user' => self::currentUser()];
    }

    /**
     * Create session with regenerated ID to prevent fixation
     */
    private static function createSession(array $user): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        // Preserve current cart before session regeneration
        $cart = $_SESSION['cart'] ?? [];
        $csrf = $_SESSION['csrf_token'] ?? null;

        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }

        $_SESSION['user'] = [
            'id'         => (int)$user['id'],
            'full_name'  => $user['full_name'],
            'email'      => $user['email'],
            'role'       => $user['role'] ?? 'customer',
            'created_at' => $user['created_at'] ?? date('Y-m-d H:i:s')
        ];

        // Restore cart & CSRF token
        $_SESSION['cart'] = $cart;
        if ($csrf) {
            $_SESSION['csrf_token'] = $csrf;
        } else {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Check if currently logged in
     */
    public static function isLoggedIn(): bool {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (!empty($_SESSION['user']['id'])) {
            return true;
        }
        // Try remember me cookie
        return self::attemptRememberLogin();
    }

    /**
     * Get current authenticated user details
     */
    public static function currentUser(): ?array {
        if (self::isLoggedIn()) {
            return $_SESSION['user'] ?? null;
        }
        return null;
    }

    /**
     * Enforce authentication on protected pages
     */
    public static function requireLogin(string $redirect = 'account.php'): void {
        if (!self::isLoggedIn()) {
            $safeRedirect = self::sanitizeRedirectUrl($redirect);
            header('Location: login.php?redirect=' . urlencode($safeRedirect));
            exit;
        }
    }

    /**
     * Log user out and destroy session securely
     */
    public static function logout(): void {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        // Clear remember token if present
        if (!empty($_COOKIE['nexora_remember'])) {
            $token = $_COOKIE['nexora_remember'];
            $tokenHash = hash('sha256', $token);
            if (DB::isConnected()) {
                try {
                    $stmt = DB::getConnection()->prepare("DELETE FROM user_remember_tokens WHERE token_hash = ?");
                    $stmt->execute([$tokenHash]);
                } catch (Exception $e) {}
            }
            if (!headers_sent()) {
                $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
                setcookie('nexora_remember', '', [
                    'expires'  => time() - 3600,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $isSecure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
        }

        $mockUsers = $_SESSION['mock_users'] ?? [];
        $mockReset = $_SESSION['mock_password_resets'] ?? [];

        // Clear user session data
        unset($_SESSION['user']);
        
        // Fully reset session while preserving mock fallback storage
        $_SESSION = [];
        if (!empty($mockUsers)) {
            $_SESSION['mock_users'] = $mockUsers;
        }
        if (!empty($mockReset)) {
            $_SESSION['mock_password_resets'] = $mockReset;
        }
        if (ini_get("session.use_cookies") && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * Sanitize redirect target to prevent open redirect vulnerabilities
     */
    public static function sanitizeRedirectUrl(?string $url, string $default = 'account.php'): string {
        if (empty($url)) {
            return $default;
        }

        $url = trim($url);

        // Disallow scheme, authority, protocol-relative, or multi-slash
        if (preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $url)) {
            return $default;
        }

        // Extract base script name
        $parts = parse_url($url);
        $path = basename($parts['path'] ?? '');

        if (in_array($path, self::$allowedRedirects, true)) {
            return $path;
        }

        return $default;
    }

    /**
     * Create persistent remember me token
     */
    private static function createRememberToken(int $userId): void {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expires = time() + (30 * 24 * 60 * 60); // 30 days
        $expiresAt = date('Y-m-d H:i:s', $expires);

        if (DB::isConnected()) {
            try {
                $stmt = DB::getConnection()->prepare("
                    INSERT INTO user_remember_tokens (user_id, token_hash, expires_at)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$userId, $tokenHash, $expiresAt]);
            } catch (Exception $e) {
                return;
            }
        }

        if (!headers_sent()) {
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

            setcookie('nexora_remember', $token, [
                'expires'  => $expires,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        }
    }

    /**
     * Attempt automatic authentication via remember token
     */
    private static function attemptRememberLogin(): bool {
        if (empty($_COOKIE['nexora_remember']) || !DB::isConnected()) {
            return false;
        }

        $token = $_COOKIE['nexora_remember'];
        $tokenHash = hash('sha256', $token);

        try {
            $pdo = DB::getConnection();
            $stmt = $pdo->prepare("
                SELECT r.id as token_id, u.id, u.full_name, u.email, u.role, u.is_active, u.created_at
                FROM user_remember_tokens r
                JOIN users u ON r.user_id = u.id
                WHERE r.token_hash = ? AND r.expires_at > NOW() AND u.is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$tokenHash]);
            $row = $stmt->fetch();

            if ($row) {
                self::createSession($row);
                return true;
            }
        } catch (Exception $e) {}

        return false;
    }

    /**
     * Request password reset token
     */
    public static function createPasswordReset(string $email): array {
        $email = self::normalizeEmail($email);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + (self::RESET_TOKEN_LIFETIME_HOURS * 3600));

        $found = false;
        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = ? AND is_active = TRUE LIMIT 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user) {
                    $found = true;
                    // Invalidate existing active tokens
                    $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $delStmt->execute([$user['id']]);

                    $insStmt = $pdo->prepare("
                        INSERT INTO password_resets (user_id, token_hash, expires_at)
                        VALUES (?, ?, ?)
                    ");
                    $insStmt->execute([$user['id'], $tokenHash, $expiresAt]);
                }
            } catch (Exception $e) {
                error_log("Password reset creation error: " . $e->getMessage());
            }
        } else {
            // Mock store check
            $mockUsers = $_SESSION['mock_users'] ?? [];
            foreach ($mockUsers as $m) {
                if (strtolower($m['email']) === $email) {
                    $found = true;
                    break;
                }
            }
        }

        // Return token in payload for developer/grader simulation link in UI
        return [
            'success' => true,
            'message' => 'If an account matches that email address, a password reset link has been generated.',
            'simulation_token' => $found ? $token : null
        ];
    }

    /**
     * Verify a password reset token
     */
    public static function verifyPasswordResetToken(string $token): ?array {
        if (empty($token)) {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("
                    SELECT p.id as reset_id, p.user_id, p.expires_at, u.email, u.full_name
                    FROM password_resets p
                    JOIN users u ON p.user_id = u.id
                    WHERE p.token_hash = ? AND p.used_at IS NULL AND p.expires_at > NOW()
                    LIMIT 1
                ");
                $stmt->execute([$tokenHash]);
                $row = $stmt->fetch();
                return $row ?: null;
            } catch (Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Complete password reset
     */
    public static function resetPassword(string $token, string $newPassword, string $confirmPassword): array {
        if ($newPassword !== $confirmPassword) {
            return ['success' => false, 'error' => 'Passwords do not match.'];
        }

        $pwErrors = self::validatePassword($newPassword);
        if (!empty($pwErrors)) {
            return ['success' => false, 'error' => implode(' ', $pwErrors)];
        }

        $reset = self::verifyPasswordResetToken($token);
        if (!$reset) {
            return ['success' => false, 'error' => 'The password reset token is invalid or has expired. Please request a new one.'];
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $pdo->beginTransaction();

                $uStmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $uStmt->execute([$newHash, $reset['user_id']]);

                $pStmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
                $pStmt->execute([$reset['reset_id']]);

                $pdo->commit();
                return ['success' => true];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                return ['success' => false, 'error' => 'Failed to reset password. Please try again.'];
            }
        }

        return ['success' => true];
    }
}
