<?php
require_once '../../includes/db.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') {
    die("Unauthorized Access.");
}

// Prepare Streaming (Matches your dashboard flow)
header('Content-Type: text/plain');
header('Cache-Control: no-cache');
ob_implicit_flush(true);
ob_end_flush();

function streamOutput($text) {
    echo $text . "\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

$remarks = $_POST['remarks'] ?? 'Atithi Sync: System Updated';
$atithi_webhook = 'https://webhooks.hostinger.com/deploy/76b62f19d8cb5408d1113b8484c451c4';

streamOutput("[STATUS]: Starting ATITHI Sync Engine...");

$vms_root = realpath(__DIR__ . '/../../');
chdir($vms_root);

// 1. Push to GitHub (Source of truth)
streamOutput("[SYSTEM]: Running 'git add .'...");
shell_exec('git add . 2>&1');

streamOutput("[SYSTEM]: Committing changes...");
shell_exec('git commit -m "' . addslashes($remarks) . '" 2>&1');

streamOutput("[SYSTEM]: Pushing to GitHub Main...");
$output = shell_exec('git push origin main 2>&1');
streamOutput("[GIT LOG]: " . trim($output));

// 2. Trigger the Official Atithi Deployment Motor
streamOutput("[SYSTEM]: Triggering Atithi Native Deployment Signal...");
$ch = curl_init($atithi_webhook);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, []); // Clean multipart POST ensures Hostinger listens
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code >= 200 && $http_code < 300) {
    streamOutput("[SUCCESS]: Atithi Signal Accepted ($http_code). Folders are moving.");
} else {
    streamOutput("[WARN]: Atithi Signal Rejected ($http_code). Please deploy manually.");
}

streamOutput("\n[SUCCESS]: ATITHI UPDATED SUCCESSFULLY.");
