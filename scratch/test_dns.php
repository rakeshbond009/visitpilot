<?php
$domains = [
    'api-openapi.dolynkcloud.com',
    'api-openapi-gl.dolynkcloud.com',
    'api-openapi-in.dolynkcloud.com',
    'openapi-gl.dolynkcloud.com',
    'openapi-in.dolynkcloud.com',
    'openapi-as.dolynkcloud.com',
    'api.dolynkcloud.com',
    'open.dolynkcloud.com'
];
foreach($domains as $d) {
    $ip = gethostbyname($d);
    echo "$d => " . ($ip === $d ? 'FAILED' : $ip) . "\n";
}
