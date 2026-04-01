<?php
require_once 'header.php';

// Redirect non-security/non-admin users to their appropriate dashboard
if ($_SESSION['role'] !== 'security' && $_SESSION['role'] !== 'admin') {
    redirect($home_url);
}

// Stats - Initial Load
$stmt = $pdo->query("SELECT count(*) FROM visits WHERE date(created_at) = CURDATE()");
$total_today = $stmt->fetchColumn();

// Fetch System Settings
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('office_start_hour', 'office_end_hour', 'max_capacity')");
$settings_map = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$start_h = (int) ($settings_map['office_start_hour'] ?? 8);
$end_h = (int) ($settings_map['office_end_hour'] ?? 18);
$max_capacity = (int) ($settings_map['max_capacity'] ?? 50);

$stmt = $pdo->query("SELECT count(*) FROM visits WHERE status = 'checked_in'");
$active_visitors = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT count(*) FROM visits WHERE approval_status = 'pending'");
$pending_approvals = $stmt->fetchColumn();

// Check-in Pending (Approved but not checked in)
// Logic: status='approved' AND (created today OR (invite for today))
$stmt = $pdo->query("SELECT count(*) FROM visits WHERE status = 'approved' AND (date(created_at) = CURDATE() OR (is_invited=1 AND visit_date = CURDATE()))");
$checkin_pending = $stmt->fetchColumn();

// List - Initial Load
$sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, emp.name as host_name, emp.department
        FROM visits v 
        JOIN visitors vis ON v.visitor_id = vis.id 
        LEFT JOIN employees emp ON v.employee_id = emp.id 
        WHERE DATE(v.created_at) = CURDATE() 
           OR v.status = 'checked_in' 
           OR v.approval_status = 'pending'
           OR DATE(v.approved_at) = CURDATE()
           OR (v.status = 'approved' AND (DATE(v.created_at) = CURDATE() OR (v.is_invited=1 AND v.visit_date = CURDATE())))
        ORDER BY v.created_at DESC";
$stmt = $pdo->query($sql);
$visits = $stmt->fetchAll();

// Traffic Chart Data (Today by Hour)
$traffic_sql = "SELECT HOUR(created_at) as hour, COUNT(*) as count 
                FROM visits 
                WHERE date(created_at) = CURDATE()
                GROUP BY HOUR(created_at)";
$traffic_raw = $pdo->query($traffic_sql)->fetchAll(PDO::FETCH_KEY_PAIR);

// Overstay Check (> 8 hours)
$overstay_sql = "SELECT v.*, vis.name 
                 FROM visits v 
                 JOIN visitors vis ON v.visitor_id = vis.id 
                 WHERE v.status = 'checked_in' 
                 AND v.check_in_time < DATE_SUB(NOW(), INTERVAL 8 HOUR)";
$overstays = $pdo->query($overstay_sql)->fetchAll();

// Hours from settings
$traffic_hours = range($start_h, $end_h);
$traffic_data = [];
foreach ($traffic_hours as $h) {
    $traffic_data[] = $traffic_raw[$h] ?? 0;
}

// AI Metrics Calculation
$crowd_density = ($max_capacity > 0) ? min(100, round(($active_visitors / $max_capacity) * 100)) : 0;

// Zone Density (Department-wise by default)
$dept_sql = "SELECT COALESCE(e.department, 'Lobby/General') as zone, COUNT(*) as count 
             FROM visits v 
             LEFT JOIN employees e ON v.employee_id = e.id 
             WHERE v.status = 'checked_in' 
             GROUP BY zone 
             ORDER BY count DESC";
$zones = $pdo->query($dept_sql)->fetchAll();

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

// Calculate Avg Check-in Time (last 24h)
$avg_sql = "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, check_in_time)) 
            FROM visits 
            WHERE status IN ('checked_in', 'checked_out') 
            AND check_in_time IS NOT NULL 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
$avg_seconds = $pdo->query($avg_sql)->fetchColumn() ?: 45;
$avg_display = ($avg_seconds < 60) ? round($avg_seconds) . "s" : round($avg_seconds / 60) . "m";

$security_status = "Perimeter Secure";
$security_msg = "No anomalies detected.";
if (!empty($overstays)) {
    $security_status = "Anomaly Alert";
    $security_msg = count($overstays) . " visitor(s) overstaying.";
}

// Peak Congestion & Best Slot (Last 30 Days)
$peak_sql = "SELECT HOUR(created_at) as h, COUNT(*) as c 
             FROM visits 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) 
             GROUP BY h ORDER BY c DESC LIMIT 1";
