<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

// --- CORS & Headers ---
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
}
else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Check if user is logged in and has appropriate role (host or employee)
$allowed_roles = ['host', 'employee', 'admin'];

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get host's employee ID
$stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$host_employee_id = $stmt->fetchColumn();
$is_admin = ($_SESSION['role'] === 'admin');

// Only block non-admins if they don't have an employee profile
if (!$is_admin && !$host_employee_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid host profile',
        'message' => 'Your user account is not linked to an employee record. Please contact admin.'
    ]);
    exit;
}

// Get last check timestamp from request (optional)
$last_check = isset($_GET['last_check']) ? $_GET['last_check'] : null;

// Get pending approvals count
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE approval_status = 'pending'");
    $stmt->execute();
}
else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND approval_status = 'pending'");
    $stmt->execute([$host_employee_id]);
}
$pending_count = $stmt->fetchColumn();

// Get today's visitors count
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
}
else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$host_employee_id]);
}
$today_count = $stmt->fetchColumn();

// Get new visits since last check
$new_visits = [];
if ($last_check) {
    $sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, vis.email
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " 
            AND (
                (v.approval_status = 'pending' AND v.created_at >= ?)
                OR 
                (v.is_invited = 1 AND v.status = 'checked_in' AND v.check_in_time >= ?)
            )
            ORDER BY v.created_at DESC";
    $stmt = $pdo->prepare($sql);
    if ($is_admin) {
        $stmt->execute([$last_check, $last_check]);
    }
    else {
        $stmt->execute([$host_employee_id, $last_check, $last_check]);
    }
    $new_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
else {
    // First load - get all pending visits + invited arrivals today
    $sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, vis.email
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " 
            AND (
                v.approval_status = 'pending' 
                OR (v.is_invited = 1 AND v.status = 'checked_in' AND DATE(v.check_in_time) = CURDATE())
            )
            ORDER BY v.created_at DESC";
    $stmt = $pdo->prepare($sql);
    if ($is_admin) {
        $stmt->execute();
    }
    else {
        $stmt->execute([$host_employee_id]);
    }
    $new_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent visitors for today (all statuses)
$sql_recent = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path
               FROM visits v 
               JOIN visitors vis ON v.visitor_id = vis.id 
               WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND DATE(v.created_at) = CURDATE()
               ORDER BY v.created_at DESC LIMIT 5";
$stmt = $pdo->prepare($sql_recent);
if ($is_admin) {
    $stmt->execute();
}
else {
    $stmt->execute([$host_employee_id]);
}
$recent_visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'pending_count' => (int)$pending_count,
    'today_count' => (int)$today_count,
    'new_visits' => $new_visits,
    'has_new' => count($new_visits) > 0,
    'recent_visitors' => $recent_visitors,
    'timestamp' => date('Y-m-d H:i:s')
]);
