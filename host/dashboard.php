<?php
require_once 'header.php';

if ($_SESSION['role'] !== 'host' && $_SESSION['role'] !== 'employee' && $_SESSION['role'] !== 'admin') {
    redirect($home_url);
}

// Initial Load
$is_admin = ($_SESSION['role'] === 'admin');

if ($is_admin) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE approval_status = 'pending'");
    $stmt->execute();
    $pending_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $today_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE is_invited = 1 AND (status = 'pending' OR status = 'approved') AND visit_date >= CURDATE()");
    $stmt->execute();
    $invite_count = $stmt->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND approval_status = 'pending'");
    $stmt->execute([$host_employee_id]);
    $pending_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$host_employee_id]);
    $today_count = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND is_invited = 1 AND (status = 'pending' OR status = 'approved') AND visit_date >= CURDATE()");
    $stmt->execute([$host_employee_id]);
    $invite_count = $stmt->fetchColumn();
}

$sql_today = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department
              FROM visits v 
              JOIN visitors vis ON v.visitor_id = vis.id
              LEFT JOIN employees e ON v.employee_id = e.id 
              WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND DATE(v.created_at) = CURDATE()
              ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_today);
if ($is_admin) {
    $stmt->execute();
} else {
    $stmt->execute([$host_employee_id]);
}
$today_visitors = $stmt->fetchAll();

// Fetch Pending Visitors
$sql_pending = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND v.approval_status = 'pending'
                ORDER BY v.created_at DESC";
$stmt = $pdo->prepare($sql_pending);
if ($is_admin) {
    $stmt->execute();
} else {
    $stmt->execute([$host_employee_id]);
}
$pending_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Active Invitations
$sql_invites = "SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department, v.visit_date
                FROM visits v 
                JOIN visitors vis ON v.visitor_id = vis.id 
                LEFT JOIN employees e ON v.employee_id = e.id
                WHERE " . ($is_admin ? "1=1" : "v.employee_id = ?") . " AND v.is_invited = 1 AND v.status IN ('pending', 'approved') AND v.visit_date >= CURDATE()
                ORDER BY v.visit_date ASC";
$stmt = $pdo->prepare($sql_invites);
if ($is_admin) {
    $stmt->execute();
} else {
    $stmt->execute([$host_employee_id]);
}
$active_invites = $stmt->fetchAll();

// Chart Data: Visits by Purpose (All Time for this Host)
$purpose_sql = "SELECT purpose, COUNT(*) as count FROM visits WHERE employee_id = ? GROUP BY purpose ORDER BY count DESC";
$purpose_stmt = $pdo->prepare($purpose_sql);
$purpose_stmt->execute([$host_employee_id]);
$purpose_data_raw = $purpose_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$purpose_labels = array_keys($purpose_data_raw);
$purpose_counts = array_values($purpose_data_raw);

// Top Focus
$top_focus = !empty($purpose_labels) ? $purpose_labels[0] : 'General';

// AI Best Slot (Find hour with min traffic based on last 30 days history)
$traffic_sql = "SELECT HOUR(created_at) as hour, COUNT(*) as count 
                FROM visits 
                WHERE created_at >= CURDATE() - INTERVAL 30 DAY 
                GROUP BY HOUR(created_at)";
$traffic_raw = $pdo->query($traffic_sql)->fetchAll(PDO::FETCH_KEY_PAIR);

// Check office hours 10 AM - 9 PM (10-21)
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
?>

<div class="row align-items-center mb-4 g-3">
    <div class="col-md-6">
        <h3 class="mb-0 fw-bold"><i class="bi bi-layout-text-window-reverse me-2 text-success"></i>Host Dashboard</h3>
    </div>
    <div class="col-md-6 text-md-end">
        <div id="alarm-control" class="d-none me-2 d-inline-block">
            <button onclick="stopAlarm()"
                class="btn btn-danger btn-sm rounded-pill px-3 animate__animated animate__flash animate__infinite">
                <i class="bi bi-volume-mute-fill"></i> STOP SOUND
            </button>
        </div>
        <?php if (canView('view_employee_report')): ?>
            <a href="<?php echo BASE_URL; ?>security/employee_visits_report.php"
                class="btn btn-outline-success btn-sm rounded-pill me-2">
                <i class="bi bi-table me-1"></i> Employee Report
            </a>
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

