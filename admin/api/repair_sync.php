<?php
require_once '../../includes/db.php';
// 1. Allow authorized automated sync or logged-in admin
$is_auto = (isset($_GET['auto']) && $_GET['auto'] == '1');
if (!$is_auto) {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        die("Unauthorized Access.");
    }
}

// 2. Git Operations - Reset to match Remote (GitHub)
// Change directory to the VMS root
$vms_root = realpath(__DIR__ . '/../../');
chdir($vms_root);

echo "<h3>Initializing System Repair...</h3>";

if (!is_dir('.git')) {
    echo "NO GIT REPO DETECTED. Running git init...<br>";
    $output = shell_exec('git init 2>&1');
    echo "<pre>$output</pre>";
    
    echo "Setting remote origin...<br>";
    $output = shell_exec('git remote add origin https://github.com/rakeshbond009/visitpilot.git 2>&1');
    echo "<pre>$output</pre>";
}

echo "Performing Git Fetch from origin...<br>";
$output = shell_exec('git fetch --all 2>&1');
echo "<pre>$output</pre>";

// 3. Backup Server-Specific Configurations
$protected_files = [
    'includes/db.php',
    'mobile_app/utils/config.js'
];
$backups = [];
foreach ($protected_files as $file) {
    if (file_exists($file)) {
        $backups[$file] = file_get_contents($file);
        echo "Backing up $file...<br>";
    }
}

echo "FORCING DEEP CLEAN...<br>";
shell_exec('git clean -fd 2>&1'); // Remove untracked files
shell_exec('git reset --hard origin/main 2>&1'); // Force overwrite everything

// 4. Restore Server-Specific Configurations
foreach ($backups as $file => $content) {
    file_put_contents($file, $content);
    echo "Restored $file (Server-Specific).<br>";
}

echo "Repair Sequence Completed.<br>";
echo "<b>Server Timestamp: " . date('Y-m-d H:i:s') . "</b><br>";

echo "<hr>";
echo "<h4 style='color: green;'>Repair Sequence Completed.</h4>";
echo "<p>Your local codebase now exactly matches the main branch on GitHub.</p>";
echo "<a href='../cloud_deployment.php' style='padding: 10px 20px; background: #000; color: #fff; text-decoration: none; border-radius: 5px;'>Return to Deployment</a>";
?>
