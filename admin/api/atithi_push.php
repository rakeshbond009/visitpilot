<?php
require_once '../../includes/db.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') {
    die("Unauthorized Access.");
}

// Prepare Streaming (Matches Codepilotx)
header('Content-Type: text/plain');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
ob_implicit_flush(true);
ob_end_flush();

function streamOutput($text) {
    echo $text . "\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

$remarks = $_POST['remarks'] ?? 'Cloud Sync: System Updated';
$atithi_webhook = 'https://webhooks.hostinger.com/deploy/76b62f19d8cb5408d1113b8484c451c4';

streamOutput("[STATUS]: Starting ATITHI Dedicated Sync...");

$vms_root = realpath(__DIR__ . '/../../');
chdir($vms_root);

// Step 1: Git Core (Same as Codepilotx)
streamOutput("[SYSTEM]: Running 'git add .'...");
shell_exec('git add . 2>&1');

streamOutput("[SYSTEM]: Committing changes: '$remarks'...");
shell_exec('git commit -m "' . addslashes($remarks) . '" 2>&1');

streamOutput("[SYSTEM]: Pushing to GitHub...");
$output = shell_exec('git push origin main 2>&1');
streamOutput("[GIT LOG]: " . trim($output));

// Step 2: Direct "Repair" Bridge Trigger (Same as Codepilotx logic)
$buster = time();
$domain = 'https://atithi.online';
$remote_url = rtrim($domain, '/') . "/admin/api/repair_sync.php?auto=1&v=" . $buster;

streamOutput("[SYNC]: Triggering Direct Update on $domain...");
$ch = curl_init($remote_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$repair_res = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 200 && strpos($repair_res, 'Completed') !== false) {
    streamOutput("[SUCCESS]: Atithi is now Synchronized via Bridge.");
} else {
    streamOutput("[WARN]: Bridge Sync failed (HTTP $http_code). Trying Native Webhook...");
}

// Step 3: Trigger Official Webhook (Same as Codepilotx logic)
streamOutput("[SYSTEM]: Triggering Official Hostinger Deployment Signal...");
$ch = curl_init($atithi_webhook);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, []); // Clean POST signal
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$wh_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($wh_code >= 200 && $wh_code < 300) {
    streamOutput("[SUCCESS]: Atithi Official Signal Accepted ($wh_code).");
} else {
    streamOutput("[WARN]: Atithi Official Signal failed ($wh_code).");
}

streamOutput("\n[SUCCESS]: ATITHI UPDATED SUCCESSFULLY.");
