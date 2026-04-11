<?php
require 'includes/db.php';
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('dahua_app_id', 'dahua_app_secret', 'dahua_product_id')");
$res = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
foreach($res as $k => $v) {
    echo "$k: $v\n";
}
