<?php
/*
  FCM Targeted Push Diagnostic Tool
  ---------------------------------
  Test if backend FCM dispatch is working for a specific User ID.
*/
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/push_helper.php';

$testUserId = 3; // Modify this to the User ID of the creator

echo "<h1>FCM Targeted Push Test</h1>";
echo "Targeting User ID: $testUserId<br>";

// Verification
$stmt = $pdo->prepare("SELECT ud.fcm_token, u.name, ud.platform FROM user_devices ud JOIN users u ON ud.user_id = u.id WHERE ud.user_id = ?");
$stmt->execute([$testUserId]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$devices) {
    echo "No devices found for this user in user_devices table.";
} else {
    echo "Found " . count($devices) . " device(s).<br>";
    foreach ($devices as $d) {
        echo "Attempting to send to token: " . substr($d['fcm_token'], 0, 20) . "... (Platform: " . $d['platform'] . ")<br>";
        $result = sendPushToUser($pdo, $testUserId, "Test Notification", "This is a targeted debug test at " . date('H:i:s'), ['type' => 'test', 'visit_id' => '123']);
        echo "Result: " . ($result ? "TRUE" : "FALSE") . "<br>";
    }
}

echo "<br><br>Check includes/push_debug.log for detailed FCM responses.";
?>
