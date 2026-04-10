<?php
class DahuaHelper {
    
    private static function get_config() {
        return [
            'client_id' => get_setting('dahua_client_id'),
            'client_secret' => get_setting('dahua_client_secret'),
            'base_url' => rtrim(get_setting('dahua_base_url') ?: 'https://open-api.dolynkcloud.com', '/')
        ];
    }

    /**
     * Get Access Token from Dahua Cloud
     */
    public static function getAccessToken() {
        $config = self::get_config();
        if (empty($config['client_id']) || empty($config['client_secret'])) return null;

        $cacheFile = __DIR__ . '/../scratch/dahua_token.json';
        if (file_exists($cacheFile)) {
            $tokenData = json_decode(file_get_contents($cacheFile), true);
            if ($tokenData && $tokenData['expire_time'] > time()) {
                return $tokenData['access_token'];
            }
        }

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
        
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['data']['accessToken'])) {
            $token = $data['data']['accessToken'];
            $expires = time() + ($data['data']['expiresIn'] ?? 3600) - 60;
            file_put_contents($cacheFile, json_encode(['access_token' => $token, 'expire_time' => $expires]));
            return $token;
        }

        return null;
    }

    /**
     * Push Visitor to Dahua Cloud
     */
    public static function syncVisitor($visitId) {
        global $pdo;
        $token = self::getAccessToken();
        if (!$token) return false;

        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               WHERE v.id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch();

        if (!$visit || empty($visit['photo_path'])) return false;

        $config = self::get_config();
        $url = $config['base_url'] . '/person/v1.0/person/add';
        
        // Convert local photo to base64
        $photoPath = __DIR__ . '/../' . ltrim($visit['photo_path'], '../');
        if (!file_exists($photoPath)) return false;
        $photoBase64 = base64_encode(file_get_contents($photoPath));

        $payload = [
            'personName' => $visit['visitor_name'],
            'personType' => 'visitor',
            'faceImage' => $photoBase64,
            'certificates' => [
                ['certificateType' => 'vms_sync', 'certificateNumber' => 'VP' . $visitId]
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ]);

        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['data']['personId'])) {
            $personId = $data['data']['personId'];
            $update = $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?");
            $update->execute([$personId, $visitId]);
            return $personId;
        }

        return false;
    }

    /**
     * Process incoming hardware event
     */
    public static function processEvent($data) {
        global $pdo;
        
        $msgBody = $data['msgBody'] ?? [];
        $events = $msgBody['data'] ?? [];

        foreach ($events as $event) {
            $personId = $event['personId'] ?? null;
            $photo = $event['capturedImage'] ?? null;
            $machineId = $event['deviceId'] ?? 'Dahua Terminal';
            $scanTime = isset($event['scanTime']) ? date('Y-m-d H:i:s', $event['scanTime'] / 1000) : date('Y-m-d H:i:s');

            if (!$personId) continue;

            // Find visit by personId
            $stmt = $pdo->prepare("SELECT id, status FROM visits WHERE dahua_person_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$personId]);
            $visit = $stmt->fetch();

            if ($visit) {
                $update = $pdo->prepare("UPDATE visits SET 
                    status = 'checked_in', 
                    machine_captured_photo = ?, 
                    machine_scan_time = ?, 
                    machine_id = ?,
                    check_in_time = IF(check_in_time IS NULL, NOW(), check_in_time)
                    WHERE id = ?");
                $update->execute([$photo, $scanTime, $machineId, $visit['id']]);
            }
        }
    }
}
