<?php
require_once 'includes/db.php';
require_once 'includes/push_helper.php';

// Simulate a visitor arrival
$title = "TEST: Visitor Arrival";
$body = "Automated test visitor is at the door.";
$data = [
    'type' => 'visitor_arrival',
    'is_call_priority' => 'true',
    'visitor_name' => 'John Doe (Test)',
    'visit_id' => '99999',
    'purpose' => 'System Testing',
    'photo' => 'https://ui-avatars.com/api/?name=John+Doe&background=random',
];

// Fetch ALL uniquely registered tokens to make sure we hit the user's phone
$stmt = $pdo->query("SELECT DISTINCT fcm_token FROM user_devices WHERE fcm_token IS NOT NULL");
$tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tokens)) {
    echo "ERROR: No FCM tokens found in user_devices table.\n";
    exit;
}

echo "Found " . count($tokens) . " tokens. Sending test notifications...\n";

$certPath = __DIR__ . '/includes/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
$serviceAccount = json_decode(file_get_contents($certPath), true);

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
        
        echo "Sent to $token: $result\n";
    } catch (Exception $e) {
        echo "Error sending to $token: " . $e->getMessage() . "\n";
    }
}
?>
