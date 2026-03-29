<?php
require_once '../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf']) && isset($_POST['visit_id'])) {
    $visit_id = $_POST['visit_id'];
    $file = $_FILES['pdf'];

    // SAFE FILENAME: No spaces, no special characters. Just Pass_ID.pdf
    $filename = "Pass_" . $visit_id . ".pdf";
    $target_path = "../../uploads/passes/" . $filename;

    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        // Return Absolute Clean URL
        $public_url = BASE_URL . "uploads/passes/" . $filename;

        // --- AUTOMATED WHATSAPP NOTIFICATION ---
        // Since the "Good" PDF is now on server, we can send the official template
        try {
            require_once '../../includes/whatsapp_helper.php';

            $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.id = ?");
            $stmt->execute([$visit_id]);
            $visit_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($visit_data && !empty($visit_data['mobile'])) {
                sendWhatsAppNotification(
                    $visit_data['mobile'],
                    "Your entry pass for visit #{$visit_id} is attached.",
                    'visit_approval_visitor_notify',
                ["*{$visit_data['visitor_name']}*"],
                    $public_url
                );
            }
        }
        catch (Exception $e) {
            error_log("WhatsApp automation error in upload_pass: " . $e->getMessage());
        }

        echo json_encode(['success' => true, 'url' => $public_url]);
    }

    else {
        echo json_encode(['success' => false, 'message' => 'Upload failed']);
    }
}
else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>