$peak_hour = $pdo->query($peak_sql)->fetchColumn() ?: 11;
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
$best_hour = $pdo->query($slot_sql)->fetchColumn() ?: $start_h;
$best_time = ($best_hour > 12 ? $best_hour - 12 : $best_hour) . ":00 " . ($best_hour >= 12 ? "PM" : "AM");
// Organizational Efficiency (Time Saved)
$completed_count = $pdo->query("SELECT count(*) FROM visits WHERE status = 'checked_out'")->fetchColumn();
$time_saved_min = $completed_count * 2;
$time_saved_fmt = $time_saved_min . " mins";
if ($time_saved_min > 60) {
    $h_saved = floor($time_saved_min / 60);
    $m_saved = $time_saved_min % 60;
    $time_saved_fmt = "{$h_saved}h {$m_saved}m";
}
?>



<div class="row align-items-center mb-4 g-3">
    <div class="col-8">
        <h3 class="mb-0 fw-bold"><i class="bi bi-shield-lock-fill text-primary"></i> Reception Dashboard</h3>
    </div>
    <div class="col-4 text-end">
        <div id="alarm-control" class="d-none me-2 d-inline-block">
            <button onclick="stopAlarm()"
                class="btn btn-danger btn-sm rounded-pill px-3 animate__animated animate__flash animate__infinite">
                <i class="bi bi-volume-mute-fill"></i> STOP SOUND
            </button>
        </div>
        <?php if (canView('view_employee_report')): ?>
            <a href="<?php echo BASE_URL; ?>security/employee_visits_report.php"
                class="btn btn-outline-primary btn-sm rounded-pill me-2"><i class="bi bi-table me-1"></i> Employee
                Report</a>
            <?php
        endif; ?>
        <div class="bg-white p-2 px-3 rounded-pill shadow-sm border d-inline-block">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="backgroundToggle"
                    onchange="toggleBackgroundMode(this)" <?php echo ($_SESSION['bg_mode'] ?? 0) ? 'checked' : ''; ?>>
                <label class="form-check-label fw-bold small text-muted" for="backgroundToggle">
                    <i class="bi bi-cpu me-1"></i> BG Mode
                </label>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-card blue cursor-pointer hover-scale" onclick="showDetails('total')">
            <h3 id="stat-total"><?php echo $total_today; ?></h3>
            <p>Total Visitors Today</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card green cursor-pointer hover-scale" onclick="showDetails('active')">
            <h3 id="stat-active"><?php echo $active_visitors; ?></h3>
            <p>Currently Inside</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card orange cursor-pointer hover-scale" onclick="showDetails('pending')">
            <h3 id="stat-pending"><?php echo $pending_approvals; ?></h3>
            <p>Pending Approvals</p>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card purple cursor-pointer hover-scale" onclick="showDetails('checkin_pending')">
            <h3 id="stat-checkin-pending"><?php echo $checkin_pending; ?></h3>
            <p>Check-in Pending</p>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="p-3 bg-white border rounded-4 shadow-sm d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center">
                <div class="p-3 bg-primary bg-opacity-10 rounded-circle me-3">
                    <i class="bi bi-lightning-charge-fill text-primary fs-4"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-0">Operational Efficiency</h5>
                    <p class="text-muted small mb-0">Total time saved by digitalizing the reception process.</p>
                </div>
            </div>
            <div class="text-end">
                <h3 class="fw-bold text-primary mb-0"><?php echo $time_saved_fmt; ?></h3>
                <small class="text-muted fw-bold text-uppercase">Time Saved</small>
            </div>
        </div>
    </div>
</div>

