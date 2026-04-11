<?php
class DahuaHelper
{

    private static function log($msg)
    {
        $logFile = dirname(__DIR__) . '/dahua_debug.txt';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
    }

    private static function get_config($pdo = null)
    {
        if (!$pdo) {
            global $pdo;
        }
        if (!$pdo)
            return [];

        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            return [
                'client_id' => $settings['dahua_app_id'] ?? null,
                'client_secret' => $settings['dahua_app_secret'] ?? null,
                'product_id' => $settings['dahua_product_id'] ?? '', // We need this now!
                'device_sns' => $settings['dahua_device_sns'] ?? '',
                'base_url' => rtrim($settings['dahua_base_url'] ?? 'https://open-api-sg.dolynkcloud.com', '/')
            ];
        } catch (Exception $e) {
            self::log("Config ERROR: " . $e->getMessage());
            return [];
        }
    }

    private static function deleteWhitespace($str)
    {
        if (!$str)
            return $str;
        // Characters to remove: Space, Tab, Newline, Carriage return, Form feed, Vertical Tab
        return preg_replace('/[ \t\n\r\f\v\x0B]/u', '', $str);
    }

    private static function generateSignV2($config, $method = "POST", $path = "", $body = "{}", $appAccessToken = "")
    {
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = 'web-' . bin2hex(random_bytes(16)) . '-' . $timestamp;
        $appId = $config['client_id'];
        $secret = $config['client_secret'];
        $productId = $config['product_id'] ?? '';
        $traceId = bin2hex(random_bytes(16));

        // 3. HMAC-SHA512 Factor (Legacy Hero Mode + ProductID for Singapore)
        // Note: We use the exact sequence that puts visitors on your screen
        $factor = $appId . $productId . $appAccessToken . $body . $timestamp . $nonce . $method;
        $sign = strtolower(hash_hmac('sha512', $factor, $secret));
        
        self::log("=== DAHUA V2 STRING TO SIGN ===\n" . $factor);

        $headers = [
            'Content-Type: application/json',
            'Version: v1',
            'AppAccessToken: ' . $appAccessToken,
            'AccessKey: ' . $appId,
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,
            'Sign: ' . $sign,
            'X-TraceId-Header: ' . $traceId,
            'ProductId: ' . $productId
        ];

        return $headers;
    }

    public static function getAccessToken($pdo = null)
    {
        $config = self::get_config($pdo);
        $appId = $config['client_id'];
        $secret = $config['client_secret'];
        $productId = $config['product_id'] ?? '';
        
        $userName = 'info@siddhitechsolution.com';
        $passWord = md5('Siddhi@!23'); 

        $cacheFile = dirname(__DIR__) . '/scratch/dahua_token_' . md5($appId . $userName) . '.json';
        if (file_exists($cacheFile)) {
            $tokenData = json_decode(file_get_contents($cacheFile), true);
            if ($tokenData && ($tokenData['expire_time'] ?? 0) > time()) {
                return $tokenData['access_token'];
            }
        }

        $url = $config['base_url'] . '/open-api/api-base/auth/userLogin';
        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = 'web-' . bin2hex(random_bytes(16)) . '-' . $timestamp;
        
        $bodyData = [
            'userName' => $userName,
            'passWord' => $passWord,
            'productId' => $productId
        ];
        $body = json_encode($bodyData);

        // SHA256 Signature for User Login
        $cleanBody = preg_replace('/[ \t\n\r\f\v\x0B]/u', '', $body);
        $bodyHash = hash('sha256', $cleanBody);
        $stringToSign = "POST\n" . $bodyHash;
        $factor = $appId . $timestamp . $nonce . $stringToSign;
        $sign = strtolower(hash_hmac('sha256', $factor, $secret));

        $headers = [
            'Content-Type: application/json',
            'Version: v1',
            'AccessKey: ' . $appId,
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,
            'Sign: ' . $sign,
            'ProductId: ' . $productId
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        curl_close($ch);

        $res = json_decode($resp, true);
        if ($res && isset($res['data']['appAccessToken'])) {
            $accessToken = $res['data']['appAccessToken'];
            $expireTime = time() + ($res['data']['expiresIn'] ?? 7000) - 60;
            file_put_contents($cacheFile, json_encode(['access_token' => $accessToken, 'expire_time' => $expireTime]));
            return $accessToken;
        }

        self::log("User Login FAIL: " . $resp);
        return null;
    }

    public static function syncVisitor($visitId, $pdo = null)
    {
        if (!$pdo) {
            global $pdo;
        }
        if (!$pdo) {
            self::log("FAIL: No DB Connection.");
            return false;
        }

        self::log("Sync: Starting v2 sync for Visit ID $visitId");
        $token = self::getAccessToken($pdo);
        if (!$token)
            return false;

        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, v.visit_code 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               WHERE v.id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch();

        if (!$visit) {
            self::log("FAIL: Visit $visitId not found.");
            return false;
        }

        $config = self::get_config($pdo);
        $deviceId = $sns = array_map('trim', explode(',', $config['device_sns']))[0] ?? '';

        // Step 1: Add User
        $userPath = '/open-api/api-iot/device/accessControl/addUsers';
        $userPayload = [
            'deviceId' => $deviceId,
            'users' => [
                [
                    'userId' => 'VP' . $visitId,
                    'userName' => $visit['visitor_name'],
                    'userType' => 0
                ]
            ]
        ];
        $userBody = json_encode($userPayload);
        $userHeaders = self::generateSignV2($config, "POST", $userPath, $userBody, $token);

        self::log("Sync Step 1: Adding user (V1 Path)...");
        $userResp = self::post($config['base_url'] . $userPath, $userBody, $userHeaders);
        $userData = json_decode($userResp, true);

        if (!isset($userData['success']) || !$userData['success']) {
            self::log("Sync STEP 1 FAIL: " . $userResp);
            return false;
        }

        // --- STEP 2: Authorize Face ---
        $photoRelative = ltrim($visit['photo_path'], './');
        $photoPath = dirname(__DIR__) . '/' . $photoRelative;

        if (file_exists($photoPath) && !empty($visit['photo_path'])) {
            self::log("Sync Step 2: Authorizing face...");
            $facePath = '/open-api/api-iot/device/accessControl/authorizeAccessFace';
            $facePayload = [
                'deviceId' => $deviceId,
                'faces' => [
                    [
                        'userId' => 'VP' . $visitId,
                        'faceImage' => base64_encode(file_get_contents($photoPath))
                    ]
                ]
            ];
            $faceBody = json_encode($facePayload);
            $faceHeaders = self::generateSignV2($config, "POST", $facePath, $faceBody, $token);
            $faceResp = self::post($config['base_url'] . $facePath, $faceBody, $faceHeaders);
            self::log("Sync Step 2 Response: " . substr($faceResp, 0, 100));
        }

        // --- STEP 3: Authorize Card ---
        if (!empty($visit['visit_code'])) {
            self::log("Sync Step 3: Authorizing card...");
            $cardPath = '/open-api/api-iot/device/accessControl/authorizeAccessCard';
            $cardPayload = [
                'deviceId' => $deviceId,
                'cards' => [
                    [
                        'userId' => 'VP' . $visitId,
                        'cardNo' => $visit['visit_code']
                    ]
                ]
            ];
            $cardBody = json_encode($cardPayload);
            $cardHeaders = self::generateSignV2($config, "POST", $cardPath, $cardBody, $token);
            $cardResp = self::post($config['base_url'] . $cardPath, $cardBody, $cardHeaders);
            self::log("Sync Step 3 Response: " . substr($cardResp, 0, 100));
        }

        self::log("Sync COMPLETE for Visit ID $visitId");
        return true;
    }

    public static function processEvent($data, $pdo = null)
    {
        if (!$pdo) {
            global $pdo;
        }
        if (!$pdo)
            return false;

        $msgBody = $data['msgBody'] ?? [];
        $events = $msgBody['data'] ?? (isset($msgBody['personId']) ? [$msgBody] : []);

        foreach ($events as $event) {
            $personId = $event['personId'] ?? null;
            if (!$personId)
                continue;

            $stmt = $pdo->prepare("SELECT id FROM visits WHERE dahua_person_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$personId]);
            $visit = $stmt->fetch();

            if ($visit) {
                $pdo->prepare("UPDATE visits SET status = 'checked_in', machine_captured_photo = ?, machine_scan_time = ?, machine_id = ?, check_in_time = IF(check_in_time IS NULL, NOW(), check_in_time) WHERE id = ?")
                    ->execute([$event['capturedImage'] ?? null, date('Y-m-d H:i:s', ($event['time'] ?? time() * 1000) / 1000), $event['deviceId'] ?? 'Dahua', $visit['id']]);
            }
        }
        return true;
    }
}
