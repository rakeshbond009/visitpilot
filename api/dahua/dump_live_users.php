<?php
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: text/plain');
$_SESSION['tenant_key'] = 'siddhi'; // SET TENANT HERE!

global $master_pdo;
$stmt = $master_pdo->prepare("SELECT * FROM tenants WHERE tenant_key = ?");
$stmt->execute(['siddhi']);
$tenant = $stmt->fetch();
$pdoSiddhi = new PDO("mysql:host={$tenant['db_host']};dbname={$tenant['db_name']}", $tenant['db_user'], $tenant['db_pass']);
$pdoSiddhi->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $pdoSiddhi->query("SELECT id, name, card_no, pwd_count, user_type, permission_level FROM machine_users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

print_r($users);
