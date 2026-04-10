<?php
class DahuaHelper {
    
    private static function log($msg) {
        $logFile = dirname(__DIR__) . '/dahua_debug.txt';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
    }

    private static function get_config($pdo = null) {
        if (!$pdo) { global $pdo; }
        if (!$pdo) return [];

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

    private static function generateSign($config, $token = null) {
        $timestamp = round(microtime(true) * 1000);
        $nonce = uniqid('vp_');
        
        // Simplified Mode Logic from Docs
        if ($token) {
            $strToSign = $config['client_id'] . $token . $timestamp . $nonce;
        } else {
            $strToSign = $config['client_id'] . $timestamp . $nonce;
        }
        
        $sign = strtoupper(hash_hmac('sha512', $strToSign, $config['client_secret']));
        
        return [
            'AccessKey' => $config['client_id'],
            'Timestamp' => (string)$timestamp,
            'Nonce' => $nonce,
            'Sign' => $sign,
            'ProductId' => $config['product_id'],
            'Version' => 'v1',
            'X-TraceId-Header' => uniqid('tid_'),
            'Sign-Type' => 'simple',
            'Content-Type' => 'application/json'
        ];
    }

    public static function getAccessToken($pdo = null) {
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

        self::log("Auth: Requesting v2 token for Product: " . $config['product_id'] . "...");
        $url = $config['base_url'] . '/open-api/api-base/auth/getAppAccessToken';
        
        $headers = self::generateSign($config);
        $formattedHeaders = [];
        foreach($headers as $k => $v) $formattedHeaders[] = "$k: $v";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
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
            file_put_contents($cacheFile, json_encode(['access_token' => $token, 'expire_time' => $expires]));
            return $token;
        }

        self::log("Auth FAIL. Response: [$code] " . ($response ?: $err));
        return null;
    }

    public static function syncVisitor($visitId, $pdo = null) {
        if (!$pdo) { global $pdo; }
        if (!$pdo) { self::log("FAIL: No DB Connection."); return false; }

        self::log("Sync: Starting v2 sync for Visit ID $visitId");
        $token = self::getAccessToken($pdo);
        if (!$token) return false;

        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, v.visit_code 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               WHERE v.id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch();

        if (!$visit) { self::log("FAIL: Visit $visitId not found."); return false; }

        $config = self::get_config($pdo);
        $deviceId = $sns = array_map('trim', explode(',', $config['device_sns']))[0] ?? '';
        
        // --- STEP 1: Add User ---
        self::log("Sync Step 1: Adding user...");
        $userUrl = $config['base_url'] . '/open-api/api-iot/v2/device/accessControl/addUsers';
        $userPayload = [
            'deviceId' => $deviceId,
            'users' => [[
                'userId' => 'VP' . $visitId,
                'userName' => $visit['visitor_name'],
                'userType' => 0 // General user
            ]]
        ];
        
        $userHeaders = self::generateSign($config, $token);
        $formattedUserHeaders = [];
        foreach($userHeaders as $k => $v) $formattedUserHeaders[] = "$k: $v";

        $ch = curl_init($userUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($userPayload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedUserHeaders);
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
            $faceUrl = $config['base_url'] . '/open-api/api-iot/v2/device/accessControl/authorizeAccessFace';
            $facePayload = [
                'deviceId' => $deviceId,
                'faces' => [[
                    'userId' => 'VP' . $visitId,
                    'faceImage' => base64_encode(file_get_contents($photoPath))
                ]]
            ];
            
            $faceHeaders = self::generateSign($config, $token);
            $formattedFaceHeaders = [];
            foreach($faceHeaders as $k => $v) $formattedFaceHeaders[] = "$k: $v";

            $ch = curl_init($faceUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($facePayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedFaceHeaders);
            $resp = curl_exec($ch);
            curl_close($ch);
            self::log("Sync Step 2 Response: " . substr($resp, 0, 100));
        }

        // --- STEP 3: Authorize Card (Optional but linked) ---
        if (!empty($visit['visit_code'])) {
            self::log("Sync Step 3: Authorizing card...");
            $cardUrl = $config['base_url'] . '/open-api/api-iot/v2/device/accessControl/authorizeAccessCard';
            $cardPayload = [
                'deviceId' => $deviceId,
                'cards' => [[
                    'userId' => 'VP' . $visitId,
                    'cardNo' => $visit['visit_code'],
                    'cardStatus' => 0
                ]]
            ];
            $cardHeaders = self::generateSign($config, $token);
            $formattedCardHeaders = [];
            foreach($cardHeaders as $k => $v) $formattedCardHeaders[] = "$k: $v";

            $ch = curl_init($cardUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cardPayload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedCardHeaders);
            curl_exec($ch);
            curl_close($ch);
        }

        self::log("SUCCESS: Synced Visit ID $visitId");
        $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?")->execute(['VP' . $visitId, $visitId]);
        return true;
    }

    public static function processEvent($data, $pdo = null) {
        if (!$pdo) { global $pdo; }
        if (!$pdo) return false;
        
        $msgBody = $data['msgBody'] ?? [];
        $events = $msgBody['data'] ?? (isset($msgBody['personId']) ? [$msgBody] : []);

        foreach ($events as $event) {
            $personId = $event['personId'] ?? null;
            if (!$personId) continue;

            $stmt = $pdo->prepare("SELECT id FROM visits WHERE dahua_person_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$personId]);
            $visit = $stmt->fetch();

            if ($visit) {
                $pdo->prepare("UPDATE visits SET status = 'checked_in', machine_captured_photo = ?, machine_scan_time = ?, machine_id = ?, check_in_time = IF(check_in_time IS NULL, NOW(), check_in_time) WHERE id = ?")
                    ->execute([$event['capturedImage'] ?? null, date('Y-m-d H:i:s', ($event['time'] ?? time()*1000) / 1000), $event['deviceId'] ?? 'Dahua', $visit['id']]);
            }
        }
        return true;
    }
}
