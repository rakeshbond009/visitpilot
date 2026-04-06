<?php
require_once '../../includes/db.php';
require_once '../../includes/push_helper.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['host', 'employee', 'admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get host's employee ID
$stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$host_employee_id = $stmt->fetchColumn();

if (!$host_employee_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid host profile',
        'message' => 'Your user account is not linked to an employee record. Please contact admin.'
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$visit_id = $data['visit_id'] ?? null;
$action = $data['action'] ?? null;
$reason = $data['reason'] ?? 'No reason provided';

if (!$visit_id || !in_array($action, ['approve', 'reject'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify this visit belongs to this host and is pending
$stmt = $pdo->prepare("SELECT id FROM visits WHERE id = ? AND employee_id = ? AND approval_status = 'pending'");
$stmt->execute([$visit_id, $host_employee_id]);
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Visit not found or already processed']);
    exit;
}

if ($action == 'approve') {
    $stmt = $pdo->prepare("UPDATE visits SET approval_status='approved', status='approved', approved_by=?, approved_at=? WHERE id=?");
    $stmt->execute([$_SESSION['user_id'], current_datetime(), $visit_id]);
    logAction($pdo, $_SESSION['user_id'], "Approved visit ID: $visit_id via Popup");

    // Send Targeted Notification to Creator
    $stmt = $pdo->prepare("SELECT created_by, (SELECT name FROM visitors WHERE id = vs.visitor_id) as visitor_name FROM visits vs WHERE vs.id = ?");
    $stmt->execute([$visit_id]);
    $visit_data = $stmt->fetch();
    $visitor_name = $visit_data['visitor_name'] ?? 'Visitor';
    
    if ($visit_data['created_by']) {
        sendPushToUser($pdo, $visit_data['created_by'], 
            ($action == 'approve' ? "Visitor Approved" : "Visitor Rejected"), 
            "Visitor $visitor_name has been " . ($action == 'approve' ? "approved" : "rejected") . " by the host.", 
            ['visit_id' => $visit_id, 'type' => ($action == 'approve' ? 'visit_approved' : 'visit_rejected')]
        );
    }

    echo json_encode(['success' => true, 'message' => ($action == 'approve' ? 'Visitor Approved' : 'Visitor Rejected')]);
}
