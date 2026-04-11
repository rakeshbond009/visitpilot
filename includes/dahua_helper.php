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

        // --- STEP 1: Compress photo to <95KB and save to public path ---
        $photoRelative = ltrim($visit['photo_path'], './');
        $photoPath = dirname(__DIR__) . '/' . $photoRelative;
        $compressDir = dirname(__DIR__) . '/uploads/dahua_compressed/';
        if (!is_dir($compressDir)) mkdir($compressDir, 0755, true);
        $compressedPath = $compressDir . $visitId . '.jpg';
        $photoUrl = null;

        if (file_exists($photoPath) && !empty($visit['photo_path'])) {
            // Dahua face recognition needs sufficient resolution and quality.
            // Target: 640x480, under 95KB, minimum quality 55 to preserve facial features.
            $img  = null;
            $mime = mime_content_type($photoPath);
            if ($mime === 'image/png')  $img = imagecreatefrompng($photoPath);
            elseif ($mime === 'image/jpeg') $img = imagecreatefromjpeg($photoPath);
            if ($img) {
                $resized = imagecreatetruecolor(640, 480);
                imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
                imagecopyresampled($resized, $img, 0, 0, 0, 0, 640, 480, imagesx($img), imagesy($img));
                $quality = 85;
                do {
                    imagejpeg($resized, $compressedPath, $quality);
                    $quality -= 5;
                } while (filesize($compressedPath) > 95000 && $quality > 55);
                imagedestroy($img);
                imagedestroy($resized);
                $photoUrl = 'https://visitor.codepilotx.com/uploads/dahua_compressed/' . $visitId . '.jpg';
                self::log("Photo compressed: " . round(filesize($compressedPath)/1024, 1) . "KB q=$quality → $photoUrl");
            }
        }

        // --- STEP 2: Add User only (no face/card embedded — they are silently ignored by addUsers API) ---
        self::log("Sync Step 1: Adding user $visitId...");
        $userPath = '/open-api/api-iot/v2/device/accessControl/addUsers';
        $userPayload = [
            'deviceId' => $deviceId,
            'users' => [[
                'userId'         => (string)$visitId,
                'userName'       => $visit['visitor_name'],
                'userType'       => 0,
                'authorityList'  => ['1'],
                'userPermission' => 1,
                'role'           => 'user',
                'departmentId'   => '1',
                'startTime'      => date('Y-m-d H:i:s'),
                'endTime'        => '2036-12-31 23:59:59'
            ]]
        ];
        $userBody    = json_encode($userPayload);
        $userHeaders = self::generateSignV2($config, "POST", $userBody, $tokenV2);
        $ch = curl_init($config['base_url'] . $userPath);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $userBody, CURLOPT_HTTPHEADER => $userHeaders]);
        $userResp = curl_exec($ch); curl_close($ch);
        self::log("Step 1 Response: " . substr($userResp, 0, 120));

        // --- STEP 3: Authorize Face (retry up to 3x with 5s delay for device propagation) ---
        // Per Dahua API Guide v2.4 s4.12.1.4.2: photoData is array, header must be stripped.
        // When both photoData + photoURL sent, photoData prevails; photoURL satisfies cloud validation.
        if ($photoUrl && file_exists($compressedPath)) {
            $facePath    = '/open-api/api-iot/v2/device/accessControl/authorizeAccessFace';
            $rawBase64   = base64_encode(file_get_contents($compressedPath)); // No data:image prefix
            $facePayload = [
                'deviceId' => $deviceId,
                'faces'    => [[
                    'userId'    => (string)$visitId,
                    'photoData' => [$rawBase64],     // array per spec
                    'photoURL'  => $photoUrl         // real hosted URL — satisfies cloud validation
                ]]
            ];
            $faceBody = json_encode($facePayload);
            sleep(2); // brief propagation window before first attempt
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if ($attempt > 1) { self::log("Face retry $attempt/3 (waiting 5s)..."); sleep(5); }
                $faceHeaders = self::generateSignV2($config, "POST", $faceBody, $tokenV2);
                $ch = curl_init($config['base_url'] . $facePath);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $faceBody, CURLOPT_HTTPHEADER => $faceHeaders]);
                $faceResp = curl_exec($ch); curl_close($ch);
                self::log("Step 2 Face (attempt $attempt): " . substr($faceResp, 0, 150));
                $faceData = json_decode($faceResp, true);
                if (($faceData['code'] ?? '') !== 'IDV0098') break;
            }
        }

        // --- STEP 4: Authorize Card ---
        if (!empty($visit['visit_code'])) {
            $cardPath    = '/open-api/api-iot/v2/device/accessControl/authorizeAccessCard';
            $cardPayload = ['deviceId' => $deviceId, 'cards' => [['userId' => (string)$visitId, 'cardNo' => $visit['visit_code'], 'cardStatus' => 0]]];
            $cardBody    = json_encode($cardPayload);
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                if ($attempt > 1) { self::log("Card retry $attempt/3 (waiting 5s)..."); sleep(5); }
                $cardHeaders = self::generateSignV2($config, "POST", $cardBody, $tokenV2);
                $ch = curl_init($config['base_url'] . $cardPath);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cardBody, CURLOPT_HTTPHEADER => $cardHeaders]);
                $cardResp = curl_exec($ch); curl_close($ch);
                self::log("Step 3 Card (attempt $attempt): " . substr($cardResp, 0, 120));
                $cardData = json_decode($cardResp, true);
                if (($cardData['code'] ?? '') !== 'IDV0098') break;
            }
        }

        self::log("SUCCESS: Synced Visit ID $visitId");
        $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?")->execute([(string)$visitId, $visitId]);
        return true;
    }

    public static function processEvent($data, $pdo = null)
    {
        if (!$pdo) global $pdo;
        if (!$pdo) return false;

        // Support both Dahua V1 nested (msgBody) and V2 flattened structures
        $events = [];
        if (isset($data['userId']) || isset($data['personId'])) {
            $events[] = $data; // Flat structure
        } else {
            $msgBody = $data['msgBody'] ?? [];
            $events = $msgBody['data'] ?? (isset($msgBody['personId']) ? [$msgBody] : []);
        }

        foreach ($events as $event) {
            $personId = $event['userId'] ?? $event['personId'] ?? null;
            if (!$personId) continue;
            
            $stmt = $pdo->prepare("SELECT id FROM visits WHERE dahua_person_id = ? AND status IN ('pending', 'approved') ORDER BY id DESC LIMIT 1");
            $stmt->execute([$personId]);
            $visit = $stmt->fetch();
            
            if ($visit) {
                $timeMs = $event['utcTime'] ?? $event['localTime'] ?? $event['time'] ?? (time() * 1000);
                $scanTime = date('Y-m-d H:i:s', $timeMs / 1000);
                $deviceId = $event['deviceId'] ?? 'Dahua';
                $image = $event['capturedImage'] ?? null;

                $pdo->prepare("
                    UPDATE visits 
                    SET status = 'checked_in', 
                        machine_captured_photo = IF(machine_captured_photo IS NULL, ?, machine_captured_photo), 
                        machine_scan_time = IF(machine_scan_time IS NULL, ?, machine_scan_time), 
                        machine_id = IF(machine_id IS NULL, ?, machine_id), 
                        check_in_time = IF(check_in_time IS NULL, NOW(), check_in_time) 
                    WHERE id = ?
                ")->execute([$image, $scanTime, $deviceId, $visit['id']]);
            }
        }
        return true;
    }
}