<style>
    .hover-scale {
        transition: transform 0.2s;
    }

    .stat-card.purple {
        background: linear-gradient(135deg, #8E2DE2 0%, #4A00E0 100%) !important;
        color: white !important;
    }

    .hover-scale:hover {
        transform: scale(1.02);
    }

    .cursor-pointer {
        cursor: pointer;
    }
</style>

<div class="row mb-4">
    <!-- Traffic Chart -->
    <div class="col-lg-8 mb-4 mb-lg-0">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Hourly Entry
                    Traffic</h5>
            </div>
            <div class="card-body">
                <canvas id="trafficChart" style="max-height: 250px;"></canvas>
            </div>
        </div>
    </div>

    <!-- AI Security Monitor -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 rounded-4 bg-dark text-white h-100 position-relative overflow-hidden">
            <!-- Background pulse effect -->
            <div class="position-absolute top-50 start-50 translate-middle"
                style="width: 200px; height: 200px; background: radial-gradient(circle, rgba(52,58,64,1) 0%, rgba(0,0,0,0) 70%); z-index: 0;">
            </div>

            <div class="card-header bg-transparent border-0 py-3 d-flex justify-content-between">
                <h5 class="mb-0 fw-bold"><i class="bi bi-eye-fill me-2"></i>AI Monitor</h5>
                <span class="badge bg-success animate__animated animate__pulse animate__infinite">LIVE</span>
            </div>
            <div class="card-body position-relative z-1">
                <div class="mb-4 text-center">
                    <h6 class="text-white-50 text-uppercase small fw-bold ls-1">Crowd Density Prediction</h6>
                    <div class="progress mt-2" style="height: 10px; background-color: rgba(255,255,255,0.1);">
                        <div id="ai-density-bar" class="progress-bar <?php echo $progress_color; ?>" role="progressbar"
                            style="width: <?php echo $crowd_density; ?>%;" aria-valuenow="<?php echo $crowd_density; ?>"
                            aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <p id="ai-density-status" class="small mt-2 <?php echo $density_color; ?> fw-bold"><i
                            class="bi bi-graph-up me-1"></i>
                        <?php echo $density_status; ?> (<?php echo $active_visitors; ?>/<?php echo $max_capacity; ?>)
                    </p>
                </div>

                <div class="d-flex align-items-center mb-3 p-2 rounded bg-white bg-opacity-10 border border-secondary cursor-pointer hover-scale"
                    onclick="showDetails('overstay')">
                    <i id="ai-security-icon"
                        class="bi bi-shield-<?php echo empty($overstays) ? 'check text-success' : 'exclamation text-danger'; ?> fs-2 me-3"></i>
                    <div>
                        <h6 id="ai-security-status" class="mb-0 fw-bold"><?php echo $security_status; ?></h6>
                        <small id="ai-security-msg" class="text-white-50"><?php echo $security_msg; ?></small>
                    </div>
                </div>

                <div class="d-flex align-items-center p-2 rounded bg-white bg-opacity-10 border border-secondary">
                    <i class="bi bi-stopwatch fs-2 me-3 text-info"></i>
                    <div>
                        <h6 class="mb-0 fw-bold">Avg. Check-in Time</h6>
                        <small id="ai-checkin-time" class="text-white-50">~<?php echo $avg_display; ?> (Optimal)</small>
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
                <span class="badge bg-danger-subtle text-danger border border-danger">Action Required</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($overstays)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-shield-check display-4 text-success opacity-50"></i>
                        <p class="mt-3 text-muted mb-0">No overstays detected. All clear.</p>
                    </div>
                    <?php
                else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($overstays as $os): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3">
                                <div>
                                    <strong><?php echo htmlspecialchars($os['name']); ?></strong>
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
        </div>
    </div>

    <!-- Zone Status -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-geo-alt-fill me-2 text-primary"></i>Current Zone
                    Density</h5>
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
                        $pct = min(100, ($z['count'] / 10) * 100); // Assume 10 per dept is 'Full'
                        $color = ($pct > 80) ? 'danger' : (($pct > 40) ? 'warning' : 'success');
                        $status = ($pct > 80) ? 'High Congestion' : (($pct > 40) ? 'Moderate Traffic' : 'Low Activity');
                        ?>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <div>
                                <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($z['zone']); ?></h6>
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

            <div class="mt-4 row g-2">
                <div class="col-6">
                    <div class="p-2 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25 h-100">
                        <small class="text-uppercase text-primary fw-bold" style="font-size: 0.6rem;">Best Slot</small>
                        <div class="fw-bold text-dark" style="font-size: 0.85rem;"><i
                                class="bi bi-clock-history me-1 text-primary"></i> <?php echo $best_time; ?></div>
                        <small class="text-muted" style="font-size: 0.6rem;">Min. Waiting</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="p-2 bg-danger bg-opacity-10 rounded-3 border border-danger border-opacity-25 h-100">
                        <small class="text-uppercase text-danger fw-bold" style="font-size: 0.6rem;">Peak Time</small>
                        <div class="fw-bold text-dark" style="font-size: 0.85rem;"><i
                                class="bi bi-graph-up-arrow me-1 text-danger"></i> <?php echo $peak_time; ?></div>
                        <small class="text-muted" style="font-size: 0.6rem;">Busy Hours</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const ctx = document.getElementById('trafficChart').getContext('2d');

        // Real Data
        const hourlyData = <?php echo json_encode($traffic_data); ?>;
        const labels = <?php
        echo json_encode(array_map(function ($h) {
            return ($h > 12 ? $h - 12 : $h) . ($h >= 12 ? 'pm' : 'am');
        }, $traffic_hours));
        ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Entries',
                    data: hourlyData,
                    borderRadius: 5,
                    backgroundColor: '#0d6efd',
                    hoverBackgroundColor: '#0b5ed7'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { borderDash: [5, 5] } }
                }
            }
        });
    });
