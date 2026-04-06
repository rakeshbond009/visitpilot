<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['host', 'employee', 'admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get host's employee ID
$stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$host_employee_id = $stmt->fetchColumn();

if (!$host_employee_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid host']);
    exit;
}

// Pending Visitors List
$sql_pending = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, e.department, u.full_name as created_by_name
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                LEFT JOIN users u ON v.created_by = u.id
                WHERE v.employee_id = ? AND v.approval_status = 'pending'
                ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_pending);
$stmt->execute([$host_employee_id]);
$pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Today's Walk-in Visitors
$sql_today = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, v.photo_path, e.department, u.full_name as created_by_name
              FROM visits v 
              JOIN visitors vis ON v.visitor_id = vis.id 
              LEFT JOIN employees e ON v.employee_id = e.id
              LEFT JOIN users u ON v.created_by = u.id
              WHERE v.employee_id = ? AND (DATE(v.created_at) = CURDATE() OR v.visit_date = CURDATE()) AND v.status IN ('approved', 'checked_in', 'checked_out')
              ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_today);
$stmt->execute([$host_employee_id]);
$today_visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active Invitations
$sql_invites = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, v.photo_path, e.department, u.full_name as created_by_name
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                LEFT JOIN users u ON v.created_by = u.id
                WHERE v.employee_id = ? AND v.is_invited = 1 AND v.status = 'pending' AND v.visit_date >= CURDATE()
                ORDER BY v.visit_date ASC";
$stmt = $pdo->prepare($sql_invites);
$stmt->execute([$host_employee_id]);
$active_invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Productivity Stats
// 1. Meetings Completed (All Time)
$meetings_sql = "SELECT COUNT(*) FROM visits 
                 WHERE employee_id = ? 
                 AND status = 'checked_out'";
$stmt = $pdo->prepare($meetings_sql);
$stmt->execute([$host_employee_id]);
$meetings_completed = (int) $stmt->fetchColumn();

// 2. Avg Meeting Time (All Time)
$duration_sql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)) 
                 FROM visits 
                 WHERE employee_id = ? 
                 AND status = 'checked_out' 
                 AND check_in_time IS NOT NULL AND check_out_time IS NOT NULL";
$stmt = $pdo->prepare($duration_sql);
$stmt->execute([$host_employee_id]);
$avg_minutes = (int) $stmt->fetchColumn();

// 3. Rejected Visits
$stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND status IN ('rejected', 'cancelled')");
$stmt->execute([$host_employee_id]);
$rejected_count = (int) $stmt->fetchColumn();

$sql_rejected = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, e.department, u.full_name as created_by_name
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                LEFT JOIN users u ON v.created_by = u.id
                WHERE v.employee_id = ? AND v.status IN ('rejected', 'cancelled')
                ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_rejected);
$stmt->execute([$host_employee_id]);
$rejected_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. AI Best Slot
$traffic_sql = "SELECT HOUR(created_at) as hour, COUNT(*) as count 
                FROM visits 
                WHERE created_at >= CURDATE() - INTERVAL 30 DAY 
                GROUP BY HOUR(created_at)";
$traffic_raw = $pdo->query($traffic_sql)->fetchAll(PDO::FETCH_KEY_PAIR);

$best_slot = 10;
$min_traffic = 9999;
for ($h = 10; $h <= 21; $h++) {
    $count = $traffic_raw[$h] ?? 0;
    if ($count < $min_traffic) {
        $min_traffic = $count;
        $best_slot = $h;
    }
}
$best_slot_formatted = ($best_slot > 12) ? ($best_slot - 12) . " PM" : $best_slot . " AM";

// 5. Visitor Insights (Chart Data)
$purpose_sql = "SELECT purpose, COUNT(*) as count FROM visits WHERE employee_id = ? GROUP BY purpose ORDER BY count DESC";
$purpose_stmt = $pdo->prepare($purpose_sql);
$purpose_stmt->execute([$host_employee_id]);
$insights = $purpose_stmt->fetchAll(PDO::FETCH_ASSOC);

// 6. Frequent Visitors
$frequent_sql = "SELECT vis.name, COUNT(*) as visit_count, MAX(v.created_at) as last_visit, vis.photo_path
                 FROM visits v
                 JOIN visitors vis ON v.visitor_id = vis.id
                 WHERE v.employee_id = ?
                 GROUP BY v.visitor_id
                 HAVING visit_count > 1
                 ORDER BY visit_count DESC, last_visit DESC LIMIT 3";
$stmt = $pdo->prepare($frequent_sql);
$stmt->execute([$host_employee_id]);
$frequent_visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'pending_count' => count($pending_list),
    'today_count' => count($today_visitors),
    'invite_count' => count($active_invites),
    'pending_list' => $pending_list,
    'today_visitors' => $today_visitors,
    'active_invites' => $active_invites,
    'visitors' => $today_visitors,
    'latest_pending' => !empty($pending_list) ? $pending_list[0] : null,
    'completed_meetings' => $meetings_completed,
    'avg_meeting_time' => $avg_minutes . "m",
    'rejected_count' => $rejected_count,
    'rejected_list' => $rejected_list,
    'best_time' => $best_slot_formatted,
    'insights' => $insights,
    'frequent_visitors' => $frequent_visitors
]);
