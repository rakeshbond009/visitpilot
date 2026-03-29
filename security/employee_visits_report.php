<?php
require_once '../includes/db.php';
requireLogin();

// Basic Role Check
$is_admin = ($_SESSION['role'] === 'admin');
$is_security = ($_SESSION['role'] === 'security');
$is_host = ($_SESSION['role'] === 'host' || $_SESSION['role'] === 'employee');

if (!$is_admin && !$is_security && !$is_host) {
    redirect('../index.php');
}

// Force Host Restriction
$limit_employee_id = null;
if ($is_host) {
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $limit_employee_id = $stmt->fetchColumn();
}

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$employee_id = $_GET['employee_id'] ?? '';
$department = $_GET['department'] ?? '';
$status = $_GET['status'] ?? '';

$whereBase = "WHERE date(v.created_at) BETWEEN ? AND ?";
$paramsBase = [$start_date, $end_date];

if ($limit_employee_id) {
    $whereBase .= " AND v.employee_id = ?";
    $paramsBase[] = $limit_employee_id;
} elseif ($employee_id) {
    $whereBase .= " AND v.employee_id = ?";
    $paramsBase[] = $employee_id;
}

if ($department && ($is_admin || $is_security)) {
    $whereBase .= " AND emp.department = ?";
    $paramsBase[] = $department;
}

$where = $whereBase;
$params = $paramsBase;

if ($status) {
    if ($status === 'pending') {
        $where .= " AND v.approval_status = 'pending'";
    } else {
        $where .= " AND v.status = ?";
        $params[] = $status;
    }
}

$sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, emp.name as host_name, emp.department 
        FROM visits v 
        JOIN visitors vis ON v.visitor_id = vis.id 
        JOIN employees emp ON v.employee_id = emp.id 
        $where 
        ORDER BY v.created_at DESC";

if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="employee_visits_report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Visit ID', 'Visitor Name', 'Mobile', 'Host', 'Department', 'Purpose', 'Check In', 'Check Out', 'Status']);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            $row['visit_code'],
            $row['visitor_name'],
            $row['mobile'],
            $row['host_name'],
            $row['department'],
            $row['purpose'],
            formatTime($row['check_in_time']),
            formatTime($row['check_out_time']),
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}

require_once 'header.php';

$employees = $pdo->query("SELECT * FROM employees ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department != '' ORDER BY department")->fetchAll();

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$visits = $stmt->fetchAll();

// --- Pivot Data Logic ---
function buildPivotData($pdo, $sqlBase, $params, $dimensionCol, $dimensionAlias)
{
    // Inject Grouping
    // We need to keep the JOINS and WHERE clause from the main query but change specific selects/order
    // Simple way: Utilize the $where variable which is already built.
    // Query: SELECT [Dimension], v.status, COUNT(*) FROM ... WHERE ... GROUP BY [Dimension], v.status

    // Extract base FROM/JOIN from original $sql string is risky if structure changes.
    // Better to reconstruct valid partial query.


    $sqlPivot = "SELECT $dimensionCol as dimension, 
                 CASE 
                    WHEN v.is_invited = 1 AND v.status = 'pending' THEN 'invited'
                    WHEN v.status = 'pending' AND v.is_invited = 0 THEN 'pending'
                    WHEN v.status = 'waiting' THEN 'approved'
                    WHEN v.status = 'denied' OR v.status = 'canceled' THEN 'rejected'
                    ELSE v.status 
                 END as mapped_status, 
                 COUNT(*) as count 
                 FROM visits v 
                 JOIN employees emp ON v.employee_id = emp.id 
                 $sqlBase
                 GROUP BY dimension, mapped_status 
                 ORDER BY dimension DESC"; 

    $stmt = $pdo->prepare($sqlPivot);
    $stmt->execute($params);
    $raw = $stmt->fetchAll();

    $pivot = [];
    $statuses = ['pending', 'invited', 'approved', 'checked_in', 'checked_out', 'rejected'];

    foreach ($raw as $r) {
        $dim = $r['dimension'] ?: 'Unknown';
        if (!isset($pivot[$dim])) {
            $pivot[$dim] = array_fill_keys($statuses, 0);
            $pivot[$dim]['total'] = 0;
        }
        $statusKey = strtolower(trim($r['mapped_status']));
        
        if (array_key_exists($statusKey, $pivot[$dim])) {
            $pivot[$dim][$statusKey] += $r['count'];
        }
        $pivot[$dim]['total'] += $r['count'];
    }
    return $pivot;
}

