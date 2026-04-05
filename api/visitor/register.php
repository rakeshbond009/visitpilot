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

    // ✅ COMMIT DB — data is safe before dispatching
    $pdo->commit();

    // ⚡ STEP 1: Dispatch background job FIRST (before responding)
    //    dispatchBackgroundTask writes a job file & fires a non-blocking cURL
    //    to background_worker.php — client does NOT wait for any of this
    dispatchBackgroundTask('register_visitor', [
        'visit_id'       => $visit_id,
        'visitor_id'     => $visitor_id,
        'visit_code'     => $visit_code,
        'photo_path'     => $photo_path,
        'employee_id'    => $data['employee_id'],
        'purpose'        => $data['purpose'],
        'visitor_name'   => $visitorRow['name']    ?? $data['name'],
        'visitor_mobile' => $visitorRow['mobile']  ?? $data['mobile'],
        'visitor_address'=> $visitorRow['address'] ?? '',
        'assets'         => $assets,
        'sync_dahua'     => $needsDahuaSync,
    ]);

    // ⚡ STEP 2: Respond instantly — sendInstantResponse calls exit() after sending
    sendInstantResponse('success', 'Visitor registered successfully', [
        'visit_id'        => $visit_id,
        'visit_code'      => $visit_code,
        'status'          => $invitation_id ? 'approved' : 'pending',
        'approval_status' => $invitation_id ? 'approved' : 'pending'
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendResponse('error', 'Database error: ' . $e->getMessage());
}