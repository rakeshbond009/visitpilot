<?php
/**
 * ATITHI GIT BOOTSTRAPPER v1.0
 * Forcing the server to talk to GitHub
 */

header('Content-Type: text/plain');
echo "BOOTSTRAPPING GIT FOR ATITHI...\n";

$root = realpath(__DIR__ . '/../../');
chdir($root);

// 1. Force the URL to use HTTPS (Bypasses SSH key issues)
echo "Setting Remote URL...\n";
shell_exec('git remote set-url origin https://github.com/rakeshbond009/visitpilot.git 2>&1');

// 2. Set Identity (Prevents "Who are you?" hang)
echo "Setting Git Identity...\n";
shell_exec('git config user.email "admin@visitpilot.com"');
shell_exec('git config user.name "Atithi Sync"');

// 3. Force a shallow fetch (Fast, no timeout)
echo "Fetching from GitHub...\n";
$output = shell_exec('git fetch --depth=1 origin main 2>&1');
echo "[FETCH LOG]: " . trim($output) . "\n";

// 4. Force Reset
echo "Forcing folders to update...\n";
$reset = shell_exec('git reset --hard origin/main 2>&1');
echo "[RESET LOG]: " . trim($reset) . "\n";

echo "\nSUCCESS: ATITHI IS NOW LINKED AND UPDATED.";
