<?php
/**
 * DIRECT UPLOAD BRIDGE v1.0
 * NO GIT REQUIRED - NO HOSTINGER SETUP REQUIRED
 * RECEIVES ZIP FROM LAPTOP AND UNPACKS IT
 */

// Security Token
$secret = 'vms_cloud_sync_2026';
if (($_POST['key'] ?? '') !== $secret) {
    header('HTTP/1.1 403 Forbidden');
    die("Access Denied.");
}

if (!isset($_FILES['bundle'])) {
    die("Error: No Bundle Received.");
}

$vms_root = realpath(__DIR__ . '/../../');
$temp_zip = $vms_root . '/update_bundle.zip';

// Receive the Zip
if (move_uploaded_file($_FILES['bundle']['tmp_name'], $temp_zip)) {
    $zip = new ZipArchive;
    if ($zip->open($temp_zip) === TRUE) {
        $zip->extractTo($vms_root);
        $zip->close();
        unlink($temp_zip);
        echo "SUCCESS: SYSTEM UPDATED MANUALLY.";
    } else {
        echo "ERROR: VERSION CORRUPTED DURING SHIPMENT.";
    }
} else {
    echo "ERROR: SHIPMENT FAILED.";
}
