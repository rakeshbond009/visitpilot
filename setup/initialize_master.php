<?php
/**
 * Master Database Initializer
 * Sets up the multi-tenant architecture.
 */

// We don't require db.php here to avoid catch-22, but we need the same config
$is_local = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) || $_SERVER['HTTP_HOST'] === 'localhost';

if ($is_local) {
    $master_host = 'localhost';
    $master_user = 'root';
    $master_pass = '';
    $master_db = 'vms_master';
    $create_db = true;
} else {
    // Hosted Credentials
    $master_host = 'localhost';
    $master_user = 'u875321134_codepilotvisit';
    $master_pass = 'Eu8~ieQH?Wzc';
    $master_db = 'u875321134_visitor'; // Store tenants table in the main DB
    $create_db = false; // Don't try to create DB on shared hosting
}

try {
    // 1. Connect (to specific DB if we aren't creating one)
    $dsn = $create_db ? "mysql:host=$master_host" : "mysql:host=$master_host;dbname=$master_db";
    $m_pdo = new PDO($dsn, $master_user, $master_pass);
    $m_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "<h3>VMS Master Setup</h3>";

    // 2. Create Database if local
    if ($create_db) {
        echo "Updating/Creating Master Database: $master_db...<br>";
        $m_pdo->exec("CREATE DATABASE IF NOT EXISTS `$master_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $m_pdo->exec("USE `$master_db` ");
    }

    // 3. Create Tenants Table
    echo "Creating 'tenants' directory table...<br>";
    $m_pdo->exec("CREATE TABLE IF NOT EXISTS `tenants` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `tenant_key` varchar(50) NOT NULL UNIQUE,
        `db_host` varchar(100) DEFAULT 'localhost',
        `db_name` varchar(100) NOT NULL,
        `db_user` varchar(100) NOT NULL,
        `db_pass` varchar(100) DEFAULT '',
        `schema_version` int(11) DEFAULT 0,
        `status` enum('active','inactive') DEFAULT 'active',
        `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 4. Register Default Tenant
    // Default tenant will point to the original DB schema
    $stmt = $m_pdo->prepare("INSERT IGNORE INTO `tenants` (tenant_key, db_host, db_name, db_user, db_pass) VALUES (?, ?, ?, ?, ?)");

    if ($is_local) {
        $stmt->execute(['default', 'localhost', 'vms_db', 'root', '']);
    } else {
        $stmt->execute(['default', 'localhost', 'u875321134_visitor', 'u875321134_codepilotvisit', 'Eu8~ieQH?Wzc']);
    }

    echo "<div style='color:green; font-weight:bold;'>Success! Master configuration initialized.</div>";
    echo "<p>You can now go back to the <a href='index.php'>Login Page</a>.</p>";

} catch (PDOException $e) {
    die("<div style='color:red;'>Initialization failed: " . $e->getMessage() . "</div>");
}
