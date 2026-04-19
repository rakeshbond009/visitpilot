<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/dahua_helper.php';

header('Content-Type: text/plain');

try {
    $_SESSION['tenant_key'] = 'siddhi';
    
    global $master_pdo;
    $stmt = $master_pdo->prepare("SELECT * FROM tenants WHERE tenant_key = ?");
    $stmt->execute(['siddhi']);
    $tenant = $stmt->fetch();
    $pdoSiddhi = new PDO("mysql:host={$tenant['db_host']};dbname={$tenant['db_name']}", $tenant['db_user'], $tenant['db_pass']);
    $pdoSiddhi->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $token = DahuaHelper::getAccessToken($pdoSiddhi);
    $config = (new ReflectionMethod('DahuaHelper', 'get_config'))->invoke(null, $pdoSiddhi);
    $deviceId = trim(explode(',', $config['device_sns'] ?? '')[0]);

    echo "Running Endpoint Tests on Live Server for user/pageGet...\n";

    function test_endpoint($path, $payload, $pdo = null) {
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

    test_endpoint('/open-api/api-iot/v2/device/accessControl/user/pageGet', [
        'productId' => (string)$config['product_id'],
        'deviceId'  => $deviceId,
        'pageNo'    => 1,
        'pageSize'  => 10
    ], $pdoSiddhi);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
