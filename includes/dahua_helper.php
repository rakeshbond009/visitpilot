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

    private static function generateNonce()
    {
        return bin2hex(random_bytes(16));
    }

    private static function generateSignV2($config, $method = "POST", $path = "", $body = "{}", $appAccessToken = "")
    {
        $appId = $config['client_id'] ?? '';
        $secret = $config['client_secret'] ?? '';
        $productId = $config['product_id'] ?? '';
        $timestamp = (string)round(microtime(true) * 1000);
        
        // Match Portal Nonce: web-{random}-{timestamp}
        $nonce = 'web-' . bin2hex(random_bytes(16)) . '-' . $timestamp;
        $traceId = bin2hex(random_bytes(16));
        
        $cleanBody = self::deleteWhitespace($body);
        // For V2, stringToSign is simply the Method (e.g., POST).
        // Some endpoints may require the body hash, but the Token success showed pure Method signing.
        $stringToSign = $method;

        // strAuthFactor = AccessKey + AppAccessToken + Timestamp + Nonce + stringToSign
        // Matching the successful 01:37:48 handshake pattern.
        $strAuthFactor = $appId . $appAccessToken . $timestamp . $nonce . $stringToSign;
        $sign = strtoupper(hash_hmac('sha512', $strAuthFactor, $secret));
        
        self::log("=== DAHUA V2 STRING TO SIGN ===\n" . $strAuthFactor);
        self::log("=== GENERATED SIGN ===\n" . $sign);

        $headers = [
            'Content-Type: application/json',
            'Version: v1', // MUST BE LOWERCASE
            'AccessKey: ' . $appId,
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,
            'Sign: ' . $sign,
            'X-TraceId-Header: ' . $traceId,
            'ProductId: ' . $productId
        ];

        if ($appAccessToken) {
            $headers[] = 'AppAccessToken: ' . $appAccessToken;
        }

        return $headers;
    }

    public static function getAccessToken($pdo = null)
    {
        $config = self::get_config($pdo);
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            self::log("FAIL: Credentials missing.");
            return null;
        }

        $cacheFile = dirname(__DIR__) . '/scratch/dahua_token_' . md5($config['client_id']) . '.json';
        if (file_exists($cacheFile)) {
            $tokenData = json_decode(file_get_contents($cacheFile), true);
            if ($tokenData && ($tokenData['expire_time'] ?? 0) > time()) {
                return $tokenData['access_token'];
            }
        }

        $path = '/open-api/api-base/auth/getAppAccessToken';
        $body = "{}";
        $url = $config['base_url'] . $path;

        self::log("Auth: Requesting v2 token for path $path...");

        $headers = self::generateSignV2($config, "POST", $path, $body);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['data']['appAccessToken'])) {
            self::log("Auth Success.");
            $token = $data['data']['appAccessToken'];
            $expires = time() + ($data['data']['expiresIn'] ?? 3600) - 120;
            if (!is_dir(dirname($cacheFile)))
                @mkdir(dirname($cacheFile), 0777, true);
            file_put_contents($cacheFile, json_encode(['access_token' => $token, 'expire_time' => $expires]));
            return $token;
        }

        self::log("Auth FAIL. Response: [$code] " . ($response ?: $err));
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

        // --- STEP 1: Add User ---
        self::log("Sync Step 1: Adding user...");
        $path = '/open-api/api-iot/v2/device/accessControl/addUsers';
        $userUrl = $config['base_url'] . $path;
        $userPayload = [
            'deviceId' => $deviceId,
            'users' => [
                [
                    'userId' => 'VP' . $visitId,
                    'userName' => $visit['visitor_name'],
                    'userType' => 0 // General user
                ]
            ]
        ];
        $userBody = json_encode($userPayload);
        $userHeaders = self::generateSignV2($config, "POST", $path, $userBody, $token);

        $ch = curl_init($userUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $userBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $userHeaders);
        $resp = curl_exec($ch);
        $userData = json_decode($resp, true);
        curl_close($ch);

        if (!isset($userData['success']) || !$userData['success']) {
            self::log("Sync STEP 1 FAIL: " . $resp);
            return false;
        }

        // --- STEP 2: Authorize Face ---
        $photoRelative = ltrim($visit['photo_path'], './');
        $photoPath = dirname(__DIR__) . '/' . $photoRelative;

        if (file_exists($photoPath) && !empty($visit['photo_path'])) {
            self::log("Sync Step 2: Authorizing face...");
            $facePath = '/open-api/api-iot/v2/device/accessControl/authorizeAccessFace';
            $faceUrl = $config['base_url'] . $facePath;
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

            $faceHeaders = self::generateSignV2($config, "POST", $faceBody, $token);

            $ch = curl_init($faceUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $faceBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $faceHeaders);
            $resp = curl_exec($ch);
            curl_close($ch);
            self::log("Sync Step 2 Response: " . substr($resp, 0, 100));
        }

        // --- STEP 3: Authorize Card (Optional but linked) ---
        if (!empty($visit['visit_code'])) {
            self::log("Sync Step 3: Authorizing card...");
            $cardPath = '/open-api/api-iot/v2/device/accessControl/authorizeAccessCard';
            $cardUrl = $config['base_url'] . $cardPath;
            $cardPayload = [
                'deviceId' => $deviceId,
                'cards' => [
                    [
                        'userId' => 'VP' . $visitId,
                        'cardNo' => $visit['visit_code'],
                        'cardStatus' => 0
                    ]
                ]
            ];
            $cardBody = json_encode($cardPayload);
            $cardHeaders = self::generateSignV2($config, "POST", $cardBody, $token);

            $ch = curl_init($cardUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cardBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $cardHeaders);
            curl_exec($ch);
            curl_close($ch);
        }

        self::log("SUCCESS: Synced Visit ID $visitId");
        $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?")->execute(['VP' . $visitId, $visitId]);
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
