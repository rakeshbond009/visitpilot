<?php
require_once '../includes/api_header.php';
require_once '../../includes/db.php';

// Safe query helpers
function safeFetchColumn($pdo, $sql, $params = [])
{
    try {
        if (!$pdo)
            return 0;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() ?: 0;
    } catch (PDOException $e) {
        return 0;
    }
}

function safeFetchAll($pdo, $sql, $params = [])
{
    try {
        if (!$pdo)
            return [];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

try {
    if (!$pdo) {
        sendResponse('error', 'Database connection unavailable');
    }

    $role = $_GET['role'] ?? null;
    $employee_id = $_GET['employee_id'] ?? null;

    if ($role === 'admin' || $role === 'security') {
        // --- ADMIN / SECURITY DASHBOARD DATA ---
        $total_emps = safeFetchColumn($pdo, "SELECT COUNT(*) FROM employees");
        $total_visits = safeFetchColumn($pdo, "SELECT COUNT(*) FROM visits");
        $today_visitors = safeFetchColumn($pdo, "SELECT COUNT(*) FROM visits WHERE DATE(created_at) = CURDATE()");
        $inside_now = safeFetchColumn($pdo, "SELECT COUNT(*) FROM visits WHERE status = 'checked_in'");

        // Fetch System Settings for capacity
        $settings_map = [];
        try {
            $settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('max_capacity', 'office_start_hour', 'office_end_hour')");
            if ($settings_stmt) {
                $settings_map = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            }
        } catch (PDOException $e) {
        }
        $max_capacity = (int) ($settings_map['max_capacity'] ?? 50);
        $crowd_density = ($max_capacity > 0) ? min(100, round(($inside_now / $max_capacity) * 100)) : 0;

        // Trends
        $trends_labels = [];
        $trends_data = [];
        $chart_sql = "SELECT DATE_FORMAT(created_at, '%a') as day_name, COUNT(*) as count 
                      FROM visits 
                      WHERE created_at >= CURDATE() - INTERVAL 6 DAY 
                      GROUP BY DATE(created_at) 
                      ORDER BY created_at ASC";
        $chart_data_raw = [];
        try {
            $chart_data_raw = $pdo->query($chart_sql)->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
        }

        for ($i = 6; $i >= 0; $i--) {
            $day_abbr = date('D', strtotime("-$i days"));
            $trends_labels[] = $day_abbr;
            $trends_data[] = (int) ($chart_data_raw[$day_abbr] ?? 0);
        }

        // Efficiency
        $completed_visits = safeFetchColumn($pdo, "SELECT count(*) FROM visits WHERE status = 'checked_out'");
        $time_saved_minutes = $completed_visits * 2;
        $time_saved_text = $time_saved_minutes . " mins";
        if ($time_saved_minutes > 60) {
            $hours = floor($time_saved_minutes / 60);
            $mins = $time_saved_minutes % 60;
            $time_saved_text = "{$hours}h {$mins}m";
        }

        // Trends and Prediction logic (Predicting for TOMORROW)
        $avg_visits = count($trends_data) > 0 ? array_sum($trends_data) / count($trends_data) : 0;
        $prediction = ceil($avg_visits * 1.1);
        $prediction_change = "+10%";

        $eff_sql = "SELECT AVG(ABS(TIMESTAMPDIFF(SECOND, created_at, check_in_time))) as avg_seconds 
                    FROM visits 
                    WHERE status IN ('checked_in', 'checked_out') 
                    AND check_in_time IS NOT NULL";
        $avg_seconds = safeFetchColumn($pdo, $eff_sql) ?: 0;

        $mins = floor($avg_seconds / 60);
        $secs = round($avg_seconds % 60);
        $avg_checkin = "{$mins}m {$secs}s";

        $total_processed = safeFetchColumn($pdo, "SELECT COUNT(*) FROM visits WHERE status IN ('checked_in', 'checked_out')");
        $satisfaction = "0%";
        if ($total_processed > 0) {
            $happy_visitors = safeFetchColumn($pdo, "SELECT COUNT(*) FROM visits WHERE status IN ('checked_in', 'checked_out') AND ABS(TIMESTAMPDIFF(MINUTE, created_at, check_in_time)) < 10");
            $satisfaction = round(($happy_visitors / $total_processed) * 100) . "%";
        }

        // Overstays
        $overstay_sql = "SELECT v.id, vr.name as visitor_name, vr.mobile, v.created_at, e.name as host_name 
                         FROM visits v 
                         JOIN visitors vr ON v.visitor_id = vr.id
                         LEFT JOIN employees e ON v.employee_id = e.id
                         WHERE v.status = 'checked_in' 
                         AND v.check_in_time < DATE_SUB(NOW(), INTERVAL 8 HOUR) 
                         ORDER BY v.check_in_time ASC";
        $overstay_list = safeFetchAll($pdo, $overstay_sql);
        $overstay_count = count($overstay_list);

        // Recent Activity
        $recent_sql = "SELECT v.id, vr.name as visitor_name, v.status, v.created_at, e.name as host_name, e.department, v.visit_photo as photo_url, v.check_in_time, v.check_out_time
                       FROM visits v 
                       JOIN visitors vr ON v.visitor_id = vr.id 
                       LEFT JOIN employees e ON v.employee_id = e.id 
                       ORDER BY v.created_at DESC 
                       LIMIT 10";
        $recent_activity = safeFetchAll($pdo, $recent_sql);

        // Zone Density
        $dept_zones_raw = safeFetchAll($pdo, "SELECT COALESCE(e.department, 'Other') as name, COUNT(v.id) as count 
                                   FROM visits v 
                                   LEFT JOIN employees e ON v.employee_id = e.id 
                                   WHERE v.status = 'checked_in' 
                                   GROUP BY name 
                                   ORDER BY count DESC");

        $dept_zones = array_map(function ($z) use ($max_capacity) {
            $z['density'] = $max_capacity > 0 ? round(($z['count'] / $max_capacity) * 100) : 0;
            return $z;
        }, $dept_zones_raw);

        $area_zones_raw = safeFetchAll($pdo, "SELECT COALESCE(access_area, 'Unassigned') as name, COUNT(id) as count 
                                   FROM visits 
                                   WHERE status = 'checked_in' 
                                   GROUP BY name 
                                   ORDER BY count DESC");

        $area_zones = array_map(function ($z) use ($max_capacity) {
            $z['density'] = $max_capacity > 0 ? round(($z['count'] / $max_capacity) * 100) : 0;
            return $z;
        }, $area_zones_raw);

        // Records lists
        $all_visits_list = safeFetchAll($pdo, "SELECT v.id, vr.name as visitor_name, vr.mobile, v.status, v.created_at, e.name as host_name 
                                       FROM visits v 
                                       JOIN visitors vr ON v.visitor_id = vr.id
                                       LEFT JOIN employees e ON v.employee_id = e.id 
                                       ORDER BY v.created_at DESC LIMIT 50");
        
        $today_visits_list = safeFetchAll($pdo, "SELECT v.id, vr.name as visitor_name, vr.mobile, v.status, v.created_at, e.name as host_name 
                                       FROM visits v 
                                       JOIN visitors vr ON v.visitor_id = vr.id
                                       LEFT JOIN employees e ON v.employee_id = e.id 
                                       WHERE DATE(v.created_at) = CURDATE()
                                       ORDER BY v.created_at DESC");

        $employee_list = safeFetchAll($pdo, "SELECT id, name, department, mobile FROM employees ORDER BY name ASC LIMIT 50");

        // Peak Hour Calculation
        $peak_hour_sql = "SELECT HOUR(created_at) as hr, COUNT(*) as count 
                          FROM visits 
                          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                          GROUP BY hr 
                          ORDER BY count DESC 
                          LIMIT 1";
        $peak_hour_data = safeFetchAll($pdo, $peak_hour_sql);
        $peak_time = "N/A";
        if (!empty($peak_hour_data)) {
            $peak_hour = $peak_hour_data[0]['hr'];
            $peak_end = $peak_hour + 1;
            $peak_time = ($peak_hour > 12 ? $peak_hour - 12 : ($peak_hour == 0 ? 12 : $peak_hour)) . ":00 " . ($peak_hour >= 12 ? "PM" : "AM") . " - " .
                ($peak_end > 12 ? $peak_end - 12 : ($peak_end == 0 ? 12 : $peak_end)) . ":00 " . ($peak_end >= 12 ? "PM" : "AM");
        }

        $most_visited_hosts = safeFetchAll($pdo, "SELECT e.name, COUNT(*) as visit_count FROM visits v JOIN employees e ON v.employee_id = e.id GROUP BY v.employee_id ORDER BY visit_count DESC LIMIT 3");

        // Best Time Slot Calculation
        $start_h = (int) ($settings_map['office_start_hour'] ?? 8);
        $end_h = (int) ($settings_map['office_end_hour'] ?? 18);
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
        $best_hour_data = safeFetchAll($pdo, $slot_sql);
        $best_hour = !empty($best_hour_data) ? $best_hour_data[0]['hour'] : $start_h;
        $best_time = ($best_hour > 12 ? $best_hour - 12 : ($best_hour == 0 ? 12 : $best_hour)) . ":00 " . ($best_hour >= 12 ? "PM" : "AM");

        $response_data = [
            'total_employees' => (int) $total_emps,
            'total_visits' => (int) $total_visits,
            'today_visitors' => (int) $today_visitors,
            'inside_now' => (int) $inside_now,
            'max_capacity' => (int) $max_capacity,
            'time_saved' => $time_saved_text,
            'trends' => ['labels' => $trends_labels, 'data' => $trends_data],
            'ai_insights' => [
                'prediction_tomorrow' => (int) $prediction,
                'prediction_change' => $prediction_change,
                'crowd_density' => $crowd_density,
                'active_visitors' => (int) $inside_now,
                'overstay_count' => $overstay_count,
                'overstay_list' => $overstay_list,
                'peak_time' => $peak_time,
                'best_time' => $best_time
            ],
            'efficiency' => [
                'avg_checkin_time' => $avg_checkin,
                'peak_hour' => $peak_time,
                'total_time_saved' => $time_saved_text,
                'satisfaction' => $satisfaction
            ],
            'recent_activity' => $recent_activity,
            'most_visited_hosts' => $most_visited_hosts,
            'zones' => [
                'department' => $dept_zones,
                'access_area' => $area_zones
            ],
            'records' => [
                'employees' => $employee_list,
                'visits' => $all_visits_list,
                'today_visits' => $today_visits_list
            ]
        ];

        sendResponse('success', 'Dashboard data retrieved', $response_data);

    } else if ($employee_id) {
        // Host Stats (Legacy/Fallback)
        $today_visitors = safeFetchColumn($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id = ? AND DATE(created_at) = CURDATE()", [$employee_id]);
        $inside_now = safeFetchColumn($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id = ? AND status = 'checked_in'", [$employee_id]);

        sendResponse('success', 'Host stats retrieved', [
            'today_visitors' => (int) $today_visitors,
            'inside_now' => (int) $inside_now
        ]);
    } else {
        sendResponse('error', 'Unauthorized role or missing ID');
    }

} catch (Exception $e) {
    sendResponse('error', 'Error: ' . $e->getMessage());
}
