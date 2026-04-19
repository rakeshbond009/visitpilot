<?php
require_once '../includes/db.php';

try {
    $pdo->exec("ALTER TABLE machine_users ADD COLUMN IF NOT EXISTS face_count INT DEFAULT 0 AFTER card_no");
    $pdo->exec("ALTER TABLE machine_users ADD COLUMN IF NOT EXISTS fp_count INT DEFAULT 0 AFTER face_count");
    $pdo->exec("ALTER TABLE machine_users ADD COLUMN IF NOT EXISTS photo_path VARCHAR(255) DEFAULT NULL AFTER fp_count");
    echo "Database updated successfully.\n";

    // Trigger a sync for any existing "Unknown" users
    require_once '../includes/dahua_helper.php';
    $sn = 'BE10FCDPAJ955DE';
    DahuaHelper::syncAllUsers($sn);
    echo "Initial sync triggered for existing users.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