<div class="row mb-4 text-center">
    <div class="col-4">
        <div class="stat-card orange py-4 rounded-4 shadow-sm h-100 cursor-pointer hover-scale"
            onclick="showDetails('pending')">
            <h2 class="fw-bold mb-0" id="host-stat-pending">
                <?php echo $pending_count; ?>
            </h2>
            <p class="mb-0 small opacity-75">Pending</p>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card green py-4 rounded-4 shadow-sm h-100 border-success border-2 cursor-pointer hover-scale"
            onclick="showDetails('invites')">
            <h2 class="fw-bold mb-0" id="host-stat-invites">
                <?php echo $invite_count; ?>
            </h2>
            <p class="mb-0 small opacity-75">Invitations</p>
        </div>
    </div>
    <div class="col-4">
        <div class="stat-card blue py-4 rounded-4 shadow-sm h-100 cursor-pointer hover-scale"
            onclick="showDetails('today')">
            <h2 class="fw-bold mb-0" id="host-stat-today">
                <?php echo $today_count; ?>
            </h2>
            <p class="mb-0 small opacity-75">Today's Visits</p>
        </div>
    </div>
</div>

<style>
    .hover-scale {
        transition: transform 0.2s;
    }

    .hover-scale:hover {
        transform: scale(1.02);
    }

    .cursor-pointer {
        cursor: pointer;
    }
</style>

<div class="row mb-4">
    <!-- Personal Stats -->
    <div class="col-md-7 mb-3 mb-md-0">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="mb-0 fw-bold text-muted text-uppercase small ls-1">Visitor Insights</h6>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <div style="height: 150px; width: 150px;">
                    <canvas id="hostChart"></canvas>
                </div>
                <div class="ms-4">
                    <h4 class="fw-bold mb-1">Top Focus</h4>
                    <p class="text-muted small mb-0">Most of your visits are for <span class="text-primary fw-bold">
                            <?php echo htmlspecialchars($top_focus); ?>
                        </span>.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Suggestion -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 rounded-4 bg-success text-white h-100">
            <div class="card-body position-relative overflow-hidden">
                <i class="bi bi-lightbulb-fill position-absolute top-0 end-0 p-3 opacity-25 display-3"></i>
                <h6 class="text-white-50 text-uppercase small fw-bold">AI Smart Scheduler</h6>

                <h3 class="fw-bold mt-2">Best Slot:
                    <?php echo $best_slot_formatted; ?>
                </h3>
                <p class="small opacity-75 mb-3">Based on reception traffic, your visitors will experience the fastest
                    check-in at
                    <?php echo $best_slot_formatted; ?> today.
                </p>

                <a href="invite.php"
                    class="btn btn-sm btn-white text-success bg-white fw-bold rounded-pill text-decoration-none">
                    <i class="bi bi-calendar-plus me-1"></i> Schedule Now
                </a>
            </div>
        </div>
    </div>
</div>

<?php
// Frequent Visitors
$frequent_sql = "SELECT vis.name, COUNT(*) as visit_count, MAX(v.created_at) as last_visit
                 FROM visits v
                 JOIN visitors vis ON v.visitor_id = vis.id
                 WHERE v.employee_id = ?
                 GROUP BY v.visitor_id
                 HAVING visit_count > 1
                 ORDER BY visit_count DESC LIMIT 3";
$stmt = $pdo->prepare($frequent_sql);
$stmt->execute([$host_employee_id]);
$frequent_visitors = $stmt->fetchAll();

// Productivity Stats (Real-Time)
// 1. Meetings Completed (All Time)
$meetings_sql = "SELECT COUNT(*) FROM visits 
                 WHERE employee_id = ? 
                 AND status = 'checked_out'";
$stmt = $pdo->prepare($meetings_sql);
$stmt->execute([$host_employee_id]);
$meetings_completed = (int) $stmt->fetchColumn();

// 2. Avg Meeting Time (All Time)
$duration_sql = "SELECT AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time)) 
                 FROM visits 
                 WHERE employee_id = ? 
                 AND status = 'checked_out' 
                 AND check_in_time IS NOT NULL AND check_out_time IS NOT NULL";