</script>


<!-- Visitor details modal logic and other scripts follow... -->

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div
                class="modal-header bg-primary text-white border-0 py-3 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <i class="bi bi-list-check fs-4 me-2"></i>
                    <h5 class="modal-title fw-bold" id="modalTitle">Visit Details</h5>
                </div>
                <div class="d-flex align-items-center flex-grow-1 mx-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-0 rounded-start-pill"><i
                                class="bi bi-search text-primary"></i></span>
                        <input type="text" id="detailsModalSearch" class="form-control border-0 rounded-end-pill"
                            placeholder="Search..." onkeyup="filterDetailsModalTable()">
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light sticky-top">
                            <tr>
                                <th class="ps-4">Visitor</th>
                                <th>Host & Dept</th>
                                <th>Date & Time</th>
                                <th>Status</th>
                                <th>In Time</th>
                                <th>Out Time</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="modalTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light border-0 py-2">
                <small class="text-muted ms-auto" id="recordCount">0 records found</small>
            </div>
        </div>
    </div>
</div>

<!-- Message Modal for Alerts -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-sm">
            <div class="modal-header border-0 pb-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center pb-5 pt-0">
                <div class="mb-3 text-warning">
                    <i class="bi bi-exclamation-circle display-4"></i>
                </div>
                <h5 class="fw-bold mb-2">Action Required</h5>
                <p class="text-muted mb-4">Host approval is pending. You cannot generate a pass yet.</p>
                <button type="button" class="btn btn-warning text-dark fw-bold rounded-pill px-5"
                    data-bs-dismiss="modal">Okay, Got it</button>
            </div>
        </div>
    </div>
</div>

