<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

date_default_timezone_set('Asia/Kolkata');
$pdo->exec("SET time_zone = '+05:30'");

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['host', 'employee', 'admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get host's employee ID
$stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$host_employee_id = $stmt->fetchColumn();

$is_admin = ($_SESSION['role'] === 'admin');

if (!$host_employee_id && !$is_admin) {
    echo json_encode(['success' => false, 'error' => 'Invalid host']);
    exit;
}

// Pending Visitors List
$sql_pending = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND v.approval_status = 'pending'
                ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_pending);
if ($is_admin) {
    $stmt->execute();
} else {
    $stmt->execute([$host_employee_id]);
}
$pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Today's Visitors
$sql_today = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department
              FROM visits v 
              JOIN visitors vis ON v.visitor_id = vis.id 
              LEFT JOIN employees e ON v.employee_id = e.id
              WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND DATE(v.created_at) = CURDATE()
              ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_today);
if ($is_admin) {
    $stmt->execute();
} else {
    $stmt->execute([$host_employee_id]);
}
$today_visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active Invitations
$sql_invites = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND v.is_invited = 1 AND (v.status = 'pending' OR v.status = 'approved') AND v.visit_date >= CURDATE()
                ORDER BY v.visit_date ASC";
$stmt = $pdo->prepare($sql_invites);
if ($is_admin) {
    $stmt->execute();
} else {
    $stmt->execute([$host_employee_id]);
}
$active_invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Productivity Stats
// 1. Meetings Completed (All Time)
$meetings_sql = "SELECT COUNT(*) FROM visits 
                 WHERE employee_id = ? 
                 AND status = 'checked_out'";
$stmt = $pdo->prepare($meetings_sql);
$stmt->execute([$host_employee_id]);
$meetings_completed = (int)$stmt->fetchColumn();

// 2. Avg Meeting Time (All Time)
$duration_sql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)) 
                 FROM visits 
                 WHERE employee_id = ? 
                 AND status = 'checked_out' 
                 AND check_in_time IS NOT NULL AND check_out_time IS NOT NULL";
$stmt = $pdo->prepare($duration_sql);
$stmt->execute([$host_employee_id]);
$avg_minutes = (int)$stmt->fetchColumn();

// 3. Check-in Pending for Today (Approved but not yet checked in)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND status = 'approved' AND (DATE(created_at) = CURDATE() OR (is_invited = 1 AND visit_date = CURDATE()))");
$stmt->execute([$host_employee_id]);
$scheduled_today = (int)$stmt->fetchColumn();

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
                 WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . "
                 GROUP BY v.visitor_id
                 HAVING visit_count > 1
                 ORDER BY visit_count DESC, last_visit DESC LIMIT 3";
$stmt = $pdo->prepare($frequent_sql);
if ($is_admin) {
    $stmt->execute();
} else {
    $stmt->execute([$host_employee_id]);
}
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
    'scheduled_today' => $scheduled_today,
    'best_time' => $best_slot_formatted,
    'insights' => $insights,
    'frequent_visitors' => $frequent_visitors
]);
