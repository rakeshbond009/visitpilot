<?php
// api/admin/get_reports.php
require_once '../includes/api_header.php';

// Role/Permission check
$permissions = $_SESSION['permissions'] ?? [];
$is_admin = ($_SESSION['role'] === 'admin');

$can_view_all = $is_admin || in_array('admin_reports', $permissions) || in_array('security_reports', $permissions) || in_array('view_employee_report', $permissions);
$can_view_own = in_array('host_reports', $permissions);

if (!$can_view_all && !$can_view_own) {
    sendResponse('error', 'Unauthorized access', null, 401);
}

// Enforce filters for self-view only
if (!$can_view_all && $can_view_own) {
    // Force employee_id to logged-in user
    if (empty($_SESSION['employee_id'])) {
         sendResponse('error', 'Employee context missing', null, 403);
    }
    $_GET['employee_id'] = $_SESSION['employee_id'];
}

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$employee_id = $_GET['employee_id'] ?? '';
$department = $_GET['department'] ?? '';

$where = "WHERE DATE(v.created_at) BETWEEN ? AND ?";
$params = [$start_date, $end_date];

if ($employee_id) {
    $where .= " AND v.employee_id = ?";
    $params[] = $employee_id;
}

if ($department) {
    $where .= " AND emp.department = ?";
    $params[] = $department;
}

try {
    // 1. Fetch Summary Stats
    $sqlSummary = "SELECT 
                    COUNT(*) as total_visits,
                    COUNT(CASE WHEN v.status = 'checked_in' THEN 1 END) as active_visits,
                    COUNT(CASE WHEN v.status = 'pending' THEN 1 END) as pending_visits
                  FROM visits v 
                  JOIN employees emp ON v.employee_id = emp.id 
                  $where";
    $stmt = $pdo->prepare($sqlSummary);
    $stmt->execute($params);
    $summary = $stmt->fetch();

    // 2. Daily Trend
    $sqlDaily = "SELECT DATE(v.created_at) as date, COUNT(*) as count 
                 FROM visits v 
                 JOIN employees emp ON v.employee_id = emp.id 
                 $where 
                 GROUP BY DATE(v.created_at) 
                 ORDER BY date ASC";
    $stmt = $pdo->prepare($sqlDaily);
    $stmt->execute($params);
    $dailyTrends = $stmt->fetchAll();

    // 4. Peak Hour & Hourly Trend
    $sqlHours = "SELECT HOUR(v.created_at) as hour, COUNT(*) as count 
                 FROM visits v 
                 JOIN employees emp ON v.employee_id = emp.id 
                 $where 
                 GROUP BY HOUR(v.created_at) 
                 ORDER BY hour ASC";
    $stmt = $pdo->prepare($sqlHours);
    $stmt->execute($params);
    $hourData = $stmt->fetchAll();

    $peakHourRow = null;
    $maxCount = -1;
    foreach ($hourData as $h) {
        if ($h['count'] > $maxCount) {
            $maxCount = $h['count'];
            $peakHourRow = $h;
        }
    }
    $peakHour = $peakHourRow ? date("h A", mktime($peakHourRow['hour'], 0, 0)) : 'N/A';

    // 5. Top Department & Dept Distribution
    $sqlDept = "SELECT emp.department, COUNT(*) as count 
                FROM visits v 
                JOIN employees emp ON v.employee_id = emp.id 
                $where 
                GROUP BY emp.department 
                ORDER BY count DESC";
    $stmt = $pdo->prepare($sqlDept);
    $stmt->execute($params);
    $deptDistribution = $stmt->fetchAll();
    $topDept = $deptDistribution[0] ?? null;

    // 6. Top Host
    $sqlHost = "SELECT emp.name, COUNT(*) as count 
                FROM visits v 
                JOIN employees emp ON v.employee_id = emp.id 
                $where 
                GROUP BY emp.id 
                ORDER BY count DESC LIMIT 1";
    $stmt = $pdo->prepare($sqlHost);
    $stmt->execute($params);
    $topHost = $stmt->fetch();

    // 7. Detailed Log
    $sqlLog = "SELECT v.id, v.visit_code, v.created_at, v.status, v.purpose, v.check_in_time, v.check_out_time,
                      vis.name as visitor_name, vis.mobile, 
                      emp.name as host_name, emp.department 
               FROM visits v 
               JOIN visitors vis ON v.visitor_id = vis.id 
               JOIN employees emp ON v.employee_id = emp.id 
               $where 
               ORDER BY v.created_at DESC";
    $stmt = $pdo->prepare($sqlLog);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    // 8. Fetch Meta for filters
    $employees = $pdo->query("SELECT id, name FROM employees ORDER BY name ASC")->fetchAll();
    $departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department ASC")->fetchAll(PDO::FETCH_COLUMN);

    // AI Prediction & Trend Logic
    $totalVisits = $summary['total_visits'] ?? 0;
    $avgDaily = count($dailyTrends) > 0 ? $totalVisits / count($dailyTrends) : 0;
    $predicted = round($avgDaily * 1.05);

    if ($predicted > ($avgDaily * 1.25)) {
        $trend_text = "Heavy Surge";
        $trend_color = "#ef4444"; // Red
    } elseif ($predicted > ($avgDaily * 1.10)) {
        $trend_text = "Increasing";
        $trend_color = "#f59e0b"; // Warning
    } elseif ($predicted >= ($avgDaily * 0.90)) {
        $trend_text = "Stable";
        $trend_color = "#10b981"; // Success
    } elseif ($predicted >= ($avgDaily * 0.50)) {
        $trend_text = "Declining";
        $trend_color = "#64748b"; // Muted
    } else {
        $trend_text = "Quiet";
        $trend_color = "#3b82f6"; // Info
    }

    $data = [
        'summary' => [
            'total_visits' => $summary['total_visits'] ?? 0,
            'active_visits' => $summary['active_visits'] ?? 0,
            'pending_visits' => $summary['pending_visits'] ?? 0,
            'peak_hour' => $peakHour,
            'top_department' => $topDept['department'] ?? 'N/A',
            'top_dept_count' => $topDept['count'] ?? 0,
            'top_host' => $topHost['name'] ?? 'N/A',
            'predicted' => $predicted,
            'trend_text' => $trend_text,
            'trend_color' => $trend_color
        ],
        'trends' => $dailyTrends,
        'hourly' => $hourData,
        'departments' => $deptDistribution,
        'logs' => $logs,
        'meta' => [
            'employees' => $employees,
            'departments' => $departments
        ]
    ];

    sendResponse('success', 'Reports data fetched', $data);

} catch (PDOException $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
