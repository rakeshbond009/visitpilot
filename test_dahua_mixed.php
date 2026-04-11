<?php
require_once 'includes/db.php';
require_once 'includes/dahua_helper.php';

$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$appId = $settings['dahua_app_id'];
$secret = $settings['dahua_app_secret'];
$productId = $settings['dahua_product_id'] ?? '';
$token = DahuaHelper::getAccessToken($pdo);
$deviceId = trim(explode(',', $settings['dahua_device_sns'] ?? '')[0]);

$path = '/open-api/api-iot/v2/device/accessControl/addUsers';
$url = 'https://open-api-sg.dolynkcloud.com' . $path;
$payload = [
    'deviceId' => $deviceId, 
    'users' => [
        [
            'userId' => 'VP_MIXED_TEST', 
            'userName' => 'MIXED_TEST', 
            'userType' => 0,
            'departmentId' => 1,
            'validityPeriod' => '2037-12-31 23:59:59'
        ]
    ]
];
$body = json_encode($payload);

$ts = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));

// MIXED MODE FACTOR (V1 Logic)
$factor = $appId . $productId . $ts . $nonce . "v1" . $secret;
$sign = strtoupper(md5($factor));

$headers = [
    'content-type: application/json',
    'version: v1',
    'accesskey: ' . $appId,
    'timestamp: ' . $ts,
    'nonce: ' . $nonce,
    'sign: ' . $sign,
    'appaccesstoken: ' . $token,
    'productid: ' . $productId
];

echo "Testing Mixed Mode (V2 Auth + V1 Sync)...\n";
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$res = curl_exec($ch);
echo "Result: $res\n";
