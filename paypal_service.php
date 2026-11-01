<?php
/**
 * Nexora - PayPal REST API Service
 * 
 * Handles PayPal Checkout v2 integration:
 * - OAuth 2.0 Client Credentials Authentication
 * - Order Creation (/v2/checkout/orders)
 * - Order Capture (/v2/checkout/orders/{id}/capture)
 * - Payment Refund (/v2/payments/captures/{id}/refund)
 * - Error Handling & Currency Validation
 */

require_once __DIR__ . '/config.php';

class PayPalService {

    /** @var callable|null */
    private static $mockHandler = null;

    /**
     * Set a mock handler for testing or local simulation
     */
    public static function setMockHandler(?callable $handler): void {
        self::$mockHandler = $handler;
    }

    /**
     * Check if PayPal is configured with valid Client ID & Secret
     */
    public static function isConfigured(): bool {
        return !empty(PAYPAL_CLIENT_ID) && !empty(PAYPAL_CLIENT_SECRET) && PAYPAL_CLIENT_ID !== 'your_paypal_sandbox_client_id_here';
    }

    /**
     * Get active PayPal environment (sandbox or live)
     */
    public static function getEnvironment(): string {
        return defined('PAYPAL_ENVIRONMENT') ? PAYPAL_ENVIRONMENT : 'sandbox';
    }

    /**
     * Get API base URL depending on environment
     */
    public static function getBaseUrl(): string {
        return self::getEnvironment() === 'live' 
            ? 'https://api-m.paypal.com' 
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Obtain OAuth 2.0 Access Token from PayPal
     */
    public static function getAccessToken(): string {
        if (self::$mockHandler !== null) {
            $mock = (self::$mockHandler)('getAccessToken', []);
            if ($mock !== null) return $mock;
        }

        if (!self::isConfigured()) {
            throw new Exception("PayPal credentials are not configured. Please set PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET in your .env file.");
        }

        // Check cached session token
        if (!empty($_SESSION['paypal_oauth_token']) && !empty($_SESSION['paypal_token_expires_at'])) {
            if (time() < ($_SESSION['paypal_token_expires_at'] - 60)) {
                return $_SESSION['paypal_oauth_token'];
            }
        }

        $url = self::getBaseUrl() . '/v1/oauth2/token';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Accept-Language: en_US'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            throw new Exception("PayPal network connection failed: " . $err);
        }

        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $desc = $data['error_description'] ?? ($data['error'] ?? 'Authentication failed');
            throw new Exception("PayPal OAuth2 error (HTTP {$httpCode}): {$desc}");
        }

        $token = $data['access_token'];
        $expiresIn = (int)($data['expires_in'] ?? 3600);

        $_SESSION['paypal_oauth_token'] = $token;
        $_SESSION['paypal_token_expires_at'] = time() + $expiresIn;

