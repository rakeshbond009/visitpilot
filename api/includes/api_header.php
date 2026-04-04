<?php
// api/includes/api_header.php
ob_start();

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

$GLOBAL_POST_DATA = null;

function getPostData()
{
    global $GLOBAL_POST_DATA;
    if ($GLOBAL_POST_DATA === null) {
        $input = file_get_contents('php://input');
        $GLOBAL_POST_DATA = json_decode($input, true) ?: [];
    }
    return $GLOBAL_POST_DATA;
}

require_once __DIR__ . '/db_api.php';

// Auth session handling (Restore session and load permissions if needed)
handlePersistentLogin();

$user_id = $_SESSION['user_id'] ?? null;
$role = $_SESSION['role'] ?? null;
$employee_id = $_SESSION['employee_id'] ?? null;

// Mobile app fallback: If session is lost, try to get user_id from request body
if (!$user_id) {
    $postData = getPostData();
    if (isset($postData['user_id'])) {
        $user_id = $postData['user_id'];
        // Recover role/employee_id from DB if missing from session
        if ($pdo) {
            try {
                $uStmt = $pdo->prepare("SELECT role, employee_id FROM users WHERE id = ?");
                $uStmt->execute([$user_id]);
                $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
                if ($uData) {
                    $role = $uData['role'];
                    $employee_id = $uData['employee_id'];
                    // Restore to session for the rest of this request
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['role'] = $role;
                    $_SESSION['employee_id'] = $employee_id;
                }
            } catch (Exception $e) {
            }
        }
    }
}

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
