<?php
// trigger_test_push.php - Upload this to your hosted server at /api/visitor/
require_once '../../includes/db.php';
require_once '../../includes/push_helper.php';

header('Content-Type: application/json');

// This script will send a TEST notification to EVERYONE registered on this server.
try {
    $stmt = $pdo->query("SELECT DISTINCT fcm_token FROM user_devices WHERE fcm_token IS NOT NULL");
    $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($tokens)) {
        // Fallback to legacy table
        $stmt = $pdo->query("SELECT DISTINCT fcm_token FROM users WHERE fcm_token IS NOT NULL");
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if (empty($tokens)) {
         echo json_encode(['success' => false, 'message' => 'No FCM tokens found in DB. Use the app to register your token first.']);
         exit;
    }

    $title = "🚨 TEST: Visitor Arrival";
    $body = "A test visitor is waiting at the main gate. Please authorize.";
    $data = [
        'type' => 'visitor_arrival',
        'is_call_priority' => 'true',
        'visitor_name' => 'John Doe (Live Test)',
        'visit_id' => '1337',
        'purpose' => 'System Final Validation',
        'photo' => 'https://ui-avatars.com/api/?name=John+Doe&background=random',
    ];

    $certPath = '../../includes/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
    $serviceAccount = json_decode(file_get_contents($certPath), true);
    $results = [];

    foreach ($tokens as $token) {
        try {
            $accessToken = getGoogleAccessToken($serviceAccount);
            $message = [
                'message' => [
                    'token' => (string)$token,
                    'data' => array_merge([
                        'title' => (string)$title,
                        'body' => (string)$body,
                    ], $data),
                    'android' => [
                        'priority' => 'high',
                        'ttl' => '0s'
                    ]
                ]
            ];

            $payload = json_encode($message);
            $ch = curl_init("https://fcm.googleapis.com/v1/projects/" . $serviceAccount['project_id'] . "/messages:send");
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $result = curl_exec($ch);
            curl_close($ch);
            $results[] = json_decode($result, true);
        } catch (Exception $inner) {
            $results[] = ['error' => $inner->getMessage()];
        }
    }

    echo json_encode(['success' => true, 'total_sent' => count($tokens), 'results' => $results]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
