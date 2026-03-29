<?php
// api/visit/employee_report.php
require_once '../includes/api_header.php';

if (!$user_id) {
    sendResponse('error', 'Unauthorized', null, 401);
}

// Permission check
if ($role !== 'admin') {
    require_once '../includes/permission_utils.php';
    $stmt = $pdo->prepare("SELECT permissions_locked FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $locked = (bool) $stmt->fetchColumn();
    $permissions = getUserPermissions($pdo, $user_id, $role, $locked);

    if (!in_array('view_employee_report', $permissions) && !in_array('admin_reports', $permissions)) {
        sendResponse('error', 'Permission denied', null, 403);
    }
}

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$employee_id_filter = $_GET['employee_id'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';

$where = "WHERE date(v.created_at) BETWEEN ? AND ?";
$params = [$start_date, $end_date];

if ($employee_id_filter) {
    $where .= " AND v.employee_id = ?";
    $params[] = $employee_id_filter;
}

if ($department) {
    $where .= " AND emp.department = ?";
    $params[] = $department;
}

if ($status) {
    $where .= " AND v.status = ?";
    $params[] = $status;
}

// --- Pivot Data ---
function buildPivotData($pdo, $where, $params, $dimensionCol)
{
    $sql = "SELECT $dimensionCol as dimension, v.status, COUNT(*) as count 
            FROM visits v 
            JOIN employees emp ON v.employee_id = emp.id 
            $where 
            GROUP BY dimension, v.status 
            ORDER BY dimension DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $pivot = [];
    $statuses = ['pending', 'approved', 'checked_in', 'checked_out', 'rejected'];

    foreach ($raw as $r) {
        $dim = $r['dimension'] ?: 'Unknown';
        if (!isset($pivot[$dim])) {
            $pivot[$dim] = array_fill_keys($statuses, 0);
            $pivot[$dim]['total'] = 0;
        }
        $pivot[$dim][$r['status']] = (int) $r['count'];
        $pivot[$dim]['total'] += (int) $r['count'];
    }

    // Convert to array format for JSON
    $result = [];
    foreach ($pivot as $name => $stats) {
        $result[] = array_merge(['name' => $name], $stats);
    }
    return $result;
}

$pivotHost = buildPivotData($pdo, $where, $params, 'emp.name');
$pivotMonth = buildPivotData($pdo, $where, $params, "DATE_FORMAT(v.created_at, '%Y-%m')");
$pivotDay = buildPivotData($pdo, $where, $params, "DATE(v.created_at)");

// --- Performance Metrics ---
$sqlPerf = "SELECT 
                emp.name, 
                COUNT(*) as total,
                AVG(CASE 
                    WHEN v.status IN ('checked_out', 'check_out') 
                    AND v.check_in_time IS NOT NULL 
                    AND v.check_out_time IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, v.check_in_time, v.check_out_time) 
                    ELSE NULL 
                END) as avg_duration,
                AVG(CASE 
                    WHEN v.status IN ('checked_in', 'check_in', 'checked_out', 'check_out') 
                    AND v.check_in_time IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, v.created_at, v.check_in_time) 
                    ELSE NULL 
                END) as avg_lead_time,
                SUM(CASE WHEN v.status IN ('approved', 'checked_in', 'check_in', 'checked_out', 'check_out') THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN v.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
            FROM visits v 
            JOIN employees emp ON v.employee_id = emp.id 
            $where 
            GROUP BY emp.name";
$stmt = $pdo->prepare($sqlPerf);
$stmt->execute($params);
$perfStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format performance data
foreach ($perfStats as &$stat) {
    $total = $stat['total'] > 0 ? $stat['total'] : 1;
    $stat['approval_rate'] = round(($stat['approved_count'] / $total) * 100, 1);
    $stat['rejection_rate'] = round(($stat['rejected_count'] / $total) * 100, 1);
    $stat['avg_duration'] = $stat['avg_duration'] ? round($stat['avg_duration'], 0) : null;
    $stat['avg_lead_time'] = $stat['avg_lead_time'] ? round($stat['avg_lead_time'], 0) : null;
}

// --- Top Visitors ---
$sqlTopVis = "SELECT 
                vis.name, 
                COUNT(*) as visit_count,
                GROUP_CONCAT(DISTINCT emp.name SEPARATOR ', ') as hosts,
                GROUP_CONCAT(DISTINCT v.purpose SEPARATOR ', ') as purposes
              FROM visits v 
              JOIN visitors vis ON v.visitor_id = vis.id 
              JOIN employees emp ON v.employee_id = emp.id 
              $where 
              GROUP BY vis.id, vis.name 
              ORDER BY visit_count DESC LIMIT 10";
$stmt = $pdo->prepare($sqlTopVis);
$stmt->execute($params);
$topVisitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Purpose Stats ---
$sqlPurpose = "SELECT v.purpose, COUNT(*) as count 
               FROM visits v 
               JOIN employees emp ON v.employee_id = emp.id 
               $where 
               GROUP BY v.purpose";
$stmt = $pdo->prepare($sqlPurpose);
$stmt->execute($params);
$purposeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Daily Trend (for line chart) ---
$sqlTrend = "SELECT DATE(v.created_at) as date, COUNT(*) as count 
             FROM visits v JOIN employees emp ON v.employee_id = emp.id 
             $where GROUP BY DATE(v.created_at) ORDER BY date ASC";
$stmt = $pdo->prepare($sqlTrend);
$stmt->execute($params);
$trendRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
$trendData = [];
foreach ($trendRaw as $r) {
    $trendData[] = [
        'label' => date('d-m', strtotime($r['date'])),
        'value' => (int) $r['count']
    ];
}

// --- Hourly Distribution (for bar chart) ---
$hourCounts = array_fill(0, 24, 0);
$stmt = $pdo->prepare("SELECT HOUR(v.created_at) as h, COUNT(*) as c FROM visits v JOIN employees emp ON v.employee_id = emp.id $where GROUP BY h");
$stmt->execute($params);
while ($r = $stmt->fetch()) {
    $hourCounts[(int) $r['h']] = (int) $r['c'];
}
$hourlyData = [];
for ($h = 8; $h <= 21; $h++) {
    $hourlyData[] = [
        'label' => date('gA', mktime($h, 0)),
        'value' => $hourCounts[$h]
    ];
}

// --- Department Distribution (for doughnut chart) ---
$sqlDept = "SELECT emp.department, COUNT(*) as count FROM visits v JOIN employees emp ON v.employee_id = emp.id $where GROUP BY emp.department";
$stmt = $pdo->prepare($sqlDept);
$stmt->execute($params);
$deptStats = [];
while ($r = $stmt->fetch()) {
    $deptStats[] = [
        'name' => $r['department'] ?: 'Unknown',
        'count' => (int) $r['count']
    ];
}

// --- Filter Options ---
$employees = $pdo->query("SELECT id, name FROM employees ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

sendResponse('success', 'Employee report data', [
    'pivot_host' => $pivotHost,
    'pivot_month' => $pivotMonth,
    'pivot_day' => $pivotDay,
    'performance' => $perfStats,
    'top_visitors' => $topVisitors,
    'purpose_stats' => $purposeStats,
    'trend_data' => $trendData,
    'hourly_data' => $hourlyData,
    'dept_stats' => $deptStats,
    'filters' => [
        'employees' => $employees,
        'departments' => $departments,
        'start_date' => $start_date,
        'end_date' => $end_date,
    ]
]);

