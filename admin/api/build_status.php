<?php
header('Content-Type: application/json');

$logFile = "../../build_log.txt";
$apkExists = false;
$status = "processing";
$lastLine = "";

if (file_exists($logFile)) {
    $lines = file($logFile);
    if (!empty($lines)) {
        $lastLine = trim(end($lines));
        
        if (strpos($lastLine, 'APK is ready') !== false) {
            $status = "complete";
            
            // Extract the filename if possible
            preg_match('/visitpilot-.*\.apk/', $lastLine, $matches);
            $apkName = $matches[0] ?? "visitpilot.apk";
        } elseif (strpos($lastLine, 'CRITICAL ERROR') !== false || strpos($lastLine, 'FAILED') !== false) {
            $status = "error";
        }
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
