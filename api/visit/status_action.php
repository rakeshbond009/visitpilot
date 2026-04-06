<?php
// api/visit/status_action.php - Dahua Enabled
require_once '../includes/api_header.php';
require_once '../includes/async_dispatch.php';
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
        logAction($pdo, $_SESSION['user_id'] ?? 0, "Approved visit ID: $id status_action");

        $bgPayload = ['visit_id' => $id];
        // ⚡ STEP 1: Apache/LSAPI path
        dispatchBackgroundTask('approve_visit', $bgPayload);
        // ⚡ STEP 2: Respond (exits on Apache, returns on FastCGI)
        sendInstantResponse('success', 'Visit Approved', ['visit_id' => $id]);
        // ⚡ STEP 3: Hostinger FastCGI inline path
        runJobInline('approve_visit', $bgPayload, $pdo);

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
        $reason = $data['reason'] ?? 'Invitation cancelled by host.';
        $stmt = $pdo->prepare("UPDATE visits SET status='rejected', approval_status='rejected', approved_at=NOW(), approved_by=?, rejection_reason=? WHERE id=? AND is_invited=1");
        $stmt->execute([$user_id, $reason, $id]);

        $bgPayload = [
            'visit_id' => $id,
            'visitor_name' => $visitor_info['name'] ?? '',
            'visitor_mobile' => $visitor_info['mobile'] ?? '',
            'host_name' => $visitor_info['host_name'] ?? 'your host',
        ];
        // ⚡ STEP 1: Apache/LSAPI path
        dispatchBackgroundTask('cancel_invite', $bgPayload);
        // ⚡ STEP 2: Respond
        sendInstantResponse('success', 'Invitation has been cancelled and visitor notified.');
        // ⚡ STEP 3: Hostinger FastCGI inline path
        runJobInline('cancel_invite', $bgPayload, $pdo);

    } elseif ($action === 'reject') {
        $reason = $data['reason'] ?? 'Host declined the visit.';
        $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_at=NOW(), approved_by=?, rejection_reason=? WHERE id=?");
        $stmt->execute([$user_id, $reason, $id]);
        logAction($pdo, $user_id, "Rejected visit ID: $id. Reason: $reason");

        $bgPayload = [
            'visit_id' => $id,
            'reason' => $reason,
        ];
        // ⚡ STEP 1: Apache/LSAPI path
        dispatchBackgroundTask('reject_visit', $bgPayload);
        // ⚡ STEP 2: Respond
        sendInstantResponse('success', 'Visit Rejected');
        // ⚡ STEP 3: Hostinger FastCGI inline path
        runJobInline('reject_visit', $bgPayload, $pdo);

    } elseif ($action === 'checkin') {
        // Check if visit is approved
        $stmt = $pdo->prepare("SELECT approval_status FROM visits WHERE id=?");
        $stmt->execute([$id]);
        $approval = $stmt->fetchColumn();

        if ($approval !== 'approved') {
            sendResponse('error', 'Cannot check-in: Visit not yet approved by host');
        }

        $stmt = $pdo->prepare("UPDATE visits SET status='checked_in', check_in_time=NOW(), checked_in_by=? WHERE id=?");
        $stmt->execute([$user_id, $id]);
        logAction($pdo, $user_id, "Visitor Check-in successful for visit ID: $id");
        sendResponse('success', 'Check-in successful');

    } elseif ($action === 'checkout') {
        $stmt = $pdo->prepare("UPDATE visits SET status='checked_out', check_out_time=NOW(), checked_out_by=? WHERE id=?");
        $stmt->execute([$user_id, $id]);
        logAction($pdo, $user_id, "Visitor Check-out successful for visit ID: $id");
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
            // 2. Invitation Flow: 
            // - If already 'approved' (meaning registration is done), allow direct Check In
            // - Otherwise, redirect to registration
            elseif ($visit['is_invited'] == 1 && $visit['status'] !== 'checked_out') {
                if ($visit['status'] == 'approved') {
                    sendResponse('check_in', 'Pre-Approved Invitation for ' . $visit['visitor_name'] . ' (Ready for Check-In). Do you want to Mark Check In?', $responseData);
                } else {
                    sendResponse('invitation', 'Pre-Approved Invitation Found for ' . $visit['visitor_name'], array_merge(['code' => $code], $responseData));
                }
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
