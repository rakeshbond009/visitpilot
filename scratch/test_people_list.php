<?php
/**
 * Test pageGetPerson endpoint directly - mirrors DahuaHelper exactly
 */
require_once '../includes/db.php';
header('Content-Type: application/json');

// Get config from DB
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$appId     = $rows['dahua_app_id'] ?? '';
$secret    = $rows['dahua_app_secret'] ?? '';
$productId = $rows['dahua_product_id'] ?? '';
$baseUrl   = $rows['dahua_base_url'] ?? 'https://open-api-sg.dolynkcloud.com';
$deviceSns = $rows['dahua_device_sns'] ?? 'BE10FCDPAJ955DE';

// Step 1: Get token (same as DahuaHelper::getAccessToken)
function getToken($appId, $secret, $productId, $baseUrl) {
    $path = '/open-api/api-base/auth/getAppAccessToken';
    $url  = $baseUrl . $path;
    $body = '{}';
    $timestamp = (string)round(microtime(true) * 1000);
    $nonce     = bin2hex(random_bytes(16));
    
    $cleanBody    = '{}';
    $bodyHash     = hash('sha512', $cleanBody);
    $stringToSign = "POST\n" . $bodyHash;
    $strAuth      = $appId . $timestamp . $nonce . $stringToSign;
    $sign         = strtoupper(hash_hmac('sha512', $strAuth, $secret));
    
    $headers = [
        'Content-Type: application/json',
        'Version: V1',
        'AccessKey: ' . $appId,
        'Timestamp: ' . $timestamp,
        'Nonce: ' . $nonce,
        'Sign: ' . $sign,
        'ProductID: ' . $productId,
        'Accept-Language: en-US'
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers
    ]);
    $resp = curl_exec($ch);
    $data = json_decode($resp, true);
    curl_close($ch);
    return ['token' => $data['data']['appAccessToken'] ?? null, 'raw' => $resp];
}

// Step 2: Call pageGetPerson exactly as DahuaHelper does
function callPageGetPerson($appId, $secret, $productId, $baseUrl, $token, $deviceId) {
    $path = '/open-api/api-device/person/pageGetPerson';
    $url  = $baseUrl . $path;
    $body = json_encode(['deviceId' => $deviceId, 'pageSize' => 100, 'pageNum' => 1]);
    
    $timestamp = (string)round(microtime(true) * 1000);
    $nonce     = bin2hex(random_bytes(16));
    $traceId   = 'tid-' . bin2hex(random_bytes(8)) . '-' . $timestamp;
    
    $cleanBody    = preg_replace('/\s+/', '', $body);
    $bodyHash     = hash('sha512', $cleanBody);
    // DahuaHelper passes $path into generateSignV2 when calling getPeopleList (line 524)
    $stringToSign = "POST\n" . $bodyHash;
    // With path in factor (as DahuaHelper does: $path passed to generateSignV2)
    $strAuth  = $appId . $token . $timestamp . $nonce . $path . $stringToSign;
    $sign     = strtoupper(hash_hmac('sha512', $strAuth, $secret));
    
    $headers = [
        'Content-Type: application/json',
        'Version: V1',
        'AccessKey: ' . $appId,
        'Timestamp: ' . $timestamp,
        'Nonce: ' . $nonce,
        'Sign: ' . $sign,
        'ProductID: ' . $productId,
        'X-TraceId-Header: ' . $traceId,
        'Accept-Language: en-US',
        'AppAccessToken: ' . $token
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['http_code' => $httpCode, 'raw' => $resp, 'parsed' => json_decode($resp, true)];
}

$tokenResult = getToken($appId, $secret, $productId, $baseUrl);
$token = $tokenResult['token'];

$result = [
    'config'       => ['appId' => $appId, 'productId' => $productId, 'baseUrl' => $baseUrl, 'deviceSns' => $deviceSns],
    'token_raw'    => $tokenResult['raw'],
    'token_ok'     => !empty($token),
    'person_list'  => $token ? callPageGetPerson($appId, $secret, $productId, $baseUrl, $token, $deviceSns) : 'NO TOKEN'
];

echo json_encode($result, JSON_PRETTY_PRINT);
