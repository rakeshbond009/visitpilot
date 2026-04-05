<?php
// api/visitor/register.php
require_once '../includes/api_header.php';

$data = getPostData();

if (!$data) {
    sendResponse('error', 'No data provided');
}

// Basic validation
$required = ['name', 'mobile', 'employee_id', 'purpose'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        sendResponse('error', "Field '$field' is required");
    }
}

    // 1. Handle Photo Save if provided (Moved OUTSIDE transaction for speed)
    $photo_path = '';
    if (!empty($data['photo_data'])) {
        $photo_data = explode(',', $data['photo_data']);
        if (isset($photo_data[1])) {
            $content = base64_decode($photo_data[1]);
            $filename = 'uploads/photos/' . uniqid() . '.jpg';
            $pAbs = '../../uploads/photos/';
            if (!is_dir($pAbs)) {
                mkdir($pAbs, 0777, true);
            }
            file_put_contents('../../' . $filename, $content);
            $photo_path = $filename;
        }
    }

try {
    $pdo->beginTransaction();

    // 2. Check if visitor exists
    $stmt = $pdo->prepare("SELECT id, id_proof_type, id_proof_number FROM visitors WHERE mobile = ?");
    $stmt->execute([$data['mobile']]);
    $visitor = $stmt->fetch();

    $id_proof_type = $data['id_proof_type'] ?? '';
    $id_proof_number = $data['id_proof_number'] ?? '';

    if ($visitor) {
        $visitor_id = $visitor['id'];
        if (empty($id_proof_type) && !empty($visitor['id_proof_type'])) {
            $id_proof_type = $visitor['id_proof_type'];
            $id_proof_number = $visitor['id_proof_number'];
        }
        $sql = "UPDATE visitors SET name=?, email=?, address=?, id_proof_type=?, id_proof_number=?";
        $params = [
            $data['name'],
            $data['email'] ?? '',
            $data['address'] ?? '',
            $id_proof_type,
            $id_proof_number
        ];
        if ($photo_path) {
            $sql .= ", photo_path=?";
            $params[] = $photo_path;
        }
        $sql .= " WHERE id=?";
        $params[] = $visitor_id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("INSERT INTO visitors (name, mobile, email, address, id_proof_type, id_proof_number, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$data['name'], $data['mobile'], $data['email'] ?? '', $data['address'] ?? '', $id_proof_type, $id_proof_number, $photo_path]);
        $visitor_id = $pdo->lastInsertId();
    }

    // 3. Handle visit record
    $invitation_id = $data['invitation_id'] ?? null;
    $visit_code = '';
    $current_time = date('Y-m-d H:i:s');
    $access_area = $data['access_area'] ?? 'Not Assigned';
    $assets = $data['assets_carried'] ?? 'None';
    $members = $data['members'] ?? [];
    $total_visitors = 1 + count($members);

    if ($invitation_id) {
        $visit_id = $invitation_id;
        $stmt = $pdo->prepare("SELECT visit_code FROM visits WHERE id = ?");
        $stmt->execute([$visit_id]);
        $visit_code = $stmt->fetchColumn();
        $stmt = $pdo->prepare("UPDATE visits SET status='approved', approval_status='approved', visitor_id=?, visit_photo=?, purpose=?, check_in_time=?, id_proof_type=?, id_proof_number=?, access_area=?, total_visitors=?, created_at=?, created_by=? WHERE id=?");
        $stmt->execute([$visitor_id, $photo_path, $data['purpose'], $current_time, $id_proof_type, $id_proof_number, $access_area, $total_visitors, $current_time, $user_id, $visit_id]);
    } else {
        $visit_code = generateVisitCode();
        $stmt = $pdo->prepare("INSERT INTO visits (visitor_id, visit_photo, employee_id, purpose, visit_code, status, approval_status, access_area, assets_carried, id_proof_type, id_proof_number, total_visitors, created_at, created_by) VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$visitor_id, $photo_path, $data['employee_id'], $data['purpose'], $visit_code, $access_area, $assets, $id_proof_type, $id_proof_number, $total_visitors, $current_time, $user_id]);
        $visit_id = $pdo->lastInsertId();
    }

    if (!empty($members)) {
        $pdo->prepare("DELETE FROM visit_members WHERE visit_id = ?")->execute([$visit_id]);
        $stmtMem = $pdo->prepare("INSERT INTO visit_members (visit_id, name) VALUES (?, ?)");
        foreach ($members as $memName) {
            if (!empty(trim($memName))) $stmtMem->execute([$visit_id, trim($memName)]);
        }
    }

    $pdo->commit();

    // INSTANT RESPONSE TO ANDROID APP
    sendAsyncResponse('success', 'Visitor registered', [
        'visit_id' => $visit_id,
        'visit_code' => $visit_code,
        'status' => $invitation_id ? 'approved' : 'pending'
    ]);

    // BACKGROUND PROCESSING (Android won't see this)
    if ($visit_code) {
        $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code);
        $ch = curl_init($qr_api_url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 2]);
        $qr_image = curl_exec($ch);
        curl_close($ch);
        if ($qr_image) {
            $qr_filename = 'uploads/qrcodes/' . $visit_code . '.png';
            if (!is_dir('../../uploads/qrcodes/')) mkdir('../../uploads/qrcodes/', 0777, true);
            file_put_contents('../../' . $qr_filename, $qr_image);
            $pdo->prepare("UPDATE visits SET qr_code_path = ? WHERE id = ?")->execute([$qr_filename, $visit_id]);
        }
    }

    require_once '../../includes/pass_pdf_helper.php';
    generatePassPdf($visit_id, $pdo);

    try {
        require_once '../../includes/push_helper.php';
        require_once '../../includes/whatsapp_helper.php';

        $stmt = $pdo->prepare("SELECT * FROM visitors WHERE id = ?"); $stmt->execute([$visitor_id]);
        $visitor = $stmt->fetch(PDO::FETCH_ASSOC);

        $hStmt = $pdo->prepare("SELECT mobile, name FROM employees WHERE id = ?"); $hStmt->execute([$data['employee_id']]);
        $host = $hStmt->fetch(PDO::FETCH_ASSOC);

        $pushData = ['visit_id' => (string) $visit_id, 'visitor_name' => $visitor['name'], 'type' => 'visitor_arrival'];
        sendPushNotification($pdo, $data['employee_id'], "New Visitor Arrival", "{$visitor['name']} has arrived.", $pushData);

        if ($host && !empty($host['mobile'])) {
            sendWhatsAppNotification($host['mobile'], "Visitor arrived.", 'visitor_arrival_host_alert', ["*{$host['name']}*", "*{$visitor['name']}*", "*{$data['purpose']}*"]);
        }
    } catch (Exception $e) { }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendResponse('error', 'Database error: ' . $e->getMessage());
}