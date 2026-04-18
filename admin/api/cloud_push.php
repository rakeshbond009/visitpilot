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

// 4. Force Update Remote Servers (Bypass Hostinger's unreliable autodeploy)
$buster = time();
$targets = [
    'https://visitor.codepilotx.com',
    'https://atithi.online'
];

streamOutput("[SYSTEM]: Broadcasting Update to " . count($targets) . " servers...");

foreach ($targets as $domain) {
    $remote_url = rtrim($domain, '/') . "/admin/api/repair_sync.php?auto=1&v=" . $buster;
    streamOutput("[SYNC]: Updating $domain...");

    // Use robust curl for multi-server broadcast
    $ch = curl_init($remote_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $repair_res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code == 200 && strpos($repair_res, 'Repair Sequence Completed') !== false) {
        streamOutput("[SUCCESS]: $domain is now Synchronized.");
    } else {
        streamOutput("[WARN]: $domain failed to sync (HTTP $http_code). Please check manually.");
    }
}

// 5. Trigger Native Hostinger Deployment for BOTH servers
$webhooks = [
    'https://webhooks.hostinger.com/deploy/2ec9b2d8778f62304677732d84784783', // Codepilotx
    'https://webhooks.hostinger.com/deploy/76b62f19d8cb5408d1113b8484c451c4'  // Atithi
];

streamOutput("[SYSTEM]: Broadcasting Deployment Signal to Official Hostinger Motors...");

foreach ($webhooks as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, []); // Clean multipart POST ensures Hostinger listens
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $name = (strpos($url, '76b62f') !== false) ? 'Atithi' : 'Codepilotx';
    if ($http_code >= 200 && $http_code < 300) {
        streamOutput("[SUCCESS]: $name Deployment Signal Accepted ($http_code).");
    } else {
        streamOutput("[WARN]: $name Signal Failed ($http_code).");
    }
}

streamOutput("\n[SUCCESS]: CLOUD SNYCHRONIZATION SUCCESSFULLY COMPLETED.");
?>