// Re-use $where defined above
// Aggregates for summarized views should use $whereBase (ignore status filter)
$pivotHost = buildPivotData($pdo, $whereBase, $paramsBase, 'emp.name', 'Host');
$pivotMonth = buildPivotData($pdo, $whereBase, $paramsBase, "DATE_FORMAT(v.created_at, '%b %Y')", 'Month');
$pivotDay = buildPivotData($pdo, $whereBase, $paramsBase, "DATE(v.created_at)", 'Date');

// --- Performance Metrics ---
// Avg Duration: Checked In -> Checked Out
// Lead Time: Created -> Checked In (time to process/arrive)
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

// --- Analytics Data ---
// 1. Top Visitors
$sqlTopVis = "SELECT 
                vis.name, 
                COUNT(*) as visit_count,
                MAX(v.id) as last_visit_id,
                GROUP_CONCAT(DISTINCT emp.name SEPARATOR ', ') as hosts,
                GROUP_CONCAT(DISTINCT v.purpose SEPARATOR ', ') as purposes,
                GROUP_CONCAT(DISTINCT emp.department SEPARATOR ', ') as departments
              FROM visits v 
              JOIN visitors vis ON v.visitor_id = vis.id 
              JOIN employees emp ON v.employee_id = emp.id 
              $where 
              GROUP BY vis.id, vis.name 
              ORDER BY visit_count DESC LIMIT 10";
$stmt = $pdo->prepare($sqlTopVis);
$stmt->execute($params);
$topVisitors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Purpose Stats
$sqlPurpose = "SELECT v.purpose, COUNT(*) as count 
               FROM visits v 
               JOIN employees emp ON v.employee_id = emp.id 
               $where 
               GROUP BY v.purpose";
$stmt = $pdo->prepare($sqlPurpose);
$stmt->execute($params);
$purposeStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // [purpose => count]

// 3. Existing Charts Data Prep
// Trend (Daily)
$sqlTrend = "SELECT DATE(v.created_at) as date, COUNT(*) as count 
             FROM visits v JOIN employees emp ON v.employee_id = emp.id 
             $where GROUP BY DATE(v.created_at) ORDER BY date ASC";
$stmt = $pdo->prepare($sqlTrend);
$stmt->execute($params);
$trendData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$trendLabels = array_map(function ($d) {
    return date('d-m-y', strtotime($d));
}, array_keys($trendData));
$trendCounts = array_values($trendData);

// Hourly
$hourLabels = array_map(function ($h) {
    return date('g A', mktime($h, 0));
}, range(8, 21));
$hourCounts = array_fill(8, 14, 0);
$stmt = $pdo->prepare("SELECT HOUR(v.created_at) as h, COUNT(*) as c FROM visits v JOIN employees emp ON v.employee_id = emp.id $where GROUP BY h");
$stmt->execute($params);
while ($r = $stmt->fetch()) {
    $h = $r['h'];
    if ($h >= 8 && $h <= 21) {
        $hourCounts[$h] = $r['c'];
    }
}
$hourCounts = array_values($hourCounts); // Reset keys to 0,1,2... for Chart.js

// Dept Stats
$deptCountsData = [];
$deptLabels = [];
$startParams = $params;
$sqlDept = "SELECT emp.department, COUNT(*) as c FROM visits v JOIN employees emp ON v.employee_id = emp.id $where GROUP BY emp.department";
$stmt = $pdo->prepare($sqlDept);
$stmt->execute($params);
while ($r = $stmt->fetch()) {
    $deptLabels[] = $r['department'] ?: 'Unknown';
    $deptCountsData[] = $r['c'];
}
?>

<h3>Employee Visits Report</h3>

