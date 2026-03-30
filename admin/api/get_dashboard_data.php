<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

// Set Timezone to IST
date_default_timezone_set('Asia/Kolkata');
$pdo->exec("SET time_zone = '+05:30'");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// Check Permission
$is_admin = ($_SESSION['role'] === 'admin');
$stmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ? AND permission_key = 'admin_dashboard'");
$stmt->execute([$_SESSION['user_id']]);
$has_perm = $stmt->fetchColumn();

if (!$is_admin && !$has_perm) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Helper for safe fetching
function safeFetchColumn($pdo, $sql, $default = 0)
{
    try {
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchColumn() : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function safeFetchAll($pdo, $sql, $mode = PDO::FETCH_ASSOC)
{
    try {
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll($mode) : [];
    } catch (PDOException $e) {
        return [];
    }
}

// stats
$total_today = safeFetchColumn($pdo, "SELECT count(*) FROM visits WHERE date(created_at) = CURDATE()");
$active_visitors = (int) safeFetchColumn($pdo, "SELECT count(*) FROM visits WHERE status = 'checked_in'");

$stats = [
    'total_emps' => safeFetchColumn($pdo, "SELECT count(*) FROM employees"),
    'total_visits' => safeFetchColumn($pdo, "SELECT count(*) FROM visits"),
    'today_visits' => $total_today,
    'active' => $active_visitors
];

// AI Metrics Calculation
$max_capacity = (int) safeFetchColumn($pdo, "SELECT setting_value FROM system_settings WHERE setting_key = 'max_capacity'") ?: 50;
$crowd_density = ($max_capacity > 0) ? min(100, round(($active_visitors / $max_capacity) * 100)) : 0;

$avg_sql = "SELECT AVG(ABS(TIMESTAMPDIFF(SECOND, created_at, check_in_time))) 
            FROM visits 
            WHERE status IN ('checked_in', 'checked_out') 
            AND check_in_time IS NOT NULL";
$avg_seconds = safeFetchColumn($pdo, $avg_sql) ?: 0;
$mins = floor($avg_seconds / 60);
$secs = round($avg_seconds % 60);
$avg_display = "{$mins}m {$secs}s";

$overstays_sql = "SELECT v.*, vis.name as visitor_name, emp.name as host_name, emp.department
                  FROM visits v 
                  JOIN visitors vis ON v.visitor_id = vis.id 
                  LEFT JOIN employees emp ON v.employee_id = emp.id 
                  WHERE v.status = 'checked_in' 
                  AND v.check_in_time < DATE_SUB(NOW(), INTERVAL 8 HOUR) 
                  ORDER BY v.check_in_time ASC";
$overstays_list = safeFetchAll($pdo, $overstays_sql);
$overstays_count = count($overstays_list);

// Zone Density (Department-wise) - UNIQUE DEPARTMENTS
$dept_sql = "SELECT COALESCE(e.department, 'Other') as name, COUNT(v.id) as count 
             FROM visits v 
             LEFT JOIN employees e ON v.employee_id = e.id 
             WHERE v.status = 'checked_in' 
             GROUP BY name 
             ORDER BY count DESC";
$dept_zones_raw = safeFetchAll($pdo, $dept_sql);
$dept_zones = array_map(function ($z) use ($max_capacity) {
    $z['density'] = $max_capacity > 0 ? round(($z['count'] / $max_capacity) * 100) : 0;
    return $z;
}, $dept_zones_raw);

// Zone Density (Access Area-wise) - UNIQUE AREAS
$area_sql = "SELECT COALESCE(access_area, 'Unassigned') as name, COUNT(id) as count 
             FROM visits 
             WHERE status = 'checked_in' 
             GROUP BY name 
             ORDER BY count DESC";
$area_zones_raw = safeFetchAll($pdo, $area_sql);
$area_zones = array_map(function ($z) use ($max_capacity) {
    $z['density'] = $max_capacity > 0 ? round(($z['count'] / $max_capacity) * 100) : 0;
    return $z;
}, $area_zones_raw);

$zones = [
    'department' => $dept_zones,
    'access_area' => $area_zones
];

// Peak & Best Slot
$peak_sql = "SELECT HOUR(created_at) as h, COUNT(*) as c FROM visits WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY h ORDER BY c DESC LIMIT 1";
$peak_hour = safeFetchColumn($pdo, $peak_sql) ?: 11;
$peak_end = $peak_hour + 1;
$peak_time = ($peak_hour > 12 ? $peak_hour - 12 : $peak_hour) . ":00 " . ($peak_hour >= 12 ? "PM" : "AM") . " - " .
    ($peak_end > 12 ? $peak_end - 12 : $peak_end) . ":00 " . ($peak_end >= 12 ? "PM" : "AM");

// Get Office Hours for calculation
$settings_map = [];
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('office_start_hour', 'office_end_hour')");
    if ($settings_stmt) {
        $settings_map = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (PDOException $e) {
}

$start_h = (int) ($settings_map['office_start_hour'] ?? 8);
$end_h = (int) ($settings_map['office_end_hour'] ?? 18);

// Construct dynamic hours union for SQL
$hours_array = [];
for ($h = $start_h; $h <= $end_h; $h++) {
    $hours_array[] = "SELECT $h as hour";
}
if (empty($hours_array)) {
    $hours_array[] = "SELECT 8 as hour UNION SELECT 9 as hour UNION SELECT 10 as hour UNION SELECT 11 as hour UNION SELECT 12 as hour UNION SELECT 13 as hour UNION SELECT 14 as hour UNION SELECT 15 as hour UNION SELECT 16 as hour UNION SELECT 17 as hour UNION SELECT 18 as hour";
}
$hours_union = implode(" UNION ", $hours_array);

$slot_sql = "SELECT h.hour, COALESCE(COUNT(v.id), 0) as c 
             FROM ($hours_union) h 
             LEFT JOIN visits v ON HOUR(v.created_at) = h.hour AND v.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
             GROUP BY h.hour ORDER BY c ASC LIMIT 1";
$best_hour = safeFetchColumn($pdo, $slot_sql, $start_h);
$best_time = ($best_hour > 12 ? $best_hour - 12 : $best_hour) . ":00 " . ($best_hour >= 12 ? "PM" : "AM");

// Recent Activity
$recent_sql = "SELECT v.*, vis.name as visitor_name, e.name as host_name, e.department
               FROM visits v 
               JOIN visitors vis ON v.visitor_id = vis.id 
               LEFT JOIN employees e ON v.employee_id = e.id 
               ORDER BY v.created_at DESC LIMIT 10";
$recent = safeFetchAll($pdo, $recent_sql);

echo json_encode([
    'success' => true,
    'stats' => $stats,
    'recent' => $recent,
    'ai_metrics' => [
        'crowd_density' => $crowd_density,
        'avg_checkin_time' => $avg_display,
        'overstays_count' => $overstays_count,
        'overstays_list' => $overstays_list,
        'zones' => $zones,
        'peak_time' => $peak_time,
        'best_time' => $best_time,
        'max_capacity' => $max_capacity
    ]
]);
