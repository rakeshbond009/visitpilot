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
        'userId' => 'VP_UPPER_TEST', 
        'userName' => 'UPPER_TEST', 
        'userType' => 0
    ]]
];
$body = json_encode($payload);

$timestamp = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));

// Using the PROVEN factor order from the success log
$factor = $appId . $body . $timestamp . $nonce . "POST";
$sign = strtoupper(hash_hmac('sha512', $factor, $secret));

$headers = [
    'content-type: application/json',
    'version: v1',
    'accesskey: ' . $appId,
    'timestamp: ' . $timestamp,
    'nonce: ' . $nonce,
    'sign: ' . $sign,
    'appaccesstoken: ' . $token,
    'productid: ' . ($settings['dahua_product_id'] ?? ''),
    'x-traceid-header: ' . bin2hex(random_bytes(16))
];

echo "Testing Case-Perfect sync...\n";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$res = curl_exec($ch);
echo "Result: $res\n";
