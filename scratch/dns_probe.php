<?php
$domains = [
    'api-openapi.dolynkcloud.com', 
    'openapi.dolynkcloud.com', 
    'api.dolynkcloud.com', 
    'openapi-la.dolynkcloud.com',
    'openapi-eu.dolynkcloud.com',
    'openapi-n.dolynkcloud.com'
];
echo "Dahua DNS Probe Started\n";
foreach($domains as $d) {
    $ip = gethostbyname($d);
    echo "$d => " . ($ip === $d ? 'FAILED' : $ip) . "\n";
}
echo "Probe Ended\n";
