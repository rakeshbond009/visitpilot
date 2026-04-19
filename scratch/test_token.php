<?php
/**
 * Dahua Token Diagnostic - Tests raw token fetch and shows raw Dahua response
 */
require_once '../includes/db.php';
header('Content-Type: application/json');

// Get config from DB directly
$stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$appId     = $rows['dahua_app_id'] ?? '';
$secret    = $rows['dahua_app_secret'] ?? '';
$productId = $rows['dahua_product_id'] ?? '';
$baseUrl   = $rows['dahua_base_url'] ?? 'https://open-api-sg.dolynkcloud.com';

// Build token request exactly as DahuaHelper does
$path     = '/open-api/api-base/auth/getAppAccessToken';
$url      = $baseUrl . $path;
$body     = '{}';
$timestamp = (string)round(microtime(true) * 1000);
$nonce     = bin2hex(random_bytes(16));

// V2 signature for auth token (no appAccessToken yet, no path in factor)
$cleanBody    = '{}';
$bodyHash     = hash('sha512', $cleanBody);
$stringToSign = "POST\n" . $bodyHash;  // body is not empty, so include hash
$strAuthFactor = $appId . $timestamp . $nonce . $stringToSign;
$sign = strtoupper(hash_hmac('sha512', $strAuthFactor, $secret));

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
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$raw_response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$parsed = json_decode($raw_response, true);
$token_ok = isset($parsed['data']['appAccessToken']);

echo json_encode([
    'config_check' => [
        'appId'     => $appId,
        'secret'    => substr($secret, 0, 6) . '***',
        'productId' => $productId,
        'baseUrl'   => $baseUrl,
    ],
    'token_request' => [
        'url'          => $url,
        'http_code'    => $http_code,
        'sign_factor'  => $appId . $timestamp . $nonce . '(stringToSign)',
        'raw_response' => $raw_response,
        'token_ok'     => $token_ok,
        'token'        => $token_ok ? substr($parsed['data']['appAccessToken'], 0, 20) . '...' : null,
    ]
], JSON_PRETTY_PRINT);