$stmt = $pdo->prepare($duration_sql);
$stmt->execute([$host_employee_id]);
$avg_minutes = (int) $stmt->fetchColumn();
$avg_duration = $avg_minutes > 0 ? $avg_minutes . "m" : "0m";

// 3. Scheduled for Today (Invited)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE employee_id = ? AND is_invited = 1 AND visit_date = CURDATE() AND status IN ('pending', 'approved')");
$stmt->execute([$host_employee_id]);
$scheduled_today = (int) $stmt->fetchColumn();
?>

<div class="row mb-4">
    <!-- Frequent Visitors -->
    <div class="col-md-6 mb-4 mb-md-0">
        <div class="card shadow-sm border-0 rounded-4 h-100">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-person-lines-fill me-2 text-primary"></i>Frequent
                    Visitors</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($frequent_visitors)): ?>
                        <li class="list-group-item text-center text-muted py-4 border-0">No frequent visitors yet.</li>
                        <?php
                    else: ?>
                        <?php foreach ($frequent_visitors as $fv): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center px-4 py-3 border-0">
                                <div>
                                    <span class="d-block fw-bold"><?php echo htmlspecialchars($fv['name']); ?></span>
                                    <small class="text-muted">Last seen:
                                        <?php echo date('M j', strtotime($fv['last_visit'])); ?>
                                    </small>
                                </div>
                                <span class="badge bg-light text-primary border rounded-pill">
                                    <?php echo $fv['visit_count']; ?>
                                    Visits</span>
                            </li>
                            <?php
                        endforeach; ?>
                        <?php
                    endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- Productivity Insights -->
    <div class="col-md-6">
        <div class="card shadow-sm border-0 rounded-4 h-100 bg-light">
            <div class="card-body">
                <h6 class="fw-bold mb-4 text-uppercase small text-muted ls-1">My Productivity</h6>

                <div class="row text-center">
                    <div class="col-6 mb-3">
                        <div class="p-3 bg-white rounded-4 shadow-sm">
                            <h2 class="fw-bold text-dark mb-0">
                                <?php echo $meetings_completed; ?>
                            </h2>
                            <small class="text-muted">Total Meetings</small>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <div class="p-3 bg-white rounded-4 shadow-sm">
                            <h2 class="fw-bold text-dark mb-0">
                                <?php echo $avg_duration; ?>
                            </h2>
                            <small class="text-muted">Avg. Meeting Time</small>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-white rounded-3 mt-1 border">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-calendar-check text-primary fs-4 me-3"></i>
                        <div>
                            <span class="d-block fw-bold text-dark">Scheduled Today</span>
                            <small class="text-muted">You have
                                <?php echo $scheduled_today; ?> invited guests
                                arriving
                                today.
                            </small>
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
    document.addEventListener("DOMContentLoaded", function () {
        const ctx = document.getElementById('hostChart').getContext('2d');

        // Real Data
        const pLabels = <?php echo json_encode($purpose_labels); ?>;
        const pData = <?php echo json_encode($purpose_counts); ?>;

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: pLabels.length ? pLabels : ['None'],
                datasets: [{
                    data: pData.length ? pData : [1],
                    backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                cutout: '70%'
            }
        });
    });
</script>





