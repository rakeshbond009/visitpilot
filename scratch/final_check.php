<?php
$appid = '2042539358257250304';
$secret = 'AhesscxM05NVtR3lYY8auSDKHaWb7AIF';
$ts = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));
$prodId = '1539964762';
$factor = $appid . $prodId . $ts . $nonce . "v1" . $secret;
$sign = strtoupper(md5($factor));

$headers = [
    'content-type: application/json',
    'version: v1',
    'accesskey: ' . $appid,
    'timestamp: ' . $ts,
    'nonce: ' . $nonce,
    'sign: ' . $sign,
    'productid: 1539964762'
];

$url = 'https://open-api-sg.dolynkcloud.com/open-api/api-base/auth/getAppAccessToken';
$body = json_encode(['appId' => $appid, 'appSecret' => $secret]);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$res = curl_exec($ch);
$data = json_decode($res, true);
$token = $data['data']['appAccessToken'] ?? '';

if ($token) {
    echo "TOKEN: $token\n";
    $ch = curl_init('https://open-api-sg.dolynkcloud.com/open-api/api-base/v1/product/list?page=1&pageSize=10');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $token]);
    echo "PRODUCTS: " . curl_exec($ch) . "\n";
} else {
    echo "ERROR: $res\n";
}
