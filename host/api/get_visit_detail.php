<?php
// host/api/get_visit_detail.php
require_once '../../includes/db.php';
require_once '../../includes/api_header.php';

$visit_id = $_GET['id'] ?? 0;

if (!$visit_id) {
    sendResponse('error', 'Missing visit ID');
}

try {
    $stmt = $pdo->prepare("
        SELECT v.*, 
               vis.name as visitor_name, vis.mobile as visitor_mobile, vis.address as visitor_company,
               e.name as host_name, e.department as department
        FROM visits v 
        JOIN visitors vis ON v.visitor_id = vis.id 
        LEFT JOIN employees e ON v.employee_id = e.id 
        WHERE v.id = ?
    ");
    $stmt->execute([$visit_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($visit) {
        sendResponse('success', 'Visit found', ['visit' => $visit]);
    } else {
        sendResponse('error', 'Visit not found');
    }
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
