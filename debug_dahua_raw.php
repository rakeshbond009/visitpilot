<?php
require_once 'includes/db.php';
require_once 'includes/dahua_helper.php';

echo "<h1>Dahua Cloud Raw Debug (ID 101)</h1>";

$pdo = DahuaHelper::getPDO();
$config = DahuaHelper::get_config($pdo);
$deviceId = 'BE10FCDPAJ955DE';
$personId = '101';

echo "<h3>Config Info</h3>";
echo "Base URL: " . $config['base_url'] . "<br>";
echo "Product ID: " . $config['product_id'] . "<br>";

echo "<h3>Testing V2 Profile Fetch (Most Reliable)</h3>";
$resV2 = DahuaHelper::getPersonDetail($deviceId, $personId, $pdo); // We'll make this try V2
echo "<pre>V2 Result:\n" . print_r($resV2, true) . "</pre>";

echo "<h3>Testing V1 User List</h3>";
$resList = DahuaHelper::getPeopleList($pdo, $deviceId);
echo "<pre>List Result (First 3):\n" . print_r(array_slice((array)($resList['data']['pageData'] ?? $resList), 0, 3), true) . "</pre>";

echo "<h3>Last Log Entries</h3>";
$logs = $pdo->query("SELECT * FROM machine_logs ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>" . print_r($logs, true) . "</pre>";
