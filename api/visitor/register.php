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

// --- TRACE LOGIN FOR ANDROID DEBUGGING ---
$traceMsg = date('[H:i:s] ') . "Register Request: " . json_encode($data) . "\n";
file_put_contents(__DIR__ . '/register_push_trace.log', $traceMsg, FILE_APPEND);

try {
    $pdo->beginTransaction();

    // 1. Handle Photo Save if provided
    $photo_path = '';
    if (!empty($data['photo_data'])) {
        $photo_data = explode(',', $data['photo_data']);
        if (isset($photo_data[1])) {
            $content  = base64_decode($photo_data[1]);
            $filename = 'uploads/photos/' . uniqid() . '.jpg';
            if (!is_dir('../../uploads/photos/')) {
                mkdir('../../uploads/photos/', 0777, true);
            }
            file_put_contents('../../' . $filename, $content);
            $photo_path = $filename;
        }
    }

    // 2. Check if visitor exists
    $stmt = $pdo->prepare("SELECT id, id_proof_type, id_proof_number FROM visitors WHERE mobile = ?");
    $stmt->execute([$data['mobile']]);
    $visitor = $stmt->fetch();

    $id_proof_type   = $data['id_proof_type']   ?? '';
    $id_proof_number = $data['id_proof_number'] ?? '';

    if ($visitor) {
        $visitor_id = $visitor['id'];

        // PRESERVE ID PROOF: keep old one if new request is empty
        if (empty($id_proof_type) && !empty($visitor['id_proof_type'])) {
            $id_proof_type   = $visitor['id_proof_type'];
            $id_proof_number = $visitor['id_proof_number'];
        }

        $sql    = "UPDATE visitors SET name=?, email=?, address=?, id_proof_type=?, id_proof_number=?";
        $params = [
            $data['name'],
            $data['email']   ?? '',
            $data['address'] ?? '',
            $id_proof_type,
            $id_proof_number
        ];
        if ($photo_path) {
            $sql     .= ", photo_path=?";
            $params[] = $photo_path;
        }
        $sql     .= " WHERE id=?";
        $params[] = $visitor_id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->prepare("INSERT INTO visitors (name, mobile, email, address, id_proof_type, id_proof_number, photo_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['name'],
            $data['mobile'],
            $data['email']   ?? '',
            $data['address'] ?? '',
            $id_proof_type,
            $id_proof_number,
            $photo_path
        ]);
        $visitor_id = $pdo->lastInsertId();
    }

    // 3. Handle visit record
    $invitation_id  = $data['invitation_id'] ?? null;
    $visit_code     = '';
    $current_time   = date('Y-m-d H:i:s');
    $access_area    = $data['access_area']    ?? 'Not Assigned';
    $assets         = $data['assets_carried'] ?? 'None';
    $members        = $data['members']        ?? [];
    if (!is_array($members)) $members = [];
    $total_visitors = 1 + count($members);

    if ($invitation_id) {
        $visit_id = $invitation_id;

        $vStmt = $pdo->prepare("SELECT visit_code FROM visits WHERE id = ?");
        $vStmt->execute([$visit_id]);
        $visit_code = $vStmt->fetchColumn();

        $stmt = $pdo->prepare("UPDATE visits SET status='approved', approval_status='pending', check_in_time=NULL, visit_date=CURDATE(), assets_carried=?, id_proof_type=?, id_proof_number=?, access_area=?, visit_photo=?, total_visitors=?, created_at=?, created_by=? WHERE id=?");
        $stmt->execute([
            $assets, $id_proof_type, $id_proof_number,
            $access_area, $photo_path, $total_visitors,
            $current_time, $user_id, $visit_id
        ]);
    } else {
        $visit_code = generateVisitCode();
        $stmt = $pdo->prepare("INSERT INTO visits (visitor_id, visit_photo, employee_id, purpose, visit_code, status, approval_status, access_area, assets_carried, id_proof_type, id_proof_number, total_visitors, created_at, created_by) VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $visitor_id, $photo_path, $data['employee_id'], $data['purpose'], $visit_code,
            $access_area, $assets, $id_proof_type, $id_proof_number,
            $total_visitors, $current_time, $user_id
        ]);
        $visit_id = $pdo->lastInsertId();
    }

    // 4. Handle Accompanying Members
    if (!empty($members)) {
        $pdo->prepare("DELETE FROM visit_members WHERE visit_id = ?")->execute([$visit_id]);
        $stmtMem = $pdo->prepare("INSERT INTO visit_members (visit_id, name) VALUES (?, ?)");
        foreach ($members as $memName) {
            if (!empty(trim($memName))) {
                $stmtMem->execute([$visit_id, trim($memName)]);
            }
        }
    }

    // Fetch visitor details needed for the background job
    $vRow = $pdo->prepare("SELECT name, mobile, address FROM visitors WHERE id = ?");
    $vRow->execute([$visitor_id]);
    $visitorRow = $vRow->fetch(PDO::FETCH_ASSOC);

    // Determine if Dahua sync is needed (only for pre-approved invitation flow)
    $needsDahuaSync = false;
    if ($invitation_id) {
        $chkStmt = $pdo->prepare("SELECT approval_status FROM visits WHERE id = ?");
        $chkStmt->execute([$visit_id]);
        $needsDahuaSync = ($chkStmt->fetchColumn() === 'approved');
    }

    // ✅ COMMIT DB — data is safe before sending response
    $pdo->commit();

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ⚡ STEP 1: Send response to client IMMEDIATELY
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    ignore_user_abort(true);
    set_time_limit(120);

    $responseBody = json_encode([
        'status'  => 'success',
        'message' => 'Visitor registered successfully',
        'data'    => [
            'visit_id'        => $visit_id,
            'visit_code'      => $visit_code,
            'status'          => $invitation_id ? 'approved' : 'pending',
            'approval_status' => $invitation_id ? 'approved' : 'pending',
        ]
    ]);

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($responseBody));
    header('Connection: close');
    echo $responseBody;
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request(); // Client disconnected — Hostinger FastCGI
    }

    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    // ⚡ STEP 2: Background work (client already got response)
    // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    $traceLog = __DIR__ . '/register_push_trace.log';
    $tlog = function($msg) use ($traceLog) {
        file_put_contents($traceLog, date('[H:i:s] ') . $msg . "\n", FILE_APPEND);
    };

    $visitor_name   = $visitorRow['name']    ?? $data['name'];
    $visitor_mobile = $visitorRow['mobile']  ?? $data['mobile'];
    $visitor_address= $visitorRow['address'] ?? '';
    $host_employee_id = $data['employee_id'];

    // 1. FCM Push to Host
    try {
        $tlog("Starting FCM push to host employee_id: $host_employee_id");
        require_once dirname(__DIR__) . '/../includes/push_helper.php';
        $photoUrl = $photo_path ? (defined('BASE_URL') ? BASE_URL : '') . $photo_path : '';
        sendPushNotification($pdo, $host_employee_id,
            "New Visitor Waiting",
            "$visitor_name is at the gate for {$data['purpose']}. Tap to open.",
            [
                'visit_id'       => (string)$visit_id,
                'visitor_name'   => $visitor_name,
                'visitor_mobile' => $visitor_mobile,
                'visitor_photo'  => $photoUrl,
                'company'        => $visitor_address ?: 'General Visitor',
                'purpose'        => $data['purpose'],
                'assets_carried' => $assets,
            ]
        );
        $tlog("FCM push completed for host $host_employee_id");
    } catch (Throwable $e) {
        $tlog("FCM PUSH ERROR: " . $e->getMessage());
    }

    // 2. WhatsApp to Host
    try {
        require_once dirname(__DIR__) . '/../includes/whatsapp_helper.php';
        $hStmt = $pdo->prepare("SELECT mobile, name FROM employees WHERE id = ?");
        $hStmt->execute([$host_employee_id]);
        $hostRow = $hStmt->fetch(PDO::FETCH_ASSOC);
        if ($hostRow && !empty($hostRow['mobile'])) {
            sendWhatsAppNotification(
                $hostRow['mobile'],
                "Visitor $visitor_name has arrived to meet you.",
                'visitor_arrival_host_alert',
                ["*{$hostRow['name']}*", "*{$visitor_name}*", "*{$data['purpose']}*"]
            );
            $tlog("WhatsApp sent to host " . $hostRow['mobile']);
        }
    } catch (Throwable $e) {
        $tlog("WhatsApp ERROR: " . $e->getMessage());
    }

    // 3. QR Code generation
    try {
        if ($visit_code) {
            $ch = curl_init("https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
                                    CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10]);
            $img = curl_exec($ch); curl_close($ch);
            if ($img) {
                $qrDir = dirname(__DIR__) . '/../uploads/qrcodes/';
                if (!is_dir($qrDir)) @mkdir($qrDir, 0777, true);
                file_put_contents($qrDir . $visit_code . '.png', $img);
                $pdo->prepare("UPDATE visits SET qr_code_path=? WHERE id=?")
                    ->execute(['uploads/qrcodes/' . $visit_code . '.png', $visit_id]);
                $tlog("QR code generated for $visit_code");
            }
        }
    } catch (Throwable $e) {
        $tlog("QR ERROR: " . $e->getMessage());
    }

    // 4. Dahua sync (invitation flow only)
    if ($needsDahuaSync) {
        try {
            require_once dirname(__DIR__) . '/../includes/dahua_helper.php';
            $s = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($s['dahua_app_id']) && !empty($s['dahua_app_secret']) && $photo_path) {
                $face = realpath(dirname(__DIR__) . '/../' . $photo_path);
                if ($face) {
                    $dahua = new DahuaHelper($s['dahua_app_id'], $s['dahua_app_secret']);
                    $dahua->syncVisitor([
                        'visitor_id' => $visitor_id, 'name' => $visitor_name, 'face_path' => $face,
                        'qr_code' => $visit_code, 'start_time' => date('Y-m-d H:i:s'),
                        'end_time' => date('Y-m-d 23:59:59'),
                        'device_sns' => array_map('trim', array_filter(explode(',', $s['dahua_device_sns'] ?? '')))
                    ]);
                    $tlog("Dahua sync complete");
                }
            }
        } catch (Throwable $e) {
            $tlog("Dahua ERROR: " . $e->getMessage());
        }
    }

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendResponse('error', 'Database error: ' . $e->getMessage());
}