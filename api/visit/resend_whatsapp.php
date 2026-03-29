<?php
header('Content-Type: application/json');
require_once '../../includes/db.php';
require_once '../../includes/whatsapp_helper.php';

// Allow from admin and security
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$visit_id = $_GET['visit_id'] ?? null;
$type = $_GET['type'] ?? 'arrival'; // 'arrival', 'approval', 'rejection', 'invite', 'meet'

if (!$visit_id) {
    echo json_encode(['success' => false, 'message' => 'Visit ID missing']);
    exit;
}

try {
    // Fetch visit details
    $stmt = $pdo->prepare("SELECT v.*, vis.mobile as visitor_mobile, vis.name as visitor_name, e.name as host_name, e.mobile as host_mobile, e.email as host_email 
                           FROM visits v 
                           JOIN visitors vis ON v.visitor_id = vis.id
                           LEFT JOIN employees e ON v.employee_id = e.id 
                           WHERE v.id = ?");
    $stmt->execute([$visit_id]);
    $visit = $stmt->fetch();

    if (!$visit) {
        throw new Exception("Visit not found");
    }

    $success = false;
    $message = "Unknown notification type";

    switch ($type) {
        case 'arrival':
            // Notify Host about visitor arrival
            $success = sendWhatsAppNotification(
                $visit['host_mobile'],
                "Visitor {$visit['visitor_name']} has arrived to meet you.",
                'visitor_arrival_host_alert',
            ["*{$visit['host_name']}*", "*{$visit['visitor_name']}*", "*{$visit['purpose']}*"]
            );
            $message = $success ? "Arrival Alert sent to Host" : "Failed to send Arrival Alert";
            break;

        case 'approval':
            // Notify Visitor about approval - template has a document header for the pass
            require_once '../../includes/pass_pdf_helper.php';
            $pdfUrl = generatePassPdf($visit_id, $pdo);
            $success = sendWhatsAppNotification(
                $visit['visitor_mobile'],
                "Your visit request has been approved.",
                'visit_approval_visitor_notify',
            ["*{$visit['visitor_name']}*"],
                $pdfUrl
            );
            $message = $success ? "Approval Notice sent to Visitor" : "Failed to send Approval Notice";
            break;

        case 'rejection':
            // Notify Visitor about rejection
            $reason = $visit['rejection_reason'] ?: 'Host declined the visit.';
            $success = sendWhatsAppNotification(
                $visit['visitor_mobile'],
                "Your visit request was declined.",
                'visit_rejection_visitor_notify',
            ["*{$visit['visitor_name']}*", "*{$reason}*"]
            );
            $message = $success ? "Rejection Notice sent to Visitor" : "Failed to send Rejection Notice";
            break;

        case 'invite':
        case 'invitation':
            // Notify Visitor about invitation - Using visitor_meet_notify as replacement for rejected invitation tpl
            $v_date_fmt = date('d-M-Y', strtotime($visit['visit_date']));
            $success = sendWhatsAppNotification(
                $visit['visitor_mobile'],
                "Invitation for visit on {$v_date_fmt}",
                'visitor_meet_notify',
            ["*{$visit['visitor_name']}*", "*{$v_date_fmt}*", "*{$visit['visit_code']}*"]
            );
            $message = $success ? "Invitation sent to Visitor" : "Failed to send Invitation";
            break;

        case 'meet':
            // Notify Host that visitor reached their desk
            $success = sendWhatsAppNotification(
                $visit['host_mobile'],
                "Visitor {$visit['visitor_name']} is outside your room/cabin.",
                'visitor_meet_notify',
            ["*{$visit['visitor_name']}*", "*{$visit['host_name']}*", "*{$visit['pass_code']}*"]
            );
            $message = $success ? "Arrival Notice sent to Host" : "Failed to send Arrival Notice";
            break;
    }

    $skipped = false;
    if ($success === true) {
        switch ($type) {
            case 'arrival': $message = "Arrival Alert sent to Host"; break;
            case 'approval': $message = "Approval Notice sent to Visitor"; break;
            case 'rejection': $message = "Rejection Notice sent to Visitor"; break;
            case 'invite':
            case 'invitation': $message = "Invitation sent to Visitor"; break;
            case 'meet': $message = "Arrival Notice sent to Host"; break;
        }
    } else if ($success === 'skipped_disabled') {
        $message = "WhatsApp notification is Disabled for this action in Settings";
        $skipped = true;
    } else if ($success === 'skipped_not_live') {
        $message = "WhatsApp API is not configured or not Live";
        $skipped = true;
    } else {
        $success = false;
        $message = "Failed to send WhatsApp notification";
    }

    // Force success = true to trigger the frontend success logic, even if skipped due to settings
    if ($skipped) {
        $success = true; // Treating it as a "success" so it doesn't show as a red error
    }

    echo json_encode(['success' => (bool)$success, 'skipped' => $skipped, 'message' => $message]);

}
catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
