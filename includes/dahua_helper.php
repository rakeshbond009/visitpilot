<?php

class DahuaHelper
{
    private static function log($msg)
    {
        $logFile = dirname(__DIR__, 2) . '/dahua_debug.txt';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
        error_log("DahuaHelper: $msg");
    }

    private static function get_config($tenant_pdo = null)
    {
        global $pdo, $master_pdo;
        $db = $tenant_pdo ?: $pdo;
        $config = [];
        $tables = ['settings', 'system_settings'];

        if ($db) {
            foreach ($tables as $table) {
                try {
                    $stmt = $db->query("SELECT setting_key, setting_value FROM $table WHERE setting_key LIKE 'dahua_%' OR setting_key = 'device_sns'");
                    if ($stmt) {
                        $res = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        if (!empty($res)) { $config = array_merge($config, $res); break; }
                    }
                } catch (Exception $e) {}
            }
        }

        if (empty($config['dahua_app_id']) && isset($master_pdo) && $master_pdo) {
            foreach ($tables as $table) {
                try {
                    $stmt = $master_pdo->query("SELECT setting_key, setting_value FROM $table WHERE setting_key LIKE 'dahua_%' OR setting_key = 'device_sns'");
                    if ($stmt) {
                        $res = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                        if (!empty($res)) { $config = array_merge($config, $res); break; }
                    }
                } catch (Exception $e) {}
            }
        }

        return [
            'client_id' => $config['dahua_app_id'] ?? $config['client_id'] ?? null,
            'client_secret' => $config['dahua_app_secret'] ?? $config['client_secret'] ?? null,
            'product_id' => $config['dahua_product_id'] ?? $config['product_id'] ?? '',
            'device_sns' => $config['dahua_device_sns'] ?? $config['device_sns'] ?? '',
            'base_url' => rtrim($config['dahua_base_url'] ?? $config['base_url'] ?? 'https://open-api-sg.dolynkcloud.com', '/')
        ];
    }

    public static function getConfig($pdo = null) { return self::get_config($pdo); }

    public static function generateSignV2($config, $method, $body, $appAccessToken = "", $isV2 = false, $path = "")
    {
        $appId = $config['client_id'];
        $appSecret = $config['client_secret'];
        $productId = $config['product_id'];
        
        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $version = "v1";

        // Signature factor: AccessKey + ProductID + Timestamp + Nonce + Version + (AppAccessToken if exists) + Body + Secret
        $factor = $appId . $productId . $timestamp . $nonce . $version;
        if ($appAccessToken) {
            $factor .= $appAccessToken;
        }
        $factor .= $body . $appSecret;
        $sign = strtoupper(md5($factor));

        $headers = [
            'Content-Type: application/json',
            'Version: ' . $version,
            'AccessKey: ' . $appId,
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,
            'Sign: ' . $sign,
            'ProductID: ' . $productId
        ];

        if ($appAccessToken) {
            $headers[] = 'AppAccessToken: ' . $appAccessToken;
        }

        return $headers;
    }

