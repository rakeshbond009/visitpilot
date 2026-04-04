<?php
/**
 * VMS Push Helper - High-Performance Parallel Version
 * Uses curl_multi to prevent 'failed to connect to server' timeouts in mobile app.
 */

function sendPushNotification($pdo, $employee_id, $title, $body, $data = [])
{
    $logFile = __DIR__ . '/push_debug.log';
    $log = function ($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $log("Starting Parallel Push for Employee ID: $employee_id.");

    $stmt = $pdo->prepare("
        SELECT u.id as user_id, ud.fcm_token, u.role, ud.platform 
        FROM users u 
        JOIN user_devices ud ON u.id = ud.user_id 
        WHERE u.employee_id = ? 
        AND ud.fcm_token IS NOT NULL
    ");
    $stmt->execute([$employee_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        $stmt = $pdo->prepare("SELECT u.id as user_id, u.fcm_token, u.role FROM users u WHERE u.employee_id = ? AND u.fcm_token IS NOT NULL");
        $stmt->execute([$employee_id]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($users)) return false;

    $certPath = __DIR__ . '/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
    if (!file_exists($certPath)) return false;
    $serviceAccount = json_decode(file_get_contents($certPath), true);
    $projectId = $serviceAccount['project_id'];

    $accessToken = getGoogleAccessToken($serviceAccount);
    if (!$accessToken) return false;

    $mh = curl_multi_init();
    $handles = [];

    foreach ($users as $user) {
        $platform = strtolower($user['platform'] ?? 'android');
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
                ],
                'android' => [ 'priority' => 'high' ]
            ]
        ];

        if ($platform !== 'android') {
            $message['message']['notification'] = [ 'title' => (string) $title, 'body' => (string) $body ];
        }

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_multi_add_handle($mh, $ch);
        $handles[] = ['handle' => $ch, 'user_id' => $user['user_id']];
    }

    $running = null;
    do { curl_multi_exec($mh, $running); } while ($running > 0);

    foreach ($handles as $h) {
        $response = curl_multi_getcontent($h['handle']);
        $log("Response for {$h['user_id']}: $response");
        curl_multi_remove_handle($mh, $h['handle']);
        curl_close($h['handle']);
    }
    curl_multi_close($mh);
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
        if (!$privateKey) return '';
        openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $jwt = $signatureInput . "." . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]));
        $tokenData = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $tokenData['access_token'] ?? '';
    } catch (Exception $e) { return ''; }
}

function sendPushNotificationToRole($pdo, $role, $title, $body, $data = [])
{
    $logFile = __DIR__ . '/push_debug.log';
    $log = function ($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $stmt = $pdo->prepare("
        SELECT u.id as user_id, ud.fcm_token, u.role 
        FROM users u 
        JOIN user_devices ud ON u.id = ud.user_id 
        WHERE u.role = ? 
        AND ud.fcm_token IS NOT NULL
    ");
    $stmt->execute([$role]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) return false;

    $certPath = __DIR__ . '/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
    if (!file_exists($certPath)) return false;
    $serviceAccount = json_decode(file_get_contents($certPath), true);
    $projectId = $serviceAccount['project_id'];
    $accessToken = getGoogleAccessToken($serviceAccount);
    if (!$accessToken) return false;

    $mh = curl_multi_init();
    $handles = [];

    foreach ($users as $user) {
        $message = [
            'message' => [
                'token' => (string) $user['fcm_token'],
                'data' => array_merge([
                    'title' => (string) $title,
                    'body' => (string) $body,
                    'type' => 'visit_update',
                    'is_call_priority' => 'false',
                    'visitId' => (string) ($data['visit_id'] ?? ''),
                ], $data),
                'android' => [ 'priority' => 'high' ],
                'apns' => [ 'payload' => [ 'aps' => [ 'alert' => [ 'title' => (string) $title, 'body' => (string) $body ], 'sound' => 'default' ] ] ]
            ]
        ];

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    $active = null;
    do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    foreach ($handles as $ch) {
        $res = curl_multi_getcontent($ch);
        $log("Role Push Multi-Response: $res");
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return true;
}

function sendPushNotificationToUserId($pdo, $user_id, $title, $body, $data = [])
{
    $logFile = __DIR__ . '/push_debug.log';
    $log = function ($msg) use ($logFile) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $stmt = $pdo->prepare("SELECT ud.fcm_token, ud.platform FROM user_devices ud WHERE ud.user_id = ? AND ud.fcm_token IS NOT NULL");
    $stmt->execute([$user_id]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($devices)) return false;

    $certPath = __DIR__ . '/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
    if (!file_exists($certPath)) return false;
    $serviceAccount = json_decode(file_get_contents($certPath), true);
    $projectId = $serviceAccount['project_id'];
    $accessToken = getGoogleAccessToken($serviceAccount);
    if (!$accessToken) return false;

    $mh = curl_multi_init();
    $handles = [];

    foreach ($devices as $device) {
        $message = [
            'message' => [
                'token' => (string) $device['fcm_token'],
                'data' => [
                    'title' => (string) $title,
                    'body' => (string) $body,
                    'visit_id' => (string) ($data['visit_id'] ?? ''),
                    'type' => 'visit_status_update',
                    'is_call_priority' => 'false'
                ],
                'android' => [ 'priority' => 'high', 'notification' => [ 'channel_id' => 'vms_status_updates', 'sound' => 'default' ] ],
                'notification' => [ 'title' => (string) $title, 'body' => (string) $body ]
            ]
        ];

        $ch = curl_init("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }

    $active = null;
    do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    foreach ($handles as $ch) {
        $res = curl_multi_getcontent($ch);
        $log("User Push Multi-Response: $res");
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return true;
}
?>