        return $token;
    }

    /**
     * Create PayPal Order with trusted server-calculated total
     */
    /**
     * Create PayPal Order with trusted server-calculated total
     */
    public static function createOrder(float $totalAmount, $items = [], $customerData = [], string $currency = 'USD'): array {
        if (is_string($items)) {
            $currency = $items;
            $items = [];
        }
        if (!is_array($items)) {
            $items = [];
        }
        if (!is_array($customerData)) {
            $customerData = [];
        }

        if (self::$mockHandler !== null) {
            $mock = (self::$mockHandler)('createOrder', [
                'totalAmount' => $totalAmount,
                'items' => $items,
                'customerData' => $customerData,
                'currency' => $currency
            ]);
            if ($mock !== null) return $mock;
        }

        $token = self::getAccessToken();
        $formattedTotal = number_format($totalAmount, 2, '.', '');

        // Build itemized list with sanitized values if items provided
        $paypalItems = [];
        $itemTotal = 0.0;
        foreach ($items as $item) {
            $qty = (int)($item['qty'] ?? ($item['quantity'] ?? 1));
            $unitPrice = (float)($item['price'] ?? 0);
            $lineTotal = round($unitPrice * $qty, 2);
            $itemTotal += $lineTotal;

            $paypalItems[] = [
                'name' => substr((string)($item['name'] ?? $item['product_name'] ?? 'Product'), 0, 127),
                'quantity' => (string)$qty,
                'unit_amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($unitPrice, 2, '.', '')
                ]
            ];
        }

        $formattedItemTotal = number_format($itemTotal, 2, '.', '');
        if ($formattedItemTotal !== $formattedTotal) {
            $formattedItemTotal = $formattedTotal;
        }

        $purchaseUnit = [
            'reference_id' => 'nexora_' . uniqid(),
            'description'  => 'Nexora E-Commerce Order',
            'custom_id'    => 'user_' . ($customerData['user_id'] ?? 'guest'),
            'amount'       => [
                'currency_code' => $currency,
                'value'         => $formattedTotal
            ]
        ];

        if (!empty($paypalItems)) {
            $purchaseUnit['amount']['breakdown'] = [
                'item_total' => [
                    'currency_code' => $currency,
                    'value'         => $formattedItemTotal
                ]
            ];
            $purchaseUnit['items'] = $paypalItems;
        }

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                $purchaseUnit
            ],
            'application_context' => [
                'brand_name'          => 'Nexora AI',
                'landing_page'        => 'NO_PREFERENCE',
                'user_action'         => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING'
            ]
        ];

        $url = self::getBaseUrl() . '/v2/checkout/orders';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'PayPal-Request-Id: req_' . uniqid()
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            throw new Exception("PayPal Order creation failed: " . $err);
        }

        $data = json_decode((string)$response, true);
        if ($httpCode !== 201 || empty($data['id'])) {
            $msg = $data['message'] ?? ($data['details'][0]['description'] ?? 'Unable to create PayPal order');
            throw new Exception("PayPal Order API error (HTTP {$httpCode}): {$msg}");
        }

        return [
            'id'     => $data['id'],
            'status' => $data['status'] ?? 'CREATED'
        ];
    }

    /**
     * Capture PayPal Order after buyer approval
     */
    public static function captureOrder(string $paypalOrderId): array {
        if (self::$mockHandler !== null) {
            $mock = (self::$mockHandler)('captureOrder', ['orderId' => $paypalOrderId]);
            if ($mock !== null) return $mock;
        }

        $paypalOrderId = trim($paypalOrderId);
        if (empty($paypalOrderId)) {
            throw new Exception("PayPal Order ID is required for capture.");
        }

        $token = self::getAccessToken();
        $url = self::getBaseUrl() . "/v2/checkout/orders/{$paypalOrderId}/capture";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'PayPal-Request-Id: cap_' . $paypalOrderId
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            throw new Exception("PayPal capture request failed: " . $err);
        }

        $data = json_decode((string)$response, true);

        // Verification of HTTP and capture status
        if ($httpCode !== 200 && $httpCode !== 201) {
            $msg = $data['message'] ?? ($data['details'][0]['description'] ?? 'PayPal capture failed');
            throw new Exception("PayPal capture error (HTTP {$httpCode}): {$msg}");
        }

        $orderStatus = $data['status'] ?? '';
        if ($orderStatus !== 'COMPLETED') {
            throw new Exception("PayPal order status is '{$orderStatus}', expected 'COMPLETED'.");
        }

        // Extract capture record from purchase_units
        $purchaseUnit = $data['purchase_units'][0] ?? [];
        $captures = $purchaseUnit['payments']['captures'] ?? [];
        if (empty($captures)) {
            throw new Exception("No capture details returned in PayPal response.");
        }

        $capture = $captures[0];
        $captureStatus = $capture['status'] ?? '';
        if ($captureStatus !== 'COMPLETED') {
            throw new Exception("Payment capture status is '{$captureStatus}', expected 'COMPLETED'.");
        }

        $captureId = $capture['id'] ?? '';
        $amount = (float)($capture['amount']['value'] ?? 0);
        $currency = $capture['amount']['currency_code'] ?? 'USD';
        $payerEmail = $data['payer']['email_address'] ?? 'payer@paypal.com';

        return [
            'success'          => true,
            'order_id'         => $paypalOrderId,
            'capture_id'       => $captureId,
            'amount'           => $amount,
            'currency'         => $currency,
            'payer_email'      => $payerEmail,
            'status'           => $captureStatus,
            'raw_response'     => $data
        ];
    }

    /**
     * Refund a captured payment via PayPal REST API
     */
    public static function refundCapture(string $captureId, ?float $amount = null, ?string $reason = null, string $currency = 'USD'): array {
        if (self::$mockHandler !== null) {
            $mock = (self::$mockHandler)('refundCapture', [
                'captureId' => $captureId,
                'amount' => $amount,
                'reason' => $reason,
                'currency' => $currency
            ]);
            if ($mock !== null) return $mock;
        }

        $captureId = trim($captureId);
        if (empty($captureId)) {
            throw new Exception("PayPal Capture ID is required to process refund.");
        }

        $token = self::getAccessToken();
        $url = self::getBaseUrl() . "/v2/payments/captures/{$captureId}/refund";

        $payload = [];
        if ($amount !== null && $amount > 0) {
            $payload['amount'] = [
                'value' => number_format($amount, 2, '.', ''),
                'currency_code' => $currency
            ];
        }
        if (!empty($reason)) {
            $payload['note_to_payer'] = substr($reason, 0, 255);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, !empty($payload) ? json_encode($payload) : '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'PayPal-Request-Id: ref_' . $captureId . '_' . time()
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if (defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        @curl_close($ch);

        if ($err) {
            throw new Exception("PayPal refund request failed: " . $err);
        }

        $data = json_decode((string)$response, true);
        if ($httpCode !== 201 && $httpCode !== 200) {
            $msg = $data['message'] ?? ($data['details'][0]['description'] ?? 'Refund could not be processed');
            throw new Exception("PayPal refund error (HTTP {$httpCode}): {$msg}");
        }

        $refundId = $data['id'] ?? '';
        $status = $data['status'] ?? 'COMPLETED';

        return [
            'success'   => true,
            'refund_id' => $refundId,
            'status'    => $status,
            'amount'    => (float)($data['amount']['value'] ?? ($amount ?: 0)),
            'currency'  => $data['amount']['currency_code'] ?? $currency
        ];
    }
}
