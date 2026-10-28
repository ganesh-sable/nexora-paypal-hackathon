<?php
$page_title = 'Checkout';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/orders_service.php';

$cart = $_SESSION['cart'] ?? [];
$subtotal = 0;
$cart_items = [];

foreach ($cart as $id => $qty) {
    $p = get_product_by_id((int)$id);
    if ($p) {
        $p['qty'] = $qty;
        $p['line_total'] = $p['price'] * $qty;
        $subtotal += $p['line_total'];
        $cart_items[] = $p;
    }
}

// Redirect if empty unless order was just placed
$order_placed = false;
$order_details = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($cart_items)) {
    $customerData = [
        'first_name' => trim($_POST['first_name'] ?? 'John'),
        'last_name'  => trim($_POST['last_name'] ?? 'Doe'),
        'email'      => trim($_POST['email'] ?? 'john@example.com'),
        'phone'      => trim($_POST['phone'] ?? '+1-555-0199'),
        'address'    => trim($_POST['address'] ?? '123 Main St'),
        'city'       => trim($_POST['city'] ?? 'New York'),
        'zip'        => trim($_POST['zip'] ?? '10001'),
        'country'    => trim($_POST['country'] ?? 'United States')
    ];

    $paymentData = [
        'method' => trim($_POST['payment_method'] ?? 'Credit Card'),
        'card'   => trim($_POST['card'] ?? '4242 4242 4242 4242'),
        'expiry' => trim($_POST['expiry'] ?? '12/28'),
        'cvv'    => trim($_POST['cvv'] ?? '123')
    ];

    // Create order with delivery tracking & transaction history
    $order_details = OrdersService::createOrder($customerData, $cart_items, $subtotal, $paymentData);

    // Clear cart
    $_SESSION['cart'] = [];
    $order_placed = true;
}

if (!$order_placed && empty($cart_items)) {
    header('Location: cart.php');
    exit;
}

require_once __DIR__ . '/header.php';
?>

