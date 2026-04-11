<?php
$appid = '2042536120520671232'; // PRIMARY ACCESS KEY
$secret = 'iO3SGz6jVLcIlUkcEtO0N6MFnALAfyGE'; // PRIMARY SECRET
$ts = (string)round(microtime(true) * 1000);
$nonce = bin2hex(random_bytes(16));
$factor = $appid . $ts . $nonce . "v1" . $secret;
$sign = strtoupper(md5($factor));

$headers = [
    'content-type: application/json',
    'version: v1',
    'accesskey: ' . $appid,
    'timestamp: ' . $ts,
    'nonce: ' . $nonce,
    'sign: ' . $sign
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
echo "PRIMARY AUTH RESPONSE: " . $res . "\n";
