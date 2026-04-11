<?php
// Quick diagnostic: show which version of dahua_helper is loaded and PHP errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== HOSTED DIAGNOSTIC ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";

$helperPath = dirname(dirname(__DIR__)) . '/includes/dahua_helper.php';
echo "Helper path: $helperPath\n";
echo "Helper exists: " . (file_exists($helperPath) ? 'YES' : 'NO') . "\n";

if (file_exists($helperPath)) {
    $content = file_get_contents($helperPath);
    // Check which version is running
    if (strpos($content, 'photoData') !== false) {
        echo "Version: NEW (3-step with photoData array) ✅\n";
    } elseif (strpos($content, 'Atomic V2 Payload') !== false) {
        echo "Version: OLD (Atomic) ❌ - needs redeploy\n";
    } else {
        echo "Version: UNKNOWN\n";
    }
    $lines = substr_count($content, "\n");
    echo "Helper total lines: $lines\n";
}

// Check compressed dir
$compressDir = dirname(dirname(__DIR__)) . '/uploads/dahua_compressed/';
echo "\nCompressed dir exists: " . (is_dir($compressDir) ? 'YES' : 'NO') . "\n";
echo "Compressed dir writable: " . (is_writable($compressDir) ? 'YES' : 'NO') . "\n";

// Check for visit 432
$db = require dirname(dirname(__DIR__)) . '/includes/db.php';
$stmt = $db->prepare("SELECT id, visitor_name, photo_path, visit_code FROM visits WHERE id = ?");
$stmt->execute([432]);
$visit = $stmt->fetch(PDO::FETCH_ASSOC);
echo "\nVisit 432: " . ($visit ? json_encode($visit) : 'NOT FOUND') . "\n";

if ($visit) {
    $photoPath = dirname(dirname(__DIR__)) . '/' . ltrim($visit['photo_path'], './');
    echo "Photo path: $photoPath\n";
    echo "Photo exists: " . (file_exists($photoPath) ? 'YES (' . filesize($photoPath) . ' bytes)' : 'NO') . "\n";

    $compressedPath = $compressDir . '432.jpg';
    echo "Compressed exists: " . (file_exists($compressedPath) ? 'YES (' . filesize($compressedPath) . ' bytes)' : 'NO') . "\n";
}
?>