<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5 border-top border-4 border-success">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-dark"><i class="bi bi-send-check-fill me-2 text-success"></i>Active
                    Invitations</h5>
                <a href="invite.php" class="btn btn-sm btn-success rounded-pill px-3 fw-bold"><i
                        class="bi bi-plus-lg me-1"></i>Create New Invite</a>
            </div>
            <div class="card-body p-4" style="background: #fafffb;">
                <div class="row g-3">
                    <?php if (empty($active_invites)): ?>
                        <div class="col-12 text-center py-4">
                            <i class="bi bi-envelope-x display-6 text-light"></i>
                            <p class="text-muted mt-2">No active invitations found.</p>
                        </div>
                        <?php
                    else: ?>
                        <?php foreach ($active_invites as $inv): ?>
                            <div class="col-md-4 col-sm-6">
                                <div class="card border-0 rounded-4 p-3 shadow-none h-100 hover-shadow transition"
                                    style="background: #eef7ff; border-left: 4px solid #0d6efd !important; cursor: pointer;"
                                    onclick="viewVisitDetails(<?php echo $inv['id']; ?>)">
                                    <div class="d-flex align-items-center mb-2">
                                        <div class="bg-white p-2 rounded-circle me-3 shadow-sm text-primary">
                                            <i class="bi bi-person-check fs-5"></i>
                                        </div>
                                        <div class="overflow-hidden">
                                            <h6 class="mb-0 fw-bold text-truncate">
                                                <?php echo htmlspecialchars($inv['visitor_name']); ?>
                                            </h6>
                                            <small class="text-primary fw-bold" style="font-size: 0.7rem;">
                                                <?php echo $inv['mobile']; ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mt-2 border-top pt-2">
                                        <div>
                                            <div class="small fw-bold text-primary">
                                                <i class="bi bi-calendar-event me-1"></i>
                                                <?php echo date('d-M-Y', strtotime($inv['visit_date'])); ?>
                                            </div>
                                            <div class="small text-dark fw-bold mt-1">Code:
                                                <?php echo $inv['visit_code']; ?>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-1" onclick="event.stopPropagation()">
                                            <button class="btn btn-sm btn-outline-success rounded-circle"
                                                style="width:32px; height:32px; padding:0" title="Resend via WhatsApp"
                                                id="wa-btn-<?php echo $inv['id']; ?>"
                                                onclick="resendInvitation(<?php echo $inv['id']; ?>, '<?php echo $inv['mobile']; ?>', '<?php echo addslashes($inv['visitor_name']); ?>', '<?php echo $inv['visit_code']; ?>', '<?php echo $inv['visit_date']; ?>', this)">
                                                <i class="bi bi-whatsapp"></i>
                                            </button>
                                            <a href="invite.php?success=1&visit_id=<?php echo $inv['id']; ?>" target="_blank"
                                                class="btn btn-sm btn-outline-secondary rounded-circle d-flex align-items-center justify-content-center"
                                                style="width:32px; height:32px; padding:0" title="Print Invitation">
                                                <i class="bi bi-printer"></i>
                                            </a>
                                            <button class="btn btn-sm btn-outline-danger rounded-circle"
                                                style="width:32px; height:32px; padding:0" title="Cancel Invitation"
                                                onclick="cancelInvitation(<?php echo $inv['id']; ?>, '<?php echo addslashes($inv['visitor_name']); ?>', '<?php echo $inv['mobile']; ?>', '<?php echo addslashes($inv['host_name']); ?>')">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php
                        endforeach; ?>
                        <?php
                    endif; ?>
                </div>
            </div>
            <div class="card-footer bg-white border-0 py-3 text-center">
                <a href="my_visitors.php?status=invited"
                    class="btn btn-sm btn-link text-decoration-none text-success">View All Invitation History &rarr;</a>
            </div>
        </div>
    </div>
</div>