<script>
    if (typeof BASE_URL === 'undefined') {
        var BASE_URL = '<?php echo BASE_URL; ?>';
    }
    // --- INITIALIZATION & GLOBAL STATE ---
    let lastPending = <?php echo $pending_approvals; ?>;
    let lastActive = <?php echo $active_visitors; ?>;
    let todaysVisits = <?php echo json_encode($visits); ?>;

    // --- HELPER FUNCTIONS ---
    function requestNotificationPermission() {
        if ("Notification" in window && Notification.permission === "default") {
            Notification.requestPermission();
        }
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

    // --- NOTIFICATIONS & ALERTS ---
    function notifyStatusChange(visit) {
        if (!visit) return;
        const isApproved = visit.approval_status === 'approved';
        const title = isApproved ? 'Visit Approved!' : 'Visit Rejected';
        const icon = isApproved ? 'success' : 'error';
        const color = isApproved ? '#28a745' : '#dc3545';

        Swal.fire({
            title: `<span class="fw-bold text-dark mt-2" style="font-size: 1.1rem; letter-spacing: -0.5px;">Arrival Status Update</span>`,
            html: `
                <div class="text-center">
                    <!-- Subtle Status Indicator -->
                    <div class="mx-auto mb-3 rounded-pill py-1 px-3 d-inline-block animate__animated animate__fadeInDown" 
                         style="background: ${isApproved ? 'rgba(25, 135, 84, 0.1)' : 'rgba(220, 53, 69, 0.1)'}; 
                                border: 1px solid ${isApproved ? 'rgba(25, 135, 84, 0.2)' : 'rgba(220, 53, 69, 0.2)'};">
                        <span class="fw-bold small" style="color: ${color};"><i class="bi ${isApproved ? 'bi-patch-check-fill' : 'bi-patch-exclamation-fill'} me-1"></i> ${title.toUpperCase()}</span>
                    </div>

                    <div class="position-relative d-inline-block mb-3">
                        <img src="../${visit.photo_path || 'assets/img/visitor-icon.png'}" 
                             class="rounded-circle shadow-sm border border-3" 
                             style="width: 80px; height: 80px; object-fit: cover; border-color: ${color} !important;"
                             onerror="this.src='../assets/img/visitor-icon.png'">
                        <div class="position-absolute bottom-0 end-0 bg-white rounded-circle p-1 shadow-sm border" style="transform: translate(20%, 20%); line-height: 1;">
                            <i class="bi ${isApproved ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'} fs-6"></i>
                        </div>
                    </div>
                    
                    <h5 class="fw-bold text-dark mb-0">${visit.visitor_name}</h5>
                    <p class="text-muted mb-3" style="font-size: 0.85rem;"><i class="bi bi-telephone me-1"></i>${visit.mobile}</p>
                    
                    <div class="bg-light rounded-4 p-3 text-start border-0 shadow-inner mb-0" style="background: rgba(0,0,0,0.03);">
                        <div class="row g-0 mb-3 border-bottom pb-2">
                            <div class="col-6 border-end pe-2">
                                <small class="text-muted text-uppercase fw-bold ls-1 d-block mb-1" style="font-size: 0.55rem;">Host Employee</small>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-person-badge text-primary me-2" style="font-size: 0.8rem;"></i>
                                    <span class="fw-bold text-dark small text-truncate">${visit.host_name}</span>
                                </div>
                            </div>
                            <div class="col-6 ps-3">
                                <small class="text-muted text-uppercase fw-bold ls-1 d-block mb-1" style="font-size: 0.55rem;">Department</small>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-building text-info me-2" style="font-size: 0.8rem;"></i>
                                    <span class="fw-bold text-dark small text-truncate">${visit.department || 'General'}</span>
                                </div>
                            </div>
                        </div>
                        <div class="row g-0">
                            <div class="col-6 border-end pe-2">
                                <small class="text-muted text-uppercase fw-bold ls-1 d-block mb-1" style="font-size: 0.55rem;">Floor Access</small>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-geo-alt text-danger me-2" style="font-size: 0.8rem;"></i>
                                    <span class="fw-bold text-dark small text-truncate">${visit.access_area || 'Not Assigned'}</span>
                                </div>
                            </div>
                            <div class="col-6 ps-3">
                                <small class="text-muted text-uppercase fw-bold ls-1 d-block mb-1" style="font-size: 0.55rem;">Assets Carried</small>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-laptop text-dark me-2" style="font-size: 0.8rem;"></i>
                                    <span class="fw-bold text-dark small text-truncate">${visit.assets_carried || 'None'}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `,
            width: '380px',
            showCancelButton: true,
            confirmButtonText: 'Manage Visit',
            cancelButtonText: 'Dismiss',
            confirmButtonColor: color,
            cancelButtonColor: '#6c757d',
            reverseButtons: true,
            customClass: {
                popup: 'rounded-5 shadow-2xl border-0 overflow-hidden',
                title: 'border-0 pb-0',
                htmlContainer: 'pt-0 pb-3 px-3'
            },
            backdrop: `rgba(0,0,0,0.5)`
        }).then((result) => {
            if (result.isConfirmed) {
                // Short timeout to allow Swal to finish closing, avoiding Bootstrap modal backdrop overlaps
                setTimeout(() => {
                    if (typeof viewVisitDetails === 'function') {
                        viewVisitDetails(visit.id);
                    }
                }, 350);
            }
        });

        // The poller (security_notifications.js) now handles the sound centrally
    }

    // --- REAL-TIME ENGINE ---
    async function refreshDashboardTable() {
        try {
            const response = await fetch('api/get_dashboard_data.php');
            const data = await response.json();

            if (data.success) {
                // Status changes are now handled centrally by security_notifications.js to ensure 
                // notifications are filtered by the creator of the entry.

                // Detect New Visitors
                if (data.stats.pending > lastPending) {
                    const bgToggle = document.getElementById('backgroundToggle');
                    if (bgToggle && bgToggle.checked && "Notification" in window && Notification.permission === "granted") {
                        new Notification("VMS Alert", { body: "New visitor pending approval.", icon: "../assets/img/visitor-icon.png" });
                    }
                }

                lastPending = data.stats.pending;
                lastActive = data.stats.active;
                todaysVisits = data.visits;

                // Update Stats
                document.getElementById('stat-total').innerText = data.stats.total_today;
                document.getElementById('stat-active').innerText = data.stats.active;
                document.getElementById('stat-pending').innerText = data.stats.pending;
                document.getElementById('stat-checkin-pending').innerText = data.stats.checkin_pending;

                // Update AI Monitor
                if (data.ai_metrics) {
                    const aim = data.ai_metrics;
                    const dBar = document.getElementById('ai-density-bar');
                    const dStatus = document.getElementById('ai-density-status');

                    dBar.style.width = aim.crowd_density + '%';
                    dBar.setAttribute('aria-valuenow', aim.crowd_density);

                    // Logic for status colors
                    let dText = 'Optimal', dColor = 'text-success', bColor = 'bg-success';
                    if (aim.crowd_density > 80) { dText = 'Critical Surge'; dColor = 'text-danger'; bColor = 'bg-danger'; }
                    else if (aim.crowd_density > 50) { dText = 'Moderate Traffic'; dColor = 'text-warning'; bColor = 'bg-warning'; }

                    dBar.className = 'progress-bar ' + bColor;
                    dStatus.className = 'small mt-2 ' + dColor + ' fw-bold';
                    dStatus.innerHTML = `<i class="bi bi-graph-up me-1"></i> ${dText} (${aim.active_count}/${aim.max_capacity})`;

                    document.getElementById('ai-checkin-time').innerText = `~${aim.avg_checkin_time} (Optimal)`;

                    const secStatus = document.getElementById('ai-security-status');
                    const secMsg = document.getElementById('ai-security-msg');
                    const secIcon = document.getElementById('ai-security-icon');

                    if (aim.overstays_count > 0) {
                        secStatus.innerText = 'Anomaly Alert';
                        secMsg.innerText = aim.overstays_count + ' visitor(s) overstaying.';
                        secIcon.className = 'bi bi-shield-exclamation text-danger fs-2 me-3';
                    } else {
                        secStatus.innerText = 'Perimeter Secure';
                        secMsg.innerText = 'No anomalies detected.';
                        secIcon.className = 'bi bi-shield-check text-success fs-2 me-3';
                    }

                    // Update Zones
                    const zContainer = document.getElementById('zone-density-container');
                    const viewType = document.querySelector('input[name="densityView"]:checked').value;
                    const selectedZones = aim.zones[viewType] || [];

                    if (selectedZones.length > 0) {
                        let zHtml = '';
                        selectedZones.forEach(z => {
                            const pct = Math.min(100, (z.count / 10) * 100);
                            const color = (pct > 80) ? 'danger' : ((pct > 40) ? 'warning' : 'success');
                            const status = (pct > 80) ? 'High Congestion' : ((pct > 40) ? 'Moderate Traffic' : 'Low Activity');

                            zHtml += `
                                <div class="d-flex align-items-center justify-content-between mb-3 animate__animated animate__fadeIn">
                                    <div>
                                        <h6 class="mb-0 fw-bold">${z.zone}</h6>
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
                }

                // Update Table
                const tbody = document.getElementById('visitor-log-body');
                let html = '';

                data.visits.forEach(visit => {
                    const statusBadgeParams = {
                        'registered': 'bg-info', 'checked_in': 'bg-success', 'checked_out': 'bg-secondary',
                        'approved': 'bg-primary', 'pending': 'bg-warning text-dark', 'rejected': 'bg-danger'
                    };
                    const statusBadge = statusBadgeParams[visit.status] || 'bg-secondary';
                    const showPass = visit.approval_status !== 'rejected';
                    const showCheckin = visit.status === 'approved' && visit.approval_status === 'approved';
                    const showCheckout = visit.status === 'checked_in';

                    const displayPhoto = visit.visit_photo;

                    html += `
                    <tr id="row-${visit.id}" class="animate__animated animate__fadeIn" onclick="viewVisitDetails(${visit.id})" style="cursor: pointer;">
                        <td class="ps-4"><strong>${visit.visit_code}</strong></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <img src="../${displayPhoto || 'assets/img/visitor-icon.png'}" 
                                     class="rounded-circle me-2 shadow-sm" width="40" height="40" style="object-fit:cover"
                                     onerror="this.src='../assets/img/visitor-icon.png'">
                                <div>
                                    <div class="fw-bold small">${visit.visitor_name}</div>
                                    <div class="text-muted" style="font-size:0.7rem">${visit.mobile}</div>
                                </div>
                            </div>
                        </td>
                        <td class="small">${visit.host_name}</td>
                        <td class="small">${visit.department || '-'}</td>
                        <td><span class="badge rounded-pill ${statusBadge}" style="font-size:0.65rem">${visit.status.replace('_', ' ').toUpperCase()}</span></td>
                        <td class="small fw-bold text-success">${visit.check_in_time ? formatTime(visit.check_in_time) : '-'}</td>
                        <td class="small fw-bold text-danger">${visit.check_out_time ? formatTime(visit.check_out_time) : '-'}</td>
                        <td class="text-end pe-4" onclick="event.stopPropagation()">
                            <div class="btn-group btn-group-sm">
                                ${showPass ? `<a href="javascript:void(0);" onclick="viewPass(${visit.id}, '${visit.approval_status}')" class="btn btn-outline-primary"><i class="bi bi-ticket-detailed"></i></a>` : ''}
                                ${showCheckin ? `<a href="process_visit.php?action=checkin&id=${visit.id}" class="btn btn-success fw-bold">Check In</a>` : ''}
                                ${showCheckout ? `<a href="process_visit.php?action=checkout&id=${visit.id}" class="btn btn-danger">Check Out</a>` : ''}
                            </div>
                        </td>
                    </tr>`;
                });
                if (html === '') html = '<tr><td colspan="5" class="text-center text-muted py-5">No visitors today</td></tr>';
                tbody.innerHTML = html;
            }
        } catch (e) { console.error("Refresh failed", e); }
    }

    // --- BG MODE CONTROL ---
    window.toggleBackgroundMode = function (toggle) {
        if (!toggle) return;

        // Persist to server
        const apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'api/user/settings.php' : '../api/user/settings.php';
        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bg_mode: toggle.checked })
        }).then(res => res.json()).then(data => {
            if (data.success) console.log("Security BG Mode setting saved.");
        });

        // Delegate audio control to shared notifications script
        if (typeof window.toggleSecurityBackgroundMode === 'function') {
            window.toggleSecurityBackgroundMode(toggle);
        }
    };

    function applySavedBGMode() {
        const toggle = document.getElementById('backgroundToggle');
        if (toggle && toggle.checked) {
            // LocalStorage should ideally already be in sync via PHP session, but ensure shared script knows
            localStorage.setItem('vms_security_bg_mode', 'true');
        }
    }


    // --- EVENT LISTENERS ---
    applySavedBGMode();
    setInterval(refreshDashboardTable, 2000);
    window.VMS_REFRESH_DASHBOARD = refreshDashboardTable;

    <?php if (isset($_GET['new_visit_id'])):
        $msgTitle = 'Visitor Registered!';
        $msgText = 'Approval request sent to host.';
        if (isset($_GET['msg'])) {
            $msgText = htmlspecialchars($_GET['msg']);
            $msgTitle = 'Check-in Successful';
        }

        if (isset($_GET['wa_status'])) {
            if ($_GET['wa_status'] == 'skipped_disabled') {
                $msgText .= ' (WhatsApp Disabled in Settings)';
            } else if ($_GET['wa_status'] == 'skipped_not_live') {
                $msgText .= ' (WhatsApp API not configured)';
            }
        }
        ?>
        window.addEventListener('load', () => {
            AppDialog.show({
                title: '<?php echo $msgTitle; ?>',
                text: '<?php echo addslashes($msgText); ?>',
                icon: 'success',
                confirmButtonText: 'OK'
            });
            setTimeout(() => { window.history.replaceState({}, document.title, window.location.pathname); }, 1000);
        });
        <?php
    endif; ?>

    const serverToday = '<?php echo date("Y-m-d"); ?>';

    // Modal Logic
    function showDetails(type) {
        const titleMap = {
            'total': 'Total Visitors Today',
            'active': 'Currently Inside (Checked In)',
            'pending': 'Pending Approvals',
            'overstay': 'Alert: Overstaying Visitors (>8h)'
        };
        const tbody = document.getElementById('modalTableBody');
        tbody.innerHTML = '';

        const searchInput = document.getElementById('detailsModalSearch');
        if (searchInput) searchInput.value = '';

        const eightHoursAgo = new Date(Date.now() - 8 * 3600 * 1000);

        const filtered = todaysVisits.filter(v => {
            if (type === 'total') {
                return v.created_at.startsWith(serverToday);
            }
            if (type === 'active') return v.status === 'checked_in';
            if (type === 'pending') return v.approval_status === 'pending';
            if (type === 'checkin_pending') {
                return v.status === 'approved' && (v.created_at.startsWith(serverToday) || (v.is_invited == 1 && v.visit_date === serverToday));
            }
            if (type === 'overstay') {
                return v.status === 'checked_in' && v.check_in_time && new Date(v.check_in_time) < eightHoursAgo;
            }
            return true;
        });

        document.getElementById('modalTitle').innerText = titleMap[type] || 'Visit Details';
        document.getElementById('recordCount').innerText = filtered.length + ' record(s)';

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-5">No records found</td></tr>';
        } else {
            filtered.forEach(visit => {
                const statusBadgeParams = {
                    'registered': 'bg-info',
                    'checked_in': 'bg-success',
                    'checked_out': 'bg-secondary',
                    'approved': 'bg-primary',
                    'pending': 'bg-warning text-dark',
                    'rejected': 'bg-danger'
                };
                const statusBadge = statusBadgeParams[visit.status] || 'bg-secondary';

                // Actions (simplified for modal)
                const showPass = visit.approval_status !== 'rejected';
                const showCheckin = visit.status === 'approved' && visit.approval_status === 'approved';
                const showCheckout = visit.status === 'checked_in';

                // Date Formatting
                const d = new Date(visit.created_at);
                const day = String(d.getDate()).padStart(2, '0');
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const year = String(d.getFullYear()).slice(-2);
                let hours = d.getHours();
                const minutes = String(d.getMinutes()).padStart(2, '0');
                const ampm = hours >= 12 ? 'PM' : 'AM';
                hours = hours % 12;
                hours = hours ? hours : 12;
                const formattedDate = `<div class="fw-bold text-dark">${day}-${month}-${year}</div><small class="text-muted">${hours}:${minutes} ${ampm}</small>`;

                const row = `
                    <tr onclick="viewVisitDetails(${visit.id})" style="cursor: pointer;">
                        <td class="ps-4">
                            <div class="fw-bold text-dark">${visit.visitor_name}</div>
                            <small class="text-muted">${visit.mobile}</small>
                        </td>
                        <td>
                            <div class="fw-bold text-dark">${visit.host_name || '-'}</div>
                            <small class="text-muted">${visit.department || '-'}</small>
                        </td>
                        <td>${formattedDate}</td>
                        <td><span class="badge rounded-pill ${statusBadge}">${visit.status.replace('_', ' ').toUpperCase()}</span></td>
                        <td class="small fw-bold text-success">${visit.check_in_time ? formatTime(visit.check_in_time) : '-'}</td>
                        <td class="small fw-bold text-danger">${visit.check_out_time ? formatTime(visit.check_out_time) : '-'}</td>
                        <td class="text-end pe-4" onclick="event.stopPropagation()">
                             <div class="btn-group btn-group-sm">
                                ${showPass ? `<a href="javascript:void(0);" onclick="viewPass(${visit.id}, '${visit.approval_status}')" class="btn btn-outline-primary"><i class="bi bi-ticket-detailed"></i></a>` : ''}
                                ${showCheckin ? `<a href="process_visit.php?action=checkin&id=${visit.id}" class="btn btn-success fw-bold">Check In</a>` : ''}
                                ${showCheckout ? `<a href="process_visit.php?action=checkout&id=${visit.id}" class="btn btn-danger">Check Out</a>` : ''}
                            </div>
                        </td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('detailsModal'));
        modal.show();
    }

    function filterDetailsModalTable() {
        const input = document.getElementById('detailsModalSearch');
        if (!input) return;
        const filter = input.value.toLowerCase();
        const modalBody = document.getElementById('modalTableBody');
        const rows = modalBody.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const rowText = rows[i].textContent.toLowerCase();
            rows[i].style.display = rowText.includes(filter) ? '' : 'none';
        }
    }

    // --- SUCCESS DIALOG FOR CHECK-IN/OUT ---
    (function () {
        const urlParams = new URLSearchParams(window.location.search);
        const action = urlParams.get('action_success');
        const vId = urlParams.get('v_id');

        if (action && vId) {
            const visit = todaysVisits.find(v => v.id == vId);
            if (visit) {
                const isCheckin = action === 'checkin';
                const visitorName = visit.visitor_name || 'Visitor';
                const hostName = visit.host_name || 'Host';
                const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

                Swal.fire({
                    title: `<h3 class="fw-bold mb-1">${isCheckin ? 'Check-in Successful!' : 'Check-out Successful!'}</h3>`,
                    html: `
                        <div class="text-center p-2">
                            <div class="mb-3">
                                <img src="../${visit.photo_path || 'assets/img/visitor-icon.png'}" 
                                     class="rounded-circle border border-4 border-success shadow" 
                                     width="100" height="100" style="object-fit: cover;"
                                     onerror="this.src='../assets/img/visitor-icon.png'">
                            </div>
                            <h4 class="fw-bold mb-1">${visitorName}</h4>
                            <p class="text-muted mb-3">${visit.mobile}</p>
                            
                            <div class="row g-2 justify-content-center">
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded-3 border text-center">
                                        <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.55rem;">${isCheckin ? 'Entry Time' : 'Exit Time'}</small>
                                        <span class="fw-bold text-dark small">${timeStr}</span>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="p-2 bg-light rounded-3 border text-center">
                                        <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.55rem;">Host</small>
                                        <span class="fw-bold text-dark small">${hostName}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `,
                    icon: 'success',
                    confirmButtonText: 'Done',
                    confirmButtonColor: '#198754',
                    customClass: {
                        popup: 'rounded-4 border-0 shadow-lg',
                        confirmButton: 'rounded-pill px-5 fw-bold btn-sm'
                    }
                }).then(() => {
                    const url = new URL(window.location);
                    url.searchParams.delete('action_success');
                    url.searchParams.delete('v_id');
                    window.history.replaceState({}, '', url);
                });
            }
        }
    })();
</script>


<?php require_once '../includes/visit_details_modal.php'; ?>
<?php require_once 'footer.php'; ?>