    public static function getAccessToken($pdo = null)
    {
        $config = self::get_config($pdo);
        if (empty($config['client_id'])) return null;

        $url = $config['base_url'] . '/open-api/api-base/auth/getAppAccessToken';
        $prodId = $config['product_id'];
        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        
        // V1 Auth Handshake (MD5)
        $factor = $config['client_id'] . $prodId . $timestamp . $nonce . "v1" . $config['client_secret'];
        $sign = strtoupper(md5($factor));

        $headers = [
            'Content-Type: application/json',
            'Version: v1',
            'AccessKey: ' . $config['client_id'],
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,
            'Sign: ' . $sign,
            'ProductId: ' . $prodId
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => "{}",
            CURLOPT_HTTPHEADER => $headers
        ]);
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        return $data['data']['accessToken'] ?? null;
    }

    public static function getAuthToken($pdo = null) { return self::getAccessToken($pdo); }

    public static function generateV2Headers($path, $body, $appId, $appSecret, $token = "")
    {
        $cfg = ['client_id' => $appId, 'client_secret' => $appSecret, 'product_id' => self::get_config()['product_id']];
        return self::generateSignV2($cfg, "POST", $body, $token, false, $path);
    }

    public static function makeRequest($url, $body, $headers)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }

    private static function getPDO() { global $pdo; return $pdo; }

    public static function processEvent($data, $pdo)
    {
        $events = $data['data'] ?? [$data];
        foreach ($events as $event) {
            try {
                $timeMs = $event['utcTime'] ?? $event['localTime'] ?? $event['time'] ?? (time() * 1000);
                $scanTime = date('Y-m-d H:i:s', $timeMs / 1000);
                $deviceId = $event['deviceId'] ?? 'Dahua';
                $personId = $event['userId'] ?? $event['personId'] ?? null;
                $personName = $event['userName'] ?? $event['name'] ?? 'Unknown';

                // Insert Log
                $stmtLog = $pdo->prepare("INSERT INTO machine_logs (machine_id, person_id, person_name, event_type, event_time, raw_payload) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([$deviceId, $personId, $personName, $event['details'] ?? 'Verification', $scanTime, json_encode($event)]);

                // Auto-populate machine_users
                if ($personId) {
                    $pdo->prepare("INSERT INTO machine_users (device_id, person_id, name, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()")->execute([$deviceId, $personId, $personName]);
                }
            } catch (Exception $e) { self::log("Webhook Error: " . $e->getMessage()); }
        }
        return true;
    }

    public static function getPersonDetail($deviceId, $personId)
    {
        try {
            $config = self::get_config();
            $token = self::getAccessToken();
            if (!$token) return null;

            $path = '/open-api/api-iot/v2/device/accessControl/getUser';
            $body = json_encode([
                'productId' => $config['product_id'],
                'deviceId' => $deviceId,
                'personId' => (string)$personId
            ]);

            $headers = self::generateSignV2($config, "POST", $body, $token, false, $path);
            $response = self::makeRequest($config['base_url'] . $path, $body, $headers);
            $data = json_decode($response, true);
            return $data['data'] ?? null;
        } catch (Exception $e) { return null; }
    }

    public static function syncAllUsers($deviceId)
    {
        $pdo = self::getPDO();
        $allUsers = self::getPeopleList($pdo, $deviceId, 1, 500);
        $stmtSync = $pdo->prepare("SELECT person_id FROM machine_users WHERE device_id = ?");
        $stmtSync->execute([$deviceId]);
        $ids = $stmtSync->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $pid) {
            $detail = self::getPersonDetail($deviceId, $pid);
            if ($detail) {
                $name = $detail['userName'] ?? $detail['name'] ?? 'Unknown';
                $cardNo = $detail['cardList'][0]['cardNo'] ?? '';
                $faceCount = isset($detail['faceList']) ? count($detail['faceList']) : 0;
                $fpCount = isset($detail['fingerprintList']) ? count($detail['fingerprintList']) : 0;
                $userType = $detail['userType'] ?? 'General User';
                $permission = $detail['permissionLevel'] ?? 'User';

                $pdo->prepare("UPDATE machine_users SET 
                    name = ?, card_no = ?, face_count = ?, fp_count = ?, 
                    user_type = ?, permission_level = ?, updated_at = NOW() 
                    WHERE person_id = ? AND device_id = ?")
                    ->execute([$name, $cardNo, $faceCount, $fpCount, $userType, $permission, $pid, $deviceId]);
            }
        }
        return true;
    }

    public static function getPeopleList($pdo = null, $deviceId = null, $page = 1, $pageSize = 100)
    {
        try {
            $config = self::get_config($pdo);
            $token = self::getAccessToken($pdo);
            if (!$token) return ['error' => 'No Token'];

            $path = '/open-api/api-iot/v2/device/accessControl/getUsers';
            $body = json_encode([
                'productId' => $config['product_id'],
                'deviceId' => $deviceId ?: trim(explode(',', $config['device_sns'])[0]),
                'pageSize' => (int)$pageSize,
                'pageNum' => (int)$page
            ]);

            $headers = self::generateSignV2($config, "POST", $body, $token, false, $path);
            $response = self::makeRequest($config['base_url'] . $path, $body, $headers);
            return json_decode($response, true);
        } catch (Exception $e) { return ['error' => $e->getMessage()]; }
    }
}
