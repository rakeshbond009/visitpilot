<?php
// api/visitor/register.php
require_once '../includes/api_header.php';
require_once '../../includes/dahua_helper.php';

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

try {
    $pdo->beginTransaction();

    // 1. Handle Photo Save if provided
    $photo_path = '';
    if (!empty($data['photo_data'])) {
        $photo_data = explode(',', $data['photo_data']);
        if (isset($photo_data[1])) {
            $content = base64_decode($photo_data[1]);
            $filename = 'uploads/photos/' . uniqid() . '.jpg';
            // Path relative to root (from api/visitor/ we go up twice)
            if (!is_dir('../../uploads/photos/')) {
                mkdir('../../uploads/photos/', 0777, true);
            }
            file_put_contents('../../' . $filename, $content);
            $photo_path = $filename;
        }
    }

    // 2. Check if visitor exists
    $stmt = $pdo->prepare("SELECT id FROM visitors WHERE mobile = ?");
    $stmt->execute([$data['mobile']]);
    $visitor = $stmt->fetch();

    if ($visitor) {
        $visitor_id = $visitor['id'];
        // Update details including photo if changed
        $sql = "UPDATE visitors SET name=?, email=?, address=?, id_proof_type=?, id_proof_number=?";
        $params = [
            $data['name'],
            $data['email'] ?? '',
            $data['address'] ?? '',
            $data['id_proof_type'] ?? '',
            $data['id_proof_number'] ?? ''
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
        $stmt->execute([
            $data['name'],
            $data['mobile'],
            $data['email'] ?? '',
            $data['address'] ?? '',
            $data['id_proof_type'] ?? '',
            $data['id_proof_number'] ?? '',
            $photo_path
        ]);
        $visitor_id = $pdo->lastInsertId();
    }

    // 3. Handle visit record
    $invitation_id = $data['invitation_id'] ?? null;
    $visit_code = '';
    $current_time = date('Y-m-d H:i:s');
    $access_area = $data['access_area'] ?? 'Not Assigned';
    $assets = $data['assets_carried'] ?? 'None';
    $members = $data['members'] ?? [];
    if (!is_array($members))
        $members = [];
    $total_visitors = 1 + count($members);

    if ($invitation_id) {
        // Update existing Invitation
        $visit_id = $invitation_id;
        // Fetch visit code for QR generation if needed
        $vStmt = $pdo->prepare("SELECT visit_code FROM visits WHERE id = ?");
        $vStmt->execute([$visit_id]);
        $visit_code = $vStmt->fetchColumn();

        $stmt = $pdo->prepare("UPDATE visits SET status='checked_in', check_in_time=?, assets_carried=?, id_proof_type=?, id_proof_number=?, access_area=?, visit_photo=?, total_visitors=? WHERE id=?");
        $stmt->execute([
            $current_time,
            $assets,
            $data['id_proof_type'] ?? '',
            $data['id_proof_number'] ?? '',
            $access_area,
            $photo_path,
            $total_visitors,
            $visit_id
        ]);
    } else {
        // Create New Visit with pending status (requires host approval)
        $visit_code = generateVisitCode();
        $stmt = $pdo->prepare("INSERT INTO visits (visitor_id, visit_photo, employee_id, purpose, visit_code, status, approval_status, access_area, assets_carried, id_proof_type, id_proof_number, total_visitors, created_at) VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $visitor_id,
            $photo_path,
            $data['employee_id'],
            $data['purpose'],
            $visit_code,
            $access_area,
            $assets,
            $data['id_proof_type'] ?? '',
            $data['id_proof_number'] ?? '',
            $total_visitors,
            $current_time
        ]);
        $visit_id = $pdo->lastInsertId();
    }

    // Generate and Save QR Code if not exists
    $qr_code_path = '';
    if ($visit_code) {
        $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code);

        // Use cURL as it's more reliable than file_get_contents for external URLs
        $ch = curl_init($qr_api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $qr_image = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($qr_image) {
            $qr_filename = 'uploads/qrcodes/' . $visit_code . '.png';
            if (!is_dir('../../uploads/qrcodes/')) {
                mkdir('../../uploads/qrcodes/', 0777, true);
            }
            file_put_contents('../../' . $qr_filename, $qr_image);
            $qr_code_path = $qr_filename;

            // Update visit with QR code path
            $pdo->prepare("UPDATE visits SET qr_code_path = ? WHERE id = ?")->execute([$qr_code_path, $visit_id]);
        } else {
            error_log("QR Generation Error: " . $curl_error);
        }
    }

    // --- DAHUA INTEGRATION (Sync on Check-in/Entry) ---
    try {
        $raw_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

        // Safety Check: Only sync if visitor is approved AND either checked-in or manually approved
        $checkStatusStmt = $pdo->prepare("SELECT status, approval_status FROM visits WHERE id = ?");
        $checkStatusStmt->execute([$visit_id]);
        $vStatus = $checkStatusStmt->fetch(PDO::FETCH_ASSOC);

        if ($vStatus && $vStatus['approval_status'] === 'approved' && in_array($vStatus['status'], ['checked_in', 'approved'])) {
            if ($photo_path && !empty($raw_settings['dahua_app_id']) && !empty($raw_settings['dahua_app_secret'])) {
                $dahua = new DahuaHelper($raw_settings['dahua_app_id'], $raw_settings['dahua_app_secret']);

                $startTime = date('Y-m-d H:i:s');
                $endTime = date('Y-m-d 23:59:59');

                $deviceSnsList = explode(',', $raw_settings['dahua_device_sns'] ?? '');
                $deviceSnsList = array_map('trim', array_filter($deviceSnsList));

                $visitorData = [
                    'visitor_id' => $visitor_id,
                    'name' => $data['name'],
                    'face_path' => realpath('../../' . $photo_path),
                    'qr_code' => $visit_code,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'device_sns' => $deviceSnsList
                ];

                $syncResult = $dahua->syncVisitor($visitorData);

                if (isset($syncResult['success']) && !$syncResult['success']) {
                    error_log("Dahua Sync Failed (Register) for Visit $visit_id: " . ($syncResult['error'] ?? 'Unknown Error'));
                } else {
                    logAction($pdo, $_SESSION['user_id'] ?? 0, "Dahua Sync Success (Register/Entry) for Visit $visit_id");
                }
            }
        }
    } catch (Exception $e) {
        error_log("Dahua Integration Error in Register: " . $e->getMessage());
    }

    // 4. Handle Accompanying Members
    if (!empty($members)) {
        // Clear existing members if any (for invited visits)
        $pdo->prepare("DELETE FROM visit_members WHERE visit_id = ?")->execute([$visit_id]);

        $stmtMem = $pdo->prepare("INSERT INTO visit_members (visit_id, name) VALUES (?, ?)");
        foreach ($members as $memName) {
            if (!empty(trim($memName))) {
                $stmtMem->execute([$visit_id, trim($memName)]);
            }
        }
    }

    $pdo->commit();

    // 5. SEND PUSH NOTIFICATION & WHATSAPP
    try {
        require_once '../../includes/push_helper.php';
        $stmt = $pdo->prepare("SELECT * FROM visitors WHERE id = ?");
        $stmt->execute([$visitor_id]);
        $visitor = $stmt->fetch(PDO::FETCH_ASSOC);

        $pushData = [
            'visitor_id' => (string) $visitor_id,
            'visit_id' => (string) $visit_id,
            'visitor_name' => (string) $visitor['name'],
            'visitor_mobile' => (string) $visitor['mobile'],
            'purpose' => (string) $data['purpose'],
            'company' => (string) ($visitor['address'] ?? ($visitor['company'] ?? 'General Visitor')),
            'photo_url' => $photo_path ? BASE_URL . $photo_path : '',
            'type' => 'visitor_arrival'
        ];

        // Send to Role/Employee
        sendPushNotification($pdo, $data['employee_id'], "New Visitor Arrival", "{$visitor['name']} is waiting for your approval.", $pushData);

        // WhatsApp Automation
        require_once '../../includes/whatsapp_helper.php';
        $hStmt = $pdo->prepare("SELECT mobile, name FROM employees WHERE id = ?");
        $hStmt->execute([$data['employee_id']]);
        $host = $hStmt->fetch(PDO::FETCH_ASSOC);
        if ($host && !empty($host['mobile'])) {
            $trace_msg = "[" . date('Y-m-d H:i:s') . "] TRACE: Found host {$host['name']} with mobile {$host['mobile']} for visitor {$visitor['name']} using visitor_arrival_host_alert\n";
            file_put_contents(__DIR__ . '/../../whatsapp_log.txt', $trace_msg, FILE_APPEND);

            sendWhatsAppNotification(
                $host['mobile'],
                "Visitor {$visitor['name']} has arrived to meet you.",
                'visitor_arrival_host_alert',
                ["*{$host['name']}*", "*{$visitor['name']}*", "*{$data['purpose']}*"]
            );
        } else {
            $trace_msg = "[" . date('Y-m-d H:i:s') . "] TRACE: Host NOT found or mobile empty for ID: " . ($data['employee_id'] ?? 'NONE') . "\n";
            file_put_contents(__DIR__ . '/../../whatsapp_log.txt', $trace_msg, FILE_APPEND);
        }
    } catch (Exception $e) {
        error_log("Notification System Error: " . $e->getMessage());
    }

    sendResponse('success', 'Visitor registered successfully', [
        'visit_id' => $visit_id,
        'visit_code' => $visit_code,
        'qr_code_url' => $qr_code_path ? $qr_code_path : null
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendResponse('error', 'Database error: ' . $e->getMessage());
}