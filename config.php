<?php
/**
 * Application & PostgreSQL Database Configuration
 */

// Load local environment variables from .env if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

// Database & Application Environment
define('APP_ENV', strtolower(getenv('APP_ENV') ?: 'development'));
define('APP_URL', getenv('APP_URL') ?: '');
define('PORT', getenv('PORT') ?: '10000');

// Configure error reporting based on environment
if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Database credentials & Neon connection string support
$databaseUrl = getenv('DATABASE_URL') ?: getenv('DB_URL');
$parsedSslMode = getenv('DB_SSLMODE') ?: '';

if ($databaseUrl) {
    $dbParts = parse_url($databaseUrl);
    if ($dbParts) {
        $parsedHost = $dbParts['host'] ?? 'localhost';
        $parsedPort = isset($dbParts['port']) ? (string)$dbParts['port'] : '5432';
        $parsedUser = isset($dbParts['user']) ? urldecode($dbParts['user']) : 'postgres';
        $parsedPass = isset($dbParts['pass']) ? urldecode($dbParts['pass']) : '';
        $parsedName = isset($dbParts['path']) ? ltrim($dbParts['path'], '/') : 'ecommerce_db';
        
        if (!empty($dbParts['query'])) {
            parse_str($dbParts['query'], $queryArgs);
            if (!empty($queryArgs['sslmode'])) {
                $parsedSslMode = $queryArgs['sslmode'];
            }
        }

        define('DATABASE_URL', $databaseUrl);
        define('DB_HOST', getenv('DB_HOST') ?: $parsedHost);
        define('DB_PORT', getenv('DB_PORT') ?: $parsedPort);
        define('DB_NAME', getenv('DB_NAME') ?: $parsedName);
        define('DB_USER', getenv('DB_USER') ?: $parsedUser);
        define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : $parsedPass);
        define('DB_SSLMODE', $parsedSslMode);
    } else {
        define('DATABASE_URL', $databaseUrl);
        define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
        define('DB_PORT', getenv('DB_PORT') ?: '5432');
        define('DB_NAME', getenv('DB_NAME') ?: 'ecommerce_db');
        define('DB_USER', getenv('DB_USER') ?: 'postgres');
        define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
        define('DB_SSLMODE', $parsedSslMode);
    }
} else {
    define('DATABASE_URL', '');
    define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
    define('DB_PORT', getenv('DB_PORT') ?: '5432');
    define('DB_NAME', getenv('DB_NAME') ?: 'ecommerce_db');
    define('DB_USER', getenv('DB_USER') ?: 'postgres');
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
    define('DB_SSLMODE', $parsedSslMode);
}

// AI Chatbot Configuration (OpenRouter by default)
define('OPENROUTER_API_KEY', getenv('OPENROUTER_API_KEY') ?: (getenv('LLM_API_KEY') ?: ''));
define('OPENROUTER_MODEL', getenv('OPENROUTER_MODEL') ?: (getenv('LLM_MODEL') ?: 'auto'));
define('LLM_PROVIDER', getenv('LLM_PROVIDER') ?: 'openrouter');
define('LLM_API_KEY', OPENROUTER_API_KEY ?: (getenv('GEMINI_API_KEY') ?: ''));
define('LLM_MODEL', OPENROUTER_MODEL);

// Application info
define('APP_NAME', 'Nexora');
define('APP_VERSION', '2.0.0');
define('CURRENCY_SYMBOL', '$');
define('INR_TO_USD_RATE', 83.0);

// PayPal Configuration (Sandbox & Live)
define('PAYPAL_CLIENT_ID', getenv('PAYPAL_CLIENT_ID') ?: '');
define('PAYPAL_CLIENT_SECRET', getenv('PAYPAL_CLIENT_SECRET') ?: (getenv('PAYPAL_SECRET') ?: ''));
define('PAYPAL_ENVIRONMENT', strtolower(getenv('PAYPAL_ENVIRONMENT') ?: (getenv('PAYPAL_MODE') ?: 'sandbox')));

// Session security configuration
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}
