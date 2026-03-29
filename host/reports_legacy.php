<?php
require_once 'header.php';

// Check permission
if (!canView('host_reports')) {
    echo "<div class='alert alert-danger m-4'>You do not have permission to view reports.</div>";
    require_once 'footer.php';
    exit;
}

// Check Role for View Mode
$isAdmin = ($_SESSION['role'] === 'admin');

// Filters
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status'] ?? 'all';

// Build Query
// We add LEFT JOIN employees e ON v.employee_id = e.id to get host info
if ($isAdmin) {
    // Admin sees ALL data
    $params = [$start_date, $end_date];
    $sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, u.username as actioned_by_name, e.name as host_name, e.department
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN users u ON v.approved_by = u.id
            LEFT JOIN employees e ON v.employee_id = e.id
            WHERE DATE(v.created_at) BETWEEN ? AND ?";
    $viewMode = "Organization Wide";
} else {
    // Hosts see only THEIR data
    if (empty($host_employee_id)) {
        echo "<div class='alert alert-warning m-4'>Error: Your account is not linked to an employee profile. Please contact admin.</div>";
        require_once 'footer.php';
        exit;
    }

    $params = [$host_employee_id, $start_date, $end_date];
    $sql = "SELECT v.*, vis.name as visitor_name, vis.mobile, u.username as actioned_by_name, e.name as host_name, e.department
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN users u ON v.approved_by = u.id
            LEFT JOIN employees e ON v.employee_id = e.id
            WHERE v.employee_id = ? 
            AND DATE(v.created_at) BETWEEN ? AND ?";
    $viewMode = "My Data";
}

if ($status_filter != 'all') {
    $sql .= " AND v.status = ?";
    $params[] = $status_filter;
}

// Search Filter
$search = $_GET['search'] ?? '';
if ($search) {
    $sql .= " AND (vis.name LIKE ? OR vis.mobile LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY v.created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $visits = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Error loading reports: " . $e->getMessage());
}

// Calculate Stats
$total_visits = count($visits);
$completed = 0;
$active = 0;
$rejected = 0;
$pending = 0;
$approved = 0;
$purpose_counts = [];
$daily_counts = [];

foreach ($visits as $v) {
    // Status Counts
    if ($v['status'] == 'checked_out') {
        $completed++;
    } elseif ($v['status'] == 'checked_in') {
        $active++;
    } elseif ($v['approval_status'] == 'approved' && $v['status'] != 'checked_in' && $v['status'] != 'checked_out') {
        // Only count as 'Approved' if NOT checked in/out (i.e., pre-arrival)
        $approved++;
    }

    if ($v['approval_status'] == 'rejected') {
        $rejected++;
    } elseif ($v['approval_status'] == 'pending') {
        $pending++;
    }

    // Purpose Counts
    $p = $v['purpose'] ?: 'Other';
    if (!isset($purpose_counts[$p]))
        $purpose_counts[$p] = 0;
    $purpose_counts[$p]++;

    // Daily Counts
    $day = date('Y-m-d', strtotime($v['created_at']));
    if (!isset($daily_counts[$day]))
        $daily_counts[$day] = 0;
    $daily_counts[$day]++;
}

// Prepare Chart Data
ksort($daily_counts);
$chart_labels = array_map(function ($d) {
    return date('d-m-y', strtotime($d));
}, array_keys($daily_counts));
$chart_data = array_values($daily_counts);

$pie_labels = array_keys($purpose_counts);
$pie_data = array_values($purpose_counts);
?>

<div class="row align-items-center mb-4">
    <div class="col-md-6">
        <h3 class="mb-0 fw-bold"><i class="bi bi-bar-chart-line text-primary me-2"></i>Productivity Reports</h3>
        <p class="text-muted small mb-0">Analyze your meeting history and visitor trends.</p>
    </div>
    <div class="col-md-6 text-md-end">
        <span class="badge bg-light text-dark border me-2">
            <i class="bi bi-eye-fill me-1"></i> <?php echo $viewMode; ?>
        </span>
        <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse"
            data-bs-target="#filterPanel">
            <i class="bi bi-funnel me-1"></i> Filters
        </button>
        <button class="btn btn-primary btn-sm ms-2" onclick="window.print()">
            <i class="bi bi-printer me-1"></i> Print Report
        </button>
    </div>
</div>

