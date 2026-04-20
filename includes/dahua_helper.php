<?php
class DahuaHelper
{

    private static function log($msg)
    {
        $logFile = dirname(__DIR__) . '/dahua_debug.txt';
        $time = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$time] $msg\n", FILE_APPEND);
    }

    private static function get_config($db = null)
    {
        if (!$db) { global $pdo; $db = $pdo; }
        if (!$db) return [];

        try {
            // Priority: system_settings table
            $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%' OR setting_key = 'device_sns'");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Hosted Fallback: If local setting_settings is empty, check master DB (if connection exists and table available)
            if (empty($settings['dahua_app_id'])) {
                global $master_pdo;
                if (isset($master_pdo)) {
                    try {
                        $stmt = $master_pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%' OR setting_key = 'device_sns'");
                        $settings = array_merge($settings, $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
                    } catch (Exception $e) {}
                }
            }

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
        if (!$str)
            return $str;
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
        if (empty($config['client_id']) || empty($config['client_secret']))
            return null;
        $cacheFile = dirname(__DIR__) . '/scratch/dahua_token_' . md5($config['client_id']) . '.json';
        if (file_exists($cacheFile)) {
            $tokenData = json_decode(file_get_contents($cacheFile), true);
            if ($tokenData && ($tokenData['expire_time'] ?? 0) > time())
                return $tokenData['access_token'];
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
        self::log("--- INITIATING SYNC FOR VISIT ID: $visitId ---");
        if (!$pdo)
            global $pdo;
        if (!$pdo)
            return false;
        $config = self::get_config($pdo);
        $tokenV2 = self::getAccessToken($pdo);
        if (!$tokenV2)
            return false;
        $tokenV1 = self::getAccessTokenV1($config) ?: $tokenV2;

        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, v.visit_code FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.id = ?");
        $stmt->execute([$visitId]);
        $visit = $stmt->fetch();
        if (!$visit)
            return false;

        // Skip integration if area is empty, "Not Assigned", or "None"
        if (empty($visit['access_area']) || $visit['access_area'] === 'Not Assigned' || $visit['access_area'] === 'None') {
            self::log("Skipping Dahua Sync: Access area is '{$visit['access_area']}'. No hardware integration required.");
            return true;
        }

        $allDevices = [];
        $hasSpecificArea = false;

        // Support multiple selection in area (e.g., "Main-SN1, Office-SN2")
        $areas = array_map('trim', explode(',', $visit['access_area']));
        foreach ($areas as $area) {
            if (!empty($area))
                $hasSpecificArea = true;

            if (strpos($area, '-') !== false) {
                $parts = explode('-', $area);
                $extractedId = trim(end($parts));
                if (!empty($extractedId) && !in_array($extractedId, $allDevices)) {
                    $allDevices[] = $extractedId;
                }
            }
        }

        // If specific areas were chosen but NONE have machines, skip hardware sync (User Requirement)
        if ($hasSpecificArea && empty($allDevices)) {
            self::log("Skipping Dahua Sync: Assigned area(s) '{$visit['access_area']}' have no machines tagged.");
            return true;
        }

        // Fallback: If no specific devices were found but we didn't skip yet, use global settings
        if (empty($allDevices)) {
            $allDevices = array_filter(array_map('trim', explode(',', $config['device_sns'])));
        }

        if (empty($allDevices)) {
            self::log("Info: No Dahua SN found for sync. Skipping.");
            return true;
        }

        // --- STEP 1: Compress photo to <95KB and save to public path ---
        $photoRelative = ltrim($visit['photo_path'], './');
        $photoPath = dirname(__DIR__) . '/' . $photoRelative;
        $compressDir = dirname(__DIR__) . '/uploads/dahua_compressed/';
        if (!is_dir($compressDir))
            mkdir($compressDir, 0755, true);
        $compressedPath = $compressDir . $visitId . '.jpg';
        $photoUrl = null;

        if (file_exists($photoPath) && !empty($visit['photo_path'])) {
            // Dahua face recognition needs sufficient resolution and quality.
            // Target: 640x480, under 95KB, minimum quality 55 to preserve facial features.
            $img = null;
            $mime = mime_content_type($photoPath);
            if ($mime === 'image/png')
                $img = imagecreatefrompng($photoPath);
            elseif ($mime === 'image/jpeg')
                $img = imagecreatefromjpeg($photoPath);
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
                $photoUrl = BASE_URL . 'uploads/dahua_compressed/' . $visitId . '.jpg';
                self::log("Photo compressed: " . round(filesize($compressedPath) / 1024, 1) . "KB q=$quality → $photoUrl");
            }
        }

        $overallSuccess = true;
        foreach ($allDevices as $deviceId) {
            self::log("--- Processing Device: $deviceId ---");

            // --- STEP 2: Add User only (no face/card embedded — they are silently ignored by addUsers API) ---
            $dahuaId = (string) $visitId . (string) $visit['visitor_id'];
            self::log("Sync Step 1: Adding user $dahuaId (Method 37: QR Support)...");
            $userPath = '/open-api/api-iot/v2/device/accessControl/addUsers';
            $userPayload = [
                'deviceId' => $deviceId,
                'users' => [
                    [
                        'userId' => $dahuaId,
                        'userName' => $visit['visitor_name'],
                        'userType' => 0,
                        'authorityList' => [
                            ['channelNo' => 0]
                        ],
                        'permission' => 0,
                        'departmentId' => 0,
                        'verifyType' => 0,
                        'personalMethod' => 35,
                        'startTime' => date('Y-m-d H:i:s', strtotime('-1 day')),
                        'endTime' => date('Y-m-d H:i:s', strtotime("+" . ($visit['validity_number'] ?: $config['default_validity_number'] ?: '8') . " " . ($visit['validity_unit'] ?: $config['default_validity_unit'] ?: 'hours')))
                    ]
                ]
            ];
            $userBody = json_encode($userPayload);
            $userHeaders = self::generateSignV2($config, "POST", $userBody, $tokenV2);
            $ch = curl_init($config['base_url'] . $userPath);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $userBody,
                CURLOPT_HTTPHEADER => $userHeaders
            ]);
            $userResp = curl_exec($ch);
            curl_close($ch);
            self::log("Step 1 Response: " . substr($userResp, 0, 120));

            // --- STEP 3: Authorize Face (retry up to 3x with 5s delay for device propagation) ---
            // Per Dahua API Guide v2.4 s4.12.1.4.2: photoData is array, header must be stripped.
            // When both photoData + photoURL sent, photoData prevails; photoURL satisfies cloud validation.
            if ($photoUrl && file_exists($compressedPath)) {
                $facePath = '/open-api/api-iot/v2/device/accessControl/authorizeAccessFace';
                $rawBase64 = base64_encode(file_get_contents($compressedPath)); // No data:image prefix
                $facePayload = [
                    'deviceId' => $deviceId,
                    'faces' => [
                        [
                            'userId' => $dahuaId,
                            'photoData' => [$rawBase64],     // array per spec
                            'photoURL' => $photoUrl         // real hosted URL — satisfies cloud validation
                        ]
                    ]
                ];
                $faceBody = json_encode($facePayload);
                sleep(2); // brief propagation window before first attempt
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    if ($attempt > 1) {
                        self::log("Face retry $attempt/3 (waiting 5s)...");
                        sleep(5);
                    }
                    $faceHeaders = self::generateSignV2($config, "POST", $faceBody, $tokenV2);
                    $ch = curl_init($config['base_url'] . $facePath);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $faceBody,
                        CURLOPT_HTTPHEADER => $faceHeaders
                    ]);
                    $faceResp = curl_exec($ch);
                    curl_close($ch);
                    self::log("Step 2 Face (attempt $attempt): " . $faceResp);
                    $faceData = json_decode($faceResp, true);
                    if (($faceData['code'] ?? '') !== 'IDV0098')
                        break;
                }
            }

            // --- STEP 4: Authorize Card (Handles QR Code) ---
            if (!empty($visit['visit_code'])) {
                self::log("Waiting 3s for user record to propagate...");
                sleep(3);
                $cardPath = '/open-api/api-iot/v2/device/accessControl/authorizeAccessCard';
                $cardPayload = ['deviceId' => $deviceId, 'cards' => [['userId' => $dahuaId, 'cardNo' => trim((string) $visit['visit_code']), 'cardStatus' => 0, 'cardType' => 0]]];
                $cardBody = json_encode($cardPayload);
                self::log("Step 3 Card Payload: " . $cardBody);
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    if ($attempt > 1) {
                        self::log("Card retry $attempt/3 (waiting 5s)...");
                        sleep(5);
                    }
                    $cardHeaders = self::generateSignV2($config, "POST", $cardBody, $tokenV2);
                    $ch = curl_init($config['base_url'] . $cardPath);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => $cardBody,
                        CURLOPT_HTTPHEADER => $cardHeaders
                    ]);
                    $cardResp = curl_exec($ch);
                    curl_close($ch);
                    self::log("Step 3 Card (attempt $attempt): " . $cardResp);
                    $cardData = json_decode($cardResp, true);
                    if (($cardData['code'] ?? '') !== 'IDV0098')
                        break;
                }
            }

        }

        self::log("SUCCESS: Synced Visit ID $visitId to devices: " . implode(',', $allDevices));
        $pdo->prepare("UPDATE visits SET dahua_person_id = ? WHERE id = ?")->execute([$dahuaId, $visitId]);
        return $overallSuccess;
    }

    public static function deleteVisitor($visitId, $pdo = null)
    {
        if (!$pdo)
            global $pdo;
        $config = self::get_config($pdo);
        if (empty($config['client_id']))
            return false;

        $stmt = $pdo->prepare("SELECT dahua_person_id, visitor_id, access_area FROM visits WHERE id = ?");
        $stmt->execute([$visitId]);
        $visitData = $stmt->fetch();
        if (!$visitData)
            return false;

        // Collect all potential devices for cleanup
        $allDevices = array_filter(array_map('trim', explode(',', $config['device_sns'])));
        if (!empty($visitData['access_area']) && $visitData['access_area'] !== 'Not Assigned') {
            $areas = array_map('trim', explode(',', $visitData['access_area']));
            foreach ($areas as $area) {
                if (strpos($area, '-') !== false) {
                    $parts = explode('-', $area);
                    $sn = trim(end($parts));
                    if (!empty($sn) && !in_array($sn, $allDevices)) {
                        $allDevices[] = $sn;
                    }
                }
            }
        }

        if (empty($allDevices))
            return false;

        $dahuaUserId = $visitData['dahua_person_id'] ?: ((string) $visitId . (string) $visitData['visitor_id']);

        $tokenV2 = self::getAccessToken($pdo);
        if (!$tokenV2)
            return false;

        $overallSuccess = true;
        foreach ($allDevices as $deviceId) {
            self::log("Deleting visitor $visitId (Dahua ID: $dahuaUserId) from device $deviceId...");

            $path = '/open-api/api-iot/v2/device/accessControl/remove';
            $payload = [
                'deviceId' => $deviceId,
                'ids' => [(string) $dahuaUserId],
                'type' => 'user'
            ];

            $body = json_encode($payload);
            $headers = self::generateSignV2($config, "POST", $body, $tokenV2);

            $ch = curl_init($config['base_url'] . $path);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            if (!isset($data['code']) || $data['code'] !== "0" && $data['code'] !== "200") {
                self::log("Failed to delete from $deviceId. Response: $response");
                $overallSuccess = false;
            } else {
                self::log("Successfully deleted from $deviceId.");
            }
        }

        return $overallSuccess;
    }

    public static function processEvent($data, $pdo = null)
    {
        if (!$pdo)
            global $pdo;
        if (!$pdo)
            return false;

        // Support both Dahua V1 nested (msgBody) and V2 flattened structures
        $events = [];
        if (isset($data['userId']) || isset($data['personId'])) {
            $events[] = $data; // Flat structure
        } else {
            $msgBody = $data['msgBody'] ?? [];
            $events = $msgBody['data'] ?? (isset($msgBody['personId']) ? [$msgBody] : []);
        }

        foreach ($events as $event) {
            // ✅ LOG EVERY ENTRY TO machine_logs
            try {
                $timeMs = $event['utcTime'] ?? $event['localTime'] ?? $event['time'] ?? (time() * 1000);
                $scanTime = date('Y-m-d H:i:s', $timeMs / 1000);
                $deviceId = $event['deviceId'] ?? 'Dahua';
                $personId = $event['userId'] ?? $event['personId'] ?? null;
                $personName = $event['userName'] ?? $event['name'] ?? null;

                // Resolve name from DB if missing
                if (!$personName && $personId) {
                    $stmtName = $pdo->prepare("(SELECT name as full_name FROM employees WHERE dahua_person_id = ? OR id = ? LIMIT 1) 
                                               UNION 
                                               (SELECT full_name FROM users WHERE id = ? LIMIT 1)");
                    $stmtName->execute([$personId, $personId, $personId]);
                    $dbName = $stmtName->fetchColumn();
                    if ($dbName) {
                        $personName = $dbName;
                    } else {
                        $personName = 'Unknown';
                    }
                } elseif (!$personName) {
                    $personName = 'Unknown';
                }

                $image = $event['capturedImage'] ?? null;
                $eventType = $event['details'] ?? $event['type'] ?? $event['openType'] ?? 'Verification';

                $stmtLog = $pdo->prepare("INSERT INTO machine_logs (machine_id, person_id, person_name, event_type, event_time, image_path, raw_payload) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtLog->execute([
                    $deviceId,
                    $personId,
                    $personName,
                    $eventType,
                    $scanTime,
                    $image,
                    json_encode($event)
                ]);

                // ✅ AUTO-POPULATE machine_users table
                if ($personId && $deviceId) {
                    $stmtUser = $pdo->prepare("INSERT INTO machine_users (device_id, person_id, name, created_at) 
                        VALUES (?, ?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE 
                            name = COALESCE(NULLIF(VALUES(name), 'Unknown'), name),
                            updated_at = NOW()");
                    $stmtUser->execute([$deviceId, $personId, $personName ?: 'Unknown']);
                }
            } catch (Exception $e) {
                self::log("Error writing to machine_logs: " . $e->getMessage());
            }

            $personId = $event['userId'] ?? $event['personId'] ?? null;
            if (!$personId)
                continue;

            $stmt = $pdo->prepare("SELECT id FROM visits WHERE dahua_person_id = ? AND status IN ('pending', 'approved') ORDER BY id DESC LIMIT 1");
            $stmt->execute([$personId]);
            $visit = $stmt->fetch();

            if ($visit) {
                $timeMs = $event['utcTime'] ?? $event['localTime'] ?? $event['time'] ?? (time() * 1000);
                $scanTime = date('Y-m-d H:i:s', $timeMs / 1000);
                $deviceId = $event['deviceId'] ?? 'Dahua';
                $image = $event['capturedImage'] ?? null;

                $pdo->prepare("UPDATE visits SET status = 'checked_in', machine_captured_photo = ?, machine_scan_time = ?, machine_id = ?, checked_in_by = 1, check_in_time = IF(check_in_time IS NULL, NOW(), check_in_time) WHERE id = ?")->execute([$image, $scanTime, $deviceId, $visit['id']]);
            }
        }
        return true;
    }

    public static function getConfig($pdo = null)
    {
        return self::get_config($pdo);
    }

    public static function getAuthToken($pdo = null)
    {
        return self::getAccessToken($pdo);
    }

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
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return $resp;
    }

    public static function getPDO()
    {
        global $pdo;
        return $pdo;
    }

    public static function getPersonDetail($deviceId, $personId)
    {
        try {
            $config = self::getConfig();
            $token = self::getAuthToken();
            if (!$token)
                return null;

            $path = "/open-api/api-device/person/getPerson";
            $body = json_encode([
                'deviceId' => $deviceId,
                'personId' => (string) $personId
            ]);

            $headers = self::generateV2Headers($path, $body, $config['client_id'], $config['client_secret'], $token);
            $headers[] = "Authorization: $token";

            $response = self::makeRequest($config['base_url'] . $path, $body, $headers);
            $data = json_decode($response, true);

            return $data['data'] ?? null;
        } catch (Exception $e) {
            self::log("Error in getPersonDetail: " . $e->getMessage());
            return null;
        }
    }

    public static function syncAllUsers($deviceId)
    {
        $pdo = self::getPDO();
        // First try the bulk list
        $allUsers = self::getPeopleList($pdo, $deviceId, 1, 100);

        $userList = $allUsers['data']['list'] ?? [];

        // If bulk failed or is empty, we can't do much without IDs.
        // But we can update existing "Unknown" users by personId.
        $stmtUnknown = $pdo->prepare("SELECT DISTINCT person_id FROM machine_users WHERE name = 'Unknown' AND device_id = ?");
        $stmtUnknown->execute([$deviceId]);
        $ids = $stmtUnknown->fetchAll(PDO::FETCH_COLUMN);

        foreach ($ids as $pid) {
            $detail = self::getPersonDetail($deviceId, $pid);
            if ($detail) {
                // Restore full mapping for names and permissions
                $name = $detail['userName'] ?? $detail['name'] ?? 'Unknown';
                $faceCount = isset($detail['faceList']) ? count($detail['faceList']) : 0;
                $fpCount = isset($detail['fingerprintList']) ? count($detail['fingerprintList']) : 0;
                $cardNo = $detail['cardList'][0]['cardNo'] ?? '';
                $userType = $detail['userType'] ?? null;
                $permission = $detail['permissionLevel'] ?? null;

                $pdo->prepare("UPDATE machine_users SET 
                    name = ?, 
                    card_no = ?,
                    face_count = ?,
                    fp_count = ?,
                    user_type = ?,
                    permission_level = ?,
                    updated_at = NOW()
                    WHERE person_id = ? AND device_id = ?")
                    ->execute([$name, $cardNo, $faceCount, $fpCount, $userType, $permission, $pid, $deviceId]);
            }
        }
        return true;
    }
    public static function getPeopleList($pdo = null, $deviceId = null, $page = 1, $pageSize = 100)
    {
        try {
            $config = self::getConfig($pdo);
            $token = self::getAuthToken($pdo);
            if (!$token)
                return ['error' => 'No Token'];

            $path = "/open-api/api-device/person/pageGetPerson";
            $body = json_encode([
                'deviceId' => $deviceId ?: explode(',', $config['device_sns'])[0],
                'pageSize' => $pageSize,
                'pageNum' => $page
            ]);

            $headers = self::generateV2Headers($path, $body, $config['client_id'], $config['client_secret'], $token);
            $headers[] = "Authorization: $token";

            $response = self::makeRequest($config['base_url'] . $path, $body, $headers);
            return json_decode($response, true);
        } catch (Exception $e) {
            self::log("Error in getPeopleList: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
}
