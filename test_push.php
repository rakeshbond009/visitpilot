<?php
$t0 = microtime(true);
require_once 'includes/db.php';
require_once 'includes/push_helper.php';

echo 'Testing getGoogleAccessToken...\n';
$certPath = __DIR__ . '/includes/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
if (!file_exists($certPath)) {
    echo "Cert not found\n";
    exit;
}
$sa = json_decode(file_get_contents($certPath), true);
$t = microtime(true);
$token = getGoogleAccessToken($sa);
echo "Token fetched in " . (microtime(true) - $t) . " seconds. Token info: " . substr($token, 0, 10) . "...\n";

echo "Testing push dispatch to user a mock user...\n";
$t2 = microtime(true);
sendPushNotificationToUserId($pdo, 1, 'Test', 'Test', []);
echo "Push dispatched in " . (microtime(true) - $t2) . " seconds.\n";

echo "Total time: " . (microtime(true) - $t0) . " seconds.\n";
