<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

$results = [
    'machine_users_count' => $pdo->query("SELECT COUNT(*) FROM machine_users")->fetchColumn(),
    'latest_machine_users' => $pdo->query("SELECT * FROM machine_users ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC),
    'latest_logs' => $pdo->query("SELECT * FROM machine_logs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC),
    'device_sns' => $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'dahua_device_sns'")->fetchColumn()
];

echo json_encode($results, JSON_PRETTY_PRINT);
