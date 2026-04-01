<?php
// api/visit/status_action.php - Dahua Enabled
require_once '../includes/api_header.php';
require_once '../../includes/dahua_helper.php';

$data = getPostData();

if (!$data || !isset($data['action'])) {
    sendResponse('error', 'Missing action parameter');
}

$action = $data['action'];

// visit_id is required unless action is qr_process
if ($action !== 'qr_process' && !isset($data['visit_id'])) {
    sendResponse('error', 'Missing visit_id parameter');
}

$id = $data['visit_id'] ?? 0;

try {
    if ($action === 'approve') {
        $stmt = $pdo->prepare("UPDATE visits SET approval_status='approved', status='approved', approved_at=NOW() WHERE id=?");
        $stmt->execute([$id]);

        // --- NOTIFICATIONS (NEW) ---
        try {
            require_once '../../includes/push_helper.php';
            require_once '../../includes/whatsapp_helper.php';

            // Get visitor details
            $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id LEFT JOIN employees e ON v.employee_id = e.id WHERE v.id = ?");
            $stmt->execute([$id]);
            $visit = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($visit) {
                // Check if PDF exists (strict lookup only, no fallback generation)
                require_once '../../includes/pass_pdf_helper.php';
                $pdfUrl = generatePassPdf($id, $pdo);

                // Send WhatsApp (will Safety Abort inside the helper if $pdfUrl is null)
                sendWhatsAppNotification($visit['mobile'], "Your visit is approved", 'visit_approval_visitor_notify', ["*{$visit['visitor_name']}*"], $pdfUrl);

                // Push to Security
                sendPushNotificationToRole($pdo, 'security', 'Visit Approved', "Host {$visit['host_name']} approved visit for {$visit['visitor_name']}.", [
                    'visit_id' => (string) $id,
                    'type' => 'approval_status'
                ]);
            }
        } catch (Throwable $e) {
            error_log("Notification error in approve: " . $e->getMessage());
        }

        $responseData = [];
        if (isset($visit)) {
            $responseData['visitor_mobile'] = $visit['mobile'];
        }

        // --- DAHUA INTEGRATION (Sync on Approval) ---
        try {
            $raw_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

            if (!empty($raw_settings['dahua_app_id']) && !empty($raw_settings['dahua_app_secret'])) {
                $dahua = new DahuaHelper($raw_settings['dahua_app_id'], $raw_settings['dahua_app_secret']);

                $startTime = $visit['created_at'];
                $endTime = date('Y-m-d 23:59:59', strtotime($startTime));

                $deviceSnsList = explode(',', $raw_settings['dahua_device_sns']);
                $deviceSnsList = array_map('trim', $deviceSnsList);

                $visitorData = [
                    'visitor_id' => $visit['visitor_id'],
                    'name' => $visit['visitor_name'],
                    'face_path' => realpath('../../' . $visit['visit_photo']),
                    'qr_code' => $visit['visit_code'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'device_sns' => $deviceSnsList
                ];

                $syncResult = $dahua->syncVisitor($visitorData);

                if (isset($syncResult['success']) && !$syncResult['success']) {
                    error_log("Dahua Sync Failed for Visit $id: " . ($syncResult['error'] ?? 'Unknown Error'));
                } else {
                    logAction($pdo, $_SESSION['user_id'] ?? 0, "Dahua Sync Success for Visit $id");
                }
            }
        } catch (Exception $e) {
            error_log("Dahua Integration Error: " . $e->getMessage());
        }
        sendResponse('success', 'Visit Approved', $responseData);

    } elseif ($action === 'reject') {
        $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_at=NOW() WHERE id=?");
        $stmt->execute([$id]);

        // --- NOTIFICATIONS (NEW) ---
        try {
            require_once '../../includes/whatsapp_helper.php';
            require_once '../../includes/push_helper.php';

            $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id LEFT JOIN employees e ON v.employee_id = e.id WHERE v.id = ?");
            $stmt->execute([$id]);
            $visit = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($visit) {
                // WhatsApp to visitor
                $reason = $data['reason'] ?? 'Host declined the visit.';
                sendWhatsAppNotification($visit['mobile'], "Your visit request has been declined.", 'visit_rejection_visitor_notify', ["*{$visit['visitor_name']}*", "*{$reason}*"]);

                // Push to Security
                sendPushNotificationToRole($pdo, 'security', 'Visit Rejected', "Host {$visit['host_name']} REJECTED visit for {$visit['visitor_name']}.", [
                    'visit_id' => (string) $id,
                    'type' => 'approval_status'
                ]);
            }
        } catch (Exception $e) {
            error_log("Notification error in reject: " . $e->getMessage());
        }

        $responseData = [];
        if (isset($visit) && isset($waMsg)) {
            $responseData['visitor_mobile'] = $visit['mobile'];
            $responseData['whatsapp_message'] = $waMsg;
        }
        sendResponse('success', 'Visit Rejected', $responseData);

    } elseif ($action === 'checkin') {
        // Check if visit is approved
        $stmt = $pdo->prepare("SELECT approval_status FROM visits WHERE id=?");
        $stmt->execute([$id]);
        $approval = $stmt->fetchColumn();

        if ($approval !== 'approved') {
            sendResponse('error', 'Cannot check-in: Visit not yet approved by host');
        }

        $stmt = $pdo->prepare("UPDATE visits SET status='checked_in', check_in_time=NOW() WHERE id=?");
        $stmt->execute([$id]);
        sendResponse('success', 'Check-in successful');

    } elseif ($action === 'checkout') {
        $stmt = $pdo->prepare("UPDATE visits SET status='checked_out', check_out_time=NOW() WHERE id=?");
        $stmt->execute([$id]);
        sendResponse('success', 'Check-out successful');

    } elseif ($action === 'qr_process') {
        $code = $data['code'];
        $stmt = $pdo->prepare("
             SELECT v.id, v.status, v.approval_status, v.is_invited, v.visit_code, v.purpose, v.visit_photo,
                    vis.name as visitor_name, vis.mobile as visitor_mobile, vis.address as visitor_company,
                    e.name as host_name, d.name as department
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN employees e ON v.employee_id = e.id 
            LEFT JOIN departments d ON e.department_id = d.id
            WHERE v.visit_code = ?
        ");
        $stmt->execute([$code]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($visit) {
            $responseData = [
                'id' => $visit['id'],
                'visitor_name' => $visit['visitor_name'],
                'visitor_mobile' => $visit['visitor_mobile'],
                'visitor_company' => $visit['visitor_company'],
                'host_name' => $visit['host_name'],
                'department' => $visit['department'],
                'purpose' => $visit['purpose'],
                'visit_photo' => $visit['visit_photo'],
                'visit_code' => $visit['visit_code']
            ];

            // Condition 1: Check-in if in approved state
            if ($visit['status'] == 'approved') {
                if ($visit['approval_status'] == 'approved') {
                    $stmt = $pdo->prepare("UPDATE visits SET status='checked_in', check_in_time=NOW() WHERE id=?");
                    $stmt->execute([$visit['id']]);
                    sendResponse('success', 'Check-in Successful for ' . $visit['visitor_name'], $responseData);
                } else {
                    sendResponse('error', 'Visit not yet approved by host', $responseData);
                }
            }
            // Condition 2: Check-out if in checked-in state
            elseif ($visit['status'] == 'checked_in') {
                $stmt = $pdo->prepare("UPDATE visits SET status='checked_out', check_out_time=NOW() WHERE id=?");
                $stmt->execute([$visit['id']]);
                sendResponse('success', 'Check-out Successful for ' . $visit['visitor_name'], $responseData);
            }
            // Condition 4: If it's a pending invitation, tell app to pre-fill
            elseif ($visit['is_invited'] == 1 && $visit['status'] == 'pending') {
                sendResponse('invitation', 'Pre-Approved Invitation Found', array_merge(['code' => $code], $responseData));
            }
            // Condition 3: Error for other conditions
            elseif ($visit['status'] == 'checked_out') {
                sendResponse('error', 'Visitor ' . $visit['visitor_name'] . ' already checked out', $responseData);
            } elseif ($visit['status'] == 'registered') {
                sendResponse('error', 'Visit request for ' . $visit['visitor_name'] . ' is pending host approval', $responseData);
            } else {
                sendResponse('error', 'Invalid visit status: ' . str_replace('_', ' ', $visit['status']), $responseData);
            }
        } else {
            sendResponse('error', 'Invalid QR Code');
        }
    } else {
        sendResponse('error', 'Invalid action');
    }
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
