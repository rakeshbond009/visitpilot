<?php
/**
 * VMS Push Helper - Refined Version with Full Data Sync
 */

function sendPushNotification($pdo, $employee_id, $title, $body, $data = [])
{
    // --- DEBUG LOGGING ---
    $logFile = __DIR__ . '/push_debug.log';
    $log = function ($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $log("Attempting push for Employee ID: $employee_id. Title: $title");

    // Fetch from user_devices table for multi-device and platform-specific support
    $stmt = $pdo->prepare("
        SELECT u.id as user_id, ud.fcm_token, u.role, ud.platform 
        FROM users u 
        JOIN user_devices ud ON u.id = ud.user_id 
        WHERE (u.employee_id = ? OR (u.id = ? AND u.role != 'visitor'))
        AND ud.fcm_token IS NOT NULL
    ");
    $stmt->execute([$employee_id, $employee_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback to legacy single token
    if (empty($users)) {
        $stmt = $pdo->prepare("SELECT u.id as user_id, u.fcm_token, u.role FROM users u WHERE u.employee_id = ? AND u.fcm_token IS NOT NULL");
        $stmt->execute([$employee_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($users)) {
        $log("No active FCM tokens found for Employee ID: $employee_id");
        return false;
    }

    $certPath = __DIR__ . '/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
    if (!file_exists($certPath)) {
        $log("CRITICAL ERROR: Firebase JSON not found");
        return false;
    }
    $serviceAccount = json_decode(file_get_contents($certPath), true);
    $projectId = $serviceAccount['project_id'];

    // Fetch token ONCE per batch
    $accessToken = getGoogleAccessToken($serviceAccount);
    if (!$accessToken) {
        $log("CRITICAL ERROR: Failed to fetch Google Access Token");
        return false;
    }

    foreach ($users as $user) {
        $platform = strtolower($user['platform'] ?? 'android');
        $log("Targeting User ID: {$user['user_id']} | Platform: $platform | Token: " . substr($user['fcm_token'], 0, 20) . '...');

        $message = [
            'message' => [
                'token' => (string) $user['fcm_token'],
                'data' => [
                    'title' => (string) $title,
                    'body' => (string) $body,
                    'type' => 'visitor_arrival',
                    'is_call_priority' => 'true',
                    'visit_id' => (string) ($data['visit_id'] ?? ''),
                    'visitor_name' => (string) ($data['visitor_name'] ?? $data['name'] ?? ''),
                    'visitor_mobile' => (string) ($data['visitor_mobile'] ?? $data['mobile'] ?? ''),
                    'visitor_photo' => (string) ($data['visitor_photo'] ?? $data['photo_url'] ?? ''),
                    'company' => (string) ($data['company'] ?? ''),
                    'purpose' => (string) ($data['purpose'] ?? ''),
                    'assets_carried' => (string) ($data['assets_carried'] ?? $data['assets'] ?? ''),
                ],
                'android' => [
                    'priority' => 'high',
                    'ttl' => '0s',
                ]
            ]
        ];

        // ANDROID KILLED STATE FIX: Omit notification block for Android
        if ($platform !== 'android') {
            $message['message']['notification'] = [
                'title' => (string) $title,
                'body' => (string) $body,
            ];
        }

        $payloadJson = json_encode($message);
        $log("Sending to User {$user['user_id']} ($platform)");

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $log("FCM Response for User {$user['user_id']} [HTTP $httpCode]: $response");

        // AUTO-PURGE: Remove stale UNREGISTERED tokens immediately
        if ($httpCode === 404) {
            $decoded = json_decode($response, true);
            $errorCode = $decoded['error']['details'][0]['errorCode'] ?? '';
            if ($errorCode === 'UNREGISTERED') {
                $log("PURGING stale token for User {$user['user_id']}");
                $pdo->prepare("DELETE FROM user_devices WHERE user_id = ? AND fcm_token = ?")
                    ->execute([$user['user_id'], $user['fcm_token']]);
                $pdo->prepare("UPDATE users SET fcm_token = NULL WHERE id = ? AND fcm_token = ?")
                    ->execute([$user['user_id'], $user['fcm_token']]);
            }
        }
    }
    return true;
}

function getGoogleAccessToken($serviceAccount)
{
    try {
        $now = time();
        $expiry = $now + 3600;
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claimSet = json_encode([
            'iss' => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $expiry,
            'iat' => $now
        ]);

        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlClaimSet = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claimSet));
        $signatureInput = $base64UrlHeader . "." . $base64UrlClaimSet;

        $privateKey = openssl_pkey_get_private($serviceAccount['private_key']);
        if (!$privateKey)
            return '';

        openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt = $signatureInput . "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $tokenData = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $tokenData['access_token'] ?? '';
    } catch (Exception $e) {
        return '';
    }
}

function sendPushNotificationToRole($pdo, $role, $title, $body, $data = [])
{
    // --- DEBUG LOGGING ---
    $logFile = __DIR__ . '/push_debug.log';
    $log = function ($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $log("Attempting push for Role: $role. Title: $title");

    // Fetch users by role
    $stmt = $pdo->prepare("
        SELECT u.id as user_id, ud.fcm_token, u.role 
        FROM users u 
        JOIN user_devices ud ON u.id = ud.user_id 
        WHERE u.role = ? 
        AND ud.fcm_token IS NOT NULL
    ");
    $stmt->execute([$role]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fallback (Backward Compatibility)
    if (empty($users)) {
        $stmt = $pdo->prepare("SELECT u.id as user_id, u.fcm_token, u.role FROM users u WHERE u.role = ? AND u.fcm_token IS NOT NULL");
        $stmt->execute([$role]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($users)) {
        $log("No active FCM tokens found for Role: $role");
        return false;
    }

    // Filter tokens
    $users = array_filter($users, function ($u) {
        return !empty(trim($u['fcm_token'])) && strlen($u['fcm_token']) > 20;
    });

    if (empty($users)) {
        return false;
    }

    $certPath = __DIR__ . '/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
    if (!file_exists($certPath)) {
        $log("CRITICAL ERROR: Firebase Service Account JSON not found");
        return false;
    }

    $serviceAccount = json_decode(file_get_contents($certPath), true);
    $projectId = $serviceAccount['project_id'];

    // ⚡ FIX: Fetch token ONCE before the loop (was fetched per-user = N extra HTTP calls)
    $accessToken = getGoogleAccessToken($serviceAccount);
    if (!$accessToken) {
        $log("CRITICAL ERROR: Failed to fetch Google Access Token for Role: $role");
        return false;
    }

    foreach ($users as $user) {
        $log("Targeting User ID: {$user['user_id']} (Role: {$user['role']}) | Token: " . substr($user['fcm_token'], 0, 20) . '...');

        $message = [
            'message' => [
                'token' => (string) $user['fcm_token'],
                'notification' => [
                    'title' => (string) $title,
                    'body' => (string) $body,
                ],
                'data' => array_merge([
                    'title' => (string) $title,
                    'body' => (string) $body,
                    'type' => 'visit_update',
                    'is_call_priority' => 'false',
                    'visit_id' => (string) ($data['visit_id'] ?? ''),
                    'click_action' => 'visit_update_action'
                ], $data),
                'android' => [
                    'priority' => 'high',
                    'ttl' => '0s',
                    'notification' => [
                        'click_action' => 'visit_update_action',
                        'sound' => 'default',
                        'channel_id' => 'vms_urgent_alerts_v2'
                    ]
                ],
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => (string) $title,
                                'body' => (string) $body,
                            ],
                            'sound' => 'default',
                            'category' => 'visit_update_action',
                            'thread-id' => 'visit-'.(string)($data['visit_id'] ?? 'unknown'),
                            'interruption-level' => 'time-sensitive'
                        ]
                    ]
                ]
            ]
        ];

        $payloadJson = json_encode($message);
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $log("FCM RESPONSE for Role $role, User {$user['user_id']} [HTTP $httpCode]: $response");

        // AUTO-PURGE: Remove stale UNREGISTERED tokens immediately
        if ($httpCode === 404) {
            $decoded = json_decode($response, true);
            $errorCode = $decoded['error']['details'][0]['errorCode'] ?? '';
            if ($errorCode === 'UNREGISTERED') {
                $log("PURGING stale token for User {$user['user_id']} (Role: $role)");
                $pdo->prepare("DELETE FROM user_devices WHERE user_id = ? AND fcm_token = ?")
                    ->execute([$user['user_id'], $user['fcm_token']]);
                $pdo->prepare("UPDATE users SET fcm_token = NULL WHERE id = ? AND fcm_token = ?")
                    ->execute([$user['user_id'], $user['fcm_token']]);
            }
        }
    }
    return true;
}

/**
 * Send notification to a specific user by ID
 */
function sendPushNotificationToUser($pdo, $user_id, $title, $body, $data = [])
{
    $logFile = __DIR__ . '/push_debug.log';
    $log = function ($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $log("Attempting push for Specific User ID: $user_id. Title: $title");

    $stmt = $pdo->prepare("
        SELECT u.id as user_id, ud.fcm_token, u.role, ud.platform 
        FROM users u 
        JOIN user_devices ud ON u.id = ud.user_id 
        WHERE u.id = ? 
        AND ud.fcm_token IS NOT NULL
    ");
    $stmt->execute([$user_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        // Legacy check
        $stmt = $pdo->prepare("SELECT id as user_id, fcm_token, role FROM users WHERE id = ? AND fcm_token IS NOT NULL");
        $stmt->execute([$user_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($users)) {
        $log("No tokens found for User ID: $user_id");
        return false;
    }

    $certPath = __DIR__ . '/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
    if (!file_exists($certPath)) return false;

    $serviceAccount = json_decode(file_get_contents($certPath), true);
    $projectId = $serviceAccount['project_id'];
    $accessToken = getGoogleAccessToken($serviceAccount);
    if (!$accessToken) return false;    foreach ($users as $user) {
        $log("Targeting Token: " . substr($user['fcm_token'], 0, 15) . "... for User $user_id");
        $message = [
            'message' => [
                'token' => (string) $user['fcm_token'],
                'notification' => [
                    'title' => (string) $title,
                    'body' => (string) $body,
                ],
                'data' => [
                    'title' => (string) $title,
                    'body' => (string) $body,
                    'type' => 'visit_update',
                    'visit_id' => (string) ($data['visit_id'] ?? ''),
                    'click_action' => 'visit_update_action'
                ],
                'android' => [
                    'priority' => 'high',
                    'ttl' => '0s',
                    'notification' => [
                        'click_action' => 'visit_update_action',
                        'sound' => 'default',
                        'channel_id' => 'vms_urgent_alerts_v2'
                    ]
                ]
            ]
        ];

        $payloadJson = json_encode($message);
        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payloadJson);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $log("FCM RESPONSE for Direct User $user_id [HTTP $httpCode]: $res");
    }
    return true;
}
?>