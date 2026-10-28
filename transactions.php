<?php
$page_title = 'Product Transaction History';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/orders_service.php';
require_once __DIR__ . '/header.php';

$searchQuery = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'all');

$transactions = OrdersService::getTransactions($statusFilter, $searchQuery);

// Calculate KPI stats
$totalVolume = 0;
$successCount = 0;
$pendingCount = 0;
$failedCount = 0;

$allTxns = OrdersService::getTransactions();
foreach ($allTxns as $t) {
    if (strtolower($t['payment_status']) === 'success') {
        $totalVolume += (float)$t['amount'];
        $successCount++;
    } elseif (strtolower($t['payment_status']) === 'pending') {
        $pendingCount++;
    } elseif (strtolower($t['payment_status']) === 'failed') {
        $failedCount++;
    }
}
$avgTicket = $successCount > 0 ? ($totalVolume / $successCount) : 0;
?>

<div class="db-setup-header">
    <div class="badge-tag">💳 Financial Records</div>
    <h1 class="page-title" style="margin-bottom:0.5rem;">Product Transaction History</h1>
    <p class="page-subtitle">Real-time payment logs, transaction receipts and payment gateway audit records.</p>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon bg-green-light">💵</div>
        <div class="kpi-info">
            <span class="kpi-title">Total Processed Volume</span>
            <span class="kpi-val">$<?php echo number_format($totalVolume, 2); ?></span>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon bg-blue-light">✅</div>
        <div class="kpi-info">
            <span class="kpi-title">Successful Payments</span>
            <span class="kpi-val"><?php echo $successCount; ?></span>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon bg-amber-light">⏳</div>
        <div class="kpi-info">
            <span class="kpi-title">Pending / In-Review</span>
            <span class="kpi-val"><?php echo $pendingCount; ?></span>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon bg-purple-light">📈</div>
        <div class="kpi-info">
            <span class="kpi-title">Average Order Value</span>
            <span class="kpi-val">$<?php echo number_format($avgTicket, 2); ?></span>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<div class="section-header" style="margin-top:2rem;">
    <div class="filter-pills">
        <a href="transactions.php?status=all<?php echo $searchQuery ? '&search=' . urlencode($searchQuery) : ''; ?>" class="pill <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">All Transactions</a>
        <a href="transactions.php?status=success" class="pill <?php echo $statusFilter === 'success' ? 'active' : ''; ?>">Successful</a>
        <a href="transactions.php?status=pending" class="pill <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">Pending</a>
        <a href="transactions.php?status=failed" class="pill <?php echo $statusFilter === 'failed' ? 'active' : ''; ?>">Failed / Declined</a>
    </div>

    <!-- Search input -->
    <form action="transactions.php" method="GET" class="inline-search">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
        <input type="text" name="search" placeholder="Search by Txn Ref, Order #, Customer..." value="<?php echo htmlspecialchars($searchQuery); ?>">
        <button type="submit" class="btn btn-outline" style="padding:0.45rem 0.9rem;">Filter</button>
    </form>
</div>

<!-- Transactions Table -->
<div class="table-responsive">
    <table class="data-table">
        <thead>
            <tr>
                <th>Transaction Ref</th>
                <th>Order Number</th>
                <th>Customer</th>
                <th>Payment Method</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Date & Time</th>
                <th>Gateway Response</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;padding:2.5rem;color:#64748b;">No transaction records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($transactions as $txn): ?>
                    <?php
                    $methodIcon = '💳';
                    $mLower = strtolower($txn['payment_method']);
                    if (str_contains($mLower, 'paypal')) $methodIcon = '🅿️';
                    elseif (str_contains($mLower, 'upi')) $methodIcon = '📱';
                    elseif (str_contains($mLower, 'apple')) $methodIcon = '🍏';
                    elseif (str_contains($mLower, 'cod')) $methodIcon = '💵';
                    ?>
                    <tr>
                        <td>
                            <strong style="font-family:monospace;color:#0f172a;">
                                <?php echo htmlspecialchars($txn['transaction_ref']); ?>
                            </strong>
                        </td>
                        <td>
                            <a href="track_delivery.php?tracking=<?php echo urlencode($txn['order_number']); ?>" style="color:#2563eb;text-decoration:none;font-weight:500;">
                                <?php echo htmlspecialchars($txn['order_number']); ?>
                            </a>
                        </td>
                        <td>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($txn['customer_name'] ?? 'Guest Customer'); ?></div>
                            <small style="color:#64748b;"><?php echo htmlspecialchars($txn['customer_email'] ?? ''); ?></small>
                        </td>
                        <td>
                            <span><?php echo $methodIcon; ?> <?php echo htmlspecialchars($txn['payment_method']); ?></span>
                            <?php if (!empty($txn['card_last4']) && $txn['card_last4'] !== 'N/A'): ?>
                                <span class="badge-pill bg-light" style="font-size:0.75rem;">•••• <?php echo htmlspecialchars($txn['card_last4']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="font-size:1rem;color:#0f172a;">
                                $<?php echo number_format($txn['amount'], 2); ?>
                            </strong>
                            <span style="font-size:0.75rem;color:#64748b;"><?php echo htmlspecialchars($txn['currency'] ?? 'USD'); ?></span>
                        </td>
                        <td>
                            <span class="delivery-status-badge status-<?php echo strtolower($txn['payment_status']); ?>">
                                ● <?php echo htmlspecialchars($txn['payment_status']); ?>
                            </span>
                        </td>
                        <td style="font-size:0.85rem;color:#64748b;white-space:nowrap;">
                            <?php echo date('M d, Y - h:i A', strtotime($txn['created_at'])); ?>
                        </td>
                        <td style="font-size:0.8rem;color:#475569;max-width:240px;">
                            <?php echo htmlspecialchars($txn['gateway_response'] ?? 'Approved'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
