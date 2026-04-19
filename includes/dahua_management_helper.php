<?php
/**
 * Dahua Management Helper (Isolated)
 * Handles hardware identity management without affecting core visitor sync.
 */
class DahuaManagementHelper {
    
    private static function get_config($pdo) {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'client_id' => $settings['dahua_app_id'] ?? null,
            'client_secret' => $settings['dahua_app_secret'] ?? null,
            'product_id' => $settings['dahua_product_id'] ?? '',
            'base_url' => rtrim($settings['dahua_base_url'] ?? 'https://open-api-sg.dolynkcloud.com', '/')
        ];
    }

    private static function generateHeaders($config, $method, $path, $body, $token = "") {
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $traceId = 'tid-' . bin2hex(random_bytes(8)) . '-' . $timestamp;
        $cleanBody = preg_replace('/\s+/', '', $body);
        $bodyHash = hash('sha512', $cleanBody);
        
        $stringToSign = $method . ($cleanBody === "{}" || $cleanBody === "" ? "" : "\n" . $bodyHash);
        // Match Dahua Singapore V2 signing factor
        $strAuthFactor = $config['client_id'] . $token . $timestamp . $nonce . $stringToSign;
        $sign = strtoupper(hash_hmac('sha512', $strAuthFactor, $config['client_secret']));

        $headers = [
            'Content-Type: application/json',
            'AppAccessToken: ' . $token,
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,

            'Sign: ' . $sign,
            'AccessKey: ' . $config['client_id'],
            'Version: V2',
            'X-TraceId-Header: ' . $traceId,
            'ProductID: ' . ($config['product_id'] ?: '1'),
            'Accept-Language: en-US'
        ];
        return $headers;
    }

    public static function getPeopleList($deviceId, $pdo) {
        $config = self::get_config($pdo);
        require_once 'dahua_helper.php'; 
        $token = DahuaHelper::getAccessToken($pdo);
        if (!$token) return ['error' => 'Auth Token Failed'];

        // Correct V2 Path for Singapore Region Access Control User List
        $path = '/open-api/api-iot/v2/device/accessControl/user/pageGet';
        $url = $config['base_url'] . $path;
        
        $body = json_encode([
            'deviceId' => $deviceId,
            'pageSize' => 100,
            'pageNum' => 1
        ]);

        $headers = self::generateHeaders($config, "POST", $path, $body, $token);
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($http_code !== 200) return ['error' => "HTTP $http_code", 'raw' => $response];
        
        return $data['data'] ?? ['error' => 'No Data', 'raw' => $response];
    }
}
