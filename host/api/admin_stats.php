<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

// --- CORS & Headers ---
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header('Access-Control-Allow-Credentials: true');
} else {
    header("Access-Control-Allow-Origin: *");
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please login again.']);
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized: Admin access required.']);
    exit;
}

// Stats
$total_emps = $pdo->query("SELECT count(*) FROM employees")->fetchColumn();
$total_visits = $pdo->query("SELECT count(*) FROM visits")->fetchColumn();
$today_visits = $pdo->query("SELECT count(*) FROM visits WHERE date(created_at) = CURDATE()")->fetchColumn();

// Fetch System Settings
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('office_start_hour', 'office_end_hour', 'max_capacity')");
$settings_map = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$max_capacity = (int) ($settings_map['max_capacity'] ?? 50);

// Chart Data: Last 7 Days
$chart_sql = "SELECT DATE_FORMAT(created_at, '%a') as day_name, COUNT(*) as count 
              FROM visits 
              WHERE created_at >= CURDATE() - INTERVAL 6 DAY 
              GROUP BY DATE(created_at) 
              ORDER BY created_at ASC";
$chart_data_raw = $pdo->query($chart_sql)->fetchAll(PDO::FETCH_KEY_PAIR);

$labels = [];
$counts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('D', strtotime("-{$i} days"));
    $labels[] = $date;
    $counts[] = (int) ($chart_data_raw[$date] ?? 0);
}

// AI Prediction
$avg_visits = count($counts) > 0 ? array_sum($counts) / count($counts) : 0;
$prediction_tomorrow = ceil($avg_visits * 1.1);

// AI Security Metrics
$stmt = $pdo->query("SELECT count(*) FROM visits WHERE status = 'checked_in'");
$active_visitors = $stmt->fetchColumn();
$crowd_density = ($max_capacity > 0) ? min(100, round(($active_visitors / $max_capacity) * 100)) : 0;

// Overstay Check
$overstay_sql = "SELECT v.id, vr.name as visitor_name, vr.mobile, v.created_at, e.name as host_name 
                 FROM visits v 
                 LEFT JOIN visitors vr ON v.visitor_id = vr.id
                 LEFT JOIN employees e ON v.employee_id = e.id
                 WHERE v.status = 'checked_in' 
                 AND v.created_at < DATE_SUB(NOW(), INTERVAL 8 HOUR)";
$overstay_stmt = $pdo->query($overstay_sql);
$overstay_list = $overstay_stmt->fetchAll(PDO::FETCH_ASSOC);
$overstay_count = count($overstay_list);

// Zone Density (Department-wise)
$dept_sql = "SELECT COALESCE(e.department, 'Other') as zone, COUNT(*) as count 
             FROM visits v 
             LEFT JOIN employees e ON v.employee_id = e.id 
             WHERE v.status = 'checked_in' 
             GROUP BY zone 
             ORDER BY count DESC";
$dept_zones = $pdo->query($dept_sql)->fetchAll(PDO::FETCH_ASSOC);

// Zone Density (Access Area-wise)
$area_sql = "SELECT COALESCE(access_area, 'Unassigned') as zone, COUNT(*) as count 
             FROM visits v 
             WHERE status = 'checked_in' 
             GROUP BY zone 
             ORDER BY count DESC";
$area_zones = $pdo->query($area_sql)->fetchAll(PDO::FETCH_ASSOC);

// Recent Activity (Latest 10 visits)
$recent_sql = "SELECT v.id, vr.name as visitor_name, v.status, v.created_at, e.name as host_name 
               FROM visits v 
               LEFT JOIN visitors vr ON v.visitor_id = vr.id
               LEFT JOIN employees e ON v.employee_id = e.id 
               ORDER BY v.created_at DESC 
               LIMIT 10";
$recent_activity = $pdo->query($recent_sql)->fetchAll(PDO::FETCH_ASSOC);

