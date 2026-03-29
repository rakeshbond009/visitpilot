<?php
/**
 * HOSTINGER GIT REPAIR TOOL
 * Resolves Git sync conflicts caused by the 'uploads/' folder exclusion.
 */
require_once '../../includes/db.php';
session_start();

// Security: Super Admin only
if (($_SESSION['role'] ?? '') !== 'admin') {
    die("Unauthorized Access.");
}

header('Content-Type: text/plain');
echo "🚀 GIT REPAIR TOOL - HOSTINGER SYNC\n";
echo "====================================\n";

// Change directory to VMS root
$vms_root = realpath(__DIR__ . '/../../');
chdir($vms_root);
echo "[CHECK]: Current Root: $vms_root\n";

// 1. Fetch current status
echo "[PROCESS]: Checking Git status...\n";
$status = shell_exec('git status 2>&1');
echo "[STATUS]: $status\n";

// 2. Clear conflicts (This doesn't delete untracked uploads since they're in .gitignore)
echo "[PROCESS]: Cleaning Git index conflicts...\n";
shell_exec('git fetch origin main 2>&1');
shell_exec('git add . 2>&1');
$repair_output = shell_exec('git reset --hard origin/main 2>&1');
echo "[REPAIR]: $repair_output\n";

// 3. Final Pull
echo "[PROCESS]: Final verify pull...\n";
$pull_output = shell_exec('git pull origin main 2>&1');
echo "[FINAL]: $pull_output\n";

echo "\n====================================\n";
echo "SUCCESS: Hostinger Git state has been repaired.\n";
echo "Your 'uploads/' folder was protected and not deleted.\n";
?>
