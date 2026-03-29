<?php
// api/dashboard/reports.php
require_once '../includes/api_header.php';

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');

try {
    // Basic visitor analytics
    $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, emp.name as host_name 
                           FROM visits v 
                           JOIN visitors vis ON v.visitor_id = vis.id 
                           JOIN employees emp ON v.employee_id = emp.id 
                           WHERE DATE(v.created_at) BETWEEN ? AND ?
                           ORDER BY v.created_at DESC");
    $stmt->execute([$from, $to]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse('success', 'Report data retrieved', $results);
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
