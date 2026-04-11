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
$payload = ['deviceId' => $deviceId, 'users' => [['userId' => 'VP_TEST', 'userName' => 'TEST', 'userType' => 0]]];
$body = json_encode($payload);
$cleanBody = preg_replace('/[ \t\n\r\f\v\x0B]/u', '', $body);
$hash = hash('sha512', $cleanBody);

$variants = [
    "V1 (Simple Concat)" => $appId . $token . "POST" . $hash,
    "V2 (Newline Path)" => $appId . $token . "POST\n" . $path . "\n" . $hash,
    "V3 (No Path, Newline Hash)" => $appId . $token . "POST\n" . $hash,
    "V4 (Full Portal)" => $appId . $token . "POST" . $hash
];

foreach ($variants as $name => $factorBase) {
    $time = (string)round(microtime(true) * 1000);
    $nonce = 'web-' . bin2hex(random_bytes(8)) . '-' . $time;
    
    // Try WITHOUT appId in the factor
    $sign = strtoupper(hash_hmac('sha512', $token . $time . $nonce . $factorBase, $secret));
    
    $headers = [
        'Content-Type: application/json',
        'Version: v1',
        'AccessKey: ' . $appId,
        'Timestamp: ' . $time,
        'Nonce: ' . $nonce,
        'Sign: ' . $sign,
        'AppAccessToken: ' . $token,
        'ProductId: ' . ($settings['dahua_product_id'] ?? '')
    ];

    echo "Testing $name...\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    echo "Response: $res\n\n";
    
    if (strpos($res, '"success":true') !== false) {
        die("!!! SUCCESS WITH $name !!!\nFactor was: $factorBase\n");
    }
}
