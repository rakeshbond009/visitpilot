<?php
require_once "../../includes/db.php";
header('Content-Type: application/json');

$logFile = "../../build_log.txt";
$apkExists = false;
$status = "processing";
$lastLine = "";

if (file_exists($logFile)) {
    $content = file_get_contents($logFile);
    $lines = explode("\n", trim($content));
    $lastLine = !empty($lines) ? trim(end($lines)) : "";

    if (strpos($content, 'APK is ready at') !== false) {
        $status = "complete";
        preg_match('/visitpilot-.*\.apk/', $content, $matches);
        $apkName = $matches[0] ?? "visitpilot.apk";
    } elseif (strpos($content, 'CRITICAL ERROR') !== false || strpos($content, 'FAILED') !== false) {
        $status = "error";
    }
} else {
    $status = "idle";
}

echo json_encode([
    'status' => $status,
    'last_message' => $lastLine,
    'apk_name' => $apkName ?? null
]);
?>
