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
                'product_id' => $settings['dahua_product_id'] ?? '',
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
        if (!$str) return $str;
        return preg_replace('/\s+/', '', $str);
    }

    private static function generateSignV2($config, $method = "POST", $body = "{}", $appAccessToken = "", $isV1 = false, $path = "")
    {
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $appId = $config['client_id'];
        $secret = $config['client_secret'];
        $productId = $config['product_id'] ?? '';
        $traceId = 'tid-' . bin2hex(random_bytes(8)) . '-' . $timestamp;

        if ($isV1) {
            $cleanBody = self::deleteWhitespace($body);
            $bodyHash = ($body === "{}" || $body === "") ? "" : hash('sha512', $cleanBody);
            $factor = $appId . $timestamp . $nonce . $bodyHash . $secret;
            $sign = strtoupper(md5($factor));
            $version = 'v1'; // Trying lowercase v1 again for Singapore
        } else {
            $cleanBody = self::deleteWhitespace($body);
            $bodyHash = hash('sha512', $cleanBody);
            $stringToSign = $method . ($cleanBody === "{}" || $cleanBody === "" ? "" : "\n" . $bodyHash);
            // Include path if provided (Singapore requirement for SOME endpoints)
            $strAuthFactor = $appId . $appAccessToken . $timestamp . $nonce . ($path ?: "") . $stringToSign;
            $sign = strtoupper(hash_hmac('sha512', $strAuthFactor, $secret));
            $version = 'V1';
        }

        $headers = [
            'Content-Type: application/json',
            'Version: ' . $version,
            'AccessKey: ' . $appId,
            'Timestamp: ' . $timestamp,
            'Nonce: ' . $nonce,
            'Sign: ' . $sign,
            'ProductID: ' . $productId,
            'X-TraceId-Header: ' . $traceId,
            'Accept-Language: en-US'
        ];

        if ($appAccessToken) {
            $headers[] = 'AppAccessToken: ' . $appAccessToken;
        }

        return $headers;
    }

    public static function getAccessTokenV1($config)
    {
        $appId = $config['client_id'];
        $secret = $config['client_secret'];
        $prodId = $config['product_id'];
        $timestamp = (string) round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $factor = $appId . $prodId . $timestamp . $nonce . "v1" . $secret;
        $sign = strtoupper(md5($factor));
        $url = $config['base_url'] . '/open-api/api-base/auth/getAppAccessToken';
        $headers = ['content-type: application/json', 'version: v1', 'accesskey: ' . $appId, 'timestamp: ' . $timestamp, 'nonce: ' . $nonce, 'sign: ' . $sign, 'productid: ' . $prodId];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $resp = curl_exec($ch);
        $data = json_decode($resp, true);
        curl_close($ch);
        return $data['data']['appAccessToken'] ?? null;
    }

    public static function getAccessToken($pdo = null)
    {
        $config = self::get_config($pdo);
        if (empty($config['client_id']) || empty($config['client_secret'])) return null;
        $cacheFile = dirname(__DIR__) . '/scratch/dahua_token_' . md5($config['client_id']) . '.json';
        if (file_exists($cacheFile)) {
            $tokenData = json_decode(file_get_contents($cacheFile), true);
            if ($tokenData && ($tokenData['expire_time'] ?? 0) > time()) return $tokenData['access_token'];
        }
        $path = '/open-api/api-base/auth/getAppAccessToken';
        $url = $config['base_url'] . $path;
        $headers = self::generateSignV2($config, "POST", "{}");
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);
        if (isset($data['data']['appAccessToken'])) {
            $token = $data['data']['appAccessToken'];
            $expires = time() + ($data['data']['expiresIn'] ?? 3600) - 120;
            file_put_contents($cacheFile, json_encode(['access_token' => $token, 'expire_time' => $expires]));
            return $token;
        }
        return null;
    }

    public static function syncVisitor($visitId, $pdo = null)
    {
        if (!$pdo) global $pdo;
        if (!$pdo) return false;
        $config = self::get_config($pdo);
        $tokenV2 = self::getAccessToken($pdo);
        if (!$tokenV2) return false;
        $tokenV1 = self::getAccessTokenV1($config) ?: $tokenV2;

        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, v.visit_code FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch();
        if (!$visit) return false;

        $deviceId = array_map('trim', explode(',', $config['device_sns']))[0] ?? '';

        // --- STEP 1: Prepare & Compress Photo to <95KB ---
        $photoRelative = ltrim($visit['photo_path'], './');
        $photoPath = dirname(__DIR__) . '/' . $photoRelative;
        $compressDir = dirname(__DIR__) . '/uploads/dahua_compressed/';
        if (!is_dir($compressDir)) mkdir($compressDir, 0755, true);
        $compressedPath = $compressDir . $visitId . '.jpg';
        $photoUrl = null;

        $srcPhoto = file_exists(dirname(__DIR__) . '/uploads/photos/fix_biometric.jpg')
            ? dirname(__DIR__) . '/uploads/photos/fix_biometric.jpg'
            : $photoPath;

        if (file_exists($srcPhoto) && !empty($visit['photo_path'])) {
            // Resize to 300x400 portrait and compress until under 95KB
            $img = null;
            $mime = mime_content_type($srcPhoto);
            if ($mime === 'image/png')  $img = imagecreatefrompng($srcPhoto);
            elseif ($mime === 'image/jpeg') $img = imagecreatefromjpeg($srcPhoto);
            if ($img) {
                $resized = imagecreatetruecolor(300, 400);
                imagecopyresampled($resized, $img, 0, 0, 0, 0, 300, 400, imagesx($img), imagesy($img));
                $quality = 85;
                do {
                    imagejpeg($resized, $compressedPath, $quality);
                    $quality -= 5;
                } while (filesize($compressedPath) > 95000 && $quality > 10);
                imagedestroy($img);
                imagedestroy($resized);
                $photoUrl = 'https://visitor.codepilotx.com/uploads/dahua_compressed/' . $visitId . '.jpg';
                self::log("Photo compressed: " . round(filesize($compressedPath)/1024, 1) . "KB → $photoUrl");
            }
        }

        // --- STEP 2: Build User Payload ---
        $userPayload = [
            'deviceId' => $deviceId,
            'users' => [
                [
                    'userId'        => (string)$visitId,
                    'userName'      => $visit['visitor_name'],
                    'userType'      => 0,
                    'authorityList' => ['1'],
                    'userPermission'=> 1,
                    'role'          => 'user',
                    'departmentId'  => '1',
                    'startTime'     => date('Y-m-d H:i:s'),
                    'endTime'       => '2036-12-31 23:59:59'
                ]
            ]
        ];

        // Attach the real hosted photo URL
        if ($photoUrl) {
            $userPayload['users'][0]['faceList'] = [
                ['photoURL' => $photoUrl]
            ];
        }

        // Attach Card
        if (!empty($visit['visit_code'])) {
            $userPayload['users'][0]['cardList'] = [
                ['cardNo' => $visit['visit_code'], 'cardStatus' => 0]
            ];
        }

        self::log("Sync: Sending Atomic V2 Payload (User+Face+Card)...");
        $userPath = '/open-api/api-iot/v2/device/accessControl/addUsers';
        $userBody = json_encode($userPayload);
        $userHeaders = self::generateSignV2($config, "POST", $userBody, $tokenV2);
        
        $ch = curl_init($config['base_url'] . $userPath);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $userBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $userHeaders);
        $resp = curl_exec($ch);
        curl_close($ch);
        
        self::log("Sync Atomic Response: " . substr($resp, 0, 100));

        if (stripos($resp, '"success":true') !== false) {
            self::log("SUCCESS: Atomic Synced Visit ID $visitId");
            $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?")->execute(['VP' . $visitId, $visitId]);
            return true;
        } else {
            self::log("FAIL: Atomic Sync rejected. Response: " . $resp);
            return false;
        }
    }

    public static function processEvent($data, $pdo = null)
    {
        if (!$pdo) global $pdo;
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
                $pdo->prepare("UPDATE visits SET status = 'checked_in', machine_captured_photo = ?, machine_scan_time = ?, machine_id = ?, check_in_time = IF(check_in_time IS NULL, NOW(), check_in_time) WHERE id = ?")->execute([$event['capturedImage'] ?? null, date('Y-m-d H:i:s', ($event['time'] ?? time() * 1000) / 1000), $event['deviceId'] ?? 'Dahua', $visit['id']]);
            }
        }
        return true;
    }
}
