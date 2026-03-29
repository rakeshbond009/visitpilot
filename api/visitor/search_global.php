<?php
// api/visitor/search_global.php
require_once '../includes/api_header.php';

$query = isset($_GET['q']) ? $_GET['q'] : '';

if (empty($query)) {
    sendResponse('error', 'Search query required');
}

try {
    $search = "%$query%";
    // Search in visitors and their visits
    $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, emp.name as host_name 
                           FROM visits v 
                           JOIN visitors vis ON v.visitor_id = vis.id 
                           JOIN employees emp ON v.employee_id = emp.id 
                           WHERE vis.name LIKE ? OR vis.mobile LIKE ? OR v.visit_code LIKE ?
                           ORDER BY v.created_at DESC LIMIT 20");
    $stmt->execute([$search, $search, $search]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse('success', 'Search results retrieved', $results);
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
