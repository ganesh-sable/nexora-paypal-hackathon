<?php
$page_title = 'Checkout';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/paypal_service.php';
require_once __DIR__ . '/orders_service.php';

// Enforce login for checkout; unauthenticated users will be redirected to login and returned back
Auth::requireLogin('checkout.php');
$currentUser = Auth::currentUser();

$nameParts = explode(' ', trim($currentUser['full_name'] ?? 'John Doe'), 2);
$defaultFirst = $nameParts[0] ?? 'John';
$defaultLast = $nameParts[1] ?? 'Doe';
$defaultEmail = $currentUser['email'] ?? 'john@example.com';

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

// Redirect if empty unless order was just placed or loaded via order_success
$order_placed = false;
$order_details = null;
$checkoutError = null;

if (!empty($_GET['order_success'])) {
    $successOrderNum = trim($_GET['order_success']);
    $fetchedOrder = OrdersService::getOrderByNumber($successOrderNum);
    if ($fetchedOrder && (int)($fetchedOrder['user_id'] ?? 0) === (int)$currentUser['id']) {
        $order_placed = true;
        $order_details = $fetchedOrder;
    }
}

if (!$order_placed && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($cart_items)) {
    if (!Auth::verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $checkoutError = 'Invalid or expired security token. Please refresh the page and try again.';
    } else {
        $customerData = [
            'first_name' => trim($_POST['first_name'] ?? $defaultFirst),
            'last_name'  => trim($_POST['last_name'] ?? $defaultLast),
            'email'      => trim($_POST['email'] ?? $defaultEmail),
            'phone'      => trim($_POST['phone'] ?? '+1-555-0199'),
            'address'    => trim($_POST['address'] ?? '123 Main St'),
            'city'       => trim($_POST['city'] ?? 'New York'),
            'state'      => trim($_POST['state'] ?? 'OR'),
            'zip'        => trim($_POST['zip'] ?? '10001'),
            'country'    => trim($_POST['country'] ?? 'United States')
        ];

        $paymentData = [
            'method' => trim($_POST['payment_method'] ?? 'Credit Card'),
            'card'   => trim($_POST['card'] ?? '4242 4242 4242 4242'),
            'expiry' => trim($_POST['expiry'] ?? '12/28'),
            'cvv'    => trim($_POST['cvv'] ?? '123')
        ];

        // Create order with user linkage, delivery tracking & transaction history
        $order_details = OrdersService::createOrder($customerData, $cart_items, $subtotal, $paymentData, (int)$currentUser['id']);

        // Clear cart
        $_SESSION['cart'] = [];
        $order_placed = true;
    }
}

if (!$order_placed && empty($cart_items)) {
    header('Location: cart.php');
    exit;
}

$paypalConfigured = PayPalService::isConfigured();
$csrfToken = Auth::generateCsrfToken();

require_once __DIR__ . '/header.php';
?>

