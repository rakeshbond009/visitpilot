<?php
/**
 * Async Dispatcher — Dual-path for Hostinger (FastCGI) and Apache/LSAPI.
 */

function _vms_log($msg) {
    $logFile = __DIR__ . '/../../storage/logs/async.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $timestamp = date('H:i:s');
    @file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
}

function sendInstantResponse($status, $message, $data = null, $code = 200)
{
    ignore_user_abort(true);
    set_time_limit(300);

    while (ob_get_level()) ob_end_clean();
    if ($code !== 200) http_response_code($code);

    $response = json_encode(['status' => $status, 'message' => $message, 'data' => $data]);

    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    echo $response;
    flush();

    if (function_exists('fastcgi_finish_request')) {
        _vms_log("Hostinger DETECTED. Detaching connection.");
        fastcgi_finish_request();
        return; 
    }

    _vms_log("Apache/LSAPI DETECTED. Exiting.");
    exit;
}

function dispatchBackgroundTask($jobType, array $payload)
{
    if (function_exists('fastcgi_finish_request')) return;

    $jobDir = __DIR__ . '/../../storage/jobs/';
    if (!is_dir($jobDir)) @mkdir($jobDir, 0777, true);

    $jobId   = uniqid('job_', true);
    $jobFile = $jobDir . $jobId . '.json';
    $payload['__job_type'] = $jobType;
    $payload['__job_id']   = $jobId;
    file_put_contents($jobFile, json_encode($payload));

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/visitor/register.php';
    preg_match('#^(.*?/api)(?:/|$)#', $scriptName, $m);
    $apiBase    = $m[1] ?? '/api';
    $workerUrl  = 'http://127.0.0.1' . $apiBase . '/background_worker.php';

    $ch = curl_init($workerUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['job_file' => $jobFile]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                                   'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')],
        CURLOPT_TIMEOUT_MS     => 500,
        CURLOPT_NOSIGNAL       => 1,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function runJobInline($jobType, array $payload, $pdo)
{
    if (!function_exists('fastcgi_finish_request')) return;

    _vms_log("Inline Job Process: $jobType STARTED");
    require_once __DIR__ . '/bg_jobs.php';

    try {
        // Re-check PDO connection just in case FastCGI finishing closed it
        try {
            $pdo->query("SELECT 1");
        } catch (Exception $e) {
            _vms_log("PDO connection lost. Reconnecting.");
            require __DIR__ . '/../../includes/db.php'; // Re-runs db.php to recreate $pdo
        }

        switch ($jobType) {
            case 'register_visitor': runJob_registerVisitor($pdo, $payload); break;
            case 'approve_visit':    runJob_approveVisit($pdo, $payload);    break;
            case 'reject_visit':     runJob_rejectVisit($pdo, $payload);     break;
            case 'cancel_invite':    runJob_cancelInvite($pdo, $payload);    break;
        }
        _vms_log("Inline Job Process: $jobType COMPLETED");
    } catch (Throwable $e) {
        _vms_log("FATAL in inline job $jobType: " . $e->getMessage());
    }
}