<!-- Filter Panel -->
<div class="collapse mb-4 <?php echo isset($_GET['start_date']) ? '' : ''; ?>" id="filterPanel">
    <div class="card card-body bg-light border-0 rounded-4 shadow-sm">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-bold">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Status</label>
                <select name="status" class="form-select">
                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="checked_in" <?php echo $status_filter == 'checked_in' ? 'selected' : ''; ?>>Checked In
                    </option>
                    <option value="checked_out" <?php echo $status_filter == 'checked_out' ? 'selected' : ''; ?>>Checked
                        Out</option>
                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved
                    </option>
                    <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected
                    </option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Search</label>
                <input type="text" name="search" class="form-control" placeholder="Visitor Name / Mobile"
                    value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-12 text-end mt-3">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Apply
                    Filters</button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Cards -->
<div class="row mb-4 g-3">
    <div class="col">
        <div class="p-3 bg-white border rounded-4 shadow-sm text-center h-100 cursor-pointer hover-card"
            onclick="showStatusDetails('all')">
            <h2 class="fw-bold text-primary mb-0"><?php echo $total_visits; ?></h2>
            <small class="text-muted text-uppercase fw-bold">Total Visits</small>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white border rounded-4 shadow-sm text-center h-100 cursor-pointer hover-card"
            onclick="showStatusDetails('checked_in')">
            <h2 class="fw-bold text-success mb-0"><?php echo $active; ?></h2>
            <small class="text-muted text-uppercase fw-bold">Checked in</small>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white border rounded-4 shadow-sm text-center h-100 cursor-pointer hover-card"
            onclick="showStatusDetails('checked_out')">
            <h2 class="fw-bold text-secondary mb-0"><?php echo $completed; ?></h2>
            <small class="text-muted text-uppercase fw-bold">Completed</small>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white border rounded-4 shadow-sm text-center h-100 cursor-pointer hover-card"
            onclick="showStatusDetails('approved')">
            <h2 class="fw-bold text-info mb-0"><?php echo $approved; ?></h2>
            <small class="text-muted text-uppercase fw-bold">Approved</small>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white border rounded-4 shadow-sm text-center h-100 cursor-pointer hover-card"
            onclick="showStatusDetails('pending')">
            <h2 class="fw-bold text-warning mb-0"><?php echo $pending; ?></h2>
            <small class="text-muted text-uppercase fw-bold">Pending</small>
        </div>
    </div>
    <div class="col">
        <div class="p-3 bg-white border rounded-4 shadow-sm text-center h-100 cursor-pointer hover-card"
            onclick="showStatusDetails('rejected')">
            <h2 class="fw-bold text-danger mb-0"><?php echo $rejected; ?></h2>
            <small class="text-muted text-uppercase fw-bold">Rejected</small>
        </div>
    </div>
</div>

<style>
    .hover-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 .5rem 1rem rgba(0, 0, 0, .15) !important;
        transition: all 0.2s;
        cursor: pointer;
    }
</style>

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <div class="d-flex align-items-center">
                    <i class="bi bi-list-check fs-4 me-2"></i>
                    <h5 class="modal-title fw-bold" id="modalTitle">Visit Details</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="p-3 bg-light border-bottom">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0 text-muted"><i
                                class="bi bi-search"></i></span>
                        <input type="text" id="modalSearchInput" class="form-control border-start-0 ps-0"
                            placeholder="Search by visitor, host, department, status..." onkeyup="filterModalTable()">
                    </div>
                </div>
                <div class="table-responsive" style="max-height: 60vh;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light sticky-top" style="z-index: 1;">
                            <tr>
                                <th class="ps-4 py-3 text-uppercase small fw-bold text-muted">Visitor</th>
                                <th class="py-3 text-uppercase small fw-bold text-muted">Host & Dept</th>
                                <th class="py-3 text-uppercase small fw-bold text-muted">In Time</th>
                                <th class="py-3 text-uppercase small fw-bold text-muted">Out Time</th>
                                <th class="py-3 text-uppercase small fw-bold text-muted">Purpose</th>
                                <th class="pe-4 py-3 text-uppercase small fw-bold text-muted text-end">Status</th>
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

