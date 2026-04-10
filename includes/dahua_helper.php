<?php
class DahuaHelper {
    
    private static function log($msg) {
        $logFile = dirname(__DIR__) . '/dahua_debug.txt';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
    }

    private static function get_config($pdo = null) {
        if (!$pdo) { global $pdo; }
        return [
            'client_id' => get_setting('dahua_client_id', null, $pdo),
            'client_secret' => get_setting('dahua_client_secret', null, $pdo),
            'base_url' => rtrim(get_setting('dahua_base_url', 'https://open-api.dolynkcloud.com', $pdo), '/')
        ];
    }

    public static function getAccessToken($pdo = null) {
        $config = self::get_config($pdo);
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            self::log("FAIL: Missing Dahua App ID or Secret in settings.");
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
        $payload = ['clientId' => $config['client_id'], 'clientSecret' => $config['client_secret'], 'grantType' => 'client_credentials'];

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
            self::log("Auth: Success.");
            $token = $data['data']['accessToken'];
            $expires = time() + ($data['data']['expiresIn'] ?? 3600) - 120;
            file_put_contents($cacheFile, json_encode(['access_token' => $token, 'expire_time' => $expires]));
            return $token;
        }

        self::log("Auth FAIL: " . ($data['message'] ?? 'Check your credentials in settings.'));
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
            self::log("FAIL: Visit record not found in DB.");
            return false;
        }

        $photoRelative = ltrim($visit['photo_path'], './'); // Fix leading path characters
        $photoPath = dirname(__DIR__) . '/' . $photoRelative;
        
        if (!file_exists($photoPath) || empty($visit['photo_path'])) {
            self::log("FAIL: Photo not found at $photoPath");
            return false;
        }
        
        self::log("API: Sending to Dahua person/add...");
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['data']['personId'])) {
            $personId = $data['data']['personId'];
            self::log("SUCCESS: Dahua Person ID: $personId");
            
            $update = $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?");
            $update->execute([$personId, $visitId]);

            // Device Authorization
            $deviceSNs = get_setting('dahua_device_sns', '', $pdo);
            if ($deviceSNs) {
                self::log("Auth: Syncing to devices: $deviceSNs");
                $sns = array_map('trim', explode(',', $deviceSNs));
                $authUrl = $config['base_url'] . '/person/v1.0/person/authorization/add';
                $authPayload = ['personId' => $personId, 'deviceIdList' => $sns];

                $ch = curl_init($authUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authPayload));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
                curl_exec($ch);
                curl_close($ch);
            }
            return $personId;
        }

        self::log("API FAIL: " . ($data['message'] ?? 'Device or Cloud rejected the request.'));
        return false;
    }

    public static function processEvent($data, $pdo = null) {
        if (!$pdo) { global $pdo; }
        if (!$pdo) return false;
        
        $msgBody = $data['msgBody'] ?? [];
        $events = $msgBody['data'] ?? (isset($msgBody['personId']) ? [$msgBody] : []);

        $processed = false;
        foreach ($events as $event) {
            $personId = $event['personId'] ?? null;
            if (!$personId) continue;

            $stmt = $pdo->prepare("SELECT id FROM visits WHERE dahua_person_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$personId]);
            $visit = $stmt->fetch();

            if ($visit) {
                $pdo->prepare("UPDATE visits SET status = 'checked_in', machine_captured_photo = ?, machine_scan_time = ?, machine_id = ?, check_in_time = IF(check_in_time IS NULL, NOW(), check_in_time) WHERE id = ?")
                    ->execute([$event['capturedImage'] ?? null, date('Y-m-d H:i:s', ($event['time'] ?? time()*1000) / 1000), $event['deviceId'] ?? 'Dahua', $visit['id']]);
                $processed = true;
            }
        }
        return $processed;
    }
}
