<?php
/**
 * Orders, Product Deliveries and Transaction History Service
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/products.php';

class OrdersService {

    /**
     * Create a complete order with delivery tracking and transaction history
     */
    public static function createOrder(array $customerData, array $cartItems, float $totalAmount, array $paymentData): array {
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
                $stmt = $pdo->prepare("INSERT INTO orders (order_number, customer_id, customer_name, customer_email, shipping_address, city, zip, country, total_amount, payment_status, order_status)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Paid', 'Confirmed') RETURNING id");
                $stmt->execute([
                    $orderNumber,
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
            'order_id'         => $orderId ?: mt_rand(100, 999),
            'order_number'     => $orderNumber,
            'customer_name'    => $customerData['first_name'] . ' ' . $customerData['last_name'],
            'customer_email'   => $customerData['email'],
            'tracking_number'  => $trackingNumber,
            'courier_name'     => $courier,
            'estimated_delivery' => $estimatedDate,
            'transaction_ref'  => $transactionRef,
            'payment_method'   => $paymentData['method'] ?? 'Credit Card',
            'total_amount'     => $totalAmount,
            'items'            => $cartItems,
            'address'          => $customerData['address'] . ', ' . $customerData['city'],
            'created_at'       => date('Y-m-d H:i:s')
        ];

        $_SESSION['last_order'] = $orderRecord;

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
}
