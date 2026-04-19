<?php
require_once '../includes/dahua_helper.php';
require_once '../includes/db.php';

header('Content-Type: text/plain');

$sn = 'BE10FCDPAJ955DE';
$pid = '5363';
$config = DahuaHelper::getConfig();
$token = DahuaHelper::getAuthToken();

echo "Deep Probe for User $pid on Device $sn\n\n";

$tests = [
    'Test 1: Direct ID' => "/open-api/api-device/person/getPerson",
    'Test 2: Search Condition' => "/open-api/api-device/person/getPeopleByCondition",
    'Test 3: Start Find' => "/open-api/api-device/person/pageGetPerson"
];

foreach ($tests as $label => $path) {
    echo "--- $label ($path) ---\n";
    $body = json_encode([
        'deviceId' => $sn,
        'personId' => $pid,
        'pageSize' => 10,
        'pageNum' => 1
    ]);
    
    // Experimenting with payload structure
    if ($label === 'Test 2: Search Condition') {
        $body = json_encode(['deviceId' => $sn, 'condition' => ['personId' => $pid]]);
    }

    $headers = DahuaHelper::generateV2Headers($path, $body, $config['dahua_app_id'], $config['dahua_app_secret']);
    $headers[] = "Authorization: $token";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://sgp-dcloud.all-over-world.com" . $path);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Short timeout for speed
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "HTTP Code: $httpCode\n";
    echo "Response: $response\n\n";
}
