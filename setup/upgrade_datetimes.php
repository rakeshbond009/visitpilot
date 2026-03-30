<?php
/**
 * Multi-Tenant Database Upgrade Script
 * Converts all TIMESTAMP fields to DATETIME across Master and all Tenant databases.
 */

require_once '../includes/db.php';

// Check if Master PDO is available
if (!isset($master_pdo)) {
    die("Master database connection not found in db.php. Check your configuration.");
}

echo "<h2>System-Wide TIMESTAMP to DATETIME Migration</h2>";
echo "Starting migration...<br><br>";

try {
    // 1. Get all tenants from the master database
    $tenants = $master_pdo->query("SELECT * FROM tenants WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Loop through each tenant and upgrade their database
    foreach ($tenants as $tenant) {
        $t_key = $tenant['tenant_key'];
        $t_db = $tenant['db_name'];
        echo "Updating Tenant: <strong>$t_key</strong> (DB: $t_db)... ";

        try {
            // Establish a temporary connection to the tenant DB
            $t_dsn = "mysql:host={$tenant['db_host']};dbname={$tenant['db_name']};charset=utf8mb4";
            $t_pdo = new PDO($t_dsn, $tenant['db_user'], $tenant['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Run the migration SQL for tenant core tables
            $t_pdo->exec("ALTER TABLE `users` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `visit_purposes` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `departments` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `employees` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `visitors` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `access_areas` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `visits` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `visit_members` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `audit_logs` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `visit_otps` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `support_requests` MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");
            
            // Devices & Sessions (with auto-update triggers)
            $t_pdo->exec("ALTER TABLE `user_devices` MODIFY `last_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            $t_pdo->exec("ALTER TABLE `user_sessions` 
                MODIFY `last_activity` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                MODIFY `expires_at` datetime DEFAULT NULL,
                MODIFY `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP");

            echo "<span style='color:green;'>SUCCESS</span><br>";
        } catch (PDOException $e) {
            echo "<span style='color:red;'>FAILED - " . $e->getMessage() . "</span><br>";
        }
    }

    // 3. Upgrade the Master Database itself
    echo "<br>Updating Master Database (Tenants table)... ";
    try {
        $master_pdo->exec("ALTER TABLE `tenants` MODIFY `created_at` datetime DEFAULT CURRENT_TIMESTAMP");
        echo "<span style='color:green;'>SUCCESS</span><br>";
    } catch (PDOException $e) {
        echo "<span style='color:red;'>FAILED - " . $e->getMessage() . "</span><br>";
    }

    echo "<br><strong>Migration Complete!</strong> All active tenants and the master database have been updated to use DATETIME.";
    echo "<br><p><a href='../index.php'>Go to Home</a></p>";

} catch (Exception $e) {
    echo "<br><span style='color:red; font-weight:bold;'>Critical Error: " . $e->getMessage() . "</span>";
}