<?php if ($order_placed && $order_details): ?>
    <?php
    $isCancelled = (strtolower($order_details['order_status'] ?? '') === 'cancelled');
    $cancelEligibility = OrdersService::isOrderEligibleForCancellation($order_details);
    ?>

    <div class="success-box" style="max-width:750px;margin:2rem auto;text-align:left;">
        <div style="text-align:center;margin-bottom:1.5rem;">
            <div class="emoji" style="font-size:3.5rem;"><?php echo $isCancelled ? '🚫' : '🎉'; ?></div>
            <h2 style="font-size:1.85rem;margin-bottom:0.35rem;">
                <?php echo $isCancelled ? 'Order Cancelled' : 'Order Successfully Placed!'; ?>
            </h2>
            <p style="color:#64748b;">
                <?php echo $isCancelled
                    ? 'This order has been cancelled and any captured payment refunded.'
                    : 'Thank you for your purchase. Your order has been recorded and scheduled for dispatch.'; ?>
            </p>
        </div>

        <div class="order-receipt-card">
            <div class="receipt-header">
                <div>
                    <span class="meta-label">Order Number</span>
                    <strong style="font-size:1.15rem;color:#0f172a;"><?php echo htmlspecialchars($order_details['order_number']); ?></strong>
                </div>
                <div>
                    <span class="meta-label">Payment Status</span>
                    <?php if ($isCancelled): ?>
                        <span class="delivery-status-badge status-cancelled">● Cancelled (Refunded)</span>
                    <?php else: ?>
                        <span class="delivery-status-badge status-delivered">● Paid ($<?php echo number_format($order_details['total_amount'], 2); ?>)</span>
                    <?php endif; ?>
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
                    <span>💳 <strong>Payment Method:</strong></span>
                    <span><?php echo htmlspecialchars($order_details['payment_method'] ?? 'PayPal'); ?></span>
                </div>
                <?php if (!empty($order_details['paypal_order_id'])): ?>
                    <div class="receipt-row">
                        <span>🅿️ <strong>PayPal Order ID:</strong></span>
                        <span style="font-family:monospace;"><?php echo htmlspecialchars($order_details['paypal_order_id']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($order_details['paypal_capture_id'])): ?>
                    <div class="receipt-row">
                        <span>🆔 <strong>PayPal Capture ID:</strong></span>
                        <span style="font-family:monospace;"><?php echo htmlspecialchars($order_details['paypal_capture_id']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="receipt-row">
                    <span>🧾 <strong>Transaction Reference:</strong></span>
                    <a href="transactions.php?search=<?php echo urlencode($order_details['transaction_ref'] ?? ''); ?>" style="font-family:monospace;color:#475569;text-decoration:none;">
                        <?php echo htmlspecialchars($order_details['transaction_ref'] ?? 'TXN-VIEW'); ?> ↗
                    </a>
                </div>
                <div class="receipt-row">
                    <span>📍 <strong>Shipping Address:</strong></span>
                    <span><?php echo htmlspecialchars($order_details['address'] ?? ($order_details['shipping_address'] ?? 'Shipping Address')); ?></span>
                </div>
            </div>

            <div class="receipt-items">
                <h4>Items Ordered</h4>
                <?php foreach (($order_details['items'] ?? []) as $it): ?>
                    <div class="receipt-item-line">
                        <span><?php echo htmlspecialchars($it['name'] ?? ($it['product_name'] ?? 'Product')); ?> × <?php echo (int)($it['qty'] ?? ($it['quantity'] ?? 1)); ?></span>
                        <span>$<?php echo number_format((float)($it['line_total'] ?? (($it['price'] ?? 0) * ($it['quantity'] ?? 1))), 2); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:flex;gap:0.85rem;margin-top:1.5rem;justify-content:center;flex-wrap:wrap;">
            <a href="account.php" class="btn btn-primary" style="padding:0.75rem 1.4rem;">
                👤 View in My Account
            </a>
            <a href="track_delivery.php?tracking=<?php echo urlencode($order_details['tracking_number']); ?>" class="btn btn-outline" style="padding:0.75rem 1.4rem;">
                🚚 Track Your Shipment
            </a>
            <a href="transactions.php" class="btn btn-outline" style="padding:0.75rem 1.4rem;">
                💳 View Transaction History
            </a>
            <?php if (!$isCancelled && $cancelEligibility['eligible']): ?>
                <button type="button" class="btn btn-outline" style="border-color:#ef4444;color:#dc2626;padding:0.75rem 1.4rem;" onclick="openCancelModal('<?php echo htmlspecialchars($order_details['order_number']); ?>')">
                    🚫 Cancel Order
                </button>
            <?php endif; ?>
            <a href="index.php" class="btn btn-secondary" style="padding:0.75rem 1.4rem;">
                Continue Shopping
            </a>
        </div>
    </div>

    <!-- Cancellation Modal -->
    <div id="cancelModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
        <div style="background:#fff;border-radius:14px;max-width:480px;width:90%;padding:1.75rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h3 style="margin:0;font-size:1.3rem;color:#0f172a;">Confirm Order Cancellation</h3>
                <button type="button" onclick="closeCancelModal()" style="border:none;background:transparent;font-size:1.5rem;cursor:pointer;color:#64748b;">&times;</button>
            </div>
            <p style="color:#475569;font-size:0.95rem;line-height:1.5;margin-bottom:1.25rem;">
                Are you sure you want to cancel order <strong id="modalOrderNumber"></strong>? If you paid via PayPal Sandbox, your payment will be refunded immediately.
            </p>
            <form action="cancel_order.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="order_number" id="modalOrderInput" value="<?php echo htmlspecialchars($order_details['order_number']); ?>">
                <input type="hidden" name="redirect_to" value="checkout.php?order_success=<?php echo urlencode($order_details['order_number']); ?>">

                <div class="form-group" style="margin-bottom:1.25rem;">
                    <label for="cancel_reason" style="font-weight:600;font-size:0.88rem;display:block;margin-bottom:0.4rem;">Reason for cancellation</label>
                    <select name="cancel_reason" id="cancel_reason" class="form-control" style="width:100%;padding:0.65rem;border:1px solid #cbd5e1;border-radius:8px;" required>
                        <option value="Changed my mind">Changed my mind</option>
                        <option value="Ordered by mistake">Ordered by mistake</option>
                        <option value="Found a better price elsewhere">Found a better price elsewhere</option>
                        <option value="Shipping time is too long">Shipping time is too long</option>
                        <option value="Incorrect shipping address entered">Incorrect shipping address entered</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div style="display:flex;gap:0.75rem;justify-content:flex-end;">
                    <button type="button" class="btn btn-outline" onclick="closeCancelModal()">Keep Order</button>
                    <button type="submit" class="btn btn-danger" style="background:#dc2626;color:#fff;border:none;">Confirm Cancellation & Refund</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openCancelModal(orderNum) {
        document.getElementById('modalOrderNumber').textContent = orderNum;
        document.getElementById('modalOrderInput').value = orderNum;
        const modal = document.getElementById('cancelModal');
        modal.style.display = 'flex';
    }
    function closeCancelModal() {
        document.getElementById('cancelModal').style.display = 'none';
    }
    </script>

<?php else: ?>

    <h1 class="page-title">Checkout</h1>
    <p class="page-subtitle">Complete your purchase. Orders, delivery tracking and payment transaction records are automatically linked to your Nexora account.</p>

    <?php if (!empty($checkoutError)): ?>
        <div class="alert alert-danger" style="margin-bottom:1.5rem;">
            <div class="alert-icon">⚠️</div>
            <div class="alert-content"><?php echo htmlspecialchars($checkoutError); ?></div>
        </div>
    <?php endif; ?>

    <div class="checkout-user-banner" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;">
        <div style="display:flex;align-items:center;gap:0.75rem;">
            <span style="font-size:1.5rem;">👤</span>
            <div>
                <strong>Ordering as: <?php echo htmlspecialchars($currentUser['full_name']); ?></strong>
                <span style="color:#64748b;font-size:0.88rem;margin-left:0.5rem;">(<?php echo htmlspecialchars($currentUser['email']); ?>)</span>
            </div>
        </div>
        <span class="badge-pill bg-light" style="color:#2563eb;font-weight:700;">✓ Authenticated Nexora Account</span>
    </div>

    <div class="checkout-grid">
        <!-- Customer Form -->
        <form action="checkout.php" method="POST" class="checkout-form">
            <?php echo Auth::csrfField(); ?>
            <h3 style="margin-bottom:1.25rem;">1. Shipping Information</h3>

            <div class="form-row">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required placeholder="John" value="<?php echo htmlspecialchars($defaultFirst); ?>">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required placeholder="Doe" value="<?php echo htmlspecialchars($defaultLast); ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="john@example.com" value="<?php echo htmlspecialchars($defaultEmail); ?>">
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

            <div class="form-row" style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:1rem;">
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" required placeholder="Springfield" value="Springfield">
                </div>
                <div class="form-group">
                    <label for="state">State</label>
                    <input type="text" id="state" name="state" required placeholder="OR" value="OR">
                </div>
                <div class="form-group">
                    <label for="zip">ZIP / Postal Code</label>
                    <input type="text" id="zip" name="zip" required placeholder="97477" value="97477">
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
                    <span>🅿️ PayPal Sandbox</span>
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

            <!-- Card Fields -->
            <div id="credit-card-fields" class="payment-card-fields" style="margin-top:1rem;">
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

            <!-- PayPal Sandbox Container Wrapper -->
            <div id="paypal-container-wrapper" style="display:none;margin-top:1.25rem;">
                <div id="paypal-status-box" style="display:none;margin-bottom:1rem;padding:0.85rem;border-radius:8px;"></div>

                <?php if ($paypalConfigured): ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:1.25rem;text-align:center;">
                        <p style="color:#475569;margin-bottom:1rem;font-size:0.92rem;">
                            🔒 Click the button below to authorize payment securely with your <strong>PayPal Sandbox</strong> buyer account:
                        </p>
                        <div id="paypal-button-container" style="max-width:400px;margin:0 auto;"></div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info" style="border-left:4px solid #2563eb;text-align:left;">
                        <div class="alert-icon">ℹ️</div>
                        <div class="alert-content">
                            <strong>PayPal Sandbox Integration Ready</strong>
                            <p style="margin:0.25rem 0 0;font-size:0.88rem;color:#475569;">
                                To enable the live PayPal interactive button, add your PayPal Sandbox REST credentials to <code>.env</code>:<br>
                                <code>PAYPAL_CLIENT_ID=your_client_id_here</code><br>
                                <code>PAYPAL_CLIENT_SECRET=your_client_secret_here</code><br>
                                <code>PAYPAL_ENVIRONMENT=sandbox</code>
                            </p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <button type="submit" id="standard-submit-btn" class="btn btn-success" style="width:100%;margin-top:1.5rem;padding:0.95rem;font-size:1.05rem;">
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

    <!-- Payment Switcher & PayPal JS SDK -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const paymentRadios = document.querySelectorAll('input[name="payment_method"]');
        const cardFields = document.getElementById('credit-card-fields');
        const paypalWrapper = document.getElementById('paypal-container-wrapper');
        const standardSubmitBtn = document.getElementById('standard-submit-btn');

        function updatePaymentUI(selectedMethod) {
            if (selectedMethod === 'PayPal') {
                if (cardFields) cardFields.style.display = 'none';
                if (paypalWrapper) paypalWrapper.style.display = 'block';
                if (standardSubmitBtn) standardSubmitBtn.style.display = '<?php echo $paypalConfigured ? "none" : "block"; ?>';
            } else if (selectedMethod === 'Credit Card') {
                if (cardFields) cardFields.style.display = 'block';
                if (paypalWrapper) paypalWrapper.style.display = 'none';
                if (standardSubmitBtn) standardSubmitBtn.style.display = 'block';
            } else {
                if (cardFields) cardFields.style.display = 'none';
                if (paypalWrapper) paypalWrapper.style.display = 'none';
                if (standardSubmitBtn) standardSubmitBtn.style.display = 'block';
            }
        }

        paymentRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                updatePaymentUI(this.value);
            });
        });
    });
    </script>

    <?php if ($paypalConfigured): ?>
        <script src="https://www.paypal.com/sdk/js?client-id=<?php echo urlencode(PAYPAL_CLIENT_ID); ?>&currency=USD"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const csrfToken = '<?php echo $csrfToken; ?>';

            if (window.paypal && document.getElementById('paypal-button-container')) {
                paypal.Buttons({
                    style: {
                        layout: 'vertical',
                        color: 'gold',
                        shape: 'rect',
                        label: 'paypal'
                    },
                    createOrder: function(data, actions) {
                        const statusBox = document.getElementById('paypal-status-box');
                        if (statusBox) statusBox.style.display = 'none';

                        const form = document.querySelector('.checkout-form');
                        const reqFields = ['first_name', 'last_name', 'email', 'address', 'city', 'state', 'zip'];
                        let missing = false;
                        reqFields.forEach(f => {
                            const el = form ? form.querySelector('#' + f) : null;
                            if (!el || !el.value.trim()) {
                                missing = true;
                                if (el) el.style.borderColor = '#ef4444';
                            } else if (el) {
                                el.style.borderColor = '';
                            }
                        });

                        if (missing) {
                            if (statusBox) {
                                statusBox.className = 'alert alert-danger';
                                statusBox.innerHTML = '⚠️ Please fill in all required shipping fields before proceeding to PayPal.';
                                statusBox.style.display = 'block';
                            }
                            return actions.reject();
                        }

                        return fetch('paypal_api.php?action=create-order', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify({
                                csrf_token: csrfToken
                            })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.error) {
                                throw new Error(data.error);
                            }
                            return data.id;
                        });
                    },
                    onApprove: function(data, actions) {
                        const form = document.querySelector('.checkout-form');
                        const statusBox = document.getElementById('paypal-status-box');
                        if (statusBox) {
                            statusBox.className = 'alert alert-info';
                            statusBox.innerHTML = 'Verifying and capturing PayPal Sandbox payment...';
                            statusBox.style.display = 'block';
                        }

                        const payload = {
                            csrf_token: csrfToken,
                            orderID: data.orderID,
                            first_name: form.querySelector('#first_name')?.value || '',
                            last_name: form.querySelector('#last_name')?.value || '',
                            email: form.querySelector('#email')?.value || '',
                            phone: form.querySelector('#phone')?.value || '',
                            address: form.querySelector('#address')?.value || '',
                            city: form.querySelector('#city')?.value || '',
                            state: form.querySelector('#state')?.value || '',
                            zip: form.querySelector('#zip')?.value || '',
                            country: form.querySelector('#country')?.value || ''
                        };

                        return fetch('paypal_api.php?action=capture-order', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken
                            },
                            body: JSON.stringify(payload)
                        })
                        .then(res => res.json())
                        .then(res => {
                            if (res.error) {
                                throw new Error(res.error);
                            }
                            if (res.redirect_url) {
                                window.location.href = res.redirect_url;
                            } else {
                                window.location.href = 'checkout.php?order_success=' + encodeURIComponent(res.order_number);
                            }
                        })
                        .catch(err => {
                            if (statusBox) {
                                statusBox.className = 'alert alert-danger';
                                statusBox.innerHTML = '⚠️ Payment capture failed: ' + err.message;
                                statusBox.style.display = 'block';
                            }
                        });
                    },
                    onCancel: function(data) {
                        fetch('paypal_api.php?action=cancel-approval', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ csrf_token: csrfToken })
                        });
                        const statusBox = document.getElementById('paypal-status-box');
                        if (statusBox) {
                            statusBox.className = 'alert alert-warning';
                            statusBox.innerHTML = '⚠️ Payment Cancelled. Your order has not been paid. Your cart items are still saved so you can retry payment anytime.';
                            statusBox.style.display = 'block';
                        }
                    },
                    onError: function(err) {
                        const statusBox = document.getElementById('paypal-status-box');
                        if (statusBox) {
                            statusBox.className = 'alert alert-danger';
                            statusBox.innerHTML = '⚠️ PayPal error: ' + (err.message || 'Please check your connection and try again.');
                            statusBox.style.display = 'block';
                        }
                    }
                }).render('#paypal-button-container');
            }
        });
        </script>
    <?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
