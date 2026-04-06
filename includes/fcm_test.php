<?php
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/push_helper.php';

$user_id = $_GET['user_id'] ?? 0;
if (!$user_id) {
    die("Please provide user_id in URL (e.g. ?user_id=1)");
}

echo "<h1>FCM Targeted Push Test</h1>";
echo "Targeting User ID: $user_id<br>";

$stmt = $pdo->prepare("SELECT ud.fcm_token, ud.platform FROM user_devices ud WHERE ud.user_id = ?");
$stmt->execute([$user_id]);
$devices = $stmt->fetchAll();

if (empty($devices)) {
    echo "No devices found in user_devices table. Falling back to users table...<br>";
    $stmt = $pdo->prepare("SELECT fcm_token, 'android' as platform FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $devices = $stmt->fetchAll();
}

if (empty($devices)) {
    die("ERROR: No FCM tokens found for this user in either table.");
}

echo "Found " . count($devices) . " device(s).<br>";

foreach ($devices as $d) {
    echo "Attempting to send to token: " . substr($d['fcm_token'], 0, 20) . "... (Platform: {$d['platform']})<br>";
    $result = sendPushToUser($pdo, $user_id, "Test Notification", "This is a test from the VMS server logic.", ["type" => "test_push"]);
    echo "Result: " . ($result ? "Function returned TRUE" : "Function returned FALSE") . "<br>";
}

echo "<br><br>Check <b>includes/push_debug.log</b> for detailed FCM responses.";
