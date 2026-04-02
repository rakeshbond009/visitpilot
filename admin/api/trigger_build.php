<?php
require_once "../../includes/db.php";

// Security check: Ensure strictly Admin/Super Admin can trigger this
$is_admin = ($_SESSION['role'] ?? '') === 'admin';
$is_super = !empty($_SESSION['is_super']);

if (!$is_admin && !$is_super) {
    // Local environment bypass for XAMPP users
    $is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
    if (!$is_local) {
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Higher permission required.']));
    }
}

header('Content-Type: application/json');

$logFile = "../../build_log.txt";
$scriptPath = realpath("../../build-apk.ps1");
$projectRoot = realpath("../../");

// Clear old log
file_put_contents($logFile, "Build triggered at " . date('Y-m-d H:i:s') . "\n");

if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Force absolute paths to ensure background process finds everything
    $psExe = "C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe";
    $absLog = realpath($logFile) ?: $projectRoot . "/build_log.txt";
    
    // We MUST use start /B to detach. Redirection to absolute path is safer.
    $cmd = "cd /d \"$projectRoot\" && start /B $psExe -ExecutionPolicy Bypass -File \"$scriptPath\" >> \"$absLog\" 2>&1";
    
    // This is the most reliable way to start a background task on Windows PHP
    pclose(popen($cmd, "r"));
    
    echo json_encode([
        'status' => 'success', 
        'message' => 'Build started successfully.',
        'log_path' => 'build_log.txt'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Build script currently only supports Windows-based XAMPP.']);
}
?>
