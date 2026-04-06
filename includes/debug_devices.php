<?php
require_once __DIR__ . '/db.php';
$userId = 3;
$stmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id = ?");
$stmt->execute([$userId]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "User ID $userId Devices:\n";
print_r($devices);
?>