<script>
    const allVisits = <?php echo json_encode($visits); ?>;
    let currentModalStatus = 'all';

    function showStatusDetails(status) {
        currentModalStatus = status;
        const titleMap = {
            'all': 'Total Visits',
            'checked_in': 'Active (Checked In)',
            'checked_out': 'Completed Meetings',
            'approved': 'Approved (Pre-Arrival)',
            'pending': 'Pending Approvals',
            'rejected': 'Rejected Visits'
        };

        document.getElementById('modalTitle').innerText = titleMap[status] || 'Visit Details';
        document.getElementById('modalSearchInput').value = ''; // Reset search

        renderModalTable();

        new bootstrap.Modal(document.getElementById('detailsModal')).show();
    }

    function filterModalTable() {
        renderModalTable(document.getElementById('modalSearchInput').value.toLowerCase());
    }

    function renderModalTable(searchQuery = '') {
        const tbody = document.getElementById('modalTableBody');
        tbody.innerHTML = '';

        const filtered = allVisits.filter(v => {
            // Status Filter
            let matchStatus = false;
            if (currentModalStatus === 'all') matchStatus = true;
            else if (currentModalStatus === 'approved') matchStatus = (v.approval_status === 'approved' && v.status !== 'checked_in' && v.status !== 'checked_out');
            else if (currentModalStatus === 'rejected') matchStatus = (v.approval_status === 'rejected');
            else if (currentModalStatus === 'pending') matchStatus = (v.approval_status === 'pending');
            else matchStatus = (v.status === currentModalStatus);

            if (!matchStatus) return false;

            // Search Filter
            if (searchQuery) {
                const searchStr = (v.visitor_name + ' ' + v.mobile + ' ' + v.purpose + ' ' + (v.host_name || '') + ' ' + (v.department || '')).toLowerCase();
                if (!searchStr.includes(searchQuery)) return false;
            }

            return true;
        });

        document.getElementById('recordCount').innerText = filtered.length + ' record(s)';

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-inbox fs-1 d-block mb-2 opacity-25"></i>No records found</td></tr>';
        } else {
            filtered.forEach(v => {
                let statusBadge = '';
                if (v.status === 'checked_in') statusBadge = '<span class="badge rounded-pill bg-success px-3">Checked In</span>';
                else if (v.status === 'checked_out') statusBadge = '<span class="badge rounded-pill bg-secondary px-3">Completed</span>';
                else if (v.approval_status === 'approved') statusBadge = '<span class="badge rounded-pill bg-info text-dark px-3">Approved</span>';
                else if (v.approval_status === 'pending') statusBadge = '<span class="badge rounded-pill bg-warning text-dark px-3">Pending</span>';
                else if (v.approval_status === 'rejected') statusBadge = '<span class="badge rounded-pill bg-danger px-3">Rejected</span>';
                else statusBadge = `<span class="badge rounded-pill bg-light text-dark border px-3">${v.status}</span>`;

                const formattedIn = v.check_in_time ? dFormatted(new Date(v.check_in_time)) : '-';
                const formattedOut = v.check_out_time ? dFormatted(new Date(v.check_out_time)) : '-';

                function dFormatted(dateObj) {
                    const dy = String(dateObj.getDate()).padStart(2, '0');
                    const mo = String(dateObj.getMonth() + 1).padStart(2, '0');
                    const yr = String(dateObj.getFullYear()).slice(-2);
                    let hr = dateObj.getHours();
                    const mi = String(dateObj.getMinutes()).padStart(2, '0');
                    const am = hr >= 12 ? 'PM' : 'AM';
                    hr = hr % 12 || 12;
                    return `<div class="fw-bold text-dark">${dy}-${mo}-${yr}</div><small class="text-muted">${hr}:${mi} ${am}</small>`;
                }

                const hostInfo = `
                    <span class="fw-semibold text-dark">${v.host_name || '-'}</span>
                    <div class="small text-muted">${v.department || '-'}</div>
                `;

                const row = `
                    <tr onclick="viewVisitDetails(${v.id})" style="cursor: pointer;">
                        <td class="ps-4">
                            <div class="fw-bold text-dark">${v.visitor_name}</div>
                            <small class="text-muted"><i class="bi bi-phone me-1"></i>${v.mobile}</small>
                        </td>
                        <td>${hostInfo}</td>
                        <td class="small fw-bold text-success">${formattedIn}</td>
                        <td class="small fw-bold text-danger">${formattedOut}</td>
                        <td><span class="badge bg-light text-dark border">${v.purpose}</span></td>
                        <td class="pe-4 text-end">${statusBadge}</td>
                    </tr>
                `;
                tbody.innerHTML += row;
            });
        }
    }
</script>

<!-- Charts -->
<div class="row mb-4">
    <div class="col-md-8 mb-4 mb-md-0">
        <div class="card border-0 rounded-4 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="mb-0 fw-bold">Visit Trends (Daily)</h6>
            </div>
            <div class="card-body">
                <canvas id="trendChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 rounded-4 shadow-sm h-100">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="mb-0 fw-bold">Visits by Purpose</h6>
            </div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <div class="w-100">
                    <canvas id="purposeChart" style="max-height: 250px;"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Rejected Visits Section -->
