<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/dahua_helper.php';

header('Content-Type: text/plain');

try {
    $token = DahuaHelper::getAccessToken($pdo);
    $config = (new ReflectionMethod('DahuaHelper', 'get_config'))->invoke(null, $pdo);
    $deviceId = trim(explode(',', $config['device_sns'] ?? '')[0]);

    echo "Running Endpoint Tests on Live Server...\n";

    function test_endpoint($path, $payload) {
        global $config, $token;
        echo "\n=== Testing $path ===\n";
        $body = json_encode($payload);
        echo "Body: $body\n";
        
        $headers = (new ReflectionMethod('DahuaHelper', 'generateSignV2'))->invoke(null, $config, "POST", $body, $token);
        
        $ch = curl_init($config['base_url'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        echo "HTTP $code\n";
        echo "Response: $response\n";
    }

    test_endpoint('/open-api/api-iot/v2/device/accessControl/getUsers', [
        'productId' => (string)$config['product_id'],
        'deviceId'  => $deviceId,
        'pageSize'  => 10,
        'pageNum'   => 1
    ]);

    test_endpoint('/open-api/api-iot/v2/device/accessControl/getUsers', [
        'productId' => (int)$config['product_id'],
        'deviceId'  => $deviceId,
        'pageSize'  => 10,
        'pageNum'   => 1
    ]);

    test_endpoint('/open-api/api-iot/v2/device/accessControl/getUsers', [
        'deviceId'  => $deviceId,
        'pageSize'  => 10,
        'pageNum'   => 1
    ]);

    test_endpoint('/open-api/api-device/person/pageGetPerson', [
        'deviceId' => $deviceId,
        'pageSize' => 10,
        'pageNum' => 1
    ]);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
