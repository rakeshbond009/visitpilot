<?php
require 'includes/db.php';

$app_id = '2042539358257250304';
$app_secret = 'AhesscxM05NVtR3lYY8auSDKHaWb7AIF';

try {
    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'dahua_app_id'");
    $stmt->execute([$app_id]);
    
    $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'dahua_app_secret'");
    $stmt->execute([$app_secret]);
    
    echo "SUCCESS: Database settings updated to VisitPilot REAL credentials.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
