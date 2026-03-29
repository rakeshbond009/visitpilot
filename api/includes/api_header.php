<?php
// api/includes/api_header.php

// Allow from any origin
// Allow from any origin (Dev only - with Credentials support)
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_X_FORWARDED_ORIGIN'] ?? null;

if ($origin) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
}
else {
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

// Auth session handling
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

function getPostData()
{
    return json_decode(file_get_contents('php://input'), true);
}
