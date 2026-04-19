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

    // Hardcode real creds from live for testing locally !!
    $config['product_id'] = '1539964762';
    $deviceId = 'BE10FCDPAJ955DE';
    $config['client_id'] = 'efebfc9d8db142a78b54133ae021da32';
    $config['client_secret'] = '6e7292150d1b46efa9b9eeb2576ca5cc';
    // Regenerate real token
    $body = json_encode(['clientId' => $config['client_id'], 'clientSecret' => $config['client_secret']]);
    $ch = curl_init('https://open-api-sg.dolynkcloud.com/open-api/api-iot/v1/auth/developer/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $res = json_decode(curl_exec($ch), true); curl_close($ch);
    $token = $res['data']['token'];

    echo "Running Endpoint Tests on Local for user/pageGet...\n";

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
    ]);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
