<?php
/**
 * Background Worker - Processes heavy async jobs for Apache/LSAPI path.
 *
 * Triggered by api/includes/async_dispatch.php via a non-blocking cURL POST.
 * Only used when fastcgi_finish_request is NOT available (XAMPP).
 *
 * Security: Only accepts requests from localhost (127.0.0.1 or ::1).
 */

// --- Security: Only localhost can call this ---
$caller_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($caller_ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'])) {
    http_response_code(403);
    die('Forbidden');
}

// Prevent this from timing out
ignore_user_abort(true);
set_time_limit(120);

// Immediately respond 200 OK so the dispatcher's cURL doesn't wait
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: 2');
header('Connection: close');
echo '{}';
if (ob_get_level()) ob_end_flush();
flush();

// --- Load core ---
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/bg_jobs.php';

// --- Read input ---
$input = json_decode(file_get_contents('php://input'), true);
$jobFile = $input['job_file'] ?? null;

if (!$jobFile || !file_exists($jobFile)) {
    error_log("[Worker] Job file not found: $jobFile");
    exit;
}

$payload = json_decode(file_get_contents($jobFile), true);
unlink($jobFile); // Clean up immediately

if (!$payload) {
    error_log("[Worker] Invalid payload in job file.");
    exit;
}

$jobType = $payload['__job_type'] ?? 'unknown';
error_log("[Worker] Processing job: $jobType (ID: " . ($payload['__job_id'] ?? '?') . ')');

// ===================================================================
// JOB HANDLERS (Delegated to shared bg_jobs.php)
// ===================================================================

switch ($jobType) {
    case 'register_visitor': runJob_registerVisitor($pdo, $payload); break;
    case 'approve_visit':    runJob_approveVisit($pdo, $payload);    break;
    case 'reject_visit':     runJob_rejectVisit($pdo, $payload);     break;
    case 'cancel_invite':    runJob_cancelInvite($pdo, $payload);    break;
    default:
        error_log("[Worker] Unknown job type: $jobType");
}

error_log("[Worker] Job complete: $jobType");
exit;
