<?php
require_once '../../includes/db.php';
require_once '../../includes/push_helper.php';
require_once '../../includes/dahua_helper.php';
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
    $stmt = $pdo->prepare("UPDATE visits SET approval_status='approved', status='approved', approved_by=?, approved_at=NOW() WHERE id=?");
    $stmt->execute([$_SESSION['user_id'], $visit_id]);
    logAction($pdo, $_SESSION['user_id'], "Approved visit ID: $visit_id via Popup");

    // Send Notification to Security
    $stmt = $pdo->prepare("SELECT v.name FROM visitors v JOIN visits vs ON v.id = vs.visitor_id WHERE vs.id = ?");
    $stmt->execute([$visit_id]);
    $visitor_name = $stmt->fetchColumn();

    sendPushNotificationToRole($pdo, 'security', "Visitor Approved", "Visitor $visitor_name has been approved by the host.", ['visit_id' => $visit_id, 'type' => 'approval_status']);

    // --- DAHUA INTEGRATION ---
    try {
        $raw_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

        if (!empty($raw_settings['dahua_app_id']) && !empty($raw_settings['dahua_app_secret'])) {
            // Fetch full visit/visitor details for sync
            $vStmt = $pdo->prepare("SELECT v.id as visitor_id, v.name, vs.visit_photo, vs.visit_code, vs.created_at 
                                   FROM visitors v 
                                   JOIN visits vs ON v.id = vs.visitor_id 
                                   WHERE vs.id = ?");
            $vStmt->execute([$visit_id]);
            $v = $vStmt->fetch(PDO::FETCH_ASSOC);

            if ($v) {
                $dahua = new DahuaHelper($raw_settings['dahua_app_id'], $raw_settings['dahua_app_secret']);

                $startTime = $v['created_at'];
                // Set expiry to 12 hours after creation or end of day
                $endTime = date('Y-m-d 23:59:59', strtotime($startTime));

                $deviceSns = explode(',', $raw_settings['dahua_device_sns']);
                $deviceSns = array_map('trim', $deviceSns);

                $visitorData = [
                    'visitor_id' => $v['visitor_id'],
                    'name' => $v['name'],
                    'face_path' => realpath('../../' . $v['visit_photo']),
                    'qr_code' => $v['visit_code'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'device_sns' => $deviceSns
                ];

                $syncResult = $dahua->syncVisitor($visitorData);

                if (isset($syncResult['success']) && !$syncResult['success']) {
                    error_log("Dahua Sync Failed for Visit $visit_id: " . ($syncResult['error'] ?? 'Unknown Error'));
                } else {
                    logAction($pdo, $_SESSION['user_id'], "Dahua Sync Success for Visit $visit_id");
                }
            }
        }
    } catch (Exception $e) {
        error_log("Dahua Integration Error: " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Visitor Approved and Synced to Security Terminals']);
} else {
    $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_by=?, approved_at=NOW(), rejection_reason=? WHERE id=?");
    $stmt->execute([$_SESSION['user_id'], $reason, $visit_id]);
    logAction($pdo, $_SESSION['user_id'], "Rejected visit ID: $visit_id via Popup");

    // Send Notification to Security
    $stmt = $pdo->prepare("SELECT v.name FROM visitors v JOIN visits vs ON v.id = vs.visitor_id WHERE vs.id = ?");
    $stmt->execute([$visit_id]);
    $visitor_name = $stmt->fetchColumn();

    sendPushNotificationToRole($pdo, 'security', "Visitor Rejected", "Visitor $visitor_name has been rejected by the host.", ['visit_id' => $visit_id, 'type' => 'approval_status']);

    echo json_encode(['success' => true, 'message' => 'Visitor Rejected']);
}
