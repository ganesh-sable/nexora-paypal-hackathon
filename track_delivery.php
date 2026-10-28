<?php
$page_title = 'Product Delivery Tracking';
require_once __DIR__ . '/config.php';
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

<?php if ($activeDelivery): ?>
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

        <!-- Visual Progress Bar -->
        <?php
        $steps = ['Order Confirmed', 'Processing', 'In Transit', 'Out for Delivery', 'Delivered'];
        $currentIdx = 0;
        $statusLower = strtolower($activeDelivery['delivery_status']);
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

        <!-- Delivery Metadata Grid -->
        <div class="delivery-meta-grid">
            <div class="meta-item">
                <span class="meta-label">Associated Order</span>
                <span class="meta-value"><strong><?php echo htmlspecialchars($activeDelivery['order_number']); ?></strong></span>
            </div>
            <div class="meta-item">
                <span class="meta-label">Estimated Delivery</span>
                <span class="meta-value" style="color:#059669;font-weight:600;">
                    <?php echo $activeDelivery['actual_delivery'] ? 'Delivered on ' . date('M d, Y', strtotime($activeDelivery['actual_delivery'])) : date('M d, Y', strtotime($activeDelivery['estimated_delivery'])); ?>
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

        <!-- Checkpoints Timeline -->
        <?php if (!empty($activeDelivery['timeline'])): ?>
            <div class="delivery-timeline-section">
                <h3>📍 Transit History & Checkpoints</h3>
                <div class="timeline-container">
                    <?php foreach ($activeDelivery['timeline'] as $chk): ?>
                        <div class="timeline-point">
                            <div class="timeline-dot"></div>
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
