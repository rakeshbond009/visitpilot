<?php
require 'includes/db.php';
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['setting_key'] . ": " . $row['setting_value'] . "\n";
}
