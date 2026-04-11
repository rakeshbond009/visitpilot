<?php
require_once 'includes/db.php';
require_once 'includes/dahua_helper.php';

$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$appId = $settings['dahua_app_id'];
$secret = $settings['dahua_app_secret'];
$token = DahuaHelper::getAccessToken($pdo);
$deviceId = trim(explode(',', $settings['dahua_device_sns'] ?? '')[0]);
$productId = $settings['dahua_product_id'] ?? '';

$path = '/open-api/api-iot/v2/device/accessControl/addUsers';
$url = 'https://open-api-sg.dolynkcloud.com' . $path;
$payload = ['deviceId' => $deviceId, 'users' => [['userId' => 'VP_V1_BRIDGE', 'userName' => 'V1 TEST', 'userType' => 0]]];
$body = json_encode($payload);

$timestamp = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));

// V1 Signature Factor: AccessKey + ProductID + Timestamp + Nonce + Version + AppSecret
$factor = $appId . $productId . $timestamp . $nonce . "v1" . $secret;
$sign = strtoupper(md5($factor));

$headers = [
    'content-type: application/json',
    'version: v1',
    'accesskey: ' . $appId,
    'timestamp: ' . $timestamp,
    'nonce: ' . $nonce,
    'sign: ' . $sign,
    'appaccesstoken: ' . $token,
    'productid: ' . $productId
];

echo "Testing V1 (MD5) Bridge Sign...\n";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$res = curl_exec($ch);
echo "Result: $res\n";