<script>

    async function resendInvitation(visitId, mobile, name, code, date, btn) {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        }

        try {
            const apiPath = '<?php echo BASE_URL; ?>api/visit/resend_whatsapp.php';
            const response = await fetch(`${apiPath}?visit_id=${visitId}&type=invitation`);
            const data = await response.json();

            if (data.success) {
                if (btn) {
                    if (data.skipped) {
                        btn.innerHTML = '<i class="bi bi-whatsapp"></i>';
                        btn.disabled = false;
                    } else {
                        btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                        btn.classList.replace('btn-success', 'btn-outline-success');
                    }
                }
                AppDialog.show({
                    title: data.skipped ? 'Notice' : 'Invitation Status',
                    text: data.message || 'Invitation processed successfully.',
                    icon: data.skipped ? 'info' : 'success',
                    confirmButtonText: 'OK'
                });
            } else {
                throw new Error(data.message || "Cloud API failed");
            }
        } catch (e) {
            console.error("Resend Error:", e);
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-whatsapp"></i>';
            }
        }
    }

    async function cancelInvitation(vId, visitorName, mobile, hostName) {
        const result = await AppDialog.confirm({
            title: 'Cancel Invitation?',
            text: `This will cancel the invitation for ${visitorName || 'this visitor'} and attempt to notify them via WhatsApp.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, cancel it!'
        });

        if (result.isConfirmed) {
            try {
                const res = await fetch(`pending_approvals.php?ajax_action=1&v_id=${vId}&act=cancel_invite&visitor_name=${encodeURIComponent(visitorName || '')}&host_name=${encodeURIComponent(hostName || '')}`);
                const data = await res.json();
                if (data.success) {
                    AppDialog.show('Canceled!', data.message || 'The invitation has been canceled.', 'success').then(() => {
                        location.reload();
                    });
                } else {
                    AppDialog.show('Error', data.message || "Failed to cancel", 'error');
                }
            } catch (e) {
                AppDialog.show('Error', "Error connecting to server", 'error');
            }
        }
    }
</script>






<script>
    // BASE_URL is defined in header.php. Defining locally as fallback if needed.
    if (typeof BASE_URL === 'undefined') {
        var BASE_URL = '<?php echo BASE_URL; ?>';
    }
    // --- REAL-TIME ENGINE ---


    async function syncHostDashboard() {
        try {
            // Using BASE_URL for consistency if defined, else relative
            const apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'host/api/get_dashboard_data.php' : 'api/get_dashboard_data.php';
            const response = await fetch(apiPath);
            const data = await response.json();

            if (data.success) {
                currentDashboardData = data;
                lastPendingCount = data.pending_count;

                document.getElementById('host-stat-pending').innerText = data.pending_count;
                document.getElementById('host-stat-invites').innerText = data.invite_count || 0;
                document.getElementById('host-stat-today').innerText = data.today_count;

                // Removed Dashboard Alert Logic (Per User Request)
                const alertCont = document.getElementById('pending-alert-container');
                if (alertCont) alertCont.innerHTML = '';

                const listBody = document.getElementById('host-visitor-list');
                const visitorsFingerprint = JSON.stringify(data.visitors.map(v => ({ id: v.id, status: v.status })));
                const hasDataChanged = (window.lastVisitorsFingerprint !== visitorsFingerprint);
                window.lastVisitorsFingerprint = visitorsFingerprint;

                if (hasDataChanged || hostFilterTerm) {
                    let listHtml = '';
                    if (data.visitors.length === 0) {
                        listHtml = '<tr><td colspan="5" class="text-center py-5 text-muted small">No activity recorded today.</td></tr>';
                    } else {
                        data.visitors.forEach(v => {
                            if (hostFilterTerm) {
                                const searchText = (v.visitor_name + (v.purpose || '') + v.status).toLowerCase();
                                if (!searchText.includes(hostFilterTerm)) return;
                            }
                            const badgeClass = {
                                'pending': 'bg-warning text-dark', 'approved': 'bg-success', 'rejected': 'bg-danger',
                                'checked_in': 'bg-primary', 'checked_out': 'bg-dark'
                            }[v.status] || 'bg-secondary';

                            listHtml += `
                            <tr onclick="viewVisitDetails(${v.id})" style="cursor: pointer;">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center py-1">
                                        <img src="../${v.visit_photo || 'assets/img/visitor-icon.png'}" 
                                             class="rounded-circle me-3 border shadow-sm" width="35" height="35" style="object-fit:cover;"
                                             onerror="this.src='../assets/img/visitor-icon.png';">
                                        <div>
                                            <div class="fw-bold small text-dark">${v.visitor_name}</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">${formatTime(v.created_at)}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="small text-muted text-truncate" style="max-width: 120px;">${v.purpose || '-'}</td>
                                <td class="small fw-bold text-success">${v.check_in_time ? formatTime(v.check_in_time) : '-'}</td>
                                <td class="small fw-bold text-danger">${v.check_out_time ? formatTime(v.check_out_time) : '-'}</td>
                                <td class="text-end pe-4">
                                    <span class="badge rounded-pill ${badgeClass} px-3 py-1" style="font-size:0.6rem; letter-spacing: 0.5px;">
                                        ${v.status.toUpperCase()}
                                    </span>
                                </td>
                            </tr>`;
                        });
                        listBody.innerHTML = listHtml;
                    }
                }
            }
        } catch (e) { }
    }

    syncHostDashboard(); // Initial load
    setInterval(syncHostDashboard, 2000);

    // Initial State for dashboard refresh logic
    let lastPendingCount = <?php echo $pending_count; ?>;
    let hostFilterTerm = '';


    // Modal Details Logic
    function showDetails(type) {
        const titleMap = {
            'pending': 'Pending Approval Requests',
            'invites': 'Your Active Invitations',
            'today': 'Today\'s Walk-in Visitors'
        };
        const tbody = document.getElementById('modalTableBody');
        const thead = document.querySelector('#detailsListModal thead tr');
        tbody.innerHTML = '';

        const searchInput = document.getElementById('detailsListSearch');
        if (searchInput) searchInput.value = '';

        // Dynamic Headers
        if (type === 'invites') {
            thead.innerHTML = `
                <th class="ps-4">Visitor</th>
                <th>Dept</th>
                <th>Purpose</th>
                <th>Scheduled Date</th>
                <th>Status</th>
                <th class="text-end pe-4">Action/Code</th>
            `;
        } else {
            thead.innerHTML = `
                <th class="ps-4">Visitor</th>
                <th>Entry Date/Time</th>
                <th>Dept</th>
                <th>Purpose</th>
                <th>In</th>
                <th>Out</th>
                <th>Status</th>
                <th class="text-end pe-4">Action/Code</th>
            `;
        }

        let list = [];
        if (type === 'pending') list = currentDashboardData.pending_list || [];
        if (type === 'invites') list = currentDashboardData.active_invites || [];
        if (type === 'today') list = currentDashboardData.today_visitors || [];

        document.getElementById('modalTitle').innerText = titleMap[type] || 'Details';
        document.getElementById('recordCount').innerText = list.length + ' record(s)';

        if (list.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${type === 'invites' ? 6 : 8}" class="text-center text-muted py-5">No records found</td></tr>`;
        } else {
            list.forEach(v => {
                const badgeClass = {
                    'pending': 'bg-warning text-dark', 'approved': 'bg-success', 'rejected': 'bg-danger',
                    'checked_in': 'bg-primary', 'checked_out': 'bg-dark'
                }[v.status] || 'bg-secondary';

                const formattedIn = v.check_in_time ? formatTime(v.check_in_time) : '-';
                const formattedOut = v.check_out_time ? formatTime(v.check_out_time) : '-';
                const scheduledDate = v.visit_date ? new Date(v.visit_date).toLocaleDateString([], { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

                tbody.innerHTML += `
                    <tr onclick="viewVisitDetails(${v.id})" style="cursor: pointer;">
                        <td class="ps-4">
                            <div class="d-flex align-items-center">
                                <img src="../${v.visit_photo || 'assets/img/visitor-icon.png'}" 
                                     class="rounded-circle me-2 shadow-sm" width="40" height="40" style="object-fit:cover"
                                     onerror="this.src='../assets/img/visitor-icon.png'">
                                <div>
                                    <div class="fw-bold small">${v.visitor_name}</div>
                                    <div class="text-muted" style="font-size:0.7rem">${v.mobile}</div>
                                </div>
                            </div>
                        </td>
                        ${type !== 'invites' ? `<td class="small">${v.created_at ? formatDateTime(v.created_at) : '-'}</td>` : ''}
                        <td><small class="text-muted">${v.department || '-'}</small></td>
                        <td class="small">${v.purpose || '-'}</td>
                        ${type === 'invites' ?
                        `<td class="small fw-bold text-primary">${scheduledDate}</td>` :
                        `<td class="small fw-bold text-success">${formattedIn}</td>
                         <td class="small fw-bold text-danger">${formattedOut}</td>`
                    }
                        <td><span class="badge rounded-pill ${badgeClass}" style="font-size:0.65rem">${v.status.toUpperCase()}</span></td>
                        <td class="text-end pe-4" onclick="event.stopPropagation()">
                            ${type === 'pending' ?
                        `<div class="btn-group">
                                     <button onclick="approveDirectly(${v.id})" class="btn btn-sm btn-success rounded-start-pill px-3">Approve</button>
                                     <button onclick="rejectDirectly(${v.id})" class="btn btn-sm btn-danger rounded-end-pill px-3">Reject</button>
                                  </div>` : type === 'invites' ?
                            `<div class="dropdown">
                            <button class="btn btn-sm btn-light border rounded-pill px-3 fw-bold dropdown-toggle shadow-sm" type="button" data-bs-toggle="dropdown" aria-expanded="false" data-bs-display="static">
                                <i class="bi bi-three-dots-vertical me-1 text-primary"></i> Action
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 rounded-4 p-2 animate__animated animate__fadeIn animate__faster" style="min-width: 180px;">
                                <li class="dropdown-header text-uppercase small fw-bold opacity-50 px-3">Pass Options</li>
                                <li>
                                    <button class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center" onclick="resendInvitation(${v.id}, '${v.mobile}', '${v.visitor_name.replace(/'/g, "\\'")}', '${v.visit_code}', '${v.visit_date}', this.querySelector('.spinner-border'))">
                                        <i class="bi bi-whatsapp me-2 text-success fs-5"></i>
                                        <div>
                                            <div class="fw-bold small">WhatsApp Pass</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">Resend to visitor</div>
                                        </div>
                                        <span class="spinner-border spinner-border-sm d-none ms-auto"></span>
                                    </button>
                                </li>
                                <li>
                                    <a class="dropdown-item py-2 px-3 rounded-3 d-flex align-items-center" href="invite.php?success=1&visit_id=${v.id}" target="_blank">
                                        <i class="bi bi-printer me-2 text-primary fs-5"></i>
                                        <div>
                                            <div class="fw-bold small">Print Pass</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">Open PDF/Print</div>
                                        </div>
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider my-2 opacity-10"></li>
                                <li>
                                    <button class="dropdown-item text-danger py-2 px-3 rounded-3 d-flex align-items-center" onclick="cancelInvitation(${v.id}, '${v.visitor_name.replace(/'/g, "\\'")}', '${v.mobile}', '${(v.host_name || '').replace(/'/g, "\\'")}')">
                                        <i class="bi bi-x-circle-fill me-2 fs-5"></i>
                                        <div>
                                            <div class="fw-bold small">Cancel Invitation</div>
                                            <div class="text-muted" style="font-size: 0.7rem;">Revoke access</div>
                                        </div>
                                    </button>
                                </li>
                            </ul>
                        </div>` :
                            `<span class="badge bg-light text-dark border fw-bold" style="font-size:0.65rem">${v.visit_code || 'N/A'}</span>`
                    }
                        </td>
                    </tr>
                `;
            });
        }

        const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('detailsListModal'));
        modal.show();
    }

    function filterDetailsListModalTable() {
        const input = document.getElementById('detailsListSearch');
        if (!input) return;
        const filter = input.value.toLowerCase();
        const modalBody = document.getElementById('modalTableBody');
        const rows = modalBody.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const hasCells = rows[i].getElementsByTagName('td').length > 0;
            if (hasCells) {
                const rowText = rows[i].textContent.toLowerCase();
                rows[i].style.display = rowText.includes(filter) ? '' : 'none';
            }
        }
    }

    async function approveDirectly(id) {
        // Find visitor data in cache
        const visitor = currentDashboardData.pending_list.find(v => v.id == id);
        if (!visitor) return;

        const result = await AppDialog.confirm({
            title: 'Approve Visitor?',
            text: `Send WhatsApp pass to ${visitor.visitor_name}?`,
            icon: 'question',
            confirmButtonText: 'Yes, Approve & Share'
        });

        if (result.isConfirmed) {
            // Close the details modal first
            const detailsModal = bootstrap.Modal.getInstance(document.getElementById('detailsListModal'));
            if (detailsModal) detailsModal.hide();

            // Trigger the main approval flow
            triggerNewVisitorAlert(visitor);
            approveAndPrepareShare();
        }
    }

    async function rejectDirectly(id) {
        // Prepare details modal handle
        const detailsModalEl = document.getElementById('detailsListModal');
        let bootstrapDetailsModal = null;
        if (detailsModalEl) {
            bootstrapDetailsModal = bootstrap.Modal.getInstance(detailsModalEl);
            if (bootstrapDetailsModal) bootstrapDetailsModal.hide();
        }

        const result = await AppDialog.show({
            title: 'Reject Visitor?',
            text: "Please provide a reason for rejection:",
            input: 'text',
            inputPlaceholder: 'Reason for rejection...',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Reject',
            inputValidator: (value) => {
                if (!value) return 'You need to provide a reason!';
            }
        });

        if (!result.isConfirmed && bootstrapDetailsModal) {
            bootstrapDetailsModal.show();
            return;
        }

        if (result.isConfirmed && result.value) {
            try {
                const res = await fetch(`pending_approvals.php?ajax_action=1&v_id=${id}&act=reject&reason=${encodeURIComponent(result.value)}`);
                if (res.ok) {
                    syncHostDashboard();
                    AppDialog.show('Rejected', 'Visitor has been rejected.', 'success');
                }
            } catch (e) {
                AppDialog.show('Error', 'Action failed', 'error');
            }
        }
    }