<?php
$rejected_visits = array_filter($visits, fn($v) => $v['approval_status'] == 'rejected');
?>
<div class="card border-0 rounded-4 shadow-sm mb-4">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold text-danger"><i class="bi bi-x-circle-fill me-2"></i>Rejected Visits</h6>
        <div class="ms-auto">
            <input type="text" id="searchRejected" class="form-control form-control-sm" placeholder="Search rejected..."
                onkeyup="filterTable('searchRejected', 'rejectedTableBody')">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Date</th>
                        <th>Visitor</th>
                        <th>Host</th>
                        <th>Department</th>
                        <th>Rejection Reason</th>
                        <th>Actioned By</th>
                    </tr>
                </thead>
                <tbody id="rejectedTableBody">
                    <?php if (empty($rejected_visits)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-4 text-muted">No rejected visits found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rejected_visits as $rv): ?>
                            <tr onclick="viewVisitDetails(<?php echo $rv['id']; ?>)" style="cursor: pointer;">
                                <td class="ps-4"><?php echo formatDateTime($rv['created_at']); ?></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($rv['visitor_name']); ?></td>
                                <td><?php echo htmlspecialchars($rv['host_name'] ?? '-'); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($rv['department'] ?? '-'); ?></small>
                                </td>
                                <td class="text-danger">
                                    <?php echo htmlspecialchars($rv['rejection_reason'] ?? 'No reason provided'); ?>
                                </td>
                                <td><span
                                        class="badge bg-light text-dark border"><?php echo htmlspecialchars($rv['actioned_by_name'] ?? 'System/You'); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detailed Table -->
<div class="card border-0 rounded-4 shadow-sm mb-4">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">Detailed Logs</h6>
        <div class="ms-auto">
            <input type="text" id="searchDetailed" class="form-control form-control-sm" placeholder="Search logs..."
                onkeyup="filterTable('searchDetailed', 'detailedTableBody')">
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Visitor</th>
                        <th>Host</th>
                        <th>In Time</th>
                        <th>Out Time</th>
                        <th>Status</th>
                        <th>Mobile</th>
                    </tr>
                </thead>
                <tbody id="detailedTableBody">
                    <?php if (empty($visits)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">No records found for this period.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($visits as $v): ?>
                            <tr onclick="viewVisitDetails(<?php echo $v['id']; ?>)" style="cursor: pointer;">
                                <td class="ps-4 fw-bold"><?php echo htmlspecialchars($v['visitor_name']); ?></td>
                                <td>
                                    <span
                                        class="fw-semibold text-dark"><?php echo htmlspecialchars($v['host_name'] ?? '-'); ?></span>
                                    <div class="small text-muted"><?php echo htmlspecialchars($v['department'] ?? '-'); ?></div>
                                </td>
                                <td class="small fw-bold text-success">
                                    <?php echo formatDateTime($v['check_in_time']); ?>
                                </td>
                                <td class="small fw-bold text-danger">
                                    <?php echo formatDateTime($v['check_out_time']); ?>
                                </td>
                                <td>
                                    <?php
                                    $statusClass = match ($v['status']) {
                                        'checked_in' => 'bg-success',
                                        'checked_out' => 'bg-secondary',
                                        default => 'bg-warning text-dark'
                                    };
                                    ?>
                                    <span
                                        class="badge rounded-pill <?php echo $statusClass; ?>"><?php echo ucfirst(str_replace('_', ' ', $v['status'])); ?></span>
                                    <div class="mt-1"><span class="badge bg-light text-muted border"
                                            style="font-size:0.6rem"><?php echo htmlspecialchars($v['purpose']); ?></span></div>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars($v['mobile']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const trendCtx = document.getElementById('trendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Visits',
                data: <?php echo json_encode($chart_data); ?>,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    const purposeCtx = document.getElementById('purposeChart').getContext('2d');
    new Chart(purposeCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($pie_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($pie_data); ?>,
                backgroundColor: ['#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b']
            }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    // Client-side Table Filter
    function filterTable(inputId, tableBodyId) {
        const input = document.getElementById(inputId);
        const filter = input.value.toLowerCase();
        const tbody = document.getElementById(tableBodyId);
        const rows = tbody.getElementsByTagName('tr');

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const text = row.textContent || row.innerText;
            if (text.toLowerCase().indexOf(filter) > -1) {
                row.style.display = "";
            } else {
                row.style.display = "none";
            }
        }
    }
</script>


<?php require_once '../includes/visit_details_modal.php'; ?>
<?php require_once 'footer.php'; ?>