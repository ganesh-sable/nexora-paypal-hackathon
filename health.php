<?php
/**
 * Health Check & Status Endpoint
 * Used by Render, Docker, and uptime monitoring services.
 * 
 * Returns HTTP 200 with system status without exposing sensitive credentials.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paypal_service.php';

$dbConnected = DB::isConnected();
$isConfiguredPayPal = PayPalService::isConfigured();
$hasAiKey = !empty(defined('LLM_API_KEY') ? LLM_API_KEY : '');

$response = [
    'status'      => 'ok',
    'app'         => defined('APP_NAME') ? APP_NAME : 'Nexora',
    'version'     => defined('APP_VERSION') ? APP_VERSION : '2.0.0',
    'environment' => defined('APP_ENV') ? APP_ENV : 'development',
    'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
    'services'    => [
        'database' => [
            'status' => $dbConnected ? 'connected' : 'disconnected',
            'driver' => in_array('pgsql', PDO::getAvailableDrivers()) ? 'pdo_pgsql' : 'missing'
        ],
        'ai_assistant' => [
            'provider'   => defined('LLM_PROVIDER') ? LLM_PROVIDER : 'openrouter',
            'configured' => $hasAiKey
        ],
        'payments' => [
            'gateway'    => 'paypal',
            'mode'       => defined('PAYPAL_ENVIRONMENT') ? PAYPAL_ENVIRONMENT : 'sandbox',
            'configured' => $isConfiguredPayPal
        ]
    ]
];

// Always return HTTP 200 so container orchestrators (Render, Docker, Kubernetes) know the web server is healthy
http_response_code(200);
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
