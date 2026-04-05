<?php
/**
 * Async Dispatcher — Stripped down, fully inline implementation.
 * Flushes to the client immediately, then processes tasks inline.
 * Completely bypasses broken background worker queues.
 */

function _vms_log($msg) {
    // Add logging back if needed, currently disabled for performance
}

function sendInstantResponse($status, $message, $data = null, $code = 200)
{
    ignore_user_abort(true);
    set_time_limit(300);

    while (ob_get_level()) ob_end_clean();
    if ($code !== 200) http_response_code($code);

    $responseBody = json_encode(['status' => $status, 'message' => $message, 'data' => $data]);

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($responseBody)); // CRITICAL for forcing disconnect on Apache
    header('Connection: close');
    echo $responseBody;
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function dispatchBackgroundTask($jobType, array $payload)
{
    // NO-OP: We no longer write to storage/jobs/ or fire cURL.
    // The background implementation happens in runJobInline safely after response.
}

function runJobInline($jobType, array $payload, $pdo)
{
    require_once __DIR__ . '/bg_jobs.php';

    try {
        switch ($jobType) {
            case 'register_visitor': runJob_registerVisitor($pdo, $payload); break;
            case 'approve_visit':    runJob_approveVisit($pdo, $payload);    break;
            case 'reject_visit':     runJob_rejectVisit($pdo, $payload);     break;
            case 'cancel_invite':    runJob_cancelInvite($pdo, $payload);    break;
        }
    } catch (Throwable $e) {}
}

