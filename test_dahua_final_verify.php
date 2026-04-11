<?php
require_once 'includes/db.php';
require_once 'includes/dahua_helper.php';

$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$appId = $settings['dahua_app_id'];
$secret = $settings['dahua_app_secret'];
$token = DahuaHelper::getAccessToken($pdo);
$deviceId = trim(explode(',', $settings['dahua_device_sns'] ?? '')[0]);

$path = '/open-api/api-iot/v2/device/accessControl/addUsers';
$url = 'https://open-api-sg.dolynkcloud.com' . $path;
$payload = [
    'deviceId' => $deviceId, 
    'users' => [[
        'userId' => 'VP_FINAL_VERIFY', 
        'userName' => 'FINAL TEST', 
        'userType' => 0
    ]]
];
// NO CLEANING. Raw JSON.
$body = json_encode($payload);

$timestamp = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));
$factor = $appId . $body . $timestamp . $nonce . "POST";
$sign = strtoupper(hash_hmac('sha512', $factor, $secret));

$headers = [
    'Content-Type: application/json',
    'Version: V1',
    'AccessKey: ' . $appId,
    'Timestamp: ' . $timestamp,
    'Nonce: ' . $nonce,
    'Sign: ' . $sign,
    'AppAccessToken: ' . $token,
    'ProductID: ' . ($settings['dahua_product_id'] ?? '')
];

echo "Factor: $factor\n";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$res = curl_exec($ch);
echo "Result: $res\n";
