<?php
/**
 * PostgreSQL Database Connection and Helper Functions
 */

require_once __DIR__ . '/config.php';

class DB {
    private static ?PDO $instance = null;
    private static bool $connectionAttempted = false;
    private static ?string $errorMessage = null;

    /**
     * Get or create PDO PostgreSQL connection
     */
    public static function getConnection(): ?PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (self::$connectionAttempted && self::$instance === null) {
            return null;
        }

        self::$connectionAttempted = true;

        if (!in_array('pgsql', PDO::getAvailableDrivers())) {
            self::$errorMessage = "PostgreSQL PDO driver ('pdo_pgsql') is not enabled in PHP.";
            return null;
        }

        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", DB_HOST, DB_PORT, DB_NAME);

        try {
            self::$instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 3
            ]);
            self::$errorMessage = null;
            return self::$instance;
        } catch (PDOException $e) {
            self::$errorMessage = $e->getMessage();
            self::$instance = null;
            return null;
        }
    }

    /**
     * Check if PostgreSQL is connected
     */
    public static function isConnected(): bool {
        return self::getConnection() !== null;
    }

    /**
     * Get error message if connection failed
     */
    public static function getErrorMessage(): ?string {
        self::getConnection();
        return self::$errorMessage;
    }

    /**
     * Get full connection status and table statistics
     */
    public static function getStatus(): array {
        $pdo = self::getConnection();
        $connected = ($pdo !== null);

        $status = [
            'connected'      => $connected,
            'host'           => DB_HOST,
            'port'           => DB_PORT,
            'database'       => DB_NAME,
            'user'           => DB_USER,
            'error'          => self::$errorMessage,
            'driver'         => in_array('pgsql', PDO::getAvailableDrivers()) ? 'pdo_pgsql (Active)' : 'Missing',
            'server_version' => null,
            'tables'         => [],
            'counts'         => [
                'categories'   => 0,
                'products'     => 0,
                'orders'       => 0,
                'deliveries'   => 0,
                'transactions' => 0
            ]
        ];

        if ($connected) {
            try {
                $status['server_version'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

                // Check table counts safely
                $checkTable = function($table) use ($pdo) {
                    try {
                        $stmt = $pdo->query("SELECT count(*) FROM " . $table);
                        return (int)$stmt->fetchColumn();
                    } catch (Exception $e) {
                        return null;
                    }
                };

                $catCount = $checkTable('categories');
                $prodCount = $checkTable('products');
                $ordCount = $checkTable('orders');
                $delivCount = $checkTable('product_deliveries');
                $txnCount = $checkTable('transactions');

                if ($catCount !== null) $status['counts']['categories'] = $catCount;
                if ($prodCount !== null) $status['counts']['products'] = $prodCount;
                if ($ordCount !== null) $status['counts']['orders'] = $ordCount;
                if ($delivCount !== null) $status['counts']['deliveries'] = $delivCount;
                if ($txnCount !== null) $status['counts']['transactions'] = $txnCount;

            } catch (Exception $e) {
                // Ignore stats fetch failure
            }
        }

        return $status;
    }

    /**
     * Execute SQL with parameters
     */
    public static function query(string $sql, array $params = []): ?PDOStatement {
        $pdo = self::getConnection();
        if (!$pdo) return null;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("DB Query Error: " . $e->getMessage() . " | SQL: " . $sql);
            return null;
        }
    }

    /**
     * Fetch all rows
     */
    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /**
     * Fetch single row
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::query($sql, $params);
        $res = $stmt ? $stmt->fetch() : null;
        return $res ?: null;
    }

    /**
     * Insert and return last inserted ID or true
     */
    public static function insert(string $sql, array $params = []): int|string|bool {
        $pdo = self::getConnection();
        if (!$pdo) return false;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("DB Insert Error: " . $e->getMessage() . " | SQL: " . $sql);
            return false;
        }
    }
}
