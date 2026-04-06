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

if ($action == 'approve' || $action == 'reject') {
    $status = ($action == 'approve') ? 'approved' : 'rejected';
    
    $stmt = $pdo->prepare("UPDATE visits SET approval_status=?, status=?, approved_by=?, approved_at=?, rejection_reason=? WHERE id=?");
    $stmt->execute([$status, $status, $_SESSION['user_id'], current_datetime(), ($action == 'reject' ? $reason : null), $visit_id]);
    
    logAction($pdo, $_SESSION['user_id'], ($action == 'approve' ? "Approved" : "Rejected") . " visit ID: $visit_id via Popup");

    // Unified Background Job (Handles PDF, WhatsApp, FCM to creator)
    require_once '../../api/includes/async_dispatch.php';
    require_once '../../api/includes/bg_jobs.php';
    
    $bgPayload = [
        'visit_id' => $visit_id,
        'reason' => $reason
    ];

    // Response FIRST for snappy UI
    echo json_encode(['success' => true, 'message' => "Visitor " . ucfirst($status)]);
    
    // Execute logic in background (FCM, WhatsApp, PDF)
    if ($action == 'approve') {
        runJobInline('approve_visit', $bgPayload, $pdo);
    } else {
        runJobInline('reject_visit', $bgPayload, $pdo);
    }
    exit;
}
