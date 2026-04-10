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
            // Direct query to bypass any caching issues in get_setting
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            self::log("Config: Fetched " . count($settings) . " dahua_ settings from DB.");
            
            return [
                'client_id' => $settings['dahua_app_id'] ?? null,
                'client_secret' => $settings['dahua_app_secret'] ?? null,
                'device_sns' => $settings['dahua_device_sns'] ?? '',
                'base_url' => rtrim($settings['dahua_base_url'] ?? 'https://open-api.dolynkcloud.com', '/')
            ];
        } catch (Exception $e) {
            self::log("Config ERROR: " . $e->getMessage());
            return [];
        }
    }

    public static function getAccessToken($pdo = null) {
        $config = self::get_config($pdo);
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            self::log("FAIL: Missing Dahua App ID or Secret. ID: " . ($config['client_id'] ? 'YES' : 'MISSING') . ", Secret: " . ($config['client_secret'] ? 'YES' : 'MISSING'));
            return null;
        }

        $cacheFile = dirname(__DIR__) . '/scratch/dahua_token.json';
        if (file_exists($cacheFile)) {
            $tokenData = json_decode(file_get_contents($cacheFile), true);
            if ($tokenData && ($tokenData['expire_time'] ?? 0) > time()) {
                return $tokenData['access_token'];
            }
        }

        self::log("Auth: Requesting new token from Dahua...");
        $url = $config['base_url'] . '/auth/v1.0/token';
        $payload = [
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'grantType' => 'client_credentials'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['data']['accessToken'])) {
            self::log("Auth: Token obtained successfully.");
            $token = $data['data']['accessToken'];
            $expires = time() + ($data['data']['expiresIn'] ?? 3600) - 120;
            file_put_contents($cacheFile, json_encode(['access_token' => $token, 'expire_time' => $expires]));
            return $token;
        }

        self::log("Auth FAIL: " . ($data['message'] ?? 'API connection error.'));
        return null;
    }

    public static function syncVisitor($visitId, $pdo = null) {
        if (!$pdo) { global $pdo; }
        if (!$pdo) {
            self::log("FAIL: No DB Connection.");
            return false;
        }

        self::log("Sync: Starting for Visit ID $visitId");
        $token = self::getAccessToken($pdo);
        if (!$token) return false;

        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, v.visit_code 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               WHERE v.id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch();

        if (!$visit) {
            self::log("FAIL: Visit record $visitId not found in DB.");
            return false;
        }

        // Convert local photo to base64
        $photoRelative = ltrim($visit['photo_path'], './');
        $photoPath = dirname(__DIR__) . '/' . $photoRelative;
        
        if (!file_exists($photoPath) || empty($visit['photo_path'])) {
            self::log("FAIL: Visitor photo not found at path: $photoPath");
            return false;
        }
        
        self::log("API: Pushing face biometric to Dahua...");
        $photoBase64 = base64_encode(file_get_contents($photoPath));
        $config = self::get_config($pdo);
        $url = $config['base_url'] . '/person/v1.0/person/add';
        
        $payload = [
            'personName' => $visit['visitor_name'],
            'personType' => 'visitor',
            'faceImage' => $photoBase64,
            'cards' => [['cardNumber' => $visit['visit_code'], 'cardType' => 'normal']],
            'certificates' => [['certificateType' => 'vms_sync', 'certificateNumber' => 'VP' . $visitId ]]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['data']['personId'])) {
            $personId = $data['data']['personId'];
            self::log("SUCCESS: Visitor synced. Dahua ID: $personId");
            
            $update = $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?");
            $update->execute([$personId, $visitId]);

            // Device Authorization
            if (!empty($config['device_sns'])) {
                self::log("Auth: Granting access to devices: " . $config['device_sns']);
                $sns = array_map('trim', explode(',', $config['device_sns']));
                $authUrl = $config['base_url'] . '/person/v1.0/person/authorization/add';
                $ch = curl_init($authUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['personId' => $personId, 'deviceIdList' => $sns]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
                curl_exec($ch);
                curl_close($ch);
            }
            return $personId;
        }

        self::log("API FAIL: " . ($data['message'] ?? 'Cloud API error code: ' . ($data['code'] ?? 'Unknown')));
        return false;
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
