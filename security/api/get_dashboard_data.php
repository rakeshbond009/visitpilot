<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

// Set Timezone to IST
date_default_timezone_set('Asia/Kolkata');
$pdo->exec("SET time_zone = '+05:30'");

// Basic Role Check
$is_admin = ($_SESSION['role'] === 'admin');
$is_security = ($_SESSION['role'] === 'security');
$is_host = ($_SESSION['role'] === 'host' || $_SESSION['role'] === 'employee');

if (!isset($_SESSION['user_id']) || (!$is_admin && !$is_security && !$is_host)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Force Host Restriction
$limit_employee_id = null;
if ($is_host) {
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $limit_employee_id = $stmt->fetchColumn();
}

try {
    function standardize_list(&$list) {
        foreach ($list as &$v) {
            $v_photo = !empty($v['visit_photo'] ?? '') ? str_replace('../', '', $v['visit_photo']) : '';
            $p_photo = !empty($v['photo_path'] ?? '') ? str_replace('../', '', $v['photo_path']) : '';
            
            $v['visit_photo'] = $v_photo ? BASE_URL . $v_photo : null;
            $v['photo_path'] = $p_photo ? BASE_URL . $p_photo : null;
            $v['photo_url'] = $v['visit_photo'] ?: $v['photo_path'];
        }
    }
    $where = "WHERE (DATE(v.created_at) = CURDATE() 
               OR v.status = 'checked_in' 
               OR v.approval_status = 'pending'
               OR DATE(v.approved_at) = CURDATE()
               OR (v.approval_status = 'approved' AND v.status = 'pending' AND (DATE(v.created_at) = CURDATE() OR (v.is_invited=1 AND v.visit_date = CURDATE()))))";
    $params = [];

    if ($limit_employee_id) {
        $where .= " AND v.employee_id = ?";
        $params[] = $limit_employee_id;
    }

    $sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, emp.name as host_name, emp.department,
                   REPLACE(v.visit_photo, '../', '') as visit_photo,
                   REPLACE(vis.photo_path, '../', '') as photo_path,
                   COALESCE(NULLIF(REPLACE(v.visit_photo, '../', ''), ''), REPLACE(vis.photo_path, '../', '')) as photo_url 
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN employees emp ON v.employee_id = emp.id 
            $where 
            ORDER BY v.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    standardize_list($visits);

    // Also get stats
    $active_visitors = (int) $pdo->query("SELECT count(*) FROM visits WHERE status = 'checked_in'")->fetchColumn();

    // Fetch System Settings for Peak/Best Slot
    $settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('office_start_hour', 'office_end_hour')");
    $settings_map = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $start_h = (int) ($settings_map['office_start_hour'] ?? 8);
    $end_h = (int) ($settings_map['office_end_hour'] ?? 18);
    if ($start_h <= 0)
        $start_h = 8;
    if ($end_h <= 0)
        $end_h = 18;

    // Also get stats
    $active_sql = "SELECT count(*) FROM visits WHERE status = 'checked_in'";
    if ($limit_employee_id)
        $active_sql .= " AND employee_id = " . $pdo->quote($limit_employee_id);
    $active_visitors = (int) $pdo->query($active_sql)->fetchColumn();

    $completed_sql = "SELECT count(*) FROM visits WHERE status = 'checked_out'";
    if ($limit_employee_id)
        $completed_sql .= " AND employee_id = " . $pdo->quote($limit_employee_id);
    $completed_count = (int) $pdo->query($completed_sql)->fetchColumn();

    $time_saved_min = $completed_count * 2;
    $time_saved_fmt = $time_saved_min . " mins";
    if ($time_saved_min > 60) {
        $h_saved = floor($time_saved_min / 60);
        $m_saved = $time_saved_min % 60;
        $time_saved_fmt = "{$h_saved}h {$m_saved}m";
    }

    $today_sql = "SELECT count(*) FROM visits WHERE (DATE(created_at) = CURDATE() OR (is_invited=1 AND visit_date = CURDATE()))";
    if ($limit_employee_id)
        $today_sql .= " AND employee_id = " . $pdo->quote($limit_employee_id);

    $pending_sql = "SELECT count(*) FROM visits WHERE approval_status = 'pending'";
    if ($limit_employee_id)
        $pending_sql .= " AND employee_id = " . $pdo->quote($limit_employee_id);

    // 3. Check-in Pending for Today (Approved but not yet checked in)
    $sql_scheduled = "SELECT v.*, vis.name as visitor_name, vis.mobile, e.name as host_name, e.department,
                             REPLACE(v.visit_photo, '../', '') as visit_photo,
                             REPLACE(vis.photo_path, '../', '') as photo_path,
                             REPLACE(vis.photo_path, '../', '') as visitor_photo,
                             COALESCE(NULLIF(REPLACE(v.visit_photo, '../', ''), ''), REPLACE(vis.photo_path, '../', '')) as photo_url
                      FROM visits v 
                      JOIN visitors vis ON v.visitor_id = vis.id 
                      LEFT JOIN employees e ON v.employee_id = e.id
                      WHERE v.approval_status = 'approved' AND v.status = 'pending' 
                      AND (DATE(v.created_at) <= CURDATE() OR (v.is_invited = 1 AND v.visit_date <= CURDATE()))";
    
    if ($limit_employee_id) {
        $sql_scheduled .= " AND v.employee_id = " . $pdo->quote($limit_employee_id);
    }
    
    $sql_scheduled .= " ORDER BY v.created_at DESC";
    $stmt_scheduled = $pdo->query($sql_scheduled);
    $scheduled_list = $stmt_scheduled ? $stmt_scheduled->fetchAll(PDO::FETCH_ASSOC) : [];
    standardize_list($scheduled_list);

    $scheduled_today_count = count($scheduled_list);

    // 4. Fast Service Rate (Checked in within 30 mins)
    $total_processed = (int) $pdo->query("SELECT COUNT(*) FROM visits WHERE status IN ('checked_in', 'checked_out') AND DATE(check_in_time) = CURDATE()")->fetchColumn();
    $fast_checkins = (int) $pdo->query("SELECT COUNT(*) FROM visits 
                                  WHERE status IN ('checked_in', 'checked_out') 
                                  AND check_in_time IS NOT NULL
                                  AND DATE(check_in_time) = CURDATE()
                                  AND TIMESTAMPDIFF(MINUTE, created_at, check_in_time) < 30")->fetchColumn();
    $fast_service_rate = ($total_processed > 0) ? (int)round(($fast_checkins / $total_processed) * 100) : 100;

    $stats = [
        'total_today' => (int) $pdo->query($today_sql)->fetchColumn(),
        'active' => $active_visitors,
        'pending' => (int) $pdo->query($pending_sql)->fetchColumn(),
        'checkin_pending' => $scheduled_today_count,
        'time_saved_fmt' => (string) $time_saved_fmt,
        'fast_service_rate' => $fast_service_rate
    ];

    // AI Metrics Calculation (Same logic as initial load)
    $max_capacity = (int) $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'max_capacity'")->fetchColumn() ?: 50;
    $crowd_density = ($max_capacity > 0) ? min(100, round(($active_visitors / $max_capacity) * 100)) : 0;

    $avg_sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, check_in_time)) 
                FROM visits 
                WHERE status IN ('checked_in', 'checked_out') 
                AND check_in_time IS NOT NULL 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    $avg_seconds = $pdo->query($avg_sql)->fetchColumn() ?: 45;
    $avg_display = ($avg_seconds < 60) ? round($avg_seconds) . "s" : round($avg_seconds / 60) . "m";

    $overstays_sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, emp.name as host_name, emp.department,
                             REPLACE(v.visit_photo, '../', '') as visit_photo,
                             REPLACE(vis.photo_path, '../', '') as photo_path,
                             COALESCE(NULLIF(REPLACE(v.visit_photo, '../', ''), ''), REPLACE(vis.photo_path, '../', '')) as photo_url
                      FROM visits v 
                      JOIN visitors vis ON v.visitor_id = vis.id 
                      LEFT JOIN employees emp ON v.employee_id = emp.id 
                      WHERE v.status = 'checked_in' 
                      AND v.created_at < DATE_SUB(NOW(), INTERVAL 8 HOUR)";
    if ($limit_employee_id)
        $overstays_sql .= " AND v.employee_id = " . $pdo->quote($limit_employee_id);
    $overstay_list = $pdo->query($overstays_sql)->fetchAll(PDO::FETCH_ASSOC);
    standardize_list($overstay_list);
    $overstays_count = count($overstay_list);

    // Peak Congestion & Best Slot (Last 30 Days)
    $peak_sql = "SELECT HOUR(created_at) as h, COUNT(*) as c 
                 FROM visits 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                 GROUP BY h ORDER BY c DESC LIMIT 1";
    $peak_hour = $pdo->query($peak_sql)->fetchColumn();
    if ($peak_hour === false)
        $peak_hour = 11;

    $peak_end = $peak_hour + 1;
    $peak_time = ($peak_hour > 12 ? $peak_hour - 12 : $peak_hour) . ":00 " . ($peak_hour >= 12 ? "PM" : "AM") . " - " .
        ($peak_end > 12 ? $peak_end - 12 : $peak_end) . ":00 " . ($peak_end >= 12 ? "PM" : "AM");

    // Construct dynamic hours union for SQL Best Slot
    $hours_array_union = [];
    for ($h = $start_h; $h <= $end_h; $h++) {
        $hours_array_union[] = "SELECT $h as hour";
    }
    $hours_union = implode(" UNION ", $hours_array_union);

    $slot_sql = "SELECT h.hour, COALESCE(COUNT(v.id), 0) as c
                 FROM ($hours_union) h
                 LEFT JOIN visits v ON HOUR(v.created_at) = h.hour AND v.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY h.hour ORDER BY c ASC LIMIT 1";
    $best_hour = $pdo->query($slot_sql)->fetchColumn();
    if ($best_hour === false)
        $best_hour = $start_h;

    $best_time = ($best_hour > 12 ? $best_hour - 12 : $best_hour) . ":00 " . ($best_hour >= 12 ? "PM" : "AM");

    // Zone Density (Department-wise) - UNIQUE DEPARTMENTS
    $dept_sql = "SELECT COALESCE(e.department, 'Other') as name, COUNT(v.id) as count 
                 FROM visits v 
                 LEFT JOIN employees e ON v.employee_id = e.id 
                 WHERE v.status = 'checked_in' 
                 GROUP BY name 
                 ORDER BY count DESC";
    $dept_zones_raw = $pdo->query($dept_sql)->fetchAll(PDO::FETCH_ASSOC);
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
    $area_zones_raw = $pdo->query($area_sql)->fetchAll(PDO::FETCH_ASSOC);
    $area_zones = array_map(function ($z) use ($max_capacity) {
        $z['density'] = $max_capacity > 0 ? round(($z['count'] / $max_capacity) * 100) : 0;
        return $z;
    }, $area_zones_raw);

    // Hourly Traffic (Last 12 Hours)
    $traffic_sql = "SELECT HOUR(created_at) as h, COUNT(*) as c 
                    FROM visits 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
                    GROUP BY h ORDER BY h ASC";
    $traffic_raw = $pdo->query($traffic_sql)->fetchAll(PDO::FETCH_KEY_PAIR);
    $traffic_chart = [];
    for ($i = 0; $i < 12; $i++) {
        $hr = (int) date('H', strtotime("-$i hours"));
        $label = date('h A', strtotime("-$i hours"));
        $traffic_chart[] = [
            'label' => $label,
            'count' => (int) ($traffic_raw[$hr] ?? 0),
            'hour' => $hr
        ];
    }
    $traffic_chart = array_reverse($traffic_chart); // Chronological order

    echo json_encode([
        'success' => true,
        'visits' => $visits,
        'scheduled_list' => $scheduled_list,
        'stats' => $stats,
        'ai_metrics' => [
            'crowd_density' => $crowd_density,
            'active_count' => $active_visitors,
            'max_capacity' => $max_capacity,
            'fast_service_rate' => $fast_service_rate,
            'avg_checkin_time' => $avg_display,
            'overstays_count' => $overstays_count,
            'overstays_list' => $overstay_list,
            'peak_time' => $peak_time,
            'best_time' => $best_time,
            'traffic' => $traffic_chart,
            'zones' => [
                'department' => $dept_zones,
                'access_area' => $area_zones
            ]
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