// Efficiency Metrics
// Organizational Efficiency (Time Saved)
$completed_visits = $pdo->query("SELECT count(*) FROM visits WHERE status = 'checked_out'")->fetchColumn();
$time_saved_minutes = $completed_visits * 2;
$time_saved_text = $time_saved_minutes . " mins";
if ($time_saved_minutes > 60) {
    $hours = floor($time_saved_minutes / 60);
    $mins = $time_saved_minutes % 60;
    $time_saved_text = "{$hours}h {$mins}m";
}

// 1. Avg Check-in Time (Align with web app: registration to check-in)
$eff_sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, check_in_time)) as avg_seconds 
            FROM visits 
            WHERE status IN ('checked_in', 'checked_out') 
            AND check_in_time IS NOT NULL 
            AND check_in_time > created_at";
$avg_seconds = $pdo->query($eff_sql)->fetchColumn() ?: 0;
$mins_eff = floor($avg_seconds / 60);
$secs_eff = round($avg_seconds % 60);
$avg_checkin_text = "{$mins_eff}m {$secs_eff}s";

// 2. Peak Hour (Align with web app formatting: 12-hour range)
$peak_hour_sql = "SELECT HOUR(created_at) as hr, COUNT(*) as count 
                  FROM visits 
                  WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                  GROUP BY hr 
                  ORDER BY count DESC 
                  LIMIT 1";
$peak_hour_data = $pdo->query($peak_hour_sql)->fetch(PDO::FETCH_ASSOC);
$peak_time = "N/A";
if ($peak_hour_data) {
    $peak_hour = $peak_hour_data['hr'];
    $peak_end = $peak_hour + 1;
    $peak_time = ($peak_hour > 12 ? $peak_hour - 12 : ($peak_hour == 0 ? 12 : $peak_hour)) . ":00 " . ($peak_hour >= 12 ? "PM" : "AM") . " - " .
                 ($peak_end > 12 ? $peak_end - 12 : ($peak_end == 0 ? 12 : $peak_end)) . ":00 " . ($peak_end >= 12 ? "PM" : "AM");
}

// 3. Visitor Satisfaction (from web app)
$total_processed = $pdo->query("SELECT COUNT(*) FROM visits WHERE status IN ('checked_in', 'checked_out')")->fetchColumn();
$satisfaction = 100;
if ($total_processed > 0) {
    $happy_visitors = $pdo->query("SELECT COUNT(*) FROM visits 
                                  WHERE status IN ('checked_in', 'checked_out') 
                                  AND check_in_time IS NOT NULL
                                  AND TIMESTAMPDIFF(MINUTE, created_at, check_in_time) < 10")->fetchColumn();
    $satisfaction = round(($happy_visitors / $total_processed) * 100);
}

// Stats for Detailed Records (All Employees)
$employees_list = $pdo->query("SELECT id, name, department, email, mobile, status FROM employees ORDER BY name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$all_visits_list = $pdo->query("SELECT v.id, vr.name as visitor_name, vr.mobile, v.status, v.created_at, e.name as host_name 
                               FROM visits v 
                               LEFT JOIN visitors vr ON v.visitor_id = vr.id
                               LEFT JOIN employees e ON v.employee_id = e.id 
                               ORDER BY v.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'stats' => [
        'total_employees' => (int) $total_emps,
        'total_visits' => (int) $total_visits,
        'today_visits' => (int) $today_visits,
        'time_saved' => $time_saved_text,
    ],
    'trends' => [
        'labels' => $labels,
        'data' => $counts
    ],
    'ai_insights' => [
        'prediction_tomorrow' => (int) $prediction_tomorrow,
        'crowd_density' => (int) $crowd_density,
        'active_visitors' => (int) $active_visitors,
        'overstay_count' => (int) $overstay_count,
        'overstay_list' => $overstay_list
    ],
    'efficiency' => [
        'avg_checkin_time' => $avg_checkin_text,
        'peak_hour' => $peak_time,
        'total_time_saved' => $time_saved_text,
        'satisfaction' => $satisfaction . "%"
    ],
    'recent_activity' => $recent_activity,
    'zones' => [
        'department' => $dept_zones,
        'access_area' => $area_zones
    ],
    'records' => [
        'employees' => $employees_list,
        'visits' => $all_visits_list
    ]
]);
