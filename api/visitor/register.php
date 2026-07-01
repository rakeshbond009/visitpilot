<?php
// api/visitor/register.php
require_once '../includes/api_header.php';
require_once '../includes/async_dispatch.php';

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
    // Fetch Approval Matrix setting BEFORE transaction
    $stmtAM = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'approval_matrix'");
    $stmtAM->execute();
    $matrix_on = $stmtAM->fetchColumn();
    $matrix_on = ($matrix_on === false) ? '1' : $matrix_on; // default ON
    $auto_approve = ($matrix_on !== '1');

    $pdo->beginTransaction();

    // 1. Handle Photo Save if provided
    $photo_path = '';
    if (!empty($data['photo_data'])) {
        $photo_data = explode(',', $data['photo_data']);
        if (isset($photo_data[1])) {
            $content = base64_decode($photo_data[1]);
            $filename = 'uploads/photos/' . uniqid() . '.jpg';
            $targetDir = dirname(__DIR__, 2) . '/uploads/photos/';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            file_put_contents(dirname(__DIR__, 2) . '/' . $filename, $content);
            $photo_path = $filename;
        }
    }

    // 2. Check if visitor exists
    $stmt = $pdo->prepare("SELECT id, id_proof_type, id_proof_number FROM visitors WHERE mobile = ?");
    $stmt->execute([$data['mobile']]);
    $visitor = $stmt->fetch();

    $id_proof_type = $data['id_proof_type'] ?? '';
    $id_proof_number = $data['id_proof_number'] ?? '';

    if ($visitor) {
        $visitor_id = $visitor['id'];

        // PRESERVE ID PROOF: keep old one if new request is empty
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
        $stmt->execute([
            $data['name'],
            $data['mobile'],
            $data['email'] ?? '',
            $data['address'] ?? '',
            $id_proof_type,
            $id_proof_number,
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

    $validity_number = intval($data['validity_number'] ?? 8);
    $validity_unit = $data['validity_unit'] ?? 'hours';

    if ($invitation_id) {
        $visit_id = $invitation_id;

        $vStmt = $pdo->prepare("SELECT visit_code FROM visits WHERE id = ?");
        $vStmt->execute([$visit_id]);
        $visit_code = $vStmt->fetchColumn();

        $new_approval = $auto_approve ? 'approved' : 'pending';
        $stmt = $pdo->prepare("UPDATE visits SET visitor_id=?, employee_id=?, purpose=?, status='approved', approval_status=?, check_in_time=NULL, assets_carried=?, id_proof_type=?, id_proof_number=?, access_area=?, visit_photo=?, total_visitors=?, gate_registered_at=?, validity_number=?, validity_unit=?, checked_in_by=? WHERE id=?");
        $stmt->execute([
            $visitor_id,
            $data['employee_id'],
            $data['purpose'],
            $new_approval,
            $assets,
            $id_proof_type,
            $id_proof_number,
            $access_area,
            $photo_path,
            $total_visitors,
            $current_time,
            $validity_number,
            $validity_unit,
            $user_id,
            $visit_id
        ]);
    } else {
        $visit_status = $auto_approve ? 'approved' : 'pending';
        $approval_status = $auto_approve ? 'approved' : 'pending';
        $visit_code = generateVisitCode();
        $current_v_date = date('Y-m-d');
        $stmt = $pdo->prepare("INSERT INTO visits (visitor_id, visit_photo, employee_id, purpose, visit_code, status, approval_status, access_area, assets_carried, id_proof_type, id_proof_number, total_visitors, created_at, created_by, validity_number, validity_unit, visit_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $visitor_id,
            $photo_path,
            $data['employee_id'],
            $data['purpose'],
            $visit_code,
            $visit_status,
            $approval_status,
            $access_area,
            $assets,
            $id_proof_type,
            $id_proof_number,
            $total_visitors,
            $current_time,
            $user_id,
            $validity_number,
            $validity_unit,
            $current_v_date
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

    // Determine if Dahua sync is needed (only for pre-approved invitation flow OR auto-approved walk-ins)
    $needsDahuaSync = false;
    if ($invitation_id || $auto_approve) {
        $needsDahuaSync = true;
    }

    // ✅ COMMIT DB — data is safe before dispatching
    $pdo->commit();

    $bgPayload = [
        'visit_id' => $visit_id,
        'visitor_id' => $visitor_id,
        'visit_code' => $visit_code,
        'photo_path' => $photo_path,
        'employee_id' => $data['employee_id'],
        'purpose' => $data['purpose'],
        'visitor_name' => $visitorRow['name'] ?? $data['name'],
        'visitor_mobile' => $visitorRow['mobile'] ?? $data['mobile'],
        'visitor_address' => $visitorRow['address'] ?? '',
        'assets' => $assets,
        'sync_dahua' => $needsDahuaSync,
        'matrix_on' => $matrix_on, // For background job FCM toggle
    ];

    // ⚡ STEP 1: No-op job writing
    dispatchBackgroundTask('register_visitor', $bgPayload);

    // Audit Log for Mobile Registration
    $newRegData = [
        'visitor' => $visitorRow['name'] ?? $data['name'],
        'purpose' => $data['purpose'],
        'visit_code' => $visit_code,
        'mobile' => $visitorRow['mobile'] ?? $data['mobile']
    ];
    logAction($pdo, $user_id, "Visitor Registered via Mobile: " . ($visitorRow['name'] ?? $data['name']) . " (Visit Code: $visit_code)", null, $newRegData);

    // ⚡ STEP 2: Respond IMMEDIATELY (flush / fastcgi_finish_request)
    sendInstantResponse('success', 'Visitor registered successfully', [
        'visit_id' => $visit_id,
        'visit_code' => $visit_code,
        'status' => ($invitation_id || $auto_approve) ? 'approved' : 'pending',
        'approval_status' => ($invitation_id || $auto_approve) ? 'approved' : 'pending'
    ]);

    // ⚡ STEP 3: Run job synchronously after client disconnects
    runJobInline('register_visitor', $bgPayload, $pdo);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendResponse('error', 'Database error: ' . $e->getMessage());
}