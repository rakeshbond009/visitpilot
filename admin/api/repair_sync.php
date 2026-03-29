<?php
require_once '../../includes/db.php';
requireLogin();

// 1. Enforce Super Admin only
if ($_SESSION['role'] !== 'admin') {
    die("Unauthorized Access.");
}

// 2. Git Operations - Reset to match Remote (GitHub)
// Change directory to the VMS root
$vms_root = realpath(__DIR__ . '/../../');
chdir($vms_root);

echo "<h3>Initializing System Repair...</h3>";
echo "Performing Git Fetch from origin...<br>";
$output = shell_exec('git fetch origin 2>&1');
echo "<pre>$output</pre>";

echo "Resetting local branch to match 'origin/main'...<br>";
echo "<i>(This will discard any local uncommitted changes)</i><br>";
$output = shell_exec('git reset --hard origin/main 2>&1');
echo "<pre>$output</pre>";

echo "<hr>";
echo "<h4 style='color: green;'>Repair Sequence Completed.</h4>";
echo "<p>Your local codebase now exactly matches the main branch on GitHub.</p>";
echo "<a href='../cloud_deployment.php' style='padding: 10px 20px; background: #000; color: #fff; text-decoration: none; border-radius: 5px;'>Return to Deployment</a>";
?>
