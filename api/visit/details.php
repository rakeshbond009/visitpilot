<?php
// api/visit/details.php
require_once '../includes/api_header.php';

$id = $_GET['id'] ?? 0;

if (!$id) {
    sendResponse('error', 'Missing visit ID');
}

try {
    $sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, 
                   vis.id_proof_type, vis.id_proof_number,
                   emp.name as host_name, emp.department, v.access_area, v.assets_carried
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            JOIN employees emp ON v.employee_id = emp.id 
            WHERE v.id = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($visit) {
        // Fallback to visitor profile photo if this specific visit photo is missing
        $final_photo = !empty($visit['visit_photo']) ? $visit['visit_photo'] : (!empty($visit['photo_path']) ? $visit['photo_path'] : '');
        $visit['photo_url'] = $final_photo ? BASE_URL . $final_photo : null;
        $visit['qr_url'] = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit['visit_code']);

        sendResponse('success', 'Visit details found', $visit);
    } else {
        sendResponse('error', 'Visit record not found');
    }
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
