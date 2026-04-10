<?php
/**
 * Dahua DoLynk Cloud V2 Authentication Diagnostic Tool
 * This script tests the HMAC-SHA512 signature and token acquisition.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/db.php';
require_once 'includes/dahua_helper.php';

echo "<h1>Dahua DoLynk Cloud V2 Diagnostic</h1>";
echo "<p>Testing connection to Dahua Open API...</p>";

try {
    // 1. Check if Helper exists
    if (!class_exists('DahuaHelper')) {
        throw new Exception("DahuaHelper class not found in includes/dahua_helper.php");
    }

    echo "<li>DahuaHelper Class: <b>LOADED</b></li>";

    // 2. Attempt to get fresh Access Token
    // We pass true to force a fresh fetch and bypass the cache for this test
    $token = DahuaHelper::getAccessToken(true);

    if ($token) {
        echo "<li style='color: green;'>Authentication: <b>SUCCESSFUL</b></li>";
        echo "<li>AppAccessToken: <code style='background: #eee; padding: 2px 5px;'>$token</code></li>";
        echo "<p><b style='color: green;'>✓ Your App ID, Secret, and Signature logic are PERFECT.</b></p>";
    } else {
        throw new Exception("Authentication failed. Token is empty.");
    }

} catch (Exception $e) {
    echo "<li style='color: red;'>Diagnostic Result: <b>FAILED</b></li>";
    echo "<p><b>Error Message:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
    
    echo "<h3>Troubleshooting Steps:</h3>";
    echo "<ul>";
    echo "<li>Verify <b>dahua_app_id</b> and <b>dahua_app_secret</b> in System Settings.</li>";
    echo "<li>Ensure your server can make outbound HTTPS requests to <b>open-api-sg.dolynkcloud.com</b>.</li>";
    echo "<li>Check <b>dahua_debug.txt</b> in the root folder for the raw signature strings.</li>";
    echo "</ul>";
}

echo "<hr>";
echo "<a href='admin/settings.php?tab=dahua'>← Back to Dahua Settings</a>";
?>
