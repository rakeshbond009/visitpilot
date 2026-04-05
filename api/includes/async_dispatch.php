<?php
/**
 * Async Dispatcher — Works on Hostinger (LiteSpeed/PHP-FPM), Apache/mod_php, and everything in between.
 *
 * THE CRITICAL RULE for all callers:
 *   ✅ ALWAYS call dispatchBackgroundTask() BEFORE sendInstantResponse()
 *      sendInstantResponse() calls exit() — anything after it never runs.
 *
 * How it works:
 *   1. Caller commits DB transaction.
 *   2. Caller calls dispatchBackgroundTask() — writes a job file, fires a
 *      non-blocking cURL POST to background_worker.php via 127.0.0.1 (300ms timeout).
 *   3. Caller calls sendInstantResponse() — sends JSON to client, then exits.
 *   4. background_worker.php processes the job independently:
 *      QR fetch, Dahua sync, FCM push, WhatsApp — all outside the request lifecycle.
 *
 * WHY 127.0.0.1 for cURL:
 *   - No DNS round-trip → fast
 *   - No SSL overhead → fast
 *   - REMOTE_ADDR = 127.0.0.1 in the worker on BOTH Apache and LiteSpeed/Hostinger
 *     (the IP security check in background_worker.php always passes)
 *   - Host header tells LiteSpeed/nginx which virtual host to route to
 */

/**
 * Send JSON response to the client and exit immediately.
 * Call this AFTER dispatchBackgroundTask().
 */
function sendInstantResponse($status, $message, $data = null, $code = 200)
{
    // Kill any buffered output so JSON is clean
    while (ob_get_level()) {
        ob_end_clean();
    }

    if ($code !== 200) http_response_code($code);

    $response = json_encode([
        'status'  => $status,
        'message' => $message,
        'data'    => $data
    ]);

    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    echo $response;
    flush();

    // FastCGI finish (PHP-FPM): tells the FPM master to flush to client
    // On LiteSpeed LSAPI this is not available — the exit() below handles it
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    // Always exit. Background work is already dispatched via dispatchBackgroundTask().
    exit;
}

/**
 * Schedule a background job.
 *
 * Writes job data to a temp file, then fires a non-blocking cURL POST
 * to background_worker.php using http://127.0.0.1 — ensuring:
 *   - REMOTE_ADDR = 127.0.0.1 in the worker (security check passes)
 *   - No DNS/SSL overhead
 *   - Works on XAMPP, Hostinger LiteSpeed, and PHP-FPM alike
 *
 * MUST be called BEFORE sendInstantResponse().
 *
 * @param string $jobType  e.g. 'register_visitor', 'approve_visit', 'reject_visit'
 * @param array  $payload  Data the worker needs
 */
function dispatchBackgroundTask($jobType, array $payload)
{
    $jobDir = _getJobsDir();
    if (!is_dir($jobDir)) {
        @mkdir($jobDir, 0777, true);
    }

    $jobId   = uniqid('job_', true);
    $jobFile = $jobDir . $jobId . '.json';

    $payload['__job_type'] = $jobType;
    $payload['__job_id']   = $jobId;

    if (file_put_contents($jobFile, json_encode($payload)) === false) {
        error_log("[AsyncDispatch] FAILED to write job file: $jobFile");
        return;
    }

    $workerUrl = _buildWorkerUrl();

    $ch = curl_init($workerUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['job_file' => $jobFile]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            // Tells LiteSpeed/nginx which virtual host to use when connecting via 127.0.0.1
            'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        ],
        CURLOPT_TIMEOUT_MS     => 300,   // Fire-and-forget: 300ms to open socket, don't wait for response
        CURLOPT_NOSIGNAL       => 1,     // Required for ms-level timeouts on Linux (Hostinger)
        CURLOPT_SSL_VERIFYPEER => false, // Internal HTTP call — no SSL needed
        CURLOPT_SSL_VERIFYHOST => false,
        // Pass session cookies so the worker can identify tenant + authenticate
        CURLOPT_COOKIE         => 'PHPSESSID=' . session_id() .
                                   '; vms_tenant=' . ($_COOKIE['vms_tenant'] ?? 'default'),
    ]);

    curl_exec($ch);
    curl_close($ch);

    error_log("[AsyncDispatch] Job dispatched: $jobType (ID: $jobId) → $workerUrl");
}

/**
 * Returns the absolute filesystem path to the jobs temp directory.
 * __FILE__ = api/includes/async_dispatch.php → go up 2 = project root
 */
function _getJobsDir()
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR;
}

/**
 * Builds the URL for background_worker.php.
 *
 * Always uses http://127.0.0.1 so the connection stays local.
 * The /api/ path is auto-detected from SCRIPT_NAME:
 *   XAMPP:     /visitpilot/api/background_worker.php
 *   Hostinger: /api/background_worker.php (visitpilot is document root)
 */
function _buildWorkerUrl()
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/visitor/register.php';

    // Extract path up to and including '/api'
    // e.g.  /visitpilot/api/visitor/register.php  →  /visitpilot/api
    //        /api/visitor/register.php             →  /api
    if (preg_match('#^(.*?/api)(?:/|$)#', $scriptName, $m)) {
        $apiBase = $m[1];
    } else {
        $apiBase = dirname(dirname($scriptName));
    }

    // Always use port 80 for the internal HTTP call regardless of the public port (80/443)
    return 'http://127.0.0.1' . $apiBase . '/background_worker.php';
}
