<?php
// api/host/process_approval.php
require_once '../includes/api_header.php';
require_once '../includes/async_dispatch.php';

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
        'error'   => 'Invalid host profile',
        'message' => 'Your user account is not linked to an employee record. Please contact admin.'
    ]);
    exit;
}

$data     = json_decode(file_get_contents('php://input'), true);
$visit_id = $data['visit_id'] ?? null;
$action   = $data['action']   ?? null;
$reason   = $data['reason']   ?? 'No reason provided';

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

if ($action === 'approve') {
    $stmt = $pdo->prepare("UPDATE visits SET approval_status='approved', status='approved', approved_by=?, approved_at=NOW() WHERE id=?");
    $stmt->execute([$_SESSION['user_id'], $visit_id]);
    logAction($pdo, $_SESSION['user_id'], "Approved visit ID: $visit_id via Popup");

    // ⚡ STEP 1: Dispatch background job FIRST
    dispatchBackgroundTask('approve_visit', ['visit_id' => $visit_id]);

    // ⚡ STEP 2: Instant response (calls exit internally)
    sendInstantResponse('success', 'Visitor Approved');

} else {
    $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_by=?, approved_at=NOW(), rejection_reason=? WHERE id=?");
    $stmt->execute([$_SESSION['user_id'], $reason, $visit_id]);
    logAction($pdo, $_SESSION['user_id'], "Rejected visit ID: $visit_id via Popup");

    // ⚡ STEP 1: Dispatch background job FIRST
    dispatchBackgroundTask('reject_visit', [
        'visit_id' => $visit_id,
        'reason'   => $reason,
    ]);

    // ⚡ STEP 2: Instant response (calls exit internally)
    sendInstantResponse('success', 'Visitor Rejected');
}
