<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

$visit_id = $_GET['visit_id'] ?? 0;

if (!$visit_id) {
    echo json_encode(['success' => false, 'message' => 'Missing visit ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            v.*, 
            vis.fullname as visitor_name, 
            vis.mobile as visitor_mobile,
            vis.photo as visitor_photo,
            vis.address as visitor_address,
            h.fullname as host_name,
            d.name as department_name
        FROM visits v
        JOIN visitors vis ON v.visitor_id = vis.id
        LEFT JOIN users h ON v.host_id = h.id
        LEFT JOIN departments d ON h.dept_id = d.id
        WHERE v.id = ?
    ");
    $stmt->execute([$visit_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($visit) {
        echo json_encode(['success' => true, 'data' => $visit]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Visit not found']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
