<?php
// api/includes/api_header.php

// Allow from any origin
// Allow from any origin (Dev only - with Credentials support)
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_X_FORWARDED_ORIGIN'] ?? null;

if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
} else {
    header("Access-Control-Allow-Origin: *");
}
header('Access-Control-Max-Age: 86400'); // cache for 1 day
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID, X-Tenant-Key");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: " . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
    exit(0);
}



header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_api.php';

// Auth session handling (Restore session and load permissions if needed)
handlePersistentLogin();

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;
$employee_id = $_SESSION['employee_id'] ?? null;

function sendResponse($status, $message, $data = null, $code = 200)
{
    if ($code !== 200) {
        http_response_code($code);
    }
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Sends a response to the client and continues script execution in the background
 */
function sendBackgroundResponse($status, $message, $data = null, $code = 200)
{
    // Clear any previous output
    if (ob_get_level()) ob_end_clean();

    if ($code !== 200) {
        http_response_code($code);
    }

    $response = json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data
    ]);

    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    header('Content-Length: ' . strlen($response));

    echo $response;

    // Flush all output to the browser
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_start();
        echo $response;
        ob_end_flush();
        flush();
    }

    // Continue execution in background
    ignore_user_abort(true);
    set_time_limit(300); // 5 minutes for background tasks
}

function getPostData()
{
    return json_decode(file_get_contents('php://input'), true);
}
