<?php
$page_title = 'Product Delivery Tracking';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/orders_service.php';
require_once __DIR__ . '/header.php';

$searchQuery = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');
$trackingCode = trim($_GET['tracking'] ?? $searchQuery);

// Selected delivery detail if tracking provided
$activeDelivery = null;
if (!empty($trackingCode)) {
    $activeDelivery = OrdersService::getDeliveryByTracking($trackingCode);
}

// Check for cancel eligibility on active delivery
$canCancel = false;
$associatedOrder = null;
if ($activeDelivery && Auth::isLoggedIn()) {
    $currUser = Auth::currentUser();
    $associatedOrder = OrdersService::getOrderByNumber($activeDelivery['order_number'] ?? '');
    if ($associatedOrder && (int)($associatedOrder['user_id'] ?? 0) === (int)$currUser['id']) {
        $eligibility = OrdersService::isOrderEligibleForCancellation($associatedOrder);
        $canCancel = $eligibility['eligible'];
    }
}

// Flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$csrfToken = Auth::generateCsrfToken();

// Fetch deliveries list
$deliveries = OrdersService::getDeliveries($statusFilter, $searchQuery);
?>

<div class="delivery-hero">
    <div class="delivery-hero-content">
        <span class="badge-tag">🚚 Logistics & Fulfillment</span>
        <h1>Product Delivery Tracking</h1>
        <p>Track shipment status, courier transit checkpoints and estimated delivery dates in real-time.</p>

        <!-- Search Form -->
        <form action="track_delivery.php" method="GET" class="tracking-search-bar">
            <span class="search-icon">🔍</span>
            <input type="text" name="tracking" placeholder="Enter Tracking Number (e.g. TRK-FEDEX-882910) or Order #..." value="<?php echo htmlspecialchars($trackingCode); ?>" required>
            <button type="submit" class="btn btn-primary">Track Package</button>
        </form>
    </div>
</div>

<?php if ($flashSuccess): ?>
    <div class="alert alert-success" style="max-width:1100px;margin:1.5rem auto 0;">
        <div class="alert-icon">✓</div>
        <div class="alert-content"><?php echo htmlspecialchars($flashSuccess); ?></div>
    </div>
<?php endif; ?>

<?php if ($flashError): ?>
    <div class="alert alert-danger" style="max-width:1100px;margin:1.5rem auto 0;">
        <div class="alert-icon">⚠️</div>
        <div class="alert-content"><?php echo htmlspecialchars($flashError); ?></div>
    </div>
<?php endif; ?>

