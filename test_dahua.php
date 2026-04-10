<?php
// Bulletproof Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h2>Dahua Debug Mode Active</h2>";

// Check file existence
$files = [
    'includes/db.php',
    'includes/dahua_helper.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        echo "✓ Found: $file<br>";
    } else {
        die("<b style='color:red;'>FATAL ERROR: $file is missing from the directory!</b>");
    }
}

try {
    require_once 'includes/db.php';
    echo "✓ db.php loaded<br>";
    
    // Check if PDO is actually connected
    if (!isset($pdo)) {
        echo "⚠ Warning: \$pdo object not found after loading db.php. Checking global...<br>";
        global $pdo;
    }

    require_once 'includes/dahua_helper.php';
    echo "✓ dahua_helper.php loaded<br>";

    if (!class_exists('DahuaHelper')) {
        die("<b style='color:red;'>FATAL ERROR: Class DahuaHelper not defined in includes/dahua_helper.php</b>");
    }

    echo "<h3>Attempting live Dahua handshake...</h3>";
    
    // We try to get a token and print EVERY step
    $token = DahuaHelper::getAccessToken($pdo, true);

    if ($token) {
        echo "<h3 style='color:green;'>SUCCESS! Dahua API is Connected.</h3>";
        echo "Token: <code>$token</code>";
    } else {
        echo "<h3 style='color:red;'>FAILED: No token received.</h3>";
        echo "Check <b>dahua_debug.txt</b> in the root folder.";
    }

} catch (Throwable $t) {
    echo "<h3 style='color:red;'>CRITICAL SCRIPT ERROR:</h3>";
    echo "<pre>" . $t->getMessage() . "\n" . $t->getTraceAsString() . "</pre>";
}
?>
