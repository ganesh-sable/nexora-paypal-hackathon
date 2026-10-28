<?php
/**
 * PostgreSQL Database Installer & Migration Runner
 * Can be run from CLI: php database/setup.php
 * Or accessed via the web UI at database_setup.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

function run_database_setup(bool $forceReset = false): array {
    $results = [
        'success' => false,
        'messages' => [],
        'error' => null
    ];

    if (!in_array('pgsql', PDO::getAvailableDrivers())) {
        $results['error'] = "PostgreSQL PDO driver ('pdo_pgsql') is not enabled in PHP. Please enable extension=pdo_pgsql in php.ini.";
        return $results;
    }

    // Step 1: Check or create database
    try {
        // Try connecting to target DB directly
        $dsn = sprintf("pgsql:host=%s;port=%s;dbname=%s", DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3
        ]);
        $results['messages'][] = "Connected directly to database '" . DB_NAME . "'.";
    } catch (PDOException $e) {
        // Try connecting to default 'postgres' database to create ecommerce_db
        try {
            $adminDsn = sprintf("pgsql:host=%s;port=%s;dbname=postgres", DB_HOST, DB_PORT);
            $adminPdo = new PDO($adminDsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3
            ]);
            $results['messages'][] = "Connected to maintenance database 'postgres'. Creating '" . DB_NAME . "'...";
            
            // Check if DB exists
            $stmt = $adminPdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $stmt->execute([DB_NAME]);
            if (!$stmt->fetch()) {
                $adminPdo->exec("CREATE DATABASE \"" . DB_NAME . "\"");
                $results['messages'][] = "Database '" . DB_NAME . "' created successfully!";
            }

            // Connect to newly created database
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3
            ]);
        } catch (PDOException $adminEx) {
            $results['error'] = "Failed to connect to PostgreSQL server at " . DB_HOST . ":" . DB_PORT . " - " . $adminEx->getMessage();
            return $results;
        }
    }

    // Step 2: Run schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        $results['error'] = "schema.sql file not found at " . $schemaFile;
        return $results;
    }

    try {
        $schemaSql = file_get_contents($schemaFile);
        $pdo->exec($schemaSql);
        $results['messages'][] = "Executed schema.sql: Created tables (categories, products, customers, orders, order_items, product_deliveries, delivery_timeline, transactions).";
    } catch (PDOException $e) {
        $results['error'] = "Error applying schema: " . $e->getMessage();
        return $results;
    }

    // Step 3: Run seeds.sql
    $seedsFile = __DIR__ . '/seeds.sql';
    if (!file_exists($seedsFile)) {
        $results['error'] = "seeds.sql file not found at " . $seedsFile;
        return $results;
    }

    try {
        $seedsSql = file_get_contents($seedsFile);
        $pdo->exec($seedsSql);
        $results['messages'][] = "Executed seeds.sql: Seeded 20 categories, 240 products, demo orders, product delivery tracking and transaction history.";
    } catch (PDOException $e) {
        $results['error'] = "Error seeding database: " . $e->getMessage();
        return $results;
    }

    $results['success'] = true;
    return $results;
}

// If run from command line
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "=== Running PostgreSQL Database Setup ===\n";
    echo "Host: " . DB_HOST . ":" . DB_PORT . "\n";
    echo "Database: " . DB_NAME . "\n";
    echo "User: " . DB_USER . "\n\n";

    $res = run_database_setup(true);

    foreach ($res['messages'] as $msg) {
        echo "[+] " . $msg . "\n";
    }

    if ($res['success']) {
        echo "\n🎉 Database initialization completed successfully!\n";
    } else {
        echo "\n❌ Setup failed: " . $res['error'] . "\n";
        echo "\nNote: If PostgreSQL is not yet running, ensure your PostgreSQL service is started or run:\n";
        echo "  docker run -d --name pg-ecommerce -p 5432:5432 -e POSTGRES_PASSWORD=your_secure_password_here -e POSTGRES_DB=ecommerce_db postgres\n";
    }
}
