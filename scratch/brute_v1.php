<?php
require 'includes/db.php';
require 'includes/dahua_helper.php';

$config = DahuaHelper::get_config($pdo);
$appId = $config['client_id'];
$secret = $config['client_secret'];
$prodId = $config['product_id'];

$timestamp = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));

$variants = [
    'A' => $appId . $prodId . $timestamp . $nonce . "v1" . $secret,
    'B' => $appId . $prodId . $timestamp . $nonce . $secret,
    'C' => $appId . $timestamp . $nonce . $prodId . $secret,
    'D' => $appId . $timestamp . $nonce . $secret,
    'E' => $appId . $prodId . "v1" . $timestamp . $nonce . $secret,
    'F' => $prodId . $appId . $timestamp . $nonce . $secret,
    'G' => $appId . $prodId . $timestamp . $nonce . "v2" . $secret,
    'H' => $appId . $timestamp . $nonce . "v1" . $secret,
];

foreach ($variants as $key => $factor) {
    $sign = strtoupper(md5($factor));
    $headers = [
        'content-type: application/json',
        'version: v1',
        'accesskey: ' . $appId,
        'timestamp: ' . $timestamp,
        'nonce: ' . $nonce,
        'sign: ' . $sign,
        'productid: ' . $prodId
    ];

    $ch = curl_init($config['base_url'] . '/open-api/api-base/auth/getAppAccessToken');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['appId' => $appId, 'appSecret' => $secret]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $data = json_decode($res, true);
    
    if (isset($data['data']['appAccessToken'])) {
        echo "SUCCESS! Variant $key worked. Token: " . $data['data']['appAccessToken'] . "\n";
        exit;
    } else {
        echo "Variant $key failed: " . ($data['msg'] ?? $res) . "\n";
    }
}
echo "All common variants failed.\n";
