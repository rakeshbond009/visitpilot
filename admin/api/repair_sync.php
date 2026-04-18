<?php
/**
 * BRUTE FORCE REPAIR BRIDGE v2.0
 * NO DEPENDENCIES - NO LOGIN REQUIRED
 * FORCES LOCAL FILES TO MATCH GITHUB
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Security Check: Simple secret key (matches what we send from dashboard)
$secret = 'vms_cloud_sync_2026';
if (($_GET['key'] ?? '') !== $secret) {
    die("Access Denied: Invalid Sync Token.");
}

$vms_root = realpath(__DIR__ . '/../../');
chdir($vms_root);

header('Content-Type: text/plain');
echo "Starting Force-Fulfillment Sequence...\n";
echo "Active Directory: $vms_root\n";

function run($cmd) {
    echo "> $cmd\n";
    $output = shell_exec($cmd . ' 2>&1');
    echo $output . "\n";
}

// Ensure Git is Initialized
if (!is_dir('.git')) {
    run('git init');
    run('git remote add origin https://github.com/rakeshbond009/visitpilot.git');
} else {
    run('git remote set-url origin https://github.com/rakeshbond009/visitpilot.git');
}

// FORCE OVERWRITE
run('git fetch --all');
run('git reset --hard origin/main');
run('git clean -fd');

echo "\n--- FOLDERS UPDATED SUCCESSFULLY ---";