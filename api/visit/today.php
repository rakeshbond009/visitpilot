<?php
require_once '../includes/api_header.php';

try {
    $stmt = $pdo->query("SELECT v.*, v.visit_photo,
                                vis.name as visitor_name, 
                                vis.mobile,
                                vis.photo_path,
                                e.name as host_name,
                                e.department
                         FROM visits v
                         JOIN visitors vis ON v.visitor_id = vis.id
                         LEFT JOIN employees e ON v.employee_id = e.id
                         WHERE DATE(v.created_at) = CURDATE()
                         ORDER BY v.created_at DESC");
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse('success', 'Today\'s visits retrieved', ['visits' => $visits]);
}
catch (Exception $e) {
    sendResponse('error', 'Failed to fetch today\'s visits: ' . $e->getMessage());
}