</script>

<!-- Generic Details Modal -->
<div class="modal fade" id="detailsListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-white border-bottom border-light py-3 d-flex justify-content-between align-items-center shadow-sm"
                style="z-index: 10;">
                <div class="d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 p-2 rounded-circle me-2 d-flex align-items-center justify-content-center"
                        style="width: 40px; height: 40px;">
                        <i class="bi bi-list-stars text-success fs-5"></i>
                    </div>
                    <h5 class="modal-title fw-bold text-dark mb-0" id="modalTitle">Details</h5>
                </div>
                <div class="d-flex align-items-center flex-grow-1 mx-4">
                    <div class="input-group shadow-sm rounded-pill overflow-hidden border">
                        <span class="input-group-text bg-white border-0 ps-3"><i
                                class="bi bi-search text-muted small"></i></span>
                        <input type="text" id="detailsListSearch" class="form-control border-0 py-2 small"
                            style="box-shadow: none;" placeholder="Quick Filter Records..."
                            onkeyup="filterDetailsListModalTable()">
                    </div>
                </div>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light sticky-top">
                            <tr>
                                <th class="ps-4">Visitor</th>
                                <th>Purpose</th>
                                <th>In</th>
                                <th>Out</th>
                                <th>Status</th>
                                <th class="text-end pe-4">Action</th>
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



