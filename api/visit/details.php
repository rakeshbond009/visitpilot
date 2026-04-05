<?php
// api/visit/details.php
require_once '../includes/api_header.php';

$id = $_GET['id'] ?? 0;

if (!$id) {
    sendResponse('error', 'Missing visit ID');
}

try {
    $sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, 
                   COALESCE(NULLIF(v.id_proof_type, ''), vis.id_proof_type) as id_proof_type,
                   COALESCE(NULLIF(v.id_proof_number, ''), vis.id_proof_number) as id_proof_number,
                   emp.name as host_name, emp.department, v.access_area, v.assets_carried,
                   u1.full_name as created_by_name,
                   u2.full_name as approved_by_name,
                   u3.full_name as checked_in_by_name,
                   u4.full_name as checked_out_by_name
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            JOIN employees emp ON v.employee_id = emp.id 
            LEFT JOIN users u1 ON v.created_by = u1.id
            LEFT JOIN users u2 ON v.approved_by = u2.id
            LEFT JOIN users u3 ON v.checked_in_by = u3.id
            LEFT JOIN users u4 ON v.checked_out_by = u4.id
            WHERE v.id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($visit) {
        $v_photo = str_replace('../', '', $visit['visit_photo']);
        $p_photo = str_replace('../', '', $visit['photo_path']);
        $final_photo = !empty($v_photo) ? $v_photo : (!empty($p_photo) ? $p_photo : '');
        $visit['photo_url'] = $final_photo ? BASE_URL . $final_photo : null;
        $visit['visit_photo'] = $v_photo;
        $visit['photo_path'] = $p_photo;
        $visit['qr_url'] = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit['visit_code']);

        sendResponse('success', 'Visit details found', $visit);
    } else {
        sendResponse('error', 'Visit record not found');
    }
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
