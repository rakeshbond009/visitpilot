<?php
session_start();
// Security check: Ensure only Admin can trigger this
// if ($_SESSION['role'] !== 'admin') {
//     die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
// }

header('Content-Type: application/json');

$logFile = "../../build_log.txt";
$scriptPath = realpath("../../build-apk.ps1");
$projectRoot = realpath("../../");

// Clear old log
file_put_contents($logFile, "Build triggered at " . date('Y-m-d H:i:s') . "\n");

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Windows: Use start /B to run in background
    $cmd = "cd /d \"$projectRoot\" && powershell.exe -ExecutionPolicy Bypass -File \"$scriptPath\" >> \"$logFile\" 2>&1";
    
    // We use pclose(popen()) to trigger it asynchronously in Windows
    pclose(popen("start /B " . $cmd, "r"));
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Build started in background. You can monitor progress in build_log.txt',
        'log_path' => 'build_log.txt'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Build script currently only supports Windows-based XAMPP.']);
}
?>
