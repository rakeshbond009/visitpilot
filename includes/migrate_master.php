<?php
require_once __DIR__ . '/db.php';
try {
    $master_pdo->exec("ALTER TABLE tenants ADD COLUMN IF NOT EXISTS max_users INT DEFAULT 10");
    echo "Master DB Updated: max_users column added.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
