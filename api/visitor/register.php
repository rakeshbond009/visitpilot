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
        $visit_id = $invitation_id;
        // Fetch visit code for QR generation if needed
        $vStmt = $pdo->prepare("SELECT visit_code FROM visits WHERE id = ?");
        $vStmt->execute([$visit_id]);
        $visit_code = $vStmt->fetchColumn();

        // Update existing Invitation - Set both status and approval_status to 'approved'
        // Since it's an invitation, the host has already pre-approved it.
        // We set visit_date=CURDATE() so it appears in today's pending check-in list.
        $stmt = $pdo->prepare("UPDATE visits SET status='approved', approval_status='approved', check_in_time=NULL, visit_date=CURDATE(), assets_carried=?, id_proof_type=?, id_proof_number=?, access_area=?, visit_photo=?, total_visitors=?, created_at=? WHERE id=?");
        $stmt->execute([
            $assets,
            $data['id_proof_type'] ?? '',
            $data['id_proof_number'] ?? '',
            $access_area,
            $photo_path,
            $total_visitors,
            $current_time,
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

    // Send response immediately to allow visitor flow to continue
    sendBackgroundResponse('success', 'Visitor registered successfully', [
        'visit_id' => $visit_id,
        'visit_code' => $visit_code,
        'qr_code_url' => isset($qr_code_path) ? $qr_code_path : null,
        'status' => $invitation_id ? 'approved' : 'pending',
        'approval_status' => $invitation_id ? 'approved' : 'pending'
    ]);

    // --- BACKGROUND TASKS START HERE ---

    // 1. DAHUA INTEGRATION (Sync on Check-in/Entry)
    try {
        $raw_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

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
                $dahua->syncVisitor($visitorData);
            }
        }
    } catch (Throwable $e) {
        error_log("Dahua Background Error: " . $e->getMessage());
    }

    // 2. SEND PUSH NOTIFICATION & WHATSAPP
    try {
        require_once dirname(__DIR__, 2) . '/includes/push_helper.php';
        require_once '../../includes/whatsapp_helper.php';

        $stmt = $pdo->prepare("SELECT v.*, vis.name, vis.mobile, vis.address, vis.company FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visitorInfo = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($visitorInfo) {
            $pushData = [
                'visitor_id' => (string) $visitorInfo['visitor_id'],
                'visit_id' => (string) $visit_id,
                'visitor_name' => (string) $visitorInfo['name'],
                'visitor_mobile' => (string) $visitorInfo['mobile'],
                'purpose' => (string) $visitorInfo['purpose'],
                'company' => (string) ($visitorInfo['address'] ?? ($visitorInfo['company'] ?? 'General Visitor')),
                'photo_url' => $visitorInfo['visit_photo'] ? BASE_URL . $visitorInfo['visit_photo'] : '',
                'type' => 'visitor_arrival',
                'assets_carried' => (string) ($visitorInfo['assets_carried'] ?? 'None')
            ];

            sendPushNotification($pdo, $visitorInfo['employee_id'], "New Visitor Arrival", "{$visitorInfo['name']} is waiting for your approval.", $pushData);

            // WhatsApp Automation
            $hStmt = $pdo->prepare("SELECT mobile, name FROM employees WHERE id = ?");
            $hStmt->execute([$visitorInfo['employee_id']]);
            $host = $hStmt->fetch(PDO::FETCH_ASSOC);
            if ($host && !empty($host['mobile'])) {
                sendWhatsAppNotification(
                    $host['mobile'],
                    "Visitor {$visitorInfo['name']} has arrived to meet you.",
                    'visitor_arrival_host_alert',
                    ["*{$host['name']}*", "*{$visitorInfo['name']}*", "*{$visitorInfo['purpose']}*"]
                );
            }
        }
    } catch (Throwable $e) {
        error_log("Notification Background Error: " . $e->getMessage());
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendResponse('error', 'Database error: ' . $e->getMessage());
}