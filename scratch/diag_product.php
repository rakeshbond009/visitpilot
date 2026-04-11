<?php
require 'includes/dahua_helper.php';
require 'includes/db.php';

$config = DahuaHelper::get_config($pdo);
// Step 1: Get App Token via simplest method
$url = $config['base_url'] . '/open-api/api-base/auth/getAppAccessToken';
$body = json_encode(['appId' => $config['client_id'], 'appSecret' => $config['client_secret']]);
$timestamp = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));
$factor = $config['client_id'] . $timestamp . $nonce . "v1" . $config['client_secret'];
$sign = strtoupper(md5($factor));

$headers = [
    'content-type: application/json',
    'version: v1',
    'accesskey: ' . $config['client_id'],
    'timestamp: ' . $timestamp,
    'nonce: ' . $nonce,
    'sign: ' . $sign
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$res = curl_exec($ch);
$data = json_decode($res, true);
$token = $data['data']['appAccessToken'] ?? null;

if (!$token) {
    die("Token fail: " . $res);
}

echo "App Token: $token\n";

// Step 2: List Products to find the real Product ID
$prodUrl = $config['base_url'] . '/open-api/api-base/v1/product/list?page=1&pageSize=10';
$ch = curl_init($prodUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: $token"]);
$res = curl_exec($ch);
echo "Product List: " . $res . "\n";
