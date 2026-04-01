<?php
require_once 'includes/db.php';
try {
    $stmt = $master_pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($settings);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