<div class="card p-3 mb-4 bg-white shadow-sm">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All Statuses</option>
                <option value="pending" <?php if ($status == 'pending')
    echo 'selected'; ?>>Pending</option>
                <option value="approved" <?php if ($status == 'approved')
    echo 'selected'; ?>>Approved</option>
                <option value="checked_in" <?php if ($status == 'checked_in')
    echo 'selected'; ?>>Checked In</option>
                <option value="checked_out" <?php if ($status == 'checked_out')
    echo 'selected'; ?>>Checked Out</option>
                <option value="rejected" <?php if ($status == 'rejected')
    echo 'selected'; ?>>Rejected</option>
            </select>
        </div>
        <?php if ($is_admin || $is_security): ?>
            <div class="col-md-2">
                <label class="form-label">Department</label>
                <select name="department" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept['department']); ?>" <?php if ($department == $dept['department'])
            echo 'selected'; ?>>
                            <?php echo htmlspecialchars($dept['department']); ?>
                        </option>
                    <?php
    endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Host</label>
                <select name="employee_id" class="form-select">
                    <option value="">All Employees</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php if ($employee_id == $emp['id'])
            echo 'selected'; ?>>
                            <?php echo htmlspecialchars($emp['name']); ?>
                        </option>
                    <?php
    endforeach; ?>
                </select>
            </div>
        <?php
endif; ?>
        <div class="col-md-2">
            <div class="btn-group w-100">
                <button type="submit" class="btn btn-primary fw-bold">Filter</button>
                <a href="employee_visits_report.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-circle"></i></a>
            </div>
        </div>
        <div class="col-12 text-end mt-2">
            <button type="submit" name="export" value="csv" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</button>
        </div>
    </form>
</div>

<!-- Pivot Reports -->
<ul class="nav nav-tabs mb-3" id="reportTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active fw-bold" id="host-tab" data-bs-toggle="tab" data-bs-target="#tab-host" type="button">By Host</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="month-tab" data-bs-toggle="tab" data-bs-target="#tab-month" type="button">By Month</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="day-tab" data-bs-toggle="tab" data-bs-target="#tab-day" type="button">By Date</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="perf-tab" data-bs-toggle="tab" data-bs-target="#tab-perf" type="button">Performance</button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link fw-bold" id="analytics-tab" data-bs-toggle="tab" data-bs-target="#tab-analytics" type="button">Analytics</button>
    </li>
</ul>

<div class="tab-content mb-4" id="reportTabsContent">
    <!-- By Host Pivot -->
    <div class="tab-pane fade show active" id="tab-host">
        <div class="card shadow-sm border-top-0 rounded-bottom-3">
             <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover small text-center">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-start">Host Name</th>
                                <th class="bg-warning text-dark">Pending</th>
                                <th class="bg-info text-white">Invited</th>
                                <th class="bg-primary text-white">Approved</th>
                                <th class="bg-success text-white">Checked In</th>
                                <th class="bg-secondary text-white">Checked Out</th>
                                <th class="bg-danger text-white">Rejected</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pivotHost as $name => $stats): ?>
                            <tr>
                                <td class="text-start fw-bold"><?php echo htmlspecialchars($name); ?></td>
                                <td><?php echo $stats['pending'] ?: '-'; ?></td>
                                <td><?php echo $stats['invited'] ?: '-'; ?></td>
                                <td><?php echo $stats['approved'] ?: '-'; ?></td>
                                <td><?php echo $stats['checked_in'] ?: '-'; ?></td>
                                <td><?php echo $stats['checked_out'] ?: '-'; ?></td>
                                <td><?php echo $stats['rejected'] ?: '-'; ?></td>
                                <td class="fw-bold"><?php echo $stats['total']; ?></td>
                            </tr>
                            <?php
endforeach; ?>
                            <?php if (empty($pivotHost)): ?>
                                <tr><td colspan="8" class="text-muted">No data found</td></tr>
                            <?php
endif; ?>
                        </tbody>
                    </table>
                </div>
             </div>
        </div>
    </div>
    
    <!-- By Month Pivot -->
    <div class="tab-pane fade" id="tab-month">
        <div class="card shadow-sm border-top-0 rounded-bottom-3">
             <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover small text-center">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-start">Month</th>
                                <th class="bg-warning text-dark">Pending</th>
                                <th class="bg-info text-white">Invited</th>
                                <th class="bg-primary text-white">Approved</th>
                                <th class="bg-success text-white">Checked In</th>
                                <th class="bg-secondary text-white">Checked Out</th>
                                <th class="bg-danger text-white">Rejected</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pivotMonth as $month => $stats): ?>
                            <tr>
                                <td class="text-start fw-bold"><?php echo date('M-y', strtotime($month . '-01')); ?></td>
                                <td><?php echo $stats['pending'] ?: '-'; ?></td>
                                <td><?php echo $stats['invited'] ?: '-'; ?></td>
                                <td><?php echo $stats['approved'] ?: '-'; ?></td>
                                <td><?php echo $stats['checked_in'] ?: '-'; ?></td>
                                <td><?php echo $stats['checked_out'] ?: '-'; ?></td>
                                <td><?php echo $stats['rejected'] ?: '-'; ?></td>
                                <td class="fw-bold"><?php echo $stats['total']; ?></td>
                            </tr>
                            <?php
