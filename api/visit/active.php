<?php
// api/visit/active.php
require_once '../includes/api_header.php';

try {
    $stmt = $pdo->prepare("
        SELECT 
            v.id as visit_id,
            v.visit_photo,
            vis.name as visitor_name,
            vis.mobile,
            vis.photo_path,
            e.name as employee_name,
            v.purpose,
            v.check_in_time,
            v.status
        FROM visits v
        JOIN visitors vis ON v.visitor_id = vis.id
        JOIN employees e ON v.employee_id = e.id
        WHERE v.status = 'checked_in'
        ORDER BY v.check_in_time DESC
    ");
    $stmt->execute();
    $active_visitors = $stmt->fetchAll();

    foreach ($active_visitors as &$visitor) {
        // Strictly use visit_photo - no fallback to profile photo path
        if (!empty($visitor['visit_photo'])) {
            $visitor['photo_url'] = BASE_URL . $visitor['visit_photo'];
        } else {
            $visitor['photo_url'] = BASE_URL . 'assets/img/visitor-icon.png';
        }
    }

    sendResponse('success', 'Active visitors retrieved', $active_visitors);
} catch (PDOException $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
