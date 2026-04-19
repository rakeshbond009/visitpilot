<?php
require_once __DIR__ . '/../../includes/dahua_helper.php';
// Use explicit DB for siddhi as requested
$dsn = "mysql:host=localhost;dbname=vms_siddhi;charset=utf8mb4";
$user = "u921200140_pmsadmin"; 
// I don't know the prod db password, but I can fetch it from db.php by requiring it first.
// Wait, I can just include db.php with correct relative path.
require_once __DIR__ . '/../../includes/db.php';

try {
    $pdo->exec("USE vms_siddhi"); // Force siddhi db
    $res = DahuaHelper::getPersonDetail('BE10FCDPAJ955DE', '01');
    header('Content-Type: application/json');
    echo json_encode($res, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