<!-- Visit Details Modal -->
<div class="modal fade" id="visitDetailsModal" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-info-circle me-2"></i> Visit Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div id="visit-details-content">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .timeline {
        position: relative;
        padding: 20px 0;
    }

    .timeline::before {
        content: '';
        position: absolute;
        left: 20px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #e9ecef;
    }

    .timeline-item {
        position: relative;
        margin-bottom: 25px;
        padding-left: 50px;
    }

    .timeline-marker {
        position: absolute;
        left: 12px;
        top: 0;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        background: #fff;
        border: 3px solid #0d6efd;
        z-index: 1;
    }

    .timeline-item.success .timeline-marker {
        border-color: #198754;
    }

    .timeline-item.warning .timeline-marker {
        border-color: #ffc107;
    }

    .timeline-item.danger .timeline-marker {
        border-color: #dc3545;
    }

    .timeline-content {
        padding-top: 0;
    }

    .timeline-date {
        font-size: 0.75rem;
        color: #6c757d;
        font-weight: 600;
    }

    #detailsListModal .modal-content {
        border-radius: 1.25rem;
        overflow: visible !important;
    }

    #detailsListModal .modal-body {
        overflow: visible !important;
    }

    #detailsListModal .table-responsive {
        overflow: visible !important;
    }

    #detailsListModal .table thead th {
        background: #f8f9fa;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.5px;
        color: #6c757d;
        border-top: 0;
        padding: 12px 15px;
    }

    #detailsListModal .table tbody tr:nth-child(even) {
        background-color: rgba(0, 0, 0, .02);
    }

    #detailsListModal .table tbody tr:hover {
        background-color: rgba(13, 110, 253, 0.05) !important;
    }

    .dropdown-menu {
        z-index: 2050 !important;
    }

    .timeline-title {
        font-weight: 700;
        margin-bottom: 2px;
    }
</style>

</script>

<?php require_once '../includes/visit_details_modal.php'; ?>
<?php require_once 'footer.php'; ?>