<?php
require_once '../../includes/api_header.php';
require_once '../../includes/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    sendResponse('error', 'Unauthorized', null, 401);
}

$host_employee_id = $_SESSION['employee_id'] ?? null;
if (!$host_employee_id) {
    // Try to fetch it if session is missing it
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $host_employee_id = $stmt->fetchColumn();
}

if (!$host_employee_id) {
    sendResponse('error', 'Invalid host profile', null, 400);
}

$is_admin = ($_SESSION['role'] === 'admin');

// 1. Stats
// Pending approvals
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE approval_status = 'pending'");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND approval_status = 'pending'");
    $stmt->execute([$host_employee_id]);
}
$pending_count = (int)$stmt->fetchColumn();

// Walk-ins today
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE DATE(created_at) = CURDATE() AND is_invited = 0");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND DATE(created_at) = CURDATE() AND is_invited = 0");
    $stmt->execute([$host_employee_id]);
}
$today_count = (int)$stmt->fetchColumn();

// Active invitations
if ($is_admin) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE is_invited = 1 AND status IN ('pending', 'approved') AND visit_date >= CURDATE()");
    $stmt->execute();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND is_invited = 1 AND status IN ('pending', 'approved') AND visit_date >= CURDATE()");
    $stmt->execute([$host_employee_id]);
}
$invite_count = (int)$stmt->fetchColumn();

// 2. Lists
// Pending visits list
$sql_pending = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, vis.email, e.name as host_name
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND v.approval_status = 'pending'
                ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_pending);
if ($is_admin) $stmt->execute(); else $stmt->execute([$host_employee_id]);
$pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Today's visitors list
$sql_today = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, vis.email, e.name as host_name
              FROM visits v 
              JOIN visitors vis ON v.visitor_id = vis.id 
              LEFT JOIN employees e ON v.employee_id = e.id
              WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND DATE(v.created_at) = CURDATE()
              ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_today);
if ($is_admin) $stmt->execute(); else $stmt->execute([$host_employee_id]);
$today_visitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Active invites list
$sql_invites = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, vis.email, e.name as host_name
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND v.is_invited = 1 AND v.status IN ('pending', 'approved') AND v.visit_date >= CURDATE()
                ORDER BY v.visit_date ASC";
$stmt = $pdo->prepare($sql_invites);
if ($is_admin) $stmt->execute(); else $stmt->execute([$host_employee_id]);
$active_invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// AI Best Slot (same logic as web)
try {
    $traffic_sql = "SELECT HOUR(created_at) as hour, COUNT(*) as count 
                    FROM visits 
                    WHERE created_at >= CURDATE() - INTERVAL 30 DAY 
                    GROUP BY HOUR(created_at)";
    $traffic_stmt = $pdo->query($traffic_sql);
    $traffic_raw = $traffic_stmt ? $traffic_stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    
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
} catch (Exception $e) {
    $best_slot_formatted = "10 AM";
    $best_slot = 10;
}

sendResponse('success', 'Dashboard data fetched', [
    'stats' => [
        'pending' => $pending_count,
        'today' => $today_count,
        'invites' => $invite_count
    ],
    'lists' => [
        'pending' => $pending_list,
        'today' => $today_visitors,
        'invites' => $active_invites
    ],
    'ai_suggestion' => [
        'best_slot' => $best_slot_formatted,
        'hour' => $best_slot
    ]
]);
