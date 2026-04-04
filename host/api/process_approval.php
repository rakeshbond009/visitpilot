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

    // --- RESPOND TO CLIENT FIRST TO DECOUPLE HEAVY BACKGROUND TASKS ---
    sendAsyncResponse(['success' => true, 'message' => 'Visitor Approved']);

    // Send Notification to Security
    $stmt = $pdo->prepare("SELECT v.name FROM visitors v JOIN visits vs ON v.id = vs.visitor_id WHERE vs.id = ?");
    $stmt->execute([$visit_id]);
    $visitor_name = $stmt->fetchColumn();

    sendPushNotificationToRole($pdo, 'security', "Visitor Approved", "Visitor $visitor_name has been approved by the host.", ['visit_id' => $visit_id, 'type' => 'approval_status']);

    exit;
}
else {
    $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_by=?, approved_at=?, rejection_reason=? WHERE id=?");
    $stmt->execute([$_SESSION['user_id'], current_datetime(), $reason, $visit_id]);
    logAction($pdo, $_SESSION['user_id'], "Rejected visit ID: $visit_id via Popup");

    // --- RESPOND TO CLIENT FIRST TO DECOUPLE HEAVY BACKGROUND TASKS ---
    sendAsyncResponse(['success' => true, 'message' => 'Visitor Rejected']);

    // Send Notification to Security
    $stmt = $pdo->prepare("SELECT v.name FROM visitors v JOIN visits vs ON v.id = vs.visitor_id WHERE vs.id = ?");
    $stmt->execute([$visit_id]);
    $visitor_name = $stmt->fetchColumn();

    sendPushNotificationToRole($pdo, 'security', "Visitor Rejected", "Visitor $visitor_name has been rejected by the host.", ['visit_id' => $visit_id, 'type' => 'approval_status']);

    exit;
}
