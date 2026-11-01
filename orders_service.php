<?php
/**
 * Orders, Product Deliveries and Transaction History Service
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/paypal_service.php';

class OrdersService {

    /**
     * Create a complete order with delivery tracking and transaction history
     */
    public static function createOrder(array $customerData, array $cartItems, float $totalAmount, array $paymentData, ?int $userId = null): array {
        if ($userId === null && Auth::isLoggedIn()) {
            $user = Auth::currentUser();
            $userId = isset($user['id']) ? (int)$user['id'] : null;
        }

        $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5));
        $trackingNumber = 'TRK-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $paymentData['method'] ?? 'EXP'), 0, 4)) . '-' . mt_rand(100000, 999999);
        $transactionRef = 'TXN-' . mt_rand(100000, 999999) . '-' . strtoupper(substr(md5(uniqid()), 0, 4));

        $couriers = ['FedEx Express', 'DHL Priority', 'BlueDart Express', 'UPS Worldwide', 'SpeedPost Premier'];
        $courier = $couriers[array_rand($couriers)];
        $estimatedDate = date('Y-m-d', strtotime('+3 days'));

        $orderId = null;
        $deliveryId = null;
        $transactionId = null;

        if (DB::isConnected()) {
            $pdo = DB::getConnection();
            try {
                $pdo->beginTransaction();

                // 1. Insert or find customer
                $stmt = $pdo->prepare("INSERT INTO customers (first_name, last_name, email, phone, address, city, zip, country)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
                $stmt->execute([
                    $customerData['first_name'],
                    $customerData['last_name'],
                    $customerData['email'],
                    $customerData['phone'] ?? null,
                    $customerData['address'],
                    $customerData['city'],
                    $customerData['zip'],
                    $customerData['country']
                ]);
                $customerId = $stmt->fetchColumn();

                // 2. Insert order
                $custFullName = $customerData['first_name'] . ' ' . $customerData['last_name'];
                $stmt = $pdo->prepare("INSERT INTO orders (order_number, user_id, customer_id, customer_name, customer_email, shipping_address, city, zip, country, total_amount, payment_status, order_status)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', 'Confirmed') RETURNING id");
                $stmt->execute([
                    $orderNumber,
                    $userId,
                    $customerId,
                    $custFullName,
                    $customerData['email'],
                    $customerData['address'],
                    $customerData['city'],
                    $customerData['zip'],
                    $customerData['country'],
                    $totalAmount
                ]);
                $orderId = $stmt->fetchColumn();

                // 3. Insert order items
                $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, price, quantity, line_total)
                                           VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($cartItems as $item) {
                    $itemStmt->execute([
                        $orderId,
                        $item['id'],
                        $item['name'],
                        $item['price'],
                        $item['qty'],
                        $item['line_total']
                    ]);
                }

                // 4. Insert Product Delivery record
                $delivStmt = $pdo->prepare("INSERT INTO product_deliveries (order_id, tracking_number, courier_name, delivery_status, current_location, estimated_delivery, shipping_address, notes)
                                            VALUES (?, ?, ?, 'Order Confirmed', 'Central Fulfillment Center', ?, ?, 'Order verified and packed in protective carton') RETURNING id");
                $delivStmt->execute([
                    $orderId,
                    $trackingNumber,
                    $courier,
                    $estimatedDate,
                    $customerData['address'] . ', ' . $customerData['city'] . ' ' . $customerData['zip'] . ', ' . $customerData['country']
                ]);
                $deliveryId = $delivStmt->fetchColumn();

                // 5. Insert initial delivery timeline checkpoint
                $timeStmt = $pdo->prepare("INSERT INTO delivery_timeline (delivery_id, status, location, activity_description)
                                           VALUES (?, 'Order Confirmed', 'Central Fulfillment Center', 'Payment verified and package queued for dispatch')");
                $timeStmt->execute([$deliveryId]);

                // 6. Insert Product Transaction History
                $last4 = substr(preg_replace('/\D/', '', $paymentData['card'] ?? '4242'), -4) ?: '4242';
                $txnStmt = $pdo->prepare("INSERT INTO transactions (transaction_ref, order_id, amount, currency, payment_method, payment_status, card_last4, gateway_response)
                                          VALUES (?, ?, ?, 'USD', ?, 'Success', ?, ?)");
                $txnStmt->execute([
                    $transactionRef,
                    $orderId,
                    $totalAmount,
                    $paymentData['method'] ?? 'Credit Card',
                    $last4,
                    'Payment Approved via ' . ($paymentData['method'] ?? 'Credit Card') . ' Gateway'
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Order creation failed: " . $e->getMessage());
            }
        }

        // Store latest order in session as well for immediate display
        $orderRecord = [
            'id'               => $orderId ?: mt_rand(100, 999),
            'order_id'         => $orderId ?: mt_rand(100, 999),
            'user_id'          => $userId,
            'order_number'     => $orderNumber,
            'customer_name'    => $customerData['first_name'] . ' ' . $customerData['last_name'],
            'customer_email'   => $customerData['email'],
            'tracking_number'  => $trackingNumber,
            'courier_name'     => $courier,
            'estimated_delivery' => $estimatedDate,
            'transaction_ref'  => $transactionRef,
            'payment_method'   => $paymentData['method'] ?? 'Credit Card',
            'payment_status'   => 'Paid',
            'order_status'     => 'Confirmed',
            'delivery_status'  => 'Order Confirmed',
            'total_amount'     => $totalAmount,
            'items'            => $cartItems,
            'city'             => $customerData['city'] ?? 'Springfield',
            'state'            => $customerData['state'] ?? 'OR',
            'zip'              => $customerData['zip'] ?? '97477',
            'country'          => $customerData['country'] ?? 'United States',
            'address'          => ($customerData['address'] ?? '') . ', ' . ($customerData['city'] ?? '') . (!empty($customerData['state']) ? ', ' . $customerData['state'] : '') . ' ' . ($customerData['zip'] ?? ''),
            'created_at'       => date('Y-m-d H:i:s')
        ];

        $_SESSION['last_order'] = $orderRecord;
        if (!isset($_SESSION['session_orders']) || !is_array($_SESSION['session_orders'])) {
            $_SESSION['session_orders'] = [];
        }
        $_SESSION['session_orders'][$orderNumber] = $orderRecord;

        // Also append to session history for demo when offline
        if (!isset($_SESSION['session_deliveries'])) $_SESSION['session_deliveries'] = [];
        array_unshift($_SESSION['session_deliveries'], [
            'id'                 => 99,
            'order_number'       => $orderNumber,
            'tracking_number'    => $trackingNumber,
            'courier_name'       => $courier,
            'delivery_status'    => 'Order Confirmed',
            'current_location'   => 'Central Fulfillment Center',
            'estimated_delivery' => $estimatedDate,
            'actual_delivery'    => null,
            'shipping_address'   => $customerData['address'] . ', ' . $customerData['city'] . ' ' . $customerData['zip'],
            'notes'              => 'Order verified and packed in protective carton',
            'created_at'         => date('Y-m-d H:i:s'),
            'timeline'           => [
                [
                    'status' => 'Order Confirmed',
                    'location' => 'Central Fulfillment Center',
                    'activity_description' => 'Payment verified and package queued for dispatch',
                    'event_time' => date('Y-m-d H:i:s')
                ]
            ]
        ]);

        if (!isset($_SESSION['session_transactions'])) $_SESSION['session_transactions'] = [];
        array_unshift($_SESSION['session_transactions'], [
            'id'               => 99,
            'transaction_ref'  => $transactionRef,
            'order_number'     => $orderNumber,
            'amount'           => $totalAmount,
            'currency'         => 'USD',
            'payment_method'   => $paymentData['method'] ?? 'Credit Card',
            'payment_status'   => 'Success',
            'card_last4'       => substr(preg_replace('/\D/', '', $paymentData['card'] ?? '4242'), -4) ?: '4242',
            'gateway_response' => 'Payment Approved via ' . ($paymentData['method'] ?? 'Credit Card') . ' Gateway',
            'created_at'       => date('Y-m-d H:i:s')
        ]);

        return $orderRecord;
    }

    /**
     * Create an order from a verified PayPal capture
     * Enforces replay protection by verifying unique paypal_capture_id
     */
    public static function createOrderFromPayPal(
        array $customerData,
        array $cartItems,
        float $totalAmount,
        string $paypalOrderId,
        string $paypalCaptureId,
        ?int $userId = null
    ): array {
        if ($userId === null && Auth::isLoggedIn()) {
            $user = Auth::currentUser();
            $userId = isset($user['id']) ? (int)$user['id'] : null;
        }

        // Idempotency check: verify if an order with this capture ID already exists
        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("
                    SELECT o.*, d.tracking_number, d.courier_name, d.delivery_status, t.transaction_ref, t.payment_method
                    FROM orders o
                    LEFT JOIN product_deliveries d ON d.order_id = o.id
                    LEFT JOIN transactions t ON t.order_id = o.id
                    WHERE o.paypal_capture_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$paypalCaptureId]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                    $itemStmt->execute([$existing['id']]);
                    $existing['items'] = $itemStmt->fetchAll();
                    return $existing;
                }
            } catch (Exception $e) {
                error_log("Error checking duplicate PayPal capture: " . $e->getMessage());
            }
        }

        if (!empty($_SESSION['session_orders']) && is_array($_SESSION['session_orders'])) {
            foreach ($_SESSION['session_orders'] as $ord) {
                if (!empty($ord['paypal_capture_id']) && $ord['paypal_capture_id'] === $paypalCaptureId) {
                    return $ord;
                }
            }
        }

        $orderNumber = 'ORD-' . date('Y') . '-' . strtoupper(substr(uniqid(), -5));
        $trackingNumber = 'TRK-PP-' . mt_rand(100000, 999999);
        $transactionRef = 'TXN-' . mt_rand(100000, 999999) . '-PAYPAL';

        $couriers = ['FedEx Express', 'DHL Priority', 'BlueDart Express', 'UPS Worldwide'];
        $courier = $couriers[array_rand($couriers)];
        $estimatedDate = date('Y-m-d', strtotime('+3 days'));

        $orderId = null;
        $deliveryId = null;

        if (DB::isConnected()) {
            $pdo = DB::getConnection();
            try {
                $pdo->beginTransaction();

                // 1. Insert or find customer
                $stmt = $pdo->prepare("INSERT INTO customers (first_name, last_name, email, phone, address, city, zip, country)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id");
                $stmt->execute([
                    $customerData['first_name'] ?? 'Guest',
                    $customerData['last_name'] ?? 'Buyer',
                    $customerData['email'] ?? 'buyer@example.com',
                    $customerData['phone'] ?? null,
                    $customerData['address'] ?? '123 Market St',
                    $customerData['city'] ?? 'San Jose',
                    $customerData['zip'] ?? '95131',
                    $customerData['country'] ?? 'United States'
                ]);
                $customerId = $stmt->fetchColumn();

                // 2. Insert order with PayPal columns
                $custFullName = ($customerData['first_name'] ?? 'Guest') . ' ' . ($customerData['last_name'] ?? 'Buyer');
                $stmt = $pdo->prepare("INSERT INTO orders
                    (order_number, user_id, customer_id, customer_name, customer_email, shipping_address, city, zip, country, total_amount, payment_status, order_status, paypal_order_id, paypal_capture_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', 'Confirmed', ?, ?) RETURNING id");
                $stmt->execute([
                    $orderNumber,
                    $userId,
                    $customerId,
                    $custFullName,
                    $customerData['email'] ?? 'buyer@example.com',
                    $customerData['address'] ?? '123 Market St',
                    $customerData['city'] ?? 'San Jose',
                    $customerData['zip'] ?? '95131',
                    $customerData['country'] ?? 'United States',
                    $totalAmount,
                    $paypalOrderId,
                    $paypalCaptureId
                ]);
                $orderId = $stmt->fetchColumn();

                // 3. Insert order items
                $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, price, quantity, line_total)
                                           VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($cartItems as $item) {
                    $itemStmt->execute([
                        $orderId,
                        $item['id'],
                        $item['name'],
                        $item['price'],
                        $item['qty'],
                        $item['line_total']
                    ]);
                }

                // 4. Insert delivery
                $delivStmt = $pdo->prepare("INSERT INTO product_deliveries (order_id, tracking_number, courier_name, delivery_status, current_location, estimated_delivery, shipping_address, notes)
                                            VALUES (?, ?, ?, 'Order Confirmed', 'Central Fulfillment Center', ?, ?, 'Paid via PayPal Sandbox. Order verified and queued for dispatch') RETURNING id");
                $delivStmt->execute([
                    $orderId,
                    $trackingNumber,
                    $courier,
                    $estimatedDate,
                    ($customerData['address'] ?? '') . ', ' . ($customerData['city'] ?? '') . ' ' . ($customerData['zip'] ?? '')
                ]);
                $deliveryId = $delivStmt->fetchColumn();

                // 5. Insert timeline
                $timeStmt = $pdo->prepare("INSERT INTO delivery_timeline (delivery_id, status, location, activity_description)
                                           VALUES (?, 'Order Confirmed', 'Central Fulfillment Center', 'Payment verified via PayPal Sandbox and package queued for dispatch')");
                $timeStmt->execute([$deliveryId]);

                // 6. Insert transaction
                $txnStmt = $pdo->prepare("INSERT INTO transactions (transaction_ref, order_id, amount, currency, payment_method, payment_status, card_last4, gateway_response)
                                          VALUES (?, ?, ?, 'USD', 'PayPal', 'Success', 'N/A', ?)");
                $txnStmt->execute([
                    $transactionRef,
                    $orderId,
                    $totalAmount,
                    'PayPal Capture ID: ' . $paypalCaptureId
                ]);

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("PayPal order creation failed: " . $e->getMessage());
            }
        }

        $orderRecord = [
            'id'               => $orderId ?: mt_rand(100, 999),
            'order_id'         => $orderId ?: mt_rand(100, 999),
            'user_id'          => $userId,
            'order_number'     => $orderNumber,
            'customer_name'    => ($customerData['first_name'] ?? 'Guest') . ' ' . ($customerData['last_name'] ?? 'Buyer'),
            'customer_email'   => $customerData['email'] ?? 'buyer@example.com',
            'tracking_number'  => $trackingNumber,
            'courier_name'     => $courier,
            'estimated_delivery' => $estimatedDate,
            'transaction_ref'  => $transactionRef,
            'payment_method'   => 'PayPal',
            'payment_status'   => 'Paid',
            'order_status'     => 'Confirmed',
            'delivery_status'  => 'Order Confirmed',
            'paypal_order_id'  => $paypalOrderId,
            'paypal_capture_id'=> $paypalCaptureId,
            'total_amount'     => $totalAmount,
            'items'            => $cartItems,
            'city'             => $customerData['city'] ?? 'Springfield',
            'state'            => $customerData['state'] ?? 'OR',
            'zip'              => $customerData['zip'] ?? '97477',
            'country'          => $customerData['country'] ?? 'United States',
            'address'          => ($customerData['address'] ?? '') . ', ' . ($customerData['city'] ?? '') . (!empty($customerData['state']) ? ', ' . $customerData['state'] : '') . ' ' . ($customerData['zip'] ?? ''),
            'created_at'       => date('Y-m-d H:i:s')
        ];

        $_SESSION['last_order'] = $orderRecord;
        if (!isset($_SESSION['session_orders']) || !is_array($_SESSION['session_orders'])) {
            $_SESSION['session_orders'] = [];
        }
        $_SESSION['session_orders'][$orderNumber] = $orderRecord;

        if (!isset($_SESSION['session_deliveries'])) $_SESSION['session_deliveries'] = [];
        array_unshift($_SESSION['session_deliveries'], [
            'id'                 => 99,
            'order_number'       => $orderNumber,
            'tracking_number'    => $trackingNumber,
            'courier_name'       => $courier,
            'delivery_status'    => 'Order Confirmed',
            'current_location'   => 'Central Fulfillment Center',
            'estimated_delivery' => $estimatedDate,
            'actual_delivery'    => null,
            'shipping_address'   => ($customerData['address'] ?? '') . ', ' . ($customerData['city'] ?? '') . ' ' . ($customerData['zip'] ?? ''),
            'notes'              => 'Paid via PayPal Sandbox. Order verified and queued for dispatch',
            'created_at'         => date('Y-m-d H:i:s'),
            'timeline'           => [
                [
                    'status' => 'Order Confirmed',
                    'location' => 'Central Fulfillment Center',
                    'activity_description' => 'Payment verified via PayPal Sandbox and package queued for dispatch',
                    'event_time' => date('Y-m-d H:i:s')
                ]
            ]
        ]);

        if (!isset($_SESSION['session_transactions'])) $_SESSION['session_transactions'] = [];
        array_unshift($_SESSION['session_transactions'], [
            'id'               => 99,
            'transaction_ref'  => $transactionRef,
            'order_number'     => $orderNumber,
            'amount'           => $totalAmount,
            'currency'         => 'USD',
            'payment_method'   => 'PayPal',
            'payment_status'   => 'Success',
            'card_last4'       => 'N/A',
            'gateway_response' => 'PayPal Capture ID: ' . $paypalCaptureId,
            'created_at'       => date('Y-m-d H:i:s')
        ]);

        return $orderRecord;
    }

    /**
     * Get all product deliveries
     */
    public static function getDeliveries(?string $status = null, ?string $search = null): array {
        if (DB::isConnected()) {
            try {
                $sql = "SELECT d.*, o.order_number, o.customer_name, o.total_amount 
                        FROM product_deliveries d 
                        JOIN orders o ON d.order_id = o.id 
                        WHERE 1=1";
                $params = [];

                if ($status && $status !== 'all') {
                    $sql .= " AND LOWER(d.delivery_status) = LOWER(?)";
                    $params[] = $status;
                }

                if ($search) {
                    $sql .= " AND (d.tracking_number ILIKE ? OR o.order_number ILIKE ? OR o.customer_name ILIKE ?)";
                    $kw = '%' . trim($search) . '%';
                    $params[] = $kw;
                    $params[] = $kw;
                    $params[] = $kw;
                }

                $sql .= " ORDER BY d.id DESC";
                return DB::fetchAll($sql, $params);
            } catch (Exception $e) {
                // fallback
            }
        }

        // Fallback demo deliveries
        $demo = [
            [
                'id' => 1,
                'order_number' => 'ORD-2026-9001',
                'tracking_number' => 'TRK-FEDEX-882910',
                'courier_name' => 'FedEx Priority',
                'delivery_status' => 'Delivered',
                'current_location' => 'Springfield, OR (Front Porch)',
                'estimated_delivery' => date('Y-m-d', strtotime('-1 day')),
                'actual_delivery' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'shipping_address' => '742 Evergreen Terrace, Springfield, OR 97477',
                'notes' => 'Package safely handed to resident / left at porch',
                'customer_name' => 'Alice Morgan',
                'total_amount' => 939.98,
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))
            ],
            [
                'id' => 2,
                'order_number' => 'ORD-2026-9002',
                'tracking_number' => 'TRK-DHL-449182',
                'courier_name' => 'DHL Express',
                'delivery_status' => 'Out for Delivery',
                'current_location' => 'Local Distribution Hub, Bikini Bottom',
                'estimated_delivery' => date('Y-m-d'),
                'actual_delivery' => null,
                'shipping_address' => '124 Conch Street, Bikini Bottom 96970',
                'notes' => 'Driver out for delivery in delivery van #402',
                'customer_name' => 'David Kim',
                'total_amount' => 1189.98,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ],
            [
                'id' => 3,
                'order_number' => 'ORD-2026-9003',
                'tracking_number' => 'TRK-BLUEDART-551923',
                'courier_name' => 'BlueDart Express',
                'delivery_status' => 'In Transit',
                'current_location' => 'Central Hub, New Delhi',
                'estimated_delivery' => date('Y-m-d', strtotime('+2 days')),
                'actual_delivery' => null,
                'shipping_address' => 'Plot 42 Cyber City Sector 29, Gurugram 122002',
                'notes' => 'Flight arrived at sorting facility',
                'customer_name' => 'Priya Sharma',
                'total_amount' => 189.98,
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ],
            [
                'id' => 4,
                'order_number' => 'ORD-2026-9004',
                'tracking_number' => 'TRK-UPS-772911',
                'courier_name' => 'UPS Worldwide',
                'delivery_status' => 'Processing',
                'current_location' => 'Sorting Facility, London Heathrow',
                'estimated_delivery' => date('Y-m-d', strtotime('+3 days')),
                'actual_delivery' => null,
                'shipping_address' => '10 Downing Way, Flat 4B, London SW1A 2AA',
                'notes' => 'Customs cleared, parcel being prepared for dispatch',
                'customer_name' => 'Liam Smith',
                'total_amount' => 564.98,
                'created_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))
            ],
            [
                'id' => 5,
                'order_number' => 'ORD-2026-9005',
                'tracking_number' => 'TRK-CANADAPOST-991204',
                'courier_name' => 'Canada Post Xpresspost',
                'delivery_status' => 'Order Confirmed',
                'current_location' => 'Mississauga ON Distribution Hub',
                'estimated_delivery' => date('Y-m-d', strtotime('+4 days')),
                'actual_delivery' => null,
                'shipping_address' => '88 Queen Street West, Toronto, ON M5H 2N2',
                'notes' => 'Fulfillment center picked order items',
                'customer_name' => 'Elena Rostova',
                'total_amount' => 129.99,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ]
        ];

        // Merge session deliveries if any
        if (!empty($_SESSION['session_deliveries'])) {
            $demo = array_merge($_SESSION['session_deliveries'], $demo);
        }

        if ($status && $status !== 'all') {
            $demo = array_filter($demo, fn($d) => strtolower($d['delivery_status']) === strtolower($status));
        }

        if ($search) {
            $kw = strtolower(trim($search));
            $demo = array_filter($demo, function($d) use ($kw) {
                return str_contains(strtolower($d['tracking_number']), $kw) ||
                       str_contains(strtolower($d['order_number']), $kw) ||
                       str_contains(strtolower($d['customer_name'] ?? ''), $kw);
            });
        }

        return $demo;
    }

    /**
     * Get single delivery with full timeline
     */
    public static function getDeliveryByTracking(string $trackingOrOrder): ?array {
        $term = trim($trackingOrOrder);
        if (empty($term)) return null;

        if (DB::isConnected()) {
            try {
                $delivery = DB::fetchOne("SELECT d.*, o.order_number, o.customer_name, o.customer_email, o.total_amount, o.created_at as order_date 
                                          FROM product_deliveries d 
                                          JOIN orders o ON d.order_id = o.id 
                                          WHERE d.tracking_number ILIKE ? OR o.order_number ILIKE ?", [$term, $term]);

                if ($delivery) {
                    $delivery['timeline'] = DB::fetchAll("SELECT * FROM delivery_timeline WHERE delivery_id = ? ORDER BY event_time ASC", [$delivery['id']]);
                    $delivery['items'] = DB::fetchAll("SELECT * FROM order_items WHERE order_id = ?", [$delivery['order_id']]);
                    return $delivery;
                }
            } catch (Exception $e) {
                // fallback
            }
        }

        // Search in fallback
        $all = self::getDeliveries();
        foreach ($all as $d) {
            if (strcasecmp($d['tracking_number'], $term) === 0 || strcasecmp($d['order_number'], $term) === 0) {
                if (empty($d['timeline'])) {
                    $d['timeline'] = [
                        ['status' => 'Order Confirmed', 'location' => 'Central Fulfillment Center', 'activity_description' => 'Order processed and packaged', 'event_time' => $d['created_at']],
                        ['status' => 'Dispatched', 'location' => 'Regional Hub', 'activity_description' => 'Handed over to carrier ' . $d['courier_name'], 'event_time' => date('Y-m-d H:i:s', strtotime($d['created_at'] . ' +1 day'))],
                        ['status' => $d['delivery_status'], 'location' => $d['current_location'], 'activity_description' => $d['notes'], 'event_time' => date('Y-m-d H:i:s')]
                    ];
                }
                return $d;
            }
        }

        return null;
    }

    /**
     * Get all transaction history
     */
    public static function getTransactions(?string $status = null, ?string $search = null): array {
        if (DB::isConnected()) {
            try {
                $sql = "SELECT t.*, o.order_number, o.customer_name, o.customer_email 
                        FROM transactions t 
                        JOIN orders o ON t.order_id = o.id 
                        WHERE 1=1";
                $params = [];

                if ($status && $status !== 'all') {
                    $sql .= " AND LOWER(t.payment_status) = LOWER(?)";
                    $params[] = $status;
                }

                if ($search) {
                    $sql .= " AND (t.transaction_ref ILIKE ? OR o.order_number ILIKE ? OR o.customer_name ILIKE ? OR t.payment_method ILIKE ?)";
                    $kw = '%' . trim($search) . '%';
                    $params[] = $kw;
                    $params[] = $kw;
                    $params[] = $kw;
                    $params[] = $kw;
                }

                $sql .= " ORDER BY t.id DESC";
                return DB::fetchAll($sql, $params);
            } catch (Exception $e) {
                // fallback
            }
        }

        // Fallback demo transactions
        $demo = [
            [
                'id' => 1,
                'transaction_ref' => 'TXN-982341-VISA',
                'order_number' => 'ORD-2026-9001',
                'amount' => 939.98,
                'currency' => 'USD',
                'payment_method' => 'Credit Card',
                'payment_status' => 'Success',
                'card_last4' => '4242',
                'gateway_response' => 'Stripe Charge Approved: ch_3N5pqr2eZvKYlo2C',
                'customer_name' => 'Alice Morgan',
                'customer_email' => 'alice.morgan@example.com',
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))
            ],
            [
                'id' => 2,
                'transaction_ref' => 'TXN-481920-PAYPAL',
                'order_number' => 'ORD-2026-9002',
                'amount' => 1189.98,
                'currency' => 'USD',
                'payment_method' => 'PayPal',
                'payment_status' => 'Success',
                'card_last4' => 'N/A',
                'gateway_response' => 'PayPal Capture ID: PAYID-MN7Y4WA9',
                'customer_name' => 'David Kim',
                'customer_email' => 'david.kim@example.com',
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ],
            [
                'id' => 3,
                'transaction_ref' => 'TXN-773812-UPI',
                'order_number' => 'ORD-2026-9003',
                'amount' => 189.98,
                'currency' => 'USD',
                'payment_method' => 'UPI / Net Banking',
                'payment_status' => 'Success',
                'card_last4' => 'N/A',
                'gateway_response' => 'UPI Reference: UPI/2026/9912048921',
                'customer_name' => 'Priya Sharma',
                'customer_email' => 'priya.sharma@example.com',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ],
            [
                'id' => 4,
                'transaction_ref' => 'TXN-391823-MASTERCARD',
                'order_number' => 'ORD-2026-9004',
                'amount' => 564.98,
                'currency' => 'USD',
                'payment_method' => 'Credit Card',
                'payment_status' => 'Success',
                'card_last4' => '8812',
                'gateway_response' => 'MasterCard 3D-Secure Authenticated: ch_7K3xyt',
                'customer_name' => 'Liam Smith',
                'customer_email' => 'liam.smith@example.co.uk',
                'created_at' => date('Y-m-d H:i:s', strtotime('-6 hours'))
            ],
            [
                'id' => 5,
                'transaction_ref' => 'TXN-661928-APPLEPAY',
                'order_number' => 'ORD-2026-9005',
                'amount' => 129.99,
                'currency' => 'USD',
                'payment_method' => 'Apple Pay',
                'payment_status' => 'Success',
                'card_last4' => '3019',
                'gateway_response' => 'Apple Pay Token Authorized: token_ap_88912',
                'customer_name' => 'Elena Rostova',
                'customer_email' => 'elena.rostova@example.com',
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ],
            [
                'id' => 6,
                'transaction_ref' => 'TXN-110928-FAILED',
                'order_number' => 'ORD-2026-9001',
                'amount' => 939.98,
                'currency' => 'USD',
                'payment_method' => 'Credit Card',
                'payment_status' => 'Failed',
                'card_last4' => '1004',
                'gateway_response' => 'Card Declined: Insufficient Funds (Simulated)',
                'customer_name' => 'Alice Morgan',
                'customer_email' => 'alice.morgan@example.com',
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days 10 minutes'))
            ]
        ];

        // Merge session transactions
        if (!empty($_SESSION['session_transactions'])) {
            $demo = array_merge($_SESSION['session_transactions'], $demo);
        }

        if ($status && $status !== 'all') {
            $demo = array_filter($demo, fn($t) => strtolower($t['payment_status']) === strtolower($status));
        }

        if ($search) {
            $kw = strtolower(trim($search));
            $demo = array_filter($demo, function($t) use ($kw) {
                return str_contains(strtolower($t['transaction_ref']), $kw) ||
                       str_contains(strtolower($t['order_number']), $kw) ||
                       str_contains(strtolower($t['customer_name'] ?? ''), $kw) ||
                       str_contains(strtolower($t['payment_method'] ?? ''), $kw);
            });
        }

        return $demo;
    }

    /**
     * Get single order by order number with delivery and transaction details
     */
    public static function getOrderByNumber(string $orderNumber): ?array {
        $orderNumber = trim($orderNumber);
        if (empty($orderNumber)) return null;

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("
                    SELECT o.*, d.tracking_number, d.courier_name, d.delivery_status, d.current_location, d.estimated_delivery, t.transaction_ref, t.payment_method
                    FROM orders o
                    LEFT JOIN product_deliveries d ON d.order_id = o.id
                    LEFT JOIN transactions t ON t.order_id = o.id
                    WHERE o.order_number = ?
                    LIMIT 1
                ");
                $stmt->execute([$orderNumber]);
                $order = $stmt->fetch();
                if ($order) {
                    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                    $itemStmt->execute([$order['id']]);
                    $order['items'] = $itemStmt->fetchAll();
                    return $order;
                }
            } catch (Exception $e) {
                error_log("Error fetching order by number: " . $e->getMessage());
            }
        }

        if (!empty($_SESSION['session_orders'][$orderNumber])) {
            return $_SESSION['session_orders'][$orderNumber];
        }

        if (!empty($_SESSION['last_order']) && ($_SESSION['last_order']['order_number'] ?? '') === $orderNumber) {
            return $_SESSION['last_order'];
        }

        return null;
    }

    /**
     * Get single order by numeric ID
     */
    public static function getOrderById(int $orderId): ?array {
        if ($orderId <= 0) return null;

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("
                    SELECT o.*, d.tracking_number, d.courier_name, d.delivery_status, d.current_location, d.estimated_delivery, t.transaction_ref, t.payment_method
                    FROM orders o
                    LEFT JOIN product_deliveries d ON d.order_id = o.id
                    LEFT JOIN transactions t ON t.order_id = o.id
                    WHERE o.id = ?
                    LIMIT 1
                ");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();
                if ($order) {
                    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                    $itemStmt->execute([$order['id']]);
                    $order['items'] = $itemStmt->fetchAll();
                    return $order;
                }
            } catch (Exception $e) {
                error_log("Error fetching order by ID: " . $e->getMessage());
            }
        }

        if (!empty($_SESSION['session_orders'])) {
            foreach ($_SESSION['session_orders'] as $ord) {
                if ((int)($ord['id'] ?? ($ord['order_id'] ?? 0)) === $orderId) {
                    return $ord;
                }
            }
        }

        if (!empty($_SESSION['last_order']) && (int)($_SESSION['last_order']['order_id'] ?? 0) === $orderId) {
            return $_SESSION['last_order'];
        }

        return null;
    }

    /**
     * Check if an order is eligible for cancellation
     */
    public static function isOrderEligibleForCancellation(array $order): array {
        $orderStatus = strtolower(trim($order['order_status'] ?? ''));
        $deliveryStatus = strtolower(trim($order['delivery_status'] ?? ''));

        if ($orderStatus === 'cancelled' || $deliveryStatus === 'cancelled') {
            return [
                'eligible' => false,
                'reason'   => 'This order has already been cancelled.'
            ];
        }

        if ($orderStatus === 'completed' || $deliveryStatus === 'delivered') {
            return [
                'eligible' => false,
                'reason'   => 'Delivered or completed orders cannot be cancelled online. Please initiate a return request.'
            ];
        }

        $dispatchedStatuses = ['shipped', 'in transit', 'out for delivery', 'dispatched'];
        if (in_array($deliveryStatus, $dispatchedStatuses, true)) {
            return [
                'eligible' => false,
                'reason'   => 'This order has already been dispatched/shipped and cannot be cancelled online. Please refuse package on delivery or contact customer support.'
            ];
        }

        // Eligible if pending or confirmed or processing
        $eligibleOrderStatuses = ['pending', 'confirmed', 'processing', 'order confirmed'];
        if (in_array($orderStatus, $eligibleOrderStatuses, true) || empty($orderStatus)) {
            return [
                'eligible' => true,
                'reason'   => 'Order is eligible for cancellation.'
            ];
        }

        return [
            'eligible' => false,
            'reason'   => 'Order status (' . ($order['order_status'] ?? 'Unknown') . ') does not permit cancellation.'
        ];
    }

    /**
     * Cancel an eligible order, update delivery & timeline, and refund PayPal if applicable
     */
    public static function cancelOrder($orderIdentifier, int $userId, string $cancelReason = '', bool $isAdmin = false): array {
        $cancelReason = trim($cancelReason) ?: 'Customer requested cancellation';

        $order = null;
        if (is_numeric($orderIdentifier)) {
            $order = self::getOrderById((int)$orderIdentifier);
        }
        if (!$order && is_string($orderIdentifier)) {
            $order = self::getOrderByNumber($orderIdentifier);
        }

        if (!$order) {
            return [
                'success' => false,
                'error'   => 'Order not found.'
            ];
        }

        // Authorization check: User must own the order unless admin
        if (!$isAdmin && (int)($order['user_id'] ?? 0) !== $userId) {
            return [
                'success' => false,
                'error'   => 'Unauthorized: You can only cancel your own orders.'
            ];
        }

        // Eligibility check
        $eligibility = self::isOrderEligibleForCancellation($order);
        if (!$eligibility['eligible']) {
            return [
                'success' => false,
                'error'   => $eligibility['reason']
            ];
        }

        $orderNumber = $order['order_number'];
        $orderId = $order['id'] ?? ($order['order_id'] ?? null);
        $totalAmount = (float)($order['total_amount'] ?? 0);
        $isPaid = (strtolower($order['payment_status'] ?? '') === 'paid');
        $hasPayPalCapture = !empty($order['paypal_capture_id']);

        $refundId = null;
        $refundStatus = null;
        $refundedAmount = 0.0;
        $newPaymentStatus = 'Cancelled';

        // Process refund if paid
        if ($isPaid) {
            if ($hasPayPalCapture) {
                try {
                    $refundRes = PayPalService::refundCapture(
                        $order['paypal_capture_id'],
                        $totalAmount,
                        'Order ' . $orderNumber . ' cancelled: ' . $cancelReason
                    );
                    $refundId = $refundRes['refund_id'] ?? ('REF-PP-' . mt_rand(100000, 999999));
                    $refundStatus = $refundRes['status'] ?? 'COMPLETED';
                    $refundedAmount = $totalAmount;
                    $newPaymentStatus = 'Refunded';
                } catch (Exception $e) {
                    error_log("PayPal refund failed during cancellation: " . $e->getMessage());
                    return [
                        'success' => false,
                        'error'   => 'Payment refund via PayPal failed: ' . $e->getMessage() . '. Order was not cancelled.'
                    ];
                }
            } else {
                // Card / other payment simulation
                $refundId = 'REF-GEN-' . mt_rand(100000, 999999);
                $refundStatus = 'COMPLETED';
                $refundedAmount = $totalAmount;
                $newPaymentStatus = 'Refunded';
            }
        }

        $cancelledAt = date('Y-m-d H:i:s');

        // Update database if connected
        if (DB::isConnected() && $orderId) {
            $pdo = DB::getConnection();
            try {
                $pdo->beginTransaction();

                // 1. Update order
                $stmt = $pdo->prepare("
                    UPDATE orders
                    SET order_status = 'Cancelled',
                        payment_status = ?,
                        cancelled_at = ?,
                        cancel_reason = ?,
                        refund_id = ?,
                        refund_status = ?,
                        refunded_amount = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $newPaymentStatus,
                    $cancelledAt,
                    $cancelReason,
                    $refundId,
                    $refundStatus,
                    $refundedAmount,
                    $orderId
                ]);

                // 2. Update delivery
                $stmt = $pdo->prepare("
                    UPDATE product_deliveries
                    SET delivery_status = 'Cancelled',
                        notes = ?
                    WHERE order_id = ?
                    RETURNING id
                ");
                $stmt->execute(['Order cancelled by customer. Reason: ' . $cancelReason, $orderId]);
                $delivId = $stmt->fetchColumn();

                // 3. Insert timeline entry
                if ($delivId) {
                    $stmt = $pdo->prepare("
                        INSERT INTO delivery_timeline (delivery_id, status, location, activity_description, event_time)
                        VALUES (?, 'Cancelled', 'Fulfillment Center', ?, ?)
                    ");
                    $stmt->execute([
                        $delivId,
                        'Order cancelled by customer. Reason: ' . $cancelReason,
                        $cancelledAt
                    ]);
                }

                // 4. Insert refund transaction if refunded
                if ($refundId) {
                    $txnRef = 'TXN-' . mt_rand(100000, 999999) . '-REFUND';
                    $stmt = $pdo->prepare("
                        INSERT INTO transactions (transaction_ref, order_id, amount, currency, payment_method, payment_status, card_last4, gateway_response, created_at)
                        VALUES (?, ?, ?, 'USD', ?, 'Refunded', 'N/A', ?, ?)
                    ");
                    $stmt->execute([
                        $txnRef,
                        $orderId,
                        $refundedAmount,
                        $order['payment_method'] ?? 'PayPal',
                        'Refund issued: ' . $refundId . ' (' . $cancelReason . ')',
                        $cancelledAt
                    ]);
                }

                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("DB update on cancellation failed: " . $e->getMessage());
            }
        }

        // Update session storage
        if (isset($_SESSION['session_orders'][$orderNumber])) {
            $_SESSION['session_orders'][$orderNumber]['order_status'] = 'Cancelled';
            $_SESSION['session_orders'][$orderNumber]['delivery_status'] = 'Cancelled';
            $_SESSION['session_orders'][$orderNumber]['payment_status'] = $newPaymentStatus;
            $_SESSION['session_orders'][$orderNumber]['cancelled_at'] = $cancelledAt;
            $_SESSION['session_orders'][$orderNumber]['cancel_reason'] = $cancelReason;
            $_SESSION['session_orders'][$orderNumber]['refund_id'] = $refundId;
            $_SESSION['session_orders'][$orderNumber]['refund_status'] = $refundStatus;
            $_SESSION['session_orders'][$orderNumber]['refunded_amount'] = $refundedAmount;
        }

        if (isset($_SESSION['last_order']) && ($_SESSION['last_order']['order_number'] ?? '') === $orderNumber) {
            $_SESSION['last_order']['order_status'] = 'Cancelled';
            $_SESSION['last_order']['delivery_status'] = 'Cancelled';
            $_SESSION['last_order']['payment_status'] = $newPaymentStatus;
            $_SESSION['last_order']['cancelled_at'] = $cancelledAt;
            $_SESSION['last_order']['cancel_reason'] = $cancelReason;
            $_SESSION['last_order']['refund_id'] = $refundId;
            $_SESSION['last_order']['refund_status'] = $refundStatus;
            $_SESSION['last_order']['refunded_amount'] = $refundedAmount;
        }

        if (!empty($_SESSION['session_deliveries'])) {
            foreach ($_SESSION['session_deliveries'] as &$del) {
                if (($del['order_number'] ?? '') === $orderNumber) {
                    $del['delivery_status'] = 'Cancelled';
                    $del['notes'] = 'Order cancelled by customer: ' . $cancelReason;
                    if (!isset($del['timeline'])) $del['timeline'] = [];
                    array_unshift($del['timeline'], [
                        'status' => 'Cancelled',
                        'location' => 'Fulfillment Center',
                        'activity_description' => 'Order cancelled by customer. Reason: ' . $cancelReason,
                        'event_time' => $cancelledAt
                    ]);
                }
            }
        }

        if ($refundId) {
            if (!isset($_SESSION['session_transactions'])) $_SESSION['session_transactions'] = [];
            array_unshift($_SESSION['session_transactions'], [
                'id'               => 999,
                'transaction_ref'  => 'TXN-' . mt_rand(100000, 999999) . '-REFUND',
                'order_number'     => $orderNumber,
                'amount'           => $refundedAmount,
                'currency'         => 'USD',
                'payment_method'   => $order['payment_method'] ?? 'PayPal',
                'payment_status'   => 'Refunded',
                'card_last4'       => 'N/A',
                'gateway_response' => 'Refund processed: ' . $refundId . ' (' . $cancelReason . ')',
                'created_at'       => $cancelledAt
            ]);
        }

        return [
            'success'         => true,
            'message'         => 'Order ' . $orderNumber . ' was successfully cancelled' . ($refundId ? " and $" . number_format($totalAmount, 2) . " was refunded via " . ($order['payment_method'] ?? 'payment method') . '.' : '.'),
            'order_number'    => $orderNumber,
            'refund_id'       => $refundId,
            'refunded_amount' => $refundedAmount,
            'refund_status'   => $refundStatus
        ];
    }

    /**
     * Get orders specifically belonging to a given user ID
     */
    public static function getUserOrders(int $userId): array {
        $orders = [];
        $orderNumbers = [];

        if (DB::isConnected()) {
            try {
                $pdo = DB::getConnection();
                $stmt = $pdo->prepare("
                    SELECT o.*, d.tracking_number, d.courier_name, d.delivery_status, t.transaction_ref, t.payment_method
                    FROM orders o
                    LEFT JOIN product_deliveries d ON d.order_id = o.id
                    LEFT JOIN transactions t ON t.order_id = o.id
                    WHERE o.user_id = ?
                    ORDER BY o.id DESC
                ");
                $stmt->execute([$userId]);
                $dbOrders = $stmt->fetchAll();

                foreach ($dbOrders as $ord) {
                    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
                    $itemStmt->execute([$ord['id']]);
                    $ord['items'] = $itemStmt->fetchAll();
                    $orders[] = $ord;
                    $orderNumbers[] = $ord['order_number'];
                }
            } catch (Exception $e) {
                error_log("Error fetching user orders: " . $e->getMessage());
            }
        }

        // Merge session orders
        if (!empty($_SESSION['session_orders']) && is_array($_SESSION['session_orders'])) {
            foreach (array_reverse($_SESSION['session_orders']) as $sessOrd) {
                if ((int)($sessOrd['user_id'] ?? 0) === $userId) {
                    if (!in_array($sessOrd['order_number'], $orderNumbers, true)) {
                        $orders[] = $sessOrd;
                        $orderNumbers[] = $sessOrd['order_number'];
                    }
                }
            }
        }

        // Fallback: check session last order
        if (!empty($_SESSION['last_order']) && (int)($_SESSION['last_order']['user_id'] ?? 0) === $userId) {
            if (!in_array($_SESSION['last_order']['order_number'], $orderNumbers, true)) {
                $orders[] = $_SESSION['last_order'];
            }
        }

        return $orders;
    }
}
