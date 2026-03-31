<?php
require_once '../../includes/db.php';

// Handle CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Basic Role Check
$is_admin = ($_SESSION['role'] === 'admin');
$is_security = ($_SESSION['role'] === 'security');
$is_host = ($_SESSION['role'] === 'host' || $_SESSION['role'] === 'employee');

if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_security && !$is_host)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Force Host Restriction
$limit_employee_id = null;
if ($is_host) {
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $limit_employee_id = $stmt->fetchColumn();
}

// Get last check timestamp from request
$last_check = isset($_GET['last_check']) ? $_GET['last_check'] : null;

$where = "WHERE DATE(v.approved_at) = CURDATE() AND v.approval_status IN ('approved', 'rejected')";
$params = [];

if ($limit_employee_id) {
    $where .= " AND v.employee_id = ?";
    $params[] = $limit_employee_id;
}

if ($last_check) {
    $where .= " AND v.approved_at > ?";
    $params[] = $last_check;
}

// User-Specific Notification: Notify only the creator
$where .= " AND v.created_by = ?";
$params[] = $_SESSION['user_id'];

$sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, emp.name as host_name, emp.department
FROM visits v
JOIN visitors vis ON v.visitor_id = vis.id
JOIN employees emp ON v.employee_id = emp.id
$where
ORDER BY v.approved_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'updates' => $updates,
    'timestamp' => date('Y-m-d H:i:s')
]);