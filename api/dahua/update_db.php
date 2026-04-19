<?php
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: text/plain');

global $master_pdo;
$stmt = $master_pdo->query("SELECT * FROM tenants WHERE status = 'active'");
$tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

function updateTenantDB($pdo, $tenant_name) {
    try {
        echo "Updating schema for $tenant_name...\n";
        
        // Add missing columns if they don't exist
        $columns_to_add = [
            'pwd_count' => "int(11) DEFAULT 0",
            'department' => "varchar(255) DEFAULT ''",
            'schedule_mode' => "varchar(255) DEFAULT ''",
            'permission_level' => "varchar(100) DEFAULT ''",
            'user_type' => "varchar(100) DEFAULT ''",
            'times_used' => "varchar(50) DEFAULT ''",
            'general_plan' => "varchar(100) DEFAULT ''",
            'holiday_plan' => "varchar(100) DEFAULT ''"
        ];
        
        foreach ($columns_to_add as $col => $type) {
            try {
                $check = $pdo->query("SHOW COLUMNS FROM machine_users LIKE '$col'");
                if ($check->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE machine_users ADD COLUMN `$col` $type");
                    echo "  - Added $col\n";
                }
            } catch (Exception $ex) {
                echo "  ! Failed to process $col: " . $ex->getMessage() . "\n";
            }
        }
    } catch (Exception $e) {
        echo "ERROR for $tenant_name: " . $e->getMessage() . "\n";
    }
}

// 1. Update master just in case it has machine_users
try {
    $check = $master_pdo->query("SHOW TABLES LIKE 'machine_users'");
    if ($check->rowCount() > 0) {
        updateTenantDB($master_pdo, 'MASTER (system)');
    }
} catch(Exception $e) {}

// 2. Update all active tenants
foreach ($tenants as $t) {
    try {
        $t_pdo = new PDO("mysql:host={$t['db_host']};dbname={$t['db_name']}", $t['db_user'], $t['db_pass']);
        $t_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Verify table exists
        $check = $t_pdo->query("SHOW TABLES LIKE 'machine_users'");
        if ($check->rowCount() > 0) {
            updateTenantDB($t_pdo, $t['tenant_key']);
        }
    } catch (Exception $e) {
        echo "Connection ERROR for {$t['tenant_key']}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone!\n";
