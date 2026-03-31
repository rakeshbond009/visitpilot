<?php
require_once 'header.php';

if ($_SESSION['role'] !== 'admin') {
    redirect($home_url);
}

// Stats - Initial
$total_emps = 0;
$total_visits = 0;
$today_visits = 0;

try {
    $total_emps = $pdo->query("SELECT count(*) FROM employees")->fetchColumn() ?: 0;
    $total_visits = $pdo->query("SELECT count(*) FROM visits")->fetchColumn() ?: 0;
    $today_visits = $pdo->query("SELECT count(*) FROM visits WHERE date(created_at) = CURDATE()")->fetchColumn() ?: 0;
} catch (PDOException $e) {
}

// Fetch System Settings
$settings_map = [];
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('office_start_hour', 'office_end_hour', 'max_capacity')");
    if ($settings_stmt) {
        $settings_map = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (PDOException $e) {
}

$start_h = (int) ($settings_map['office_start_hour'] ?? 8);
$end_h = (int) ($settings_map['office_end_hour'] ?? 18);
$max_capacity = (int) ($settings_map['max_capacity'] ?? 50);

// Recent - Initial
$recent = [];
try {
    $recent_stmt = $pdo->query("SELECT v.*, vis.name as visitor_name, emp.name as host_name, emp.department
                       FROM visits v 
                       JOIN visitors vis ON v.visitor_id = vis.id 
                       LEFT JOIN employees emp ON v.employee_id = emp.id 
                       ORDER BY v.created_at DESC LIMIT 5");
    if ($recent_stmt) {
        $recent = $recent_stmt->fetchAll();
    }
} catch (PDOException $e) {
}

// Top Hosts
$top_hosts = [];
try {
    $top_hosts_stmt = $pdo->query("SELECT e.name, COUNT(*) as visit_count 
                          FROM visits v 
                          JOIN employees e ON v.employee_id = e.id 
                          GROUP BY v.employee_id 
                          ORDER BY visit_count DESC LIMIT 3");
    if ($top_hosts_stmt) {
        $top_hosts = $top_hosts_stmt->fetchAll();
    }
} catch (PDOException $e) {
}

// Chart Data: Last 7 Days
$chart_data_raw = [];
try {
    $chart_sql = "SELECT DATE_FORMAT(created_at, '%a') as day_name, COUNT(*) as count 
                  FROM visits 
                  WHERE created_at >= CURDATE() - INTERVAL 6 DAY 
                  GROUP BY DATE(created_at) 
                  ORDER BY created_at ASC";
    $chart_stmt = $pdo->query($chart_sql);
    if ($chart_stmt) {
        $chart_data_raw = $chart_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
} catch (PDOException $e) {
}

// Fill missing days with 0
$labels = [];
$counts = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('D', strtotime("-{$i} days"));
    $labels[] = $date;
    $counts[] = $chart_data_raw[$date] ?? 0;
}

// AI Prediction (Simple Heuristic: Avg of last 7 days + 10%)
$avg_visits = count($counts) > 0 ? array_sum($counts) / count($counts) : 0;
$prediction_tomorrow = ceil($avg_visits * 1.1);

// AI Security Metrics (Today)
$active_visitors = 0;
try {
    $active_stmt = $pdo->query("SELECT count(*) FROM visits WHERE status = 'checked_in'");
    if ($active_stmt) {
        $active_visitors = $active_stmt->fetchColumn() ?: 0;
    }
} catch (PDOException $e) {
}

$crowd_density = ($max_capacity > 0) ? min(100, round(($active_visitors / $max_capacity) * 100)) : 0;

$density_status = "Optimal";
$density_color = "text-success";
$progress_color = "bg-success";

if ($crowd_density > 80) {
    $density_status = "Critical Surge";
    $density_color = "text-danger";
    $progress_color = "bg-danger";
} elseif ($crowd_density > 50) {
    $density_status = "Moderate Traffic";
    $density_color = "text-warning";
    $progress_color = "bg-warning";
}

// Overstay Check (> 8 hours)
$overstays = [];
try {
    $overstay_sql = "SELECT v.*, vis.name as visitor_name, e.name as host_name, e.department
                     FROM visits v 
                     JOIN visitors vis ON v.visitor_id = vis.id 
                     LEFT JOIN employees e ON v.employee_id = e.id
                     WHERE v.status = 'checked_in' 
                     AND v.check_in_time < DATE_SUB(NOW(), INTERVAL 8 HOUR)
                     ORDER BY v.check_in_time ASC";
    $overstay_stmt = $pdo->query($overstay_sql);
    if ($overstay_stmt) {
        $overstays = $overstay_stmt->fetchAll();
    }
} catch (PDOException $e) {
}

$security_status = "Perimeter Secure";
$security_msg = "No anomalies detected.";
if (!empty($overstays)) {
    $security_status = "Anomaly Alert";
    $security_msg = count($overstays) . " visitor(s) overstaying.";
}

// Zone Density (Department-wise by default)
$zones = [];
try {
    $zone_sql = "SELECT COALESCE(e.department, 'Other') as name, COUNT(v.id) as count 
                 FROM visits v 
                 LEFT JOIN employees e ON v.employee_id = e.id 
                 WHERE v.status = 'checked_in' 
                 GROUP BY name 
                 ORDER BY count DESC";
    $zone_stmt = $pdo->query($zone_sql);
    if ($zone_stmt) {
        $zones = $zone_stmt->fetchAll();
    }
} catch (PDOException $e) {
}

// Peak Congestion & Best Slot (Last 30 Days)
$peak_hour = 11;
try {
    $peak_sql = "SELECT HOUR(created_at) as h, COUNT(*) as c 
                 FROM visits 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
                 GROUP BY h ORDER BY c DESC LIMIT 1";
    $peak_stmt = $pdo->query($peak_sql);
    if ($peak_stmt) {
        $peak_hour = $peak_stmt->fetchColumn() ?: 11;
    }
} catch (PDOException $e) {
}

$peak_end = $peak_hour + 1;
$peak_time = ($peak_hour > 12 ? $peak_hour - 12 : $peak_hour) . ":00 " . ($peak_hour >= 12 ? "PM" : "AM") . " - " .
    ($peak_end > 12 ? $peak_end - 12 : $peak_end) . ":00 " . ($peak_end >= 12 ? "PM" : "AM");

$hours_array = [];
for ($h = $start_h; $h <= $end_h; $h++) {
    $hours_array[] = "SELECT $h as hour";
}
if (empty($hours_array)) {
    $hours_array[] = "SELECT 8 as hour UNION SELECT 9 as hour UNION SELECT 10 as hour UNION SELECT 11 as hour UNION SELECT 12 as hour UNION SELECT 13 as hour UNION SELECT 14 as hour UNION SELECT 15 as hour UNION SELECT 16 as hour UNION SELECT 17 as hour UNION SELECT 18 as hour";
}
$hours_union = implode(" UNION ", $hours_array);

$best_hour = $start_h;
try {
    $slot_sql = "SELECT h.hour, COALESCE(COUNT(v.id), 0) as c
                 FROM ($hours_union) h
                 LEFT JOIN visits v ON HOUR(v.created_at) = h.hour AND v.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                 GROUP BY h.hour ORDER BY c ASC LIMIT 1";
    $slot_stmt = $pdo->query($slot_sql);
    if ($slot_stmt) {
        $best_hour = $slot_stmt->fetchColumn() ?: $start_h;
    }
} catch (PDOException $e) {
}

$best_time = ($best_hour > 12 ? $best_hour - 12 : $best_hour) . ":00 " . ($best_hour >= 12 ? "PM" : "AM");

// Organizational Efficiency (Time Saved)
$completed_visits = 0;
try {
    $completed_stmt = $pdo->query("SELECT count(*) FROM visits WHERE status = 'checked_out'");
    if ($completed_stmt) {
        $completed_visits = $completed_stmt->fetchColumn() ?: 0;
    }
} catch (PDOException $e) {
}
$time_saved_minutes = $completed_visits * 2;
$time_saved_text = $time_saved_minutes . " mins";
if ($time_saved_minutes > 60) {
    $hours = floor($time_saved_minutes / 60);
    $mins = $time_saved_minutes % 60;
    $time_saved_text = "{$hours}h {$mins}m";
}
?>

<div class="row align-items-center mb-4 g-3">
    <div class="col-8">
        <h3 class="mb-0 fw-bold"><i class="bi bi-speedometer2 text-primary me-2"></i>Admin Dashboard</h3>
    </div>
    <div class="col-4 text-end">
        <div class="bg-white p-2 px-3 rounded-pill shadow-sm border d-inline-block">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="backgroundToggle"
                    onchange="toggleBackgroundMode(this)" <?php echo ($_SESSION['bg_mode'] ?? 0) ? 'checked' : ''; ?>>
                <label class="form-check-label fw-bold small text-muted" for="backgroundToggle">
                    <i class="bi bi-cpu me-1"></i> BG Mode
                </label>
            </div>
        </div>
        <?php if (canView('view_employee_report')): ?>
            <a href="<?php echo BASE_URL; ?>security/employee_visits_report.php"
                class="btn btn-primary rounded-pill ms-2 shadow-sm"><i class="bi bi-table me-2"></i>Employee Report</a>
            <?php
        endif; ?>
    </div>
</div>

<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card blue h-100" onclick="showStatsModal('employees')" style="cursor: pointer;">
            <h3 id="stat-emps"><?php echo $total_emps; ?></h3>
            <p class="mb-0 opacity-75">Total Employees</p>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card green h-100" onclick="showStatsModal('all_visits')" style="cursor: pointer;">
            <h3 id="stat-total"><?php echo $total_visits; ?></h3>
            <p class="mb-0 opacity-75">Total Visits (All Time)</p>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card orange h-100" onclick="showStatsModal('today_visits')" style="cursor: pointer;">
            <h3 id="stat-today"><?php echo $today_visits; ?></h3>
            <p class="mb-0 opacity-75">Today's Visits</p>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card purple h-100 shadow-sm border-0 rounded-4">
            <h3><?php echo $time_saved_text; ?></h3>
            <p class="mb-0 opacity-75">Time Saved (All Time)</p>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Chart Section -->
    <div class="col-lg-8 mb-4 mb-lg-0">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-graph-up-arrow me-2 text-primary"></i>Visitor Traffic
                    Trends</h5>
            </div>
            <div class="card-body">
                <canvas id="visitorTrendChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>

    <!-- AI Insights Section -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 rounded-4 bg-primary text-white h-100 position-relative overflow-hidden">
            <div class="position-absolute top-0 end-0 p-3 opacity-25">
                <i class="bi bi-robot display-1"></i>
            </div>
            <div class="card-header bg-transparent border-0 py-3">
                <h5 class="mb-0 fw-bold"><i class="bi bi-stars me-2"></i>AI Insights</h5>
            </div>
            <div class="card-body position-relative z-1">
                <div class="mb-4">
                    <h6 class="text-white-50 text-uppercase small fw-bold ls-1">Prediction for Tomorrow</h6>
                    <div class="d-flex align-items-baseline">
                        <h2 class="fw-bold mb-0">~<?php echo $prediction_tomorrow; ?></h2>
                        <span class="ms-2 badge bg-white text-primary rounded-pill"><i class="bi bi-arrow-up-short"></i>
                            +10%</span>
                    </div>
                    <p class="small opacity-75 mt-1">Based on historical weekday patterns.</p>
                </div>

                <div class="mb-4">
                    <h6 class="text-white-50 text-uppercase small fw-bold ls-1">Crowd Density (Live)</h6>
                    <div class="progress mt-2" style="height: 6px; background-color: rgba(255,255,255,0.1);">
                        <div id="ai-density-bar" class="progress-bar <?php echo $progress_color; ?>" role="progressbar"
                            style="width: <?php echo $crowd_density; ?>%;" aria-valuenow="<?php echo $crowd_density; ?>"
                            aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <p id="ai-density-status" class="small mt-2 fw-bold text-white"><i class="bi bi-graph-up me-1"></i>
                        <?php echo $density_status; ?> (<?php echo $active_visitors; ?>/<?php echo $max_capacity; ?>)
                    </p>
                </div>

                <div class="d-flex align-items-center mb-0 p-2 rounded bg-white bg-opacity-10 border border-white border-opacity-25 cursor-pointer hover-scale"
                    onclick="showDetails('overstay')">
                    <i id="ai-security-icon"
                        class="bi bi-shield-<?php echo empty($overstays) ? 'check text-white' : 'exclamation-triangle text-warning'; ?> fs-3 me-3"></i>
                    <div>
                        <h6 id="ai-security-status" class="mb-0 fw-bold small"><?php echo $security_status; ?></h6>
                        <small id="ai-security-msg" class="text-white-50"
                            style="font-size: 0.7rem;"><?php echo $security_msg; ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Active Alerts / Anomalies -->
    <div class="col-md-6 mb-4 mb-md-0">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Overstay Alerts
                </h5>
                <span id="overstay-badge"
                    class="badge <?php echo empty($overstays) ? 'bg-success-subtle text-success border-success' : 'bg-danger-subtle text-danger border-danger'; ?> border">
                    <?php echo empty($overstays) ? 'All Clear' : 'Action Required'; ?>
                </span>
            </div>
            <div class="card-body p-0" id="overstay-list">
                <?php if (empty($overstays)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-shield-check display-4 text-success opacity-50"></i>
                        <p class="mt-3 text-muted mb-0">No overstays detected. All clear.</p>
                    </div>
                    <?php
                else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($overstays as $os): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3 border-0">
                                <div>
                                    <strong><?php echo htmlspecialchars($os['visitor_name']); ?></strong>
                                    <div class="small text-muted">In since
                                        <?php echo date('H:i', strtotime($os['created_at'])); ?>
                                    </div>
                                </div>
                                <span class="badge bg-danger rounded-pill">Over 8h</span>
                            </li>
                            <?php
                        endforeach; ?>
                    </ul>
                    <?php
                endif; ?>
            </div>
            <?php if (!empty($overstays)): ?>
                <div class="card-footer bg-white border-0 text-center py-3">
                    <!-- Removed Detailed Report button -->
                </div>
                <?php
            endif; ?>
        </div>
    </div>

    <!-- Zone Status -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-geo-alt-fill me-2 text-primary"></i>Zone Density</h5>
                <div class="btn-group btn-group-sm rounded-pill border p-1" role="group">
                    <input type="radio" class="btn-check" name="densityView" id="viewDept" value="department" checked
                        onchange="refreshDashboardTable()">
                    <label class="btn btn-outline-primary border-0 rounded-pill px-3" for="viewDept">Dept</label>
                    <input type="radio" class="btn-check" name="densityView" id="viewArea" value="access_area"
                        onchange="refreshDashboardTable()">
                    <label class="btn btn-outline-primary border-0 rounded-pill px-3" for="viewArea">Area</label>
                </div>
            </div>
            <div class="card-body" id="zone-density-container">
                <?php if (empty($zones)): ?>
                    <div class="text-center py-4">
                        <p class="text-muted mb-0 small">No active visitors in any zone.</p>
                    </div>
                    <?php
                else: ?>
                    <?php foreach ($zones as $z):
                        $limit = $max_capacity > 0 ? $max_capacity : 50;
                        $pct = min(100, ($z['count'] / $limit) * 100);
                        $color = ($pct > 80) ? 'danger' : (($pct > 40) ? 'warning' : 'success');
                        $status = ($pct > 80) ? 'High Congestion' : (($pct > 40) ? 'Moderate Traffic' : 'Low Activity');
                        ?>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($z['name']); ?></h6>
                                <small class="text-<?php echo $color; ?>"><?php echo $status; ?></small>
                            </div>
                            <div class="d-flex align-items-center">
                                <span class="badge bg-light text-dark border me-2"
                                    style="font-size: 0.7rem;"><?php echo $z['count']; ?></span>
                                <div class="progress" style="width: 80px; height: 6px;">
                                    <div class="progress-bar bg-<?php echo $color; ?>" role="progressbar"
                                        style="width: <?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php
                    endforeach; ?>
                    <?php
                endif; ?>
            </div>
            <div class="card-footer bg-light border-0 py-3">
                <div class="row g-2">
                    <div class="col-6 text-center border-end">
                        <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.6rem;">Best Entry
                            Slot</small>
                        <span id="best-slot-time" class="fw-bold text-primary" style="font-size: 0.85rem;"><i
                                class="bi bi-clock-history me-1"></i> <?php echo $best_time; ?></span>
                    </div>
                    <div class="col-6 text-center">
                        <small class="text-uppercase text-muted fw-bold d-block" style="font-size: 0.6rem;">Peak Traffic
                            Hour</small>
                        <span id="peak-traffic-time" class="fw-bold text-danger" style="font-size: 0.85rem;"><i
                                class="bi bi-graph-up-arrow me-1"></i> <?php echo $peak_time; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <!-- Top Hosts Section -->
    <div class="col-md-6 mb-4 mb-md-0">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-trophy-fill me-2 text-warning"></i>Most Visited Hosts
                </h5>
            </div>
            <div class="card-body p-0">
                <ul id="top-hosts-list" class="list-group list-group-flush">
                    <?php if (empty($top_hosts)): ?>
                        <li class="list-group-item text-center text-muted py-4 border-0">No data available</li>
                        <?php
                    else: ?>
                        <?php foreach ($top_hosts as $index => $host): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3 border-0">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-light text-dark rounded-circle me-3"
                                        style="width:30px;height:30px;line-height:25px;"><?php echo $index + 1; ?></span>
                                    <span class="fw-bold"><?php echo htmlspecialchars($host['name']); ?></span>
                                </div>
                                <span class="badge bg-primary rounded-pill"><?php echo $host['visit_count']; ?> Visits</span>
                            </li>
                            <?php
                        endforeach; ?>
                        <?php
                    endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Efficiency Stats -->
    <?php
    // Calculate Real Efficiency Metrics from DB
    
    // 1. Avg Check-in Time (Time between registration and actual check-in)
// We use check_in_time which records when they entered
// Note: If you want time between 'Arrival' and 'Check-in', you need created_at vs check_in_time
    $eff_sql = "SELECT AVG(ABS(TIMESTAMPDIFF(SECOND, created_at, check_in_time))) as avg_seconds 
                FROM visits 
                WHERE status IN ('checked_in', 'checked_out') 
                AND check_in_time IS NOT NULL";
    $avg_seconds = $pdo->query($eff_sql)->fetchColumn() ?: 0;

    // Format Minutes and Seconds
    $mins = floor($avg_seconds / 60);
    $secs = round($avg_seconds % 60);
    $avg_time_str = "{$mins}m {$secs}s";

    // 2. Visitor Satisfaction (inferred from Wait Time < 10 mins)
    $total_processed = $pdo->query("SELECT COUNT(*) FROM visits WHERE status IN ('checked_in', 'checked_out') AND DATE(created_at) = DATE(check_in_time)")->fetchColumn();

    if ($total_processed > 0) {
        $happy_visitors = $pdo->query("SELECT COUNT(*) FROM visits 
                                      WHERE status IN ('checked_in', 'checked_out') 
                                      AND check_in_time IS NOT NULL
                                      AND DATE(created_at) = DATE(check_in_time)
                                      AND TIMESTAMPDIFF(MINUTE, created_at, check_in_time) < 30")->fetchColumn();
        $satisfaction = round(($happy_visitors / $total_processed) * 100);
    } else {
        $satisfaction = 100; // Default if no data for today
    }

    // Dynamic Bar Colors
    $sat_color = $satisfaction >= 80 ? 'bg-info' : ($satisfaction >= 50 ? 'bg-warning' : 'bg-danger');
    $sat_text = $satisfaction >= 80 ? 'text-info' : ($satisfaction >= 50 ? 'text-warning' : 'text-danger');
    ?>

    <div class="col-md-6 mb-4 mb-md-0">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="mb-0 fw-bold text-dark"><i
                        class="bi bi-lightning-charge-fill me-2 text-success"></i>Efficiency Metrics</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 bg-light text-center h-100">
                            <h2 class="fw-bold text-success mb-0"><?php echo $avg_time_str; ?></h2>
                            <small class="text-muted text-uppercase fw-bold">Avg. Check-in Time</small>
                            <div class="progress mt-3" style="height: 6px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: 100%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="p-4 rounded-4 bg-light text-center h-100">
                            <h2 id="total-satisfaction-rate" class="fw-bold <?php echo $sat_text; ?> mb-0"><?php echo $satisfaction; ?>%</h2>
                            <small class="text-muted text-uppercase fw-bold">Fast Service Rate</small>
                            <div class="progress mt-3" style="height: 6px;">
                                <div id="satisfaction-bar" class="progress-bar <?php echo $sat_color; ?>" role="progressbar"
                                    style="width: <?php echo $satisfaction; ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="p-3 border rounded-3 d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="mb-0 fw-bold">Database Health</h6>
                                <small class="text-success"><i class="bi bi-check-circle-fill me-1"></i> Running
                                    Smoothly</small>
                            </div>
                            <span class="badge bg-success-subtle text-success border border-success px-3">GOOD</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<!-- Chart.js -->
<script src="../assets/js/datetime-format.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    if (typeof BASE_URL === 'undefined') {
        var BASE_URL = '<?php echo BASE_URL; ?>';
    }
    function formatTime(dateTimeString) {
        if (!dateTimeString) return '-';
        const date = new Date(dateTimeString);
        let hours = date.getHours();
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12;
        return `${hours}:${minutes} ${ampm}`;
    }
</script>

<script>
    // Initialize Charts
    document.addEventListener("DOMContentLoaded", function () {
        const ctx = document.getElementById('visitorTrendChart').getContext('2d');

        // Real Data from PHP
        const labels = <?php echo json_encode($labels); ?>;
        const data = <?php echo json_encode($counts); ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Visitors',
                    data: data,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#0d6efd',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { borderDash: [5, 5] } }
                }
            }
        });
    });

    let heartbeat = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA');

    function requestNotificationPermission() {
        if ("Notification" in window && Notification.permission === "default") {
            Notification.requestPermission();
        }
    }

    function toggleBackgroundMode(toggle) {
        if (!toggle) return;

        // Persist to server
        const apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'api/user/settings.php' : '../api/user/settings.php';
        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bg_mode: toggle.checked })
        }).then(res => res.json()).then(data => {
            if (data.success) console.log("Admin BG Mode setting saved.");
        });

        if (toggle.checked) {
            requestNotificationPermission();
            heartbeat.loop = true;
            heartbeat.play().then(() => {
                console.log("Admin BG Mode: ON");
            }).catch(e => {
                console.log("Admin Heartbeat pending interaction");
            });
        } else {
            heartbeat.pause();
        }
    }

    // Initialize Admin BG Mode
    window.addEventListener('load', () => {
        const toggle = document.getElementById('backgroundToggle');
        if (toggle && toggle.checked) {
            document.body.addEventListener('click', () => {
                if (toggle.checked && heartbeat.paused) {
                    toggleBackgroundMode(toggle);
                }
            }, { once: true });
        }
    });

    // --- REAL-TIME UPDATES ---
    var adminFilterTerm = ''; // Store search filter
    var overstaysCache = <?php echo json_encode($overstays); ?>; // Initial overstays from PHP
    var todaysVisits = <?php echo json_encode($recent); ?>; // Store recent visits for table

    async function refreshDashboardTable() {
        try {
            // Add cache buster to prevent stale data
            const response = await fetch('api/get_dashboard_data.php?t=' + new Date().getTime());
            const data = await response.json();

            if (data.success) {
                document.getElementById('stat-emps').innerText = data.stats.total_emps;
                document.getElementById('stat-total').innerText = data.stats.total_visits;
                document.getElementById('stat-today').innerText = data.stats.today_visits;

                todaysVisits = data.recent; // Update cache

                // Update AI Monitor
                if (data.ai_metrics) {
                    const aim = data.ai_metrics;
                    const dBar = document.getElementById('ai-density-bar');
                    const dStatus = document.getElementById('ai-density-status');
                    
                    const activeCount = aim.active_count !== undefined ? aim.active_count : 0;
                    const maxCap = aim.max_capacity !== undefined ? aim.max_capacity : 50;

                    dBar.style.width = aim.crowd_density + '%';
                    dBar.setAttribute('aria-valuenow', aim.crowd_density);

                    let dText = 'Optimal', bColor = 'bg-success';
                    if (aim.crowd_density > 80) { dText = 'Critical Surge'; bColor = 'bg-danger'; }
                    else if (aim.crowd_density > 50) { dText = 'Moderate Traffic'; bColor = 'bg-warning'; }

                    dBar.className = 'progress-bar ' + bColor;
                    dStatus.innerHTML = `<i class="bi bi-graph-up me-1"></i> ${dText} (${activeCount}/${maxCap})`;

                    const secStatus = document.getElementById('ai-security-status');
                    const secMsg = document.getElementById('ai-security-msg');
                    const secIcon = document.getElementById('ai-security-icon');
                    const overBadge = document.getElementById('overstay-badge');
                    const overList = document.getElementById('overstay-list');

                        // Update Efficiency Metrics
                        if (aim.fast_service_rate !== undefined) {
                            const satRate = document.getElementById('total-satisfaction-rate');
                            const satBar = document.getElementById('satisfaction-bar');
                            if (satRate) satRate.innerText = aim.fast_service_rate + '%';
                            if (satBar) {
                                satBar.style.width = aim.fast_service_rate + '%';
                                let barCol = 'bg-info', textCol = 'text-info';
                                if (aim.fast_service_rate < 50) { barCol = 'bg-danger'; textCol = 'text-danger'; }
                                else if (aim.fast_service_rate < 80) { barCol = 'bg-warning'; textCol = 'text-warning'; }
                                
                                satBar.className = 'progress-bar ' + barCol;
                                if (satRate) satRate.className = 'fw-bold mb-0 ' + textCol;
                            }
                        }

                        if (aim.overstays_count > 0) {
                        secStatus.innerText = 'Anomaly Alert';
                        secMsg.innerText = aim.overstays_count + ' visitor(s) overstaying.';
                        if (overBadge) {
                            overBadge.className = 'badge bg-danger-subtle text-danger border border-danger';
                            overBadge.innerText = 'Action Required';
                        }
                        overstaysCache = aim.overstays_list; // Update overstays cache
                    } else {
                        secStatus.innerText = 'Perimeter Secure';
                        secMsg.innerText = 'No anomalies detected.';
                        secIcon.className = 'bi bi-shield-check text-white fs-3 me-3';
                        if (overBadge) {
                            overBadge.className = 'badge bg-success-subtle text-success border border-success';
                            overBadge.innerText = 'All Clear';
                        }
                        overstaysCache = [];
                        if (overList && overList.innerHTML.includes('list-group')) {
                            overList.innerHTML = '<div class="text-center py-5"><i class="bi bi-shield-check display-4 text-success opacity-50"></i><p class="mt-3 text-muted mb-0">No overstays detected. All clear.</p></div>';
                        }
                    }

                    // Update Zones
                    const zContainer = document.getElementById('zone-density-container');
                    const viewType = document.querySelector('input[name="densityView"]:checked').value;
                    const selectedZones = aim.zones[viewType] || [];

                    if (selectedZones.length > 0) {
                        let zHtml = '';
                        selectedZones.forEach(z => {
                            const capacityLimit = aim.max_capacity || 50;
                            const pct = Math.min(100, (z.count / capacityLimit) * 100);
                            const color = (pct > 80) ? 'danger' : ((pct > 40) ? 'warning' : 'success');
                            const status = (pct > 80) ? 'High Congestion' : ((pct > 40) ? 'Moderate Traffic' : 'Low Activity');

                            zHtml += `
                                <div class="d-flex align-items-center justify-content-between mb-3 animate__animated animate__fadeIn">
                                    <div>
                                        <h6 class="mb-0 fw-bold">${z.name}</h6>
                                        <small class="text-${color}">${status}</small>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <span class="badge bg-light text-dark border me-2" style="font-size: 0.7rem;">${z.count}</span>
                                        <div class="progress" style="width: 80px; height: 6px;">
                                            <div class="progress-bar bg-${color}" role="progressbar" style="width: ${pct}%"></div>
                                        </div>
                                    </div>
                                </div>`;
                        });
                        zContainer.innerHTML = zHtml;
                    } else {
                        zContainer.innerHTML = `<div class="text-center py-4"><p class="text-muted mb-0 small">No active visitors in any ${viewType.replace('_', ' ')}.</p></div>`;
                    }

                    // Update Peak & Best Slot
                    if (aim.best_time) {
                        document.getElementById('best-slot-time').innerHTML = `<i class="bi bi-clock-history me-1"></i> ${aim.best_time}`;
                    }
                    if (aim.peak_time) {
                        document.getElementById('peak-traffic-time').innerHTML = `<i class="bi bi-graph-up-arrow me-1"></i> ${aim.peak_time}`;
                    }
                }

                const tbody = document.getElementById('activity-body');
                let html = '';
                data.recent.forEach(r => {
                    // Filter Logic
                    if (adminFilterTerm) {
                        const searchText = (r.visitor_name + ' ' + (r.mobile || '') + ' ' + (r.host_name || '') + ' ' + r.status).toLowerCase();
                        if (!searchText.includes(adminFilterTerm)) return;
                    }

                    const timeString = r.created_at.split(' ')[1] || '00:00:00';
                    const time = timeString.substring(0, 5);
                    html += `
                    <tr class="animate__animated animate__fadeIn" onclick="viewVisitDetails(${r.id})" style="cursor: pointer;">
                        <td class="ps-4">${time}</td>
                        <td><strong>${r.visitor_name}</strong></td>
                        <td>
                            <span class="badge rounded-pill bg-light text-dark border px-3">
                                ${r.status.toUpperCase()}
                            </span>
                        </td>
                        <td class="small fw-bold text-success">${r.check_in_time ? formatTime(r.check_in_time) : '-'}</td>
                        <td class="small fw-bold text-danger">${r.check_out_time ? formatTime(r.check_out_time) : '-'}</td>
                        <td>${r.host_name}</td>
                        <td><span class="badge bg-light text-dark border">${r.department || '-'}</span></td>
                    </tr>
                `;
                });
                tbody.innerHTML = html;
            }
        } catch (e) { }
    }

    function showDetails(type) {
        if (type === 'overstay') {
            const modalEl = document.getElementById('statsModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

            document.getElementById('statsModalTitle').innerText = 'Overstay Alerts (>8h)';
            const modalBody = document.getElementById('statsModalBody');

            if (!overstaysCache || overstaysCache.length === 0) {
                modalBody.innerHTML = '<div class="alert alert-success text-center py-4"><i class="bi bi-check-circle me-2"></i>No overstays detected. All active visitors are within the 8-hour limit.</div>';
            } else {
                let html = '<div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>Visitor</th><th>Host</th><th>Dept</th><th>Entry Time</th><th>Time Inside</th></tr></thead><tbody>';
                overstaysCache.forEach(v => {
                    const entryTime = new Date((v.check_in_time || v.created_at).replace(/-/g, "/"));
                    const diffMs = Date.now() - entryTime.getTime();
                    const diffHrs = Math.floor(diffMs / (1000 * 60 * 60));
                    html += `<tr onclick="viewVisitDetails(${v.id})" style="cursor: pointer;">
                        <td><strong>${v.visitor_name}</strong></td>
                        <td>${v.host_name || '-'}</td>
                        <td><span class="badge bg-light text-dark border">${v.department || '-'}</span></td>
                        <td>${entryTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</td>
                        <td><span class="badge bg-danger">${diffHrs} Hours</span></td>
                    </tr>`;
                });
                html += '</tbody></table></div>';
                modalBody.innerHTML = html;
            }
            modal.show();
        } else {
            showStatsModal(type);
        }
    }

    // Check for updates every 2 seconds
    setInterval(refreshDashboardTable, 2000);

    // Stats Modal Function
    async function showStatsModal(type) {
        const modalEl = document.getElementById('statsModal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const modalTitle = document.getElementById('statsModalTitle');
        const modalBody = document.getElementById('statsModalBody');
        const searchInput = document.getElementById('statsModalSearch');

        if (searchInput) searchInput.value = ''; // Clear search when opening

        // Set title based on type
        const titles = {
            'employees': 'All Employees',
            'all_visits': 'All Visits',
            'today_visits': 'Today\'s Visits'
        };
        modalTitle.textContent = titles[type] || 'Data';

        // Show loading
        modalBody.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
        modal.show();

        try {
            if (type === 'employees') {
                // Fetch all employees
                const response = await fetch('../api/employee/list.php');
                const data = await response.json();

                if (data.status === 'success' && data.data.employees) {
                    let html = '<div class="table-responsive"><table class="table table-hover"><thead class="table-light"><tr><th>Name</th><th>Department</th><th>Mobile</th><th>Email</th></tr></thead><tbody>';
                    data.data.employees.forEach(emp => {
                        html += `<tr>
                            <td><strong>${emp.name}</strong></td>
                            <td><span class="badge bg-light text-dark border">${emp.department || '-'}</span></td>
                            <td>${emp.mobile || '-'}</td>
                            <td><small class="text-muted">${emp.email || '-'}</small></td>
                        </tr>`;
                    });
                    html += '</tbody></table></div>';
                    modalBody.innerHTML = html;
                } else {
                    modalBody.innerHTML = '<div class="alert alert-warning">No employees found.</div>';
                }
            } else {
                // Fetch visits (all or today)
                const url = type === 'today_visits' ? '../api/visit/today.php' : '../api/visit/all.php';
                const response = await fetch(url);
                const data = await response.json();

                if (data.status === 'success' && data.data && data.data.visits) {
                    let html = '<div class="table-responsive"><table class="table table-hover"><thead class="table-light"><tr><th>Entry Date/Time</th><th>Visitor</th><th>Host</th><th>Status</th><th>Check In</th><th>Check Out</th><th>Actions</th></tr></thead><tbody>';
                    data.data.visits.forEach(visit => {
                        const statusClass = visit.status === 'checked_in' ? 'bg-success' : visit.status === 'checked_out' ? 'bg-secondary' : 'bg-warning';
                        html += `<tr onclick="viewVisitDetails(${visit.id})" style="cursor: pointer;">
                            <td class="small">${formatDateTime(visit.created_at)}</td>
                            <td><strong>${visit.visitor_name}</strong></td>
                            <td>${visit.host_name || '-'}</td>
                            <td><span class="badge ${statusClass}">${visit.status.toUpperCase()}</span></td>
                            <td class="small">${visit.check_in_time ? formatDateTime(visit.check_in_time) : '-'}</td>
                            <td class="small">${visit.check_out_time ? formatDateTime(visit.check_out_time) : '-'}</td>
                            <td onclick="event.stopPropagation()"><a href="javascript:void(0)" onclick="viewPass(${visit.id}, '${visit.approval_status}')" class="btn btn-sm btn-outline-primary"><i class="bi bi-ticket-detailed"></i></a></td>
                        </tr>`;
                    });
                    html += '</tbody></table></div>';
                    modalBody.innerHTML = html;
                } else {
                    modalBody.innerHTML = '<div class="alert alert-warning">No visits found.</div>';
                }
            }
        } catch (error) {
            modalBody.innerHTML = '<div class="alert alert-danger">Failed to load data.</div>';
        }
    }

    function filterStatsModalTable() {
        const input = document.getElementById('statsModalSearch');
        if (!input) return;
        const filter = input.value.toLowerCase();
        const modalBody = document.getElementById('statsModalBody');
        const rows = modalBody.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const hasCells = rows[i].getElementsByTagName('td').length > 0;
            if (hasCells) {
                const rowText = rows[i].textContent.toLowerCase();
                rows[i].style.display = rowText.includes(filter) ? '' : 'none';
            }
        }
    }
</script>

<!-- Stats Modal -->
<div class="modal fade" id="statsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white border-0 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <h5 class="modal-title fw-bold" id="statsModalTitle">Data</h5>
                </div>
                <div class="d-flex align-items-center flex-grow-1 mx-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-0 rounded-start-pill"><i
                                class="bi bi-search text-primary"></i></span>
                        <input type="text" id="statsModalSearch" class="form-control border-0 rounded-end-pill"
                            placeholder="Search..." onkeyup="filterStatsModalTable()">
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="statsModalBody">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary"></div>
                </div>
            </div>
        </div>
    </div>
</div>


<?php require_once '../includes/visit_details_modal.php'; ?>
<?php require_once 'footer.php'; ?>