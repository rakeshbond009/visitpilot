<?php
require_once 'includes/db.php';
try {
    $stmt = $master_pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_logo')");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    echo json_encode($settings);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
