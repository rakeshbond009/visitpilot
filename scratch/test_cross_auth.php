<?php
$appid = '2042539358257250304'; // VISITPILOT APP ID
$secret = 'iO3SGz6jVLcIlUkcEtO0N6MFnALAfyGE'; // PRIMARY SECRET
$prodId = '1539964762'; // VISITPILOT PRODUCT ID

$ts = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));
$factor = $appid . $prodId . $ts . $nonce . "v1" . $secret;
$sign = strtoupper(md5($factor));

$headers = [
    'content-type: application/json',
    'version: v1',
    'accesskey: ' . $appid,
    'timestamp: ' . $ts,
    'nonce: ' . $nonce,
    'sign: ' . $sign,
    'productid: ' . $prodId
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
echo "CROSS-AUTH RESPONSE: " . $res . "\n";
