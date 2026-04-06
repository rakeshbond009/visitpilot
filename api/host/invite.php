<?php
// api/host/invite.php
require_once '../includes/api_header.php';

$data = getPostData();

if (!$data) {
    sendResponse('error', 'No data provided');
}

// Basic validation
$required = ['name', 'mobile', 'purpose'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        sendResponse('error', "Field '$field' is required");
    }
}

// Get host employee ID from token/session
// RE-FETCH from database to ensure consistency with fresh dashboard data
$stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$db_employee_id = $stmt->fetchColumn();

$host_id = $data['employee_id'] ?? $db_employee_id ?? $employee_id;

if (!$host_id) {
    sendResponse('error', 'Host employee identification missing. Please contact administrator.');
}

try {
    $pdo->beginTransaction();

    // 1. Check if visitor exists
    $stmt = $pdo->prepare("SELECT id, id_proof_type, id_proof_number FROM visitors WHERE mobile = ?");
    $stmt->execute([$data['mobile']]);
    $visitor = $stmt->fetch();

    if ($visitor) {
        $visitor_id = $visitor['id'];
        // Update details if provided, but don't overwrite ID proof if it exists and new one is empty
        $update_id_type = (!empty($data['id_proof_type'])) ? $data['id_proof_type'] : $visitor['id_proof_type'];
        $update_id_number = (!empty($data['id_proof_number'])) ? $data['id_proof_number'] : $visitor['id_proof_number'];

        $stmt = $pdo->prepare("UPDATE visitors SET name = ?, email = ?, id_proof_type = ?, id_proof_number = ? WHERE id = ?");
        $stmt->execute([$data['name'], $data['email'] ?? '', $update_id_type, $update_id_number, $visitor_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO visitors (name, mobile, email, id_proof_type, id_proof_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$data['name'], $data['mobile'], $data['email'] ?? '', $data['id_proof_type'] ?? '', $data['id_proof_number'] ?? '']);
        $visitor_id = $pdo->lastInsertId();
    }

    // 2. Create visit record (pre-approved as it's an invitation)
    $visit_code = generateVisitCode();
    $visit_date = $data['visit_date'] ?? date('Y-m-d');

    // Generate QR code path
    $qr_filename = 'uploads/qrcodes/INV_' . $visit_code . '.png';
    if (!is_dir('../../uploads/qrcodes/')) {
        mkdir('../../uploads/qrcodes/', 0777, true);
    }

    // Generate QR Code via external API (matching web app logic)
    $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code);

    // Use cURL as it's more reliable than file_get_contents for external URLs in some environments
    $ch = curl_init($qr_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $qr_image = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($qr_image) {
        file_put_contents('../../' . $qr_filename, $qr_image);
    } else {
        error_log("QR Generation Error: " . $curl_error);
    }

    $stmt = $pdo->prepare("INSERT INTO visits (visitor_id, employee_id, purpose, visit_date, visit_code, status, approval_status, is_invited, qr_code_path, access_area, id_proof_type, id_proof_number, created_by, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 'pending', 'approved', 1, ?, 'Not Assigned', ?, ?, ?, ?, NOW())");
    $stmt->execute([
        $visitor_id,
        $host_id,
        $data['purpose'],
        $visit_date,
        $visit_code,
        $qr_filename,
        $data['id_proof_type'] ?? '',
        $data['id_proof_number'] ?? '',
        $user_id,
        $user_id
    ]);

    $pdo->commit();

    // WhatsApp Notification to Invited Visitor
    require_once '../../includes/whatsapp_helper.php';
    if (!empty($data['mobile'])) {
        $v_date_fmt = date('d-M-Y', strtotime($visit_date));
        $waMessage = "Hello {$data['name']}, You are invited for a visit on {$v_date_fmt}. Code: {$visit_code}";
        sendWhatsAppNotification($data['mobile'], $waMessage, 'visitor_meet_notify', ["*{$data['name']}*", "*{$v_date_fmt}*", "*{$visit_code}*"]);
    }

    // Consolidated Audit Log for Invitation
    $v_name = $data['name'] ?? 'Visitor';
    logAction($pdo, $user_id, "Invitation Created via Mobile for: $v_name (Visit Date: $visit_date, Code: $visit_code)");

    sendResponse('success', "Invitation sent to " . $v_name, [
        'visit_code' => $visit_code,
        'qr_code' => $qr_filename,
        'visitor_name' => $data['name'],
        'visit_date' => $visit_date
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