<?php if ($activeDelivery): ?>
    <?php
    $statusLower = strtolower($activeDelivery['delivery_status'] ?? '');
    $isCancelled = ($statusLower === 'cancelled');
    ?>
    <!-- Active Delivery Tracking Card -->
    <div class="delivery-detail-card">
        <div class="delivery-detail-header">
            <div>
                <span class="tracking-title">Tracking Number:</span>
                <span class="tracking-code"><?php echo htmlspecialchars($activeDelivery['tracking_number']); ?></span>
                <span class="badge-pill bg-light" style="margin-left:0.5rem;"><?php echo htmlspecialchars($activeDelivery['courier_name']); ?></span>
            </div>
            <div>
                <span class="delivery-status-badge status-<?php echo strtolower(str_replace(' ', '-', $activeDelivery['delivery_status'])); ?>">
                    ● <?php echo htmlspecialchars($activeDelivery['delivery_status']); ?>
                </span>
            </div>
        </div>

        <?php if ($isCancelled): ?>
            <!-- Cancellation Notice -->
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:1.15rem 1.35rem;margin:1.25rem 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
                <div style="display:flex;align-items:center;gap:0.85rem;">
                    <span style="font-size:1.8rem;">🚫</span>
                    <div>
                        <strong style="color:#991b1b;display:block;font-size:1.05rem;">Shipment Cancelled</strong>
                        <span style="color:#7f1d1d;font-size:0.9rem;"><?php echo htmlspecialchars($activeDelivery['notes'] ?? 'This order and shipment was cancelled before delivery dispatch.'); ?></span>
                    </div>
                </div>
                <span class="badge-status" style="background:#fee2e2;color:#b91c1c;">● Discontinued</span>
            </div>
        <?php else: ?>
            <!-- Visual Progress Bar -->
            <?php
            $steps = ['Order Confirmed', 'Processing', 'In Transit', 'Out for Delivery', 'Delivered'];
            $currentIdx = 0;
            if ($statusLower === 'processing') $currentIdx = 1;
            elseif ($statusLower === 'dispatched' || $statusLower === 'in transit') $currentIdx = 2;
            elseif ($statusLower === 'out for delivery') $currentIdx = 3;
            elseif ($statusLower === 'delivered') $currentIdx = 4;
            ?>
            <div class="stepper-wrapper">
                <?php foreach ($steps as $idx => $stepName): ?>
                    <?php
                    $isCompleted = ($idx <= $currentIdx);
                    $isCurrent = ($idx === $currentIdx);
                    ?>
                    <div class="stepper-item <?php echo $isCompleted ? 'completed' : ''; ?> <?php echo $isCurrent ? 'current' : ''; ?>">
                        <div class="step-counter">
                            <?php if ($idx < $currentIdx): ?>✓<?php else: ?><?php echo $idx + 1; ?><?php endif; ?>
                        </div>
                        <div class="step-name"><?php echo $stepName; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Delivery Metadata Grid -->
        <div class="delivery-meta-grid">
            <div class="meta-item">
                <span class="meta-label">Associated Order</span>
                <span class="meta-value"><strong><?php echo htmlspecialchars($activeDelivery['order_number']); ?></strong></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Estimated Delivery</span>
                <span class="meta-value" style="color:<?php echo $isCancelled ? '#991b1b' : '#059669'; ?>;font-weight:600;">
                    <?php
                    if ($isCancelled) {
                        echo 'Cancelled';
                    } elseif ($activeDelivery['actual_delivery']) {
                        echo 'Delivered on ' . date('M d, Y', strtotime($activeDelivery['actual_delivery']));
                    } else {
                        echo date('M d, Y', strtotime($activeDelivery['estimated_delivery']));
                    }
                    ?>
                </span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Recipient / Destination</span>
                <span class="meta-value"><?php echo htmlspecialchars($activeDelivery['shipping_address']); ?></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Current Location / Status</span>
                <span class="meta-value"><?php echo htmlspecialchars($activeDelivery['current_location'] ?: 'In Transit'); ?></span>
            </div>
        </div>

        <?php if ($canCancel): ?>
            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:0.85rem 1.25rem;margin:1.25rem 0 0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.75rem;">
                <span style="font-size:0.9rem;color:#475569;">Need to change or cancel this order before dispatch?</span>
                <button type="button" class="btn-sm btn-outline" style="border-color:#ef4444;color:#dc2626;" onclick="openCancelModal('<?php echo htmlspecialchars($activeDelivery['order_number']); ?>')">
                    🚫 Cancel Order & Refund
                </button>
            </div>
        <?php endif; ?>

        <!-- Checkpoints Timeline -->
        <?php if (!empty($activeDelivery['timeline'])): ?>
            <div class="delivery-timeline-section">
                <h3>📍 Transit History & Checkpoints</h3>
                <div class="timeline-container">
                    <?php foreach ($activeDelivery['timeline'] as $chk): ?>
                        <div class="timeline-point">
                            <div class="timeline-dot" style="<?php echo strtolower($chk['status'] ?? '') === 'cancelled' ? 'background:#ef4444;' : ''; ?>"></div>
                            <div class="timeline-content">
                                <div class="timeline-header">
                                    <strong><?php echo htmlspecialchars($chk['status']); ?></strong>
                                    <span class="timeline-time"><?php echo date('M d, Y - h:i A', strtotime($chk['event_time'])); ?></span>
                                </div>
                                <div class="timeline-location">🏢 <?php echo htmlspecialchars($chk['location']); ?></div>
                                <div class="timeline-desc"><?php echo htmlspecialchars($chk['activity_description']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Cancellation Modal for Tracking Page -->
    <?php if ($canCancel): ?>
        <div id="cancelModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(15,23,42,0.6);z-index:9999;align-items:center;justify-content:center;backdrop-filter:blur(3px);">
            <div style="background:#fff;border-radius:14px;max-width:480px;width:90%;padding:1.75rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                    <h3 style="margin:0;font-size:1.3rem;color:#0f172a;">Cancel Order</h3>
                    <button type="button" onclick="closeCancelModal()" style="border:none;background:transparent;font-size:1.5rem;cursor:pointer;color:#64748b;">&times;</button>
                </div>
                <p style="color:#475569;font-size:0.95rem;line-height:1.5;margin-bottom:1.25rem;">
                    Are you sure you want to cancel order <strong id="modalOrderNumber"></strong>? If the order was paid via PayPal Sandbox, your payment will be refunded immediately.
                </p>
                <form action="cancel_order.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="order_number" id="modalOrderInput" value="<?php echo htmlspecialchars($activeDelivery['order_number']); ?>">
                    <input type="hidden" name="redirect_to" value="track_delivery.php?tracking=<?php echo urlencode($activeDelivery['tracking_number']); ?>">

                    <div class="form-group" style="margin-bottom:1.25rem;">
                        <label for="track_cancel_reason" style="font-weight:600;font-size:0.88rem;display:block;margin-bottom:0.4rem;">Reason for cancellation</label>
                        <select name="cancel_reason" id="track_cancel_reason" class="form-control" style="width:100%;padding:0.65rem;border:1px solid #cbd5e1;border-radius:8px;" required>
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
    <?php endif; ?>

<?php elseif (!empty($trackingCode)): ?>
    <div class="alert alert-warning" style="margin-bottom:2rem;">
        ⚠️ No delivery record found matching <strong>"<?php echo htmlspecialchars($trackingCode); ?>"</strong>. Please check your tracking number or select from the shipments below.
    </div>
<?php endif; ?>

<!-- Deliveries List & Filters -->
<div class="section-header">
    <h2>All Product Shipments & Deliveries</h2>
    <div class="filter-pills">
        <a href="track_delivery.php?status=all<?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" class="pill <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">All Deliveries</a>
        <a href="track_delivery.php?status=in transit" class="pill <?php echo $statusFilter === 'in transit' ? 'active' : ''; ?>">In Transit</a>
        <a href="track_delivery.php?status=out for delivery" class="pill <?php echo $statusFilter === 'out for delivery' ? 'active' : ''; ?>">Out for Delivery</a>
        <a href="track_delivery.php?status=delivered" class="pill <?php echo $statusFilter === 'delivered' ? 'active' : ''; ?>">Delivered</a>
        <a href="track_delivery.php?status=processing" class="pill <?php echo $statusFilter === 'processing' ? 'active' : ''; ?>">Processing</a>
        <a href="track_delivery.php?status=cancelled" class="pill <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
    </div>
</div>

<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Tracking Number</th>
                <th>Order Ref</th>
                <th>Recipient</th>
                <th>Courier</th>
                <th>Status</th>
                <th>Estimated Date</th>
                <th>Current Station</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($deliveries)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:2.5rem;color:#64748b;">No shipments found matching criteria.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($deliveries as $del): ?>
                    <tr>
                        <td>
                            <strong style="font-family:monospace;color:#2563eb;">
                                <?php echo htmlspecialchars($del['tracking_number']); ?>
                            </strong>
                        </td>
                        <td><?php echo htmlspecialchars($del['order_number']); ?></td>
                        <td><?php echo htmlspecialchars($del['customer_name'] ?? 'Customer'); ?></td>
                        <td><span class="courier-badge">📦 <?php echo htmlspecialchars($del['courier_name']); ?></span></td>
                        <td>
                            <span class="delivery-status-badge status-<?php echo strtolower(str_replace(' ', '-', $del['delivery_status'])); ?>">
                                ● <?php echo htmlspecialchars($del['delivery_status']); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($del['estimated_delivery'])); ?></td>
                        <td style="max-width:200px;font-size:0.85rem;color:#475569;">
                            <?php echo htmlspecialchars($del['current_location'] ?: 'Sorting Facility'); ?>
                        </td>
                        <td>
                            <a href="track_delivery.php?tracking=<?php echo urlencode($del['tracking_number']); ?>" class="btn-sm btn-outline">
                                View Tracker
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