<?php if ($order_placed && $order_details): ?>

    <div class="success-box" style="max-width:750px;margin:2rem auto;text-align:left;">
        <div style="text-align:center;margin-bottom:1.5rem;">
            <div class="emoji" style="font-size:3.5rem;">🎉</div>
            <h2 style="font-size:1.85rem;margin-bottom:0.35rem;">Order Successfully Placed!</h2>
            <p style="color:#64748b;">Thank you for your purchase. Your order has been recorded and scheduled for dispatch.</p>
        </div>

        <div class="order-receipt-card">
            <div class="receipt-header">
                <div>
                    <span class="meta-label">Order Number</span>
                    <strong style="font-size:1.15rem;color:#0f172a;"><?php echo htmlspecialchars($order_details['order_number']); ?></strong>
                </div>
                <div>
                    <span class="meta-label">Payment Status</span>
                    <span class="delivery-status-badge status-delivered">● Paid ($<?php echo number_format($order_details['total_amount'], 2); ?>)</span>
                </div>
            </div>

            <div class="receipt-body">
                <div class="receipt-row">
                    <span>🚚 <strong>Tracking Number:</strong></span>
                    <a href="track_delivery.php?tracking=<?php echo urlencode($order_details['tracking_number']); ?>" style="font-family:monospace;font-weight:700;color:#2563eb;text-decoration:none;">
                        <?php echo htmlspecialchars($order_details['tracking_number']); ?> ↗
                    </a>
                </div>
                <div class="receipt-row">
                    <span>📦 <strong>Courier & Delivery:</strong></span>
                    <span><?php echo htmlspecialchars($order_details['courier_name']); ?> (Est. <?php echo date('M d, Y', strtotime($order_details['estimated_delivery'])); ?>)</span>
                </div>
                <div class="receipt-row">
                    <span>💳 <strong>Transaction Reference:</strong></span>
                    <a href="transactions.php?search=<?php echo urlencode($order_details['transaction_ref']); ?>" style="font-family:monospace;color:#475569;text-decoration:none;">
                        <?php echo htmlspecialchars($order_details['transaction_ref']); ?> ↗
                    </a>
                </div>
                <div class="receipt-row">
                    <span>📍 <strong>Shipping Address:</strong></span>
                    <span><?php echo htmlspecialchars($order_details['address']); ?></span>
                </div>
            </div>

            <div class="receipt-items">
                <h4>Items Ordered</h4>
                <?php foreach ($order_details['items'] as $it): ?>
                    <div class="receipt-item-line">
                        <span><?php echo htmlspecialchars($it['name']); ?> × <?php echo $it['qty']; ?></span>
                        <span>$<?php echo number_format($it['line_total'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:flex;gap:1rem;margin-top:1.5rem;justify-content:center;flex-wrap:wrap;">
            <a href="track_delivery.php?tracking=<?php echo urlencode($order_details['tracking_number']); ?>" class="btn btn-primary" style="padding:0.75rem 1.5rem;">
                🚚 Track Your Shipment
            </a>
            <a href="transactions.php" class="btn btn-outline" style="padding:0.75rem 1.5rem;">
                💳 View Transaction History
            </a>
            <a href="index.php" class="btn btn-secondary" style="padding:0.75rem 1.5rem;">
                Continue Shopping
            </a>
        </div>
    </div>

<?php else: ?>

    <h1 class="page-title">Checkout</h1>
    <p class="page-subtitle">Complete your purchase. Orders, delivery tracking and payment transaction records are automatically recorded.</p>

    <div class="checkout-grid">
        <!-- Customer Form -->
        <form action="checkout.php" method="POST" class="checkout-form">
            <h3 style="margin-bottom:1.25rem;">1. Shipping Information</h3>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required placeholder="John" value="John">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required placeholder="Doe" value="Doe">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="john@example.com" value="john.doe@example.com">
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" placeholder="+1-555-0199" value="+1-555-0199">
                </div>
            </div>

            <div class="form-group">
                <label for="address">Shipping Street Address</label>
                <input type="text" id="address" name="address" required placeholder="123 Tech Avenue, Suite 400" value="742 Evergreen Terrace">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" required placeholder="New York" value="Springfield">
                </div>
                <div class="form-group">
                    <label for="zip">ZIP / Postal Code</label>
                    <input type="text" id="zip" name="zip" required placeholder="10001" value="97477">
                </div>
            </div>

            <div class="form-group">
                <label for="country">Country</label>
                <select id="country" name="country" required>
                    <option value="United States" selected>United States</option>
                    <option value="India">India</option>
                    <option value="United Kingdom">United Kingdom</option>
                    <option value="Canada">Canada</option>
                    <option value="Australia">Australia</option>
                    <option value="Germany">Germany</option>
                </select>
            </div>

            <h3 style="margin:2rem 0 1rem;">2. Payment Method</h3>
            <div class="payment-method-selector">
                <label class="payment-radio">
                    <input type="radio" name="payment_method" value="Credit Card" checked>
                    <span>💳 Credit / Debit Card</span>
                </label>
                <label class="payment-radio">
                    <input type="radio" name="payment_method" value="PayPal">
                    <span>🅿️ PayPal</span>
                </label>
                <label class="payment-radio">
                    <input type="radio" name="payment_method" value="UPI / Net Banking">
                    <span>📱 UPI / Net Banking</span>
                </label>
                <label class="payment-radio">
                    <input type="radio" name="payment_method" value="Apple Pay">
                    <span>🍏 Apple Pay</span>
                </label>
            </div>

            <div class="payment-card-fields" style="margin-top:1rem;">
                <div class="form-group">
                    <label for="card">Card Number</label>
                    <input type="text" id="card" name="card" placeholder="4242 4242 4242 4242" value="4242 4242 4242 4242" maxlength="19">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="expiry">Expiry</label>
                        <input type="text" id="expiry" name="expiry" placeholder="MM/YY" value="12/28">
                    </div>
                    <div class="form-group">
                        <label for="cvv">CVV</label>
                        <input type="text" id="cvv" name="cvv" placeholder="123" value="123" maxlength="4">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-success" style="width:100%;margin-top:1.5rem;padding:0.95rem;font-size:1.05rem;">
                Confirm Order & Pay — $<?php echo number_format($subtotal, 2); ?>
            </button>
        </form>

        <!-- Order Summary -->
        <div class="order-summary">
            <h3>Order Summary (<?php echo count($cart_items); ?> items)</h3>
            <div class="order-summary-scroll">
                <?php foreach ($cart_items as $item): ?>
                    <div class="order-item">
                        <div style="display:flex;align-items:center;gap:0.75rem;">
                            <img src="<?php echo htmlspecialchars($item['image']); ?>" 
                                 alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                 class="order-item-photo"
                                 onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=120&auto=format&fit=crop&q=80';">
                            <div>
                                <div style="font-weight:600;font-size:0.9rem;"><?php echo htmlspecialchars($item['name']); ?></div>
                                <span style="font-size:0.78rem;color:#64748b;">Qty: <?php echo $item['qty']; ?></span>
                            </div>
                        </div>
                        <span style="font-weight:700;">$<?php echo number_format($item['line_total'], 2); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="summary-row" style="margin-top:1.25rem;">
                <span>Subtotal</span>
                <span>$<?php echo number_format($subtotal, 2); ?></span>
            </div>
            <div class="summary-row">
                <span>Express Delivery</span>
                <span style="color:#059669;font-weight:600;">FREE</span>
            </div>
            <div class="summary-row total">
                <span>Total</span>
                <span>$<?php echo number_format($subtotal, 2); ?></span>
            </div>

            <div class="summary-guarantee">
                🛡️ Encrypted 256-Bit SSL Checkout • Live Delivery Tracking Included
            </div>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
