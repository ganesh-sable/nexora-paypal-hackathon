<?php
$page_title = 'PostgreSQL Database Setup & Diagnostics';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/database/setup.php';

$message = null;
$messageType = 'info';

// Handle initialize action
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'init_db') {
        $result = run_database_setup(true);
        if ($result['success']) {
            $message = "Database initialized and seeded successfully! 20 Categories, 240 Products, Deliveries, and Transaction History are now active in PostgreSQL.";
            $messageType = 'success';
        } else {
            $message = "Database Setup Notice: " . $result['error'];
            $messageType = 'danger';
        }
    }
}

$status = DB::getStatus();
require_once __DIR__ . '/header.php';
?>

<div class="db-setup-header">
    <div class="badge-tag">🐘 PostgreSQL Integration</div>
    <h1 class="page-title" style="margin-bottom:0.5rem;">Database Management & Setup</h1>
    <p class="page-subtitle">Configure, test and migrate your PostgreSQL database tables, products, delivery tracking and transaction history.</p>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 2rem;">
        <?php if ($messageType === 'success'): ?>
            ✅ <strong>Success:</strong> <?php echo htmlspecialchars($message); ?>
        <?php else: ?>
            ⚠️ <strong>Notice:</strong> <?php echo htmlspecialchars($message); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="db-grid">
    <!-- Connection Status Card -->
    <div class="db-card">
        <div class="db-card-header">
            <h3>🔌 Connection Status</h3>
            <span class="status-pill <?php echo $status['connected'] ? 'status-connected' : 'status-disconnected'; ?>">
                <span class="status-dot"></span>
                <?php echo $status['connected'] ? 'PostgreSQL Connected' : 'Disconnected / Standby'; ?>
            </span>
        </div>
        <div class="db-card-body">
            <table class="db-info-table">
                <tr>
                    <td><strong>Driver:</strong></td>
                    <td><code><?php echo htmlspecialchars($status['driver']); ?></code></td>
                </tr>
                <tr>
                    <td><strong>Host:</strong></td>
                    <td><code><?php echo htmlspecialchars($status['host']); ?></code></td>
                </tr>
                <tr>
                    <td><strong>Port:</strong></td>
                    <td><code><?php echo htmlspecialchars($status['port']); ?></code></td>
                </tr>
                <tr>
                    <td><strong>Database Name:</strong></td>
                    <td><code><?php echo htmlspecialchars($status['database']); ?></code></td>
                </tr>
                <tr>
                    <td><strong>DB User:</strong></td>
                    <td><code><?php echo htmlspecialchars($status['user']); ?></code></td>
                </tr>
                <?php if ($status['connected']): ?>
                    <tr>
                        <td><strong>PostgreSQL Version:</strong></td>
                        <td><span class="badge-pill bg-light"><?php echo htmlspecialchars($status['server_version'] ?? 'PostgreSQL'); ?></span></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td><strong>Status Details:</strong></td>
                        <td style="color:#ef4444;font-size:0.875rem;"><?php echo htmlspecialchars($status['error'] ?: 'PostgreSQL server not detected at localhost:5432'); ?></td>
                    </tr>
                <?php endif; ?>
            </table>

            <div style="margin-top:1.5rem;">
                <form method="POST" style="display:inline-block;width:100%;">
                    <input type="hidden" name="action" value="init_db">
                    <button type="submit" class="btn btn-primary" style="width:100%;padding:0.75rem 1.25rem;">
                        🔄 Initialize & Seed PostgreSQL Database
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Data Statistics Card -->
    <div class="db-card">
        <div class="db-card-header">
            <h3>📊 Live Database Records</h3>
            <span class="badge-pill bg-primary">Table Metrics</span>
        </div>
        <div class="db-card-body">
            <div class="stats-mini-grid">
                <div class="stat-mini-box">
                    <span class="stat-mini-icon">🏷️</span>
                    <div class="stat-mini-val"><?php echo $status['connected'] ? $status['counts']['categories'] : '20 (Demo)'; ?></div>
                    <div class="stat-mini-lbl">Categories</div>
                </div>
                <div class="stat-mini-box">
                    <span class="stat-mini-icon">📦</span>
                    <div class="stat-mini-val"><?php echo $status['connected'] ? $status['counts']['products'] : '240 (Demo)'; ?></div>
                    <div class="stat-mini-lbl">Products</div>
                </div>
                <div class="stat-mini-box">
                    <span class="stat-mini-icon">🛒</span>
                    <div class="stat-mini-val"><?php echo $status['connected'] ? $status['counts']['orders'] : '5+ (Demo)'; ?></div>
                    <div class="stat-mini-lbl">Orders</div>
                </div>
                <div class="stat-mini-box">
                    <span class="stat-mini-icon">🚚</span>
                    <div class="stat-mini-val"><?php echo $status['connected'] ? $status['counts']['deliveries'] : '5+ (Demo)'; ?></div>
                    <div class="stat-mini-lbl">Deliveries</div>
                </div>
                <div class="stat-mini-box">
                    <span class="stat-mini-icon">💳</span>
                    <div class="stat-mini-val"><?php echo $status['connected'] ? $status['counts']['transactions'] : '6+ (Demo)'; ?></div>
                    <div class="stat-mini-lbl">Transactions</div>
                </div>
            </div>

            <div class="db-note-box">
                <p>💡 <strong>Automatic Hybrid Mode:</strong> If your PostgreSQL instance is currently offline or still launching, Nexora seamlessly runs in standalone catalog mode with all 20 categories and 240 products available for browsing and checkout. Once PostgreSQL is started, all operations automatically persist directly to PostgreSQL!</p>
            </div>
        </div>
    </div>
</div>

<!-- PostgreSQL Quick Start Instructions -->
<div class="db-card" style="margin-top:2rem;">
    <div class="db-card-header">
        <h3>🚀 How to Run PostgreSQL for This Project</h3>
    </div>
    <div class="db-card-body">
        <div class="quickstart-steps">
            <div class="step-item">
                <div class="step-num">1</div>
                <div class="step-content">
                    <h4>Option A: Run with Docker in 5 Seconds (Quickest)</h4>
                    <p>Run the following command in terminal to launch an official PostgreSQL 16 container:</p>
                    <pre class="code-block"><code>docker run --name pg-ecommerce -e POSTGRES_PASSWORD=your_secure_password_here -e POSTGRES_DB=ecommerce_db -p 5432:5432 -d postgres:latest</code></pre>
                </div>
            </div>

            <div class="step-item">
                <div class="step-num">2</div>
                <div class="step-content">
                    <h4>Option B: Native PostgreSQL / pgAdmin / Neon / Supabase</h4>
                    <p>You can configure any PostgreSQL connection via <code>.env</code> file or environment variables:</p>
                    <pre class="code-block"><code># .env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=ecommerce_db
DB_USER=postgres
DB_PASS=your_secure_password_here</code></pre>
                </div>
            </div>

            <div class="step-item">
                <div class="step-num">3</div>
                <div class="step-content">
                    <h4>Option C: Import Schema & Seeds via CLI</h4>
                    <p>Execute the SQL files directly using <code>psql</code>:</p>
                    <pre class="code-block"><code>psql -U postgres -d ecommerce_db -f database/schema.sql
psql -U postgres -d ecommerce_db -f database/seeds.sql</code></pre>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
