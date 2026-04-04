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
        $stmt = $pdo->prepare("UPDATE visits SET approval_status='approved', status='approved', approved_at=NOW(), approved_by=? WHERE id=?");
        $stmt->execute([$user_id, $id]);

        // Fetch visit details for notifications
        require_once '../../includes/push_helper.php';
        require_once '../../includes/whatsapp_helper.php';
        $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id LEFT JOIN employees e ON v.employee_id = e.id WHERE v.id = ?");
        $stmt->execute([$id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!isset($visit)) {
            sendResponse('error', 'Visit not found', []);
            exit;
        }

        // ── ROBUST FLUSH RESPONSE ─────────────────────────────────────────────
        $respJson = json_encode(['status' => 'success', 'message' => 'Visit Approved', 'data' => []]);
        if (function_exists('fastcgi_finish_request')) {
            header('Content-Type: application/json');
            echo $respJson;
            fastcgi_finish_request();
        } else {
            @ini_set('zlib.output_compression', '0');
            header('Content-Type: application/json');
            header('Content-Length: ' . strlen($respJson));
            header('Connection: close');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            echo $respJson;
            flush();
        }
        ignore_user_abort(true);
        // ──────────────────────────────────────────────────────────────────────

        // Background: WhatsApp + PDF + FCM Push
        try {
            require_once '../../includes/pass_pdf_helper.php';
            $pdfUrl = generatePassPdf($id, $pdo);
            sendWhatsAppNotification($visit['mobile'], "Your visit is approved", 'visit_approval_visitor_notify', ["*{$visit['visitor_name']}*"], $pdfUrl);
        } catch (Throwable $e) {
            error_log("WhatsApp/PDF error in approve: " . $e->getMessage());
        }

        try {
            sendPushNotificationToRole($pdo, 'security', 'Visit Approved', "Host {$visit['host_name']} approved visit for {$visit['visitor_name']}.", [
                'visit_id' => (string) $id,
                'type'     => 'approval_status',
            ]);
        } catch (Throwable $e) {
            error_log("FCM push error in approve: " . $e->getMessage());
        }

        exit;


    } elseif ($action === 'cancel') {
        // Fetch visitor details before canceling for notification
        $stmt = $pdo->prepare("SELECT vis.name, vis.mobile, e.name as host_name, v.created_by 
                              FROM visits v 
                              JOIN visitors vis ON v.visitor_id = vis.id
                              LEFT JOIN employees e ON v.employee_id = e.id
                              WHERE v.id = ?");
        $stmt->execute([$id]);
        $visitor_info = $stmt->fetch();

        // Update status - for invitations, approval_status is already approved. 
        // Setting it to rejected. 1 is for when the invite is cancelled by the host. 
        $stmt = $pdo->prepare("UPDATE visits SET status='rejected', approval_status='rejected', approved_at=NOW(), approved_by=? WHERE id=? AND is_invited=1");
        $stmt->execute([$user_id, $id]);

        if ($visitor_info && !empty($visitor_info['mobile'])) {
            try {
                require_once '../../includes/whatsapp_helper.php';
                sendWhatsAppNotification(
                    $visitor_info['mobile'],
                    "Your meeting has been cancelled.",
                    'invite_cancelled',
                    [$visitor_info['name'] ?? 'Visitor', $visitor_info['host_name'] ?? 'your host']
                );
            } catch (Throwable $waErr) {
                error_log("invite_cancelled WA error: " . $waErr->getMessage());
            }

            try {
                require_once '../../includes/push_helper.php';
                sendPushNotificationToRole($pdo, 'security', 'Invitation Cancelled', "Host {$visitor_info['host_name']} CANCELLED invitation for {$visitor_info['name']}.", [
                    'visit_id' => (string) $id,
                    'type' => 'approval_status'
                ]);
            } catch (Throwable $pushErr) {
            }
        }

        sendResponse('success', 'Invitation has been cancelled and visitor notified.');

    } elseif ($action === 'reject') {
        $reason = $data['reason'] ?? 'Host declined the visit.';
        $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_at=NOW(), approved_by=?, rejection_reason=? WHERE id=?");
        $stmt->execute([$user_id, $reason, $id]);

        // Fetch visit details for notifications
        require_once '../../includes/whatsapp_helper.php';
        require_once '../../includes/push_helper.php';
        $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id LEFT JOIN employees e ON v.employee_id = e.id WHERE v.id = ?");
        $stmt->execute([$id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!isset($visit)) {
            sendResponse('error', 'Visit not found', []);
            exit;
        }

        // ── ROBUST FLUSH RESPONSE ─────────────────────────────────────────────
        $respJson = json_encode(['status' => 'success', 'message' => 'Visit Rejected', 'data' => []]);
        if (function_exists('fastcgi_finish_request')) {
            header('Content-Type: application/json');
            echo $respJson;
            fastcgi_finish_request();
        } else {
            @ini_set('zlib.output_compression', '0');
            header('Content-Type: application/json');
            header('Content-Length: ' . strlen($respJson));
            header('Connection: close');
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            echo $respJson;
            flush();
        }
        ignore_user_abort(true);
        // ──────────────────────────────────────────────────────────────────────

        // Background: WhatsApp + FCM Push
        try {
            sendWhatsAppNotification($visit['mobile'], "Your visit request has been declined.", 'visit_rejection_visitor_notify', ["*{$visit['visitor_name']}*", "*{$reason}*"]);
        } catch (Exception $e) {
            error_log("WhatsApp reject error: " . $e->getMessage());
        }
        
        try {
            sendPushNotificationToRole($pdo, 'security', 'Visit Rejected', "Host {$visit['host_name']} REJECTED visit for {$visit['visitor_name']}.", [
                'visit_id' => (string) $id,
                'type'     => 'approval_status',
            ]);
        } catch (Exception $e) {
            error_log("FCM reject error: " . $e->getMessage());
        }

        exit;


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
             SELECT v.id, v.status, v.is_invited, v.visit_code, v.purpose, v.visit_photo,
                    vis.name as visitor_name, vis.mobile as visitor_mobile, vis.address as visitor_company,
                    e.name as host_name, e.department as department
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN employees e ON v.employee_id = e.id 
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

            // 1. If currently checked-in, ask to Check Out
            if ($visit['status'] == 'checked_in') {
                sendResponse('check_out', 'Visitor ' . $visit['visitor_name'] . ' is currently inside. Do you want to Mark Check Out?', $responseData);
            }
            // 2. Invitation Flow: Always return invitation status for any non-checked-in invitation
            // This ensures the mobile app always shows the registration prompt as per the user's requirement
            elseif ($visit['is_invited'] == 1 && $visit['status'] !== 'checked_out') {
                sendResponse('invitation', 'Pre-Approved Invitation Found for ' . $visit['visitor_name'], array_merge(['code' => $code], $responseData));
            }
            // 3. Normal Flow - Ask to Check In if approved or registered
            elseif ($visit['status'] == 'approved' || $visit['status'] == 'registered') {
                sendResponse('check_in', 'Visit request for ' . $visit['visitor_name'] . ' is approved. Do you want to Mark Check In?', $responseData);
            }
            // 4. Other states
            elseif ($visit['status'] == 'checked_out') {
                sendResponse('error', 'Visitor ' . $visit['visitor_name'] . ' already checked out', $responseData);
            } elseif ($visit['status'] == 'pending') {
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
