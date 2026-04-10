<?php
function test_url($url) {
    echo "Testing $url ...\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    echo "HTTP CODE: " . $info['http_code'] . "\n";
    echo "RESPONSE: " . substr($resp, 0, 100) . "...\n";
    echo "-----------------------------------\n";
}

$urls = [
    'https://open.dolynkcloud.com/auth/v1.0/token',
    'https://openapi.dolynkcloud.com/auth/v1.0/token'
];

foreach($urls as $u) test_url($u);
