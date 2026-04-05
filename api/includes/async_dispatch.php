<?php
/**
 * Async Dispatcher — Dual-path for Hostinger (FastCGI) and Apache/LSAPI.
 *
 * HOSTINGER (PHP-FPM, fastcgi_finish_request available):
 *   dispatchBackgroundTask() → no-op (inline will handle it)
 *   sendInstantResponse()    → flushes + fastcgi_finish_request() + RETURNS
 *   runJobInline()           → runs the job in same process (client already gone)
 *
 * XAMPP / LiteSpeed LSAPI (fastcgi_finish_request NOT available):
 *   dispatchBackgroundTask() → writes job file + fires non-blocking cURL to background_worker.php
 *   sendInstantResponse()    → echo + exit
 *   runJobInline()           → no-op (already dispatched)
 *
 * CALLER ORDER in every API file:
 *   1. dispatchBackgroundTask(...)   ← schedules work for Apache path
 *   2. sendInstantResponse(...)      ← exits on Apache, returns on FastCGI
 *   3. runJobInline(...)             ← runs inline on FastCGI, no-op on Apache
 */

function sendInstantResponse($status, $message, $data = null, $code = 200)
{
    while (ob_get_level()) ob_end_clean();
    if ($code !== 200) http_response_code($code);

    $response = json_encode(['status' => $status, 'message' => $message, 'data' => $data]);

    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    echo $response;
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();           // Hostinger: flush to client, continue script
        ignore_user_abort(true);
        set_time_limit(120);
        return;                             // ← RETURNS so caller can do inline work
    }

    exit;                                   // Apache/LSAPI: exit (cURL worker handles the rest)
}

/**
 * For Apache/LSAPI: write job file + fire non-blocking cURL.
 * For FastCGI (Hostinger): no-op — work is done inline via runJobInline().
 */
function dispatchBackgroundTask($jobType, array $payload)
{
    if (function_exists('fastcgi_finish_request')) {
        return; // FastCGI: inline execution handles it (runJobInline called after send)
    }

    // Apache / LiteSpeed LSAPI path
    $jobDir = dirname(__DIR__, 2) . '/storage/jobs/';
    if (!is_dir($jobDir)) @mkdir($jobDir, 0777, true);

    $jobId   = uniqid('job_', true);
    $jobFile = $jobDir . $jobId . '.json';
    $payload['__job_type'] = $jobType;
    $payload['__job_id']   = $jobId;
    file_put_contents($jobFile, json_encode($payload));

    // Build worker URL from SCRIPT_NAME
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
        CURLOPT_COOKIE         => 'PHPSESSID=' . session_id() .
                                   '; vms_tenant=' . ($_COOKIE['vms_tenant'] ?? 'default'),
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Run a job inline — ONLY executes on FastCGI (Hostinger) after sendInstantResponse() returns.
 * On Apache/LSAPI, sendInstantResponse() already called exit(), so this is never reached.
 */
function runJobInline($jobType, array $payload, $pdo)
{
    if (!function_exists('fastcgi_finish_request')) return; // Safety guard

    require_once __DIR__ . '/bg_jobs.php';

    switch ($jobType) {
        case 'register_visitor': runJob_registerVisitor($pdo, $payload); break;
        case 'approve_visit':    runJob_approveVisit($pdo, $payload);    break;
        case 'reject_visit':     runJob_rejectVisit($pdo, $payload);     break;
        case 'cancel_invite':    runJob_cancelInvite($pdo, $payload);    break;
    }
}
