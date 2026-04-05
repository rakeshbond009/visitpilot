<?php
require_once '../includes/api_header.php';

$query = sanitize($_GET['query'] ?? '');

if (empty($query)) {
    sendResponse('error', 'Query is empty');
}

try {
    // Search by mobile or visit code
    $sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.email, vis.address, 
                   COALESCE(NULLIF(v.id_proof_type, ''), vis.id_proof_type) as id_proof_type,
                   COALESCE(NULLIF(v.id_proof_number, ''), vis.id_proof_number) as id_proof_number,
                   emp.name as host_name, emp.department as host_dept, emp.id as host_id, v.visit_date
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            JOIN employees emp ON v.employee_id = emp.id 
            WHERE (v.visit_code = ? OR vis.mobile = ?) 
              AND v.is_invited = 1
            ORDER BY v.created_at DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$query, $query]);
    $invitation = $stmt->fetch();

    if ($invitation) {
        $scheduled_date = date('Y-m-d', strtotime($invitation['visit_date']));
        $today = date('Y-m-d');

        if ($invitation['status'] === 'canceled') {
            sendResponse('error', 'This meeting has been canceled by the host.');
        } elseif ($scheduled_date < $today) {
            sendResponse('error', 'This invitation has expired (Scheduled Date: ' . date('d-M-Y', strtotime($invitation['visit_date'])) . '). Please ask for a new invite.');
        } elseif (!in_array($invitation['status'], ['pending', 'approved'])) {
            sendResponse('error', 'This invitation code has already been used or is inactive.');
        } else {
            sendResponse('success', 'Invitation found', $invitation);
        }
    } else {
        sendResponse('error', 'No active invitation found for this code or number.');
    }

} catch (PDOException $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
?>