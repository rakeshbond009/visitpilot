<?php
// api/visit/log.php
require_once '../includes/api_header.php';

try {
    $employee_id = $_GET['employee_id'] ?? null;
    $params = [];

    $sql = "SELECT v.id as visit_id, v.visit_code, v.status as visit_status, v.approval_status, 
                   v.check_in_time, v.check_out_time, v.purpose, v.visit_photo,
                   vis.name as visitor_name, vis.mobile, vis.photo_path,
                   emp.name as host_name
            FROM visits v
            JOIN visitors vis ON v.visitor_id = vis.id
            JOIN employees emp ON v.employee_id = emp.id
            WHERE 1=1";

    if ($employee_id) {
        $sql .= " AND v.employee_id = ?";
        $params[] = $employee_id;
        // For hosts, show pending approvals OR today's history
        $sql .= " AND (v.approval_status = 'pending' OR DATE(v.created_at) = CURDATE())";
    } else {
        // Security/Admin sees today's log
        $sql .= " AND DATE(v.created_at) = CURDATE()";
    }

    $sql .= " ORDER BY v.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as &$log) {
        // Strictly use visit_photo - no fallback to profile photo path
        $log['photo_url'] = !empty($log['visit_photo']) ? BASE_URL . $log['visit_photo'] : BASE_URL . 'assets/img/visitor-icon.png';
    }

    sendResponse('success', 'Daily log retrieved', $logs);
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
