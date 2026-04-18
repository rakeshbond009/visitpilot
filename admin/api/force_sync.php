<?php
/**
 * FORCE SYNC BRIDGE v1.0 - NO GIT REQUIRED
 * DOWNLOADS PROJECT ZIP AND UNPACKS IT
 */

// Deployment URL from GitHub
$repo_zip = 'https://github.com/rakeshbond009/visitpilot/archive/refs/heads/main.zip';
$target_dir = realpath(__DIR__ . '/../../');

header('Content-Type: text/plain');
echo "STARTING FORCE FOLDERS UPDATE...\n";

// 1. Download
echo "Downloading latest code from GitHub...\n";
$zip_data = file_get_contents($repo_zip);
if (!$zip_data) die("ERROR: Cannot reach GitHub zip.");

$temp_zip = $target_dir . '/master_update.zip';
file_put_contents($temp_zip, $zip_data);

// 2. Unpack
echo "Unpacking files...\n";
$zip = new ZipArchive;
if ($zip->open($temp_zip) === TRUE) {
    // Extract to temp folder first to handle the "visitpilot-main" container
    $extract_to = $target_dir . '/tmp_extract';
    if (!is_dir($extract_to)) mkdir($extract_to);
    
    $zip->extractTo($extract_to);
    $zip->close();
    
    // Move files from visitpilot-main to root
    $inner_dir = $extract_to . '/visitpilot-main';
    if (is_dir($inner_dir)) {
        $files = scandir($inner_dir);
        foreach ($files as $file) {
            if ($file == '.' || $file == '..') continue;
            rename($inner_dir . '/' . $file, $target_dir . '/' . $file);
        }
    }
    
    // Cleanup
    unlink($temp_zip);
    // Recursively delete tmp_extract (simplified here)
    echo "SUCCESS: ALL FOLDERS UPDATED TO MATCH GITHUB.\n";
} else {
    echo "ERROR: Zip file corrupted.\n";
}
