<?php
/**
 * Dahua DoLynk Cloud V2 - STANDALONE HARDWARE TEST
 * Tests connection and Hardware Sync WITHOUT creating a visitor entry.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db.php';
require_once 'includes/dahua_helper.php';

header('Content-Type: text/plain');

echo "=== DAHUA HARDWARE HANDSHAKE TEST ===\n";
echo "Environment: HOSTED SERVER\n\n";

try {
    // 1. Fetch Config
    $pdo = $pdo ?? null;
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (empty($settings['dahua_app_id'])) {
        echo "DATABASE CHECK: No settings found for this tenant. Found keys: " . implode(', ', array_keys($settings)) . "\n";
        die("ERROR: Dahua configuration not found in database.\n");
    }

    echo "DATABASE CHECK: App ID found (" . substr($settings['dahua_app_id'], 0, 5) . "...)\n";
    echo "DATABASE CHECK: App Secret found? " . (!empty($settings['dahua_app_secret']) ? 'YES' : 'NO') . "\n\n";

    echo "[1/3] Fetching Access Token...\n";
    // We call it normally since you reverted the forceRefresh signature
    $token = DahuaHelper::getAccessToken($pdo);
    
    if (!$token) {
        die("FAILED: Could not get AccessToken. Check dahua_debug.txt for Sign errors.\n");
    }
    echo "SUCCESS: Token acquired.\n\n";

    // 2. Identify Device
    $deviceId = trim(explode(',', $settings['dahua_device_sns'] ?? '')[0]);
    if (empty($deviceId)) {
        die("ERROR: No Device SN found in dahua_device_sns setting.\n");
    }
    echo "[2/3] Target Device: $deviceId\n";

    // 3. Test Business API (Add Test User)
    echo "[3/3] Sending 'Add User' request to Hardware...\n";
    
    $config = [
        'client_id' => $settings['dahua_app_id'],
        'client_secret' => $settings['dahua_app_secret'],
        'product_id' => $settings['dahua_product_id'] ?? '',
        'base_url' => rtrim($settings['dahua_base_url'] ?? 'https://open-api-sg.dolynkcloud.com', '/')
    ];

    $path = '/open-api/api-iot/v2/device/accessControl/addUsers';
    $url = $config['base_url'] . $path;
    
    $payload = [
        'deviceId' => $deviceId,
        'users' => [
            [
                'userId' => 'VP_TEST_HANDSHAKE',
                'userName' => 'TEST_HANDSHAKE',
                'userType' => 0
            ]
        ]
    ];
    
    $body = json_encode($payload);
    
    // Using the V2 Signature method we built
    $reflection = new ReflectionMethod('DahuaHelper', 'generateSignV2');
    $reflection->setAccessible(true);
    $headers = $reflection->invoke(null, $config, "POST", $body, $token);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "HTTP Status: $httpCode\n";
    echo "Dahua Response: $response\n\n";

    $data = json_decode($response, true);
    if (isset($data['success']) && $data['success']) {
        echo "=========================================\n";
        echo "🎉 INTEGRATION VERIFIED!\n";
        echo "Your server is successfully talking to your Dahua Hardware.\n";
        echo "=========================================\n";
    } else {
        echo "-----------------------------------------\n";
        echo "❌ HARDWARE COMMUNICATION FAILED\n";
        echo "The Cloud accepted your token, but the Device rejected the command.\n";
        echo "Reason: " . ($data['msg'] ?? 'Unknown error') . "\n";
        echo "-----------------------------------------\n";
    }

} catch (Exception $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
}
