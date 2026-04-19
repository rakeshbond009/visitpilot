<?php
require_once '../includes/db.php';
require_once '../includes/dahua_helper.php';
require_once '../includes/dahua_management_helper.php';

header('Content-Type: application/json');

$sn = $_GET['sn'] ?? 'BE10FCDPAJ955DE';
$result = [
    'device_info' => DahuaManagementHelper::getDeviceInfo($sn, $pdo),
    'machine_logs' => DahuaManagementHelper::getMachineLogs($sn, $pdo)
];

echo json_encode($result, JSON_PRETTY_PRINT);
