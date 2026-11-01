<?php
$page_title = 'My Account';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/orders_service.php';

Auth::requireLogin('account.php');

$user = Auth::currentUser();
$orders = OrdersService::getUserOrders((int)$user['id']);

// Check flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$csrfToken = Auth::generateCsrfToken();

require_once __DIR__ . '/header.php';
?>

<div class="account-container">
    <?php if ($flashSuccess): ?>
        <div class="alert alert-success" role="alert" style="margin-bottom:1.5rem;">
            <div class="alert-icon">✓</div>
            <div class="alert-content"><?php echo htmlspecialchars($flashSuccess); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-danger" role="alert" style="margin-bottom:1.5rem;">
            <div class="alert-icon">⚠️</div>
            <div class="alert-content"><?php echo htmlspecialchars($flashError); ?></div>
        </div>
    <?php endif; ?>

    <div class="account-header-card">
        <div class="account-profile-main">
            <div class="account-avatar">
                <?php 
                    $initials = '';
                    $parts = explode(' ', trim($user['full_name']));
                    foreach ($parts as $p) {
                        if (!empty($p)) $initials .= strtoupper($p[0]);
                    }
                    echo htmlspecialchars(substr($initials, 0, 2) ?: 'NX');
                ?>
            </div>
            <div class="account-info">
                <div class="account-role-badge">Verified Customer Account</div>
                <h1 class="account-name"><?php echo htmlspecialchars($user['full_name']); ?></h1>
                <p class="account-email">✉️ <?php echo htmlspecialchars($user['email']); ?></p>
                <div class="account-meta">
                    <span>🗓️ Member since <?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                    <span>🔒 256-Bit SSL Encrypted Session</span>
                </div>
            </div>
        </div>
        <div class="account-header-actions">
            <a href="index.php" class="btn btn-outline btn-sm">🛍️ Browse Store</a>
            <form action="logout.php" method="POST" style="display:inline;">
                <?php echo Auth::csrfField(); ?>
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to log out?');">
                    🚪 Sign Out
                </button>
            </form>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="account-stats-grid">
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div>
                <div class="stat-val"><?php echo count($orders); ?></div>
                <div class="stat-lbl">Total Orders Placed</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🚚</div>
            <div>
                <div class="stat-val">
                    <?php 
                        $inTransit = 0;
                        foreach ($orders as $o) {
                            $delivStatus = strtolower($o['delivery_status'] ?? '');
                            $ordStatus = strtolower($o['order_status'] ?? '');
                            if (!empty($delivStatus) && !in_array($delivStatus, ['delivered', 'cancelled'], true) && $ordStatus !== 'cancelled') {
                                $inTransit++;
                            }
                        }
                        echo $inTransit;
                    ?>
                </div>
                <div class="stat-lbl">Active Shipments</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">💳</div>
            <div>
                <div class="stat-val">
                    $<?php 
                        $spent = 0;
                        foreach ($orders as $o) {
                            $isOrdCancelled = (strtolower($o['order_status'] ?? '') === 'cancelled');
                            if (!$isOrdCancelled) {
                                $spent += (float)($o['total_amount'] ?? 0);
                            }
                        }
                        echo number_format($spent, 2);
                    ?>
                </div>
                <div class="stat-lbl">Net Store Spend</div>
            </div>
        </div>
    </div>

    <!-- Order History Section -->
    <div class="account-section">
        <div class="section-title-bar">
            <h2>Your Order History</h2>
            <a href="index.php" class="text-link font-semibold">Start New Order →</a>
        </div>

        <?php if (empty($orders)): ?>
            <div class="empty-state-box">
                <div class="empty-state-icon">🛒</div>
                <h3>No Orders Found Yet</h3>
                <p>When you complete a checkout on Nexora, your order receipts, package tracking, and payment transaction logs will appear here.</p>
                <a href="index.php" class="btn btn-primary" style="margin-top:1rem;">Explore Catalog</a>
            </div>
        <?php else: ?>
            <div class="orders-list">
                <?php foreach ($orders as $order): ?>
                    <?php 
                    $isCancelled = (strtolower($order['order_status'] ?? '') === 'cancelled');
                    $isRefunded = (strtolower($order['payment_status'] ?? '') === 'refunded');
                    $cancellation = OrdersService::isOrderEligibleForCancellation($order);
                    ?>
                    <div class="order-card" style="<?php echo $isCancelled ? 'opacity:0.92;border-left:4px solid #ef4444;' : ''; ?>">
                        <div class="order-card-header">
                            <div>
                                <span class="order-number-lbl">Order Number</span>
                                <strong class="order-number-val"><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                <span class="order-date">Placed on <?php echo date('M d, Y', strtotime($order['created_at'])); ?></span>
                            </div>
                            <div style="text-align:right;">
                                <div class="order-total-amount">$<?php echo number_format((float)$order['total_amount'], 2); ?></div>
                                <div style="display:flex;gap:0.4rem;justify-content:flex-end;margin-top:0.25rem;">
                                    <?php if ($isCancelled): ?>
                                        <span class="badge-status" style="background:#fee2e2;color:#b91c1c;">● Cancelled</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-success">● <?php echo htmlspecialchars($order['order_status'] ?? 'Confirmed'); ?></span>
                                    <?php endif; ?>

                                    <?php if ($isRefunded): ?>
                                        <span class="badge-status" style="background:#f3e8ff;color:#7e22ce;">● Refunded</span>
                                    <?php elseif (strtolower($order['payment_status'] ?? '') === 'paid'): ?>
                                        <span class="badge-status badge-success">● Paid</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-warning">● <?php echo htmlspecialchars($order['payment_status'] ?? 'Pending'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="order-card-body">
                            <div class="order-quick-info">
                                <?php if (!empty($order['tracking_number'])): ?>
                                    <div>
                                        <strong>🚚 Tracking:</strong>
                                        <a href="track_delivery.php?tracking=<?php echo urlencode($order['tracking_number']); ?>" class="text-link" style="font-family:monospace;font-weight:700;">
                                            <?php echo htmlspecialchars($order['tracking_number']); ?> ↗
                                        </a>
                                        <span class="delivery-status-pill status-<?php echo strtolower(str_replace(' ', '-', $order['delivery_status'] ?? 'Processing')); ?>">
                                            <?php echo htmlspecialchars($order['delivery_status'] ?? 'Processing'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($order['payment_method'])): ?>
                                    <div>
                                        <strong>💳 Payment:</strong>
                                        <span><?php echo htmlspecialchars($order['payment_method']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($order['paypal_capture_id'])): ?>
                                    <div>
                                        <strong>🆔 PayPal Capture:</strong>
                                        <span style="font-family:monospace;font-size:0.85rem;color:#475569;"><?php echo htmlspecialchars($order['paypal_capture_id']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($order['transaction_ref'])): ?>
                                    <div>
                                        <strong>🧾 Payment Ref:</strong>
                                        <a href="transactions.php?search=<?php echo urlencode($order['transaction_ref']); ?>" class="text-link" style="font-family:monospace;">
                                            <?php echo htmlspecialchars($order['transaction_ref']); ?> ↗
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($isCancelled): ?>
                                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:0.75rem 1rem;margin:0.85rem 0;font-size:0.88rem;color:#991b1b;">
                                    <strong>🚫 Order Cancelled:</strong> <?php echo htmlspecialchars($order['cancel_reason'] ?? 'Customer request'); ?>
                                    <?php if (!empty($order['cancelled_at'])): ?>
                                        <span style="color:#7f1d1d;margin-left:0.5rem;">(on <?php echo date('M d, Y - h:i A', strtotime($order['cancelled_at'])); ?>)</span>
                                    <?php endif; ?>
                                    <?php if (!empty($order['refund_id'])): ?>
                                        <div style="margin-top:0.35rem;color:#6b21a8;font-weight:600;">
                                            💰 Refund processed: <?php echo htmlspecialchars($order['refund_id']); ?> (Amount: $<?php echo number_format((float)($order['refunded_amount'] ?? $order['total_amount']), 2); ?>)
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($order['items'])): ?>
                                <div class="order-items-preview">
                                    <strong>Items:</strong>
                                    <ul style="margin:0.25rem 0 0 1.25rem;color:#475569;">
                                        <?php foreach ($order['items'] as $item): ?>
                                            <li>
                                                <?php echo htmlspecialchars($item['product_name'] ?? ($item['name'] ?? 'Product')); ?> 
                                                × <?php echo (int)($item['quantity'] ?? ($item['qty'] ?? 1)); ?> 
                                                ($<?php echo number_format((float)($item['price'] ?? 0), 2); ?> each)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <!-- Actions Row -->
                            <div style="display:flex;gap:0.75rem;margin-top:1rem;justify-content:flex-end;align-items:center;flex-wrap:wrap;border-top:1px solid #f1f5f9;padding-top:0.85rem;">
                                <?php if (!empty($order['tracking_number'])): ?>
                                    <a href="track_delivery.php?tracking=<?php echo urlencode($order['tracking_number']); ?>" class="btn-sm btn-outline">
                                        🚚 Track Delivery
                                    </a>
                                <?php endif; ?>

                                <?php if (!$isCancelled && $cancellation['eligible']): ?>
                                    <button type="button" class="btn-sm btn-outline" style="border-color:#ef4444;color:#dc2626;" onclick="openCancelModal('<?php echo htmlspecialchars($order['order_number']); ?>')">
                                        🚫 Cancel Order
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Cancellation Modal -->
<div id="cancelModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
    <div style="background:#fff;border-radius:14px;max-width:480px;width:90%;padding:1.75rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h3 style="margin:0;font-size:1.3rem;color:#0f172a;">Cancel Order</h3>
            <button type="button" onclick="closeCancelModal()" style="border:none;background:transparent;font-size:1.5rem;cursor:pointer;color:#64748b;">&times;</button>
        </div>
        <p style="color:#475569;font-size:0.95rem;line-height:1.5;margin-bottom:1.25rem;">
            Are you sure you want to cancel order <strong id="modalOrderNumber"></strong>? If the order was paid via PayPal Sandbox, your payment will be refunded immediately.
        </p>
        <form action="cancel_order.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="order_number" id="modalOrderInput" value="">
            <input type="hidden" name="redirect_to" value="account.php">

            <div class="form-group" style="margin-bottom:1.25rem;">
                <label for="modal_cancel_reason" style="font-weight:600;font-size:0.88rem;display:block;margin-bottom:0.4rem;">Reason for cancellation</label>
                <select name="cancel_reason" id="modal_cancel_reason" class="form-control" style="width:100%;padding:0.65rem;border:1px solid #cbd5e1;border-radius:8px;" required>
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

<?php require_once __DIR__ . '/footer.php'; ?>