endforeach; ?>
                            <?php if (empty($pivotMonth)): ?>
                                <tr><td colspan="8" class="text-muted">No data found</td></tr>
                            <?php
endif; ?>
                        </tbody>
                    </table>
                </div>
             </div>
        </div>
    </div>
    
    <!-- By Date Pivot -->
    <div class="tab-pane fade" id="tab-day">
        <div class="card shadow-sm border-top-0 rounded-bottom-3">
             <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover small text-center">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-start">Date</th>
                                <th class="bg-warning text-dark">Pending</th>
                                <th class="bg-info text-white">Invited</th>
                                <th class="bg-primary text-white">Approved</th>
                                <th class="bg-success text-white">Checked In</th>
                                <th class="bg-secondary text-white">Checked Out</th>
                                <th class="bg-danger text-white">Rejected</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pivotDay as $day => $stats): ?>
                            <tr>
                                <td class="text-start fw-bold"><?php echo date('d-M-y', strtotime($day)); ?></td>
                                <td><?php echo $stats['pending'] ?: '-'; ?></td>
                                <td><?php echo $stats['invited'] ?: '-'; ?></td>
                                <td><?php echo $stats['approved'] ?: '-'; ?></td>
                                <td><?php echo $stats['checked_in'] ?: '-'; ?></td>
                                <td><?php echo $stats['checked_out'] ?: '-'; ?></td>
                                <td><?php echo $stats['rejected'] ?: '-'; ?></td>
                                <td class="fw-bold"><?php echo $stats['total']; ?></td>
                            </tr>
                            <?php
endforeach; ?>
                            <?php if (empty($pivotDay)): ?>
                                <tr><td colspan="8" class="text-muted">No data found</td></tr>
                            <?php
endif; ?>
                        </tbody>
                    </table>
                </div>
             </div>
        </div>
    </div>

    <!-- Performance Tab -->
    <div class="tab-pane fade" id="tab-perf">
        <div class="card shadow-sm border-top-0 rounded-bottom-3">
             <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped table-hover small text-center align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-start">Host Name</th>
                                <th>Total Requests</th>
                                <th>Avg Duration</th>
                                <th>Avg Lead Time <i class="bi bi-info-circle" title="Time from Request to Check-in"></i></th>
                                <th>Approval Rate</th>
                                <th>Rejection Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($perfStats as $stat):
    $total = $stat['total'] > 0 ? $stat['total'] : 1;
    $appRate = round(($stat['approved_count'] / $total) * 100, 1);
    $rejRate = round(($stat['rejected_count'] / $total) * 100, 1);
    $duration = $stat['avg_duration'] ? round($stat['avg_duration'], 0) . ' min' : '-';
    $leadTime = $stat['avg_lead_time'] ? round($stat['avg_lead_time'], 0) . ' min' : '-';
?>
                            <tr>
                                <td class="text-start fw-bold"><?php echo htmlspecialchars($stat['name']); ?></td>
                                <td><?php echo $stat['total']; ?></td>
                                <td><?php echo $duration; ?></td>
                                <td><?php echo $leadTime; ?></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $appRate; ?>%;" aria-valuenow="<?php echo $appRate; ?>" aria-valuemin="0" aria-valuemax="100"><?php echo $appRate; ?>%</div>
                                    </div>
                                </td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo $rejRate; ?>%;" aria-valuenow="<?php echo $rejRate; ?>" aria-valuemin="0" aria-valuemax="100"><?php echo $rejRate; ?>%</div>
                                    </div>
                                </td>
                            </tr>
                            <?php
