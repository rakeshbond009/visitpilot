<?php
// api/visitor/search.php
require_once '../includes/api_header.php';

$mobile = $_GET['mobile'] ?? '';

if (empty($mobile)) {
    sendResponse('error', 'Mobile number is required');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM visitors WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $visitor = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($visitor) {
        // Convert photo path to full URL using BASE_URL
        if ($visitor['photo_path']) {
            $visitor['photo_url'] = BASE_URL . $visitor['photo_path'];
        }

        // Fetch last visit details
        $stmtHistory = $pdo->prepare("
            SELECT DATE_FORMAT(v.created_at, '%d-%b-%Y %h:%i %p') as check_in_time, v.purpose, e.name as host_name, v.employee_id 
            FROM visits v 
            LEFT JOIN employees e ON v.employee_id = e.id 
            WHERE v.visitor_id = ? 
            ORDER BY v.id DESC LIMIT 1
        ");
        $stmtHistory->execute([$visitor['id']]);
        $last_visit = $stmtHistory->fetch(PDO::FETCH_ASSOC);

        if ($last_visit) {
            $visitor['last_visit'] = $last_visit;
        }
        sendResponse('success', 'Visitor found', $visitor);
    } else {
        sendResponse('error', 'Visitor not found');
    }
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
