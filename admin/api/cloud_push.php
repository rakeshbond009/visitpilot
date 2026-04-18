<?php
require_once '../../includes/db.php';
requireLogin();

// 1. Enforce Super Admin only
if ($_SESSION['role'] !== 'admin') {
    die("Unauthorized Access.");
}

// Prepare Streaming
header('Content-Type: text/plain');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // For Nginx if applicable
ob_implicit_flush(true);
ob_end_flush();

function streamOutput($text)
{
    echo $text . "\n";
    if (ob_get_level() > 0)
        ob_flush();
    flush();
}

// 2. Deployment Parameters
$remarks = $_POST['remarks'] ?? 'Cloud Sync: Updated code and permissions';
if (empty($remarks))
    $remarks = 'Cloud Sync: System Updated';

// Fetch Hostinger Webhook
$webhook = '';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'hostinger_webhook'");
    $stmt->execute();
    $res = $stmt->fetch();
    if ($res)
        $webhook = $res['setting_value'];
} catch (Exception $e) {
}

streamOutput("[STATUS]: Starting Preparation Sequence...");

// 3. Git Operations
// Change directory to the VMS root
$vms_root = realpath(__DIR__ . '/../../');
chdir($vms_root);

streamOutput("[STATUS]: Path detected - " . $vms_root);

// Step 0: Increment Version & Timestamp in includes/init.php
streamOutput("[SYSTEM]: Updating System Build Timestamp...");
$init_file = $vms_root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'init.php';
if (file_exists($init_file)) {
    $new_ver = date('Y.m.d.Hi');
    $new_ts = date('Y-m-d H:i:s');
    $content = "<?php\n/**\n * System Initialization & Version Control\n * Automatically updated on Cloud Sync\n */\ndefine('APP_VERSION', '$new_ver');\ndefine('BUILD_TIMESTAMP', '$new_ts');\n";
    file_put_contents($init_file, $content);
    streamOutput("[INFO]: Build Version updated to $new_ver");
}

// Step 1: Add all files
streamOutput("[SYSTEM]: Running 'git add .'...");
$output = shell_exec('git add . 2>&1');
if ($output)
    streamOutput("[GIT LOG]: " . trim($output));

// Step 2: Commit changes
streamOutput("[SYSTEM]: Committing changes with remarks: '$remarks'...");
$commit_cmd = 'git commit -m "' . addslashes($remarks) . '" 2>&1';
$output = shell_exec($commit_cmd);
if ($output)
    streamOutput("[GIT LOG]: " . trim($output));

if (strpos($output, 'nothing to commit') !== false || strpos($output, 'On branch main') !== false) {
    streamOutput("[WARN]: No new changes to commit. Proceeding to push local updates...");
}

// Step 3: Push to GitHub
streamOutput("[SYSTEM]: Pushing to GitHub (origin main)...");
$push_cmd = 'git push origin main 2>&1';
$output = shell_exec($push_cmd);
if ($output)
    streamOutput("[GIT LOG]: " . trim($output));

$is_error = (strpos($output, 'fatal:') !== false || strpos($output, 'error:') !== false || strpos($output, '[remote rejected]') !== false);
$is_uptodate = (strpos($output, 'Everything up-to-date') !== false);

if ($is_error && !$is_uptodate) {
    if (strpos($output, '403') !== false) {
        streamOutput("[FAILED]: GitHub Authentication Error (403).");
    } else {
        streamOutput("[FAILED]: Git Push Error.");
    }
}

// 4. Force Update Server (Bypass Hostinger's unreliable autodeploy)
$buster = time();
$server_repair_url = BASE_URL . "admin/api/repair_sync.php?auto=1&v=" . $buster;
// Convert Local BASE_URL to Remote if needed - Force HTTPS
$remote_url = str_replace(['http://localhost/visitpilot', 'localhost/visitpilot'], 'https://visitor.codepilotx.com', $server_repair_url);

streamOutput("[SYSTEM]: Triggering Forced Server Update (v=$buster)...");

// Use robust curl instead of file_get_contents
$ch = curl_init($remote_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$repair_res = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code == 200 && strpos($repair_res, 'Repair Sequence Completed') !== false) {
    streamOutput("[SUCCESS]: SERVER UPDATED AND SYNCHRONIZED SUCCESSFULLY.");
} else {
    streamOutput("[WARN]: Git Push OK, but Server Update failed (HTTP $http_code).");
    streamOutput("[INFO]: Please visit this link manually if needed: " . $remote_url);
}

// 5. Trigger Webhook (If set)
if (!empty($webhook)) {
    streamOutput("[SYSTEM]: Triggering Hostinger Webhook for automated deployment...");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhook);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['remarks' => $remarks])); // Use JSON for better compatibility
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-GitHub-Event: push',                 // Mimics GitHub push event
        'X-GitHub-Delivery: ' . uniqid(),       // Professional unique delivery ID
        'User-Agent: GitHub-Hookshot/VisitPilot' // Mimics GitHub's automated service
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 || $http_code === 202) {
        streamOutput("[STATUS]: Webhook triggered successfully (HTTP $http_code).");
    } else {
        streamOutput("[WARN]: Webhook failed or returned unusual status (HTTP $http_code). Response: " . substr(strip_tags($response), 0, 100));
    }
} else {
    streamOutput("[INFO]: No Hostinger Webhook configured. Manual pull on server might be required.");
}

streamOutput("\n[SUCCESS]: CLOUD SNYCHRONIZATION SUCCESSFULLY COMPLETED.");
?>