endforeach; ?>
                        </tbody>
                    </table>
                </div>
             </div>
        </div>
    </div>

    <!-- Analytics Tab -->
    <div class="tab-pane fade" id="tab-analytics">
        <div class="row">
            <!-- Charts Row 1 -->
            <div class="col-md-8 mb-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-bold">Daily Visit Trend</div>
                    <div class="card-body">
                        <canvas id="trendChart" style="max-height: 300px;"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm h-100">
                     <div class="card-header bg-white fw-bold">Visits by Department</div>
                     <div class="card-body">
                        <canvas id="deptChart" style="max-height: 300px;"></canvas>
                     </div>
                </div>
            </div>

            <!-- Charts Row 2 -->
             <div class="col-md-6 mb-4">
                <div class="card shadow-sm h-100">
                     <div class="card-header bg-white fw-bold">Peak Traffic (Hour of Day)</div>
                     <div class="card-body">
                        <canvas id="hourChart" style="max-height: 300px;"></canvas>
                     </div>
                </div>
            </div>
            <div class="col-md-6 mb-4">
                <div class="card shadow-sm h-100">
                     <div class="card-header bg-white fw-bold">Purpose Breakdown</div>
                     <div class="card-body">
                        <canvas id="purposeChart" style="max-height: 300px;"></canvas>
                     </div>
                </div>
            </div>

            <!-- Top Visitors Table -->
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-header bg-white fw-bold">Top 10 Frequent Visitors</div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0 text-center small align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-start">Visitor Name</th>
                                    <th>Frequent Host(s)</th>
                                    <th>Purpose(s)</th>
                                    <th>Department(s)</th>
                                    <th>Total Visits</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topVisitors as $vis): ?>
                                <tr onclick="viewVisitDetails(<?php echo $vis['last_visit_id']; ?>)" style="cursor: pointer;" title="Click to view last visit details">
                                    <td class="fw-bold text-start ps-3">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-person-circle me-2 text-primary opacity-50"></i>
                                            <?php echo htmlspecialchars($vis['name']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars(mb_strimwidth($vis['hosts'], 0, 50, "...")); ?></td>
                                    <td><?php echo htmlspecialchars(mb_strimwidth($vis['purposes'], 0, 40, "...")); ?></td>
                                    <td><?php echo htmlspecialchars(mb_strimwidth($vis['departments'], 0, 40, "...")); ?></td>
                                    <td><span class="badge bg-primary rounded-pill"><?php echo $vis['visit_count']; ?></span></td>
                                </tr>
                                <?php
endforeach; ?>
                                <?php if (empty($topVisitors)): ?>
                                    <tr><td colspan="5" class="text-muted py-3">No data available</td></tr>
                                <?php
endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Universal Chart Config
    const chartOptions = { responsive: true, maintainAspectRatio: false };

    // Trend Chart
    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trendLabels); ?>,
            datasets: [{
                label: 'Visits',
                data: <?php echo json_encode($trendCounts); ?>,
                borderColor: '#4361ee',
                backgroundColor: 'rgba(67, 97, 238, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: { ...chartOptions, plugins: { legend: { display: false } } }
    });

    // Hour Chart
    new Chart(document.getElementById('hourChart'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($hourLabels); ?>,
            datasets: [{
                label: 'Visits',
                data: <?php echo json_encode($hourCounts); ?>,
                backgroundColor: '#4cc9f0',
                borderRadius: 5
            }]
        },
        options: { ...chartOptions, plugins: { legend: { display: false } } }
    });

    // Dept Chart
    new Chart(document.getElementById('deptChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($deptLabels); ?>,
            datasets: [{
                data: <?php echo json_encode($deptCountsData); ?>,
                backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#E74C3C', '#2ECC71']
            }]
        },
        options: chartOptions
    });

    // Purpose Chart
    new Chart(document.getElementById('purposeChart'), {
        type: 'pie',
        data: {
            labels: <?php echo json_encode(array_keys($purposeStats)); ?>,
            datasets: [{
                data: <?php echo json_encode(array_values($purposeStats)); ?>,
                backgroundColor: ['#E74C3C', '#3498DB', '#F39C12', '#1ABC9C', '#9B59B6', '#E67E22', '#34495E', '#16A085']
            }]
        },
        options: chartOptions
    });
</script>


<?php require_once '../includes/visit_details_modal.php'; ?>
<?php require_once 'footer.php'; ?>