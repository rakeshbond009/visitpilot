<?php
ob_start();
require_once '../includes/db.php';
require_once 'header.php'; // Header handles login and enforcePageSecurity

// Permission Check (Same pattern as reports.php)
$is_admin = (($_SESSION['role'] ?? '') === 'admin');
$can_view_machine_logs = ($is_admin || canView('view_hardware_logs'));

if (!$can_view_machine_logs) {
    ob_end_clean();
    echo "<div class='alert alert-danger m-4'>You do not have permission to view machine logs.</div>";
    require_once 'footer.php';
    exit;
}

require_once '../includes/dahua_helper.php';
$dahua = new DahuaHelper($pdo);

// Pagination for Logs
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Filters for Logs
$machine_id = $_GET['machine_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$where = ["1=1"];
$params = [];

if ($machine_id) {
    $where[] = "machine_id = ?";
    $params[] = $machine_id;
}
if ($date_from) {
    $where[] = "DATE(localTime) >= ?";
    $params[] = $date_from;
}
if ($date_to) {
    $where[] = "DATE(localTime) <= ?";
    $params[] = $date_to;
}

$whereClause = implode(" AND ", $where);

// Total Logs for Pagination
$stmt = $pdo->prepare("SELECT COUNT(*) FROM machine_logs WHERE $whereClause");
$stmt->execute($params);
$totalLogs = $stmt->fetchColumn();
$totalPages = ceil($totalLogs / $limit);

// Fetch Logs
$stmt = $pdo->prepare("SELECT * FROM machine_logs WHERE $whereClause ORDER BY localTime DESC LIMIT $limit OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Fetch Machines for Filter
$machines = $pdo->query("SELECT DISTINCT machine_id, deviceName FROM machine_logs")->fetchAll();

$page_title = "Hardware Access Logs";
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-0"><i class="bi bi-cpu-fill me-2 text-primary"></i>Hardware Access Logs</h2>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary active" id="tab-logs">Access Logs</button>
            <button type="button" class="btn btn-outline-primary" id="tab-users">Machine Users</button>
        </div>
    </div>

    <!-- LOGS TAB -->
    <div id="logs-section">
        <!-- Filters -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Device</label>
                        <select name="machine_id" class="form-select">
                            <option value="">All Devices</option>
                            <?php foreach ($machines as $m): ?>
                                <option value="<?php echo $m['machine_id']; ?>" <?php echo ($machine_id == $m['machine_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['deviceName'] ?: $m['machine_id']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Filter</button>
                        <a href="machine_logs.php" class="btn btn-light border">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Logs Table -->
        <div class="card shadow-sm border-0">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Time</th>
                                <th>Device</th>
                                <th>Person</th>
                                <th>Access Method</th>
                                <th>Raw Event</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): 
                                $details = json_decode($log['event_details'], true);
                                $eventColor = 'secondary';
                                if (strpos($log['event_type'], 'Access') !== false) $eventColor = 'success';
                                if (strpos($log['event_type'], 'Alarm') !== false) $eventColor = 'danger';
                            ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?php echo date('d M Y', strtotime($log['localTime'])); ?></div>
                                        <small class="text-muted"><?php echo date('h:i:s A', strtotime($log['localTime'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($log['deviceName']); ?></span>
                                        <div class="small text-muted"><?php echo $log['machine_id']; ?></div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-primary-soft text-primary rounded-circle me-2 d-flex align-items-center justify-content-center" style="width:32px; height:32px; background: #e7f0ff;">
                                                <i class="bi bi-person"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold"><?php echo htmlspecialchars($log['personName'] ?: 'Unknown'); ?></div>
                                                <small class="text-muted">ID: <?php echo $log['personId'] ?: 'N/A'; ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $eventColor; ?>-soft text-<?php echo $eventColor; ?> rounded-pill" style="background-color: rgba(var(--bs-<?php echo $eventColor; ?>-rgb), 0.1);">
                                            <?php echo htmlspecialchars($log['event_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick='viewJson(<?php echo json_encode($details); ?>)'>
                                            <i class="bi bi-code-slash me-1"></i> JSON
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        <i class="bi bi-info-circle mb-2 d-block h1"></i>
                                        No logs found matching filters.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                    <a class="page-item" href="?page=<?php echo $i; ?>&machine_id=<?php echo $machine_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" class="page-link"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- USERS TAB (Hidden by default) -->
    <div id="users-section" style="display:none;">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <div id="users-container">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2">Fetching live user list from hardware...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Raw Event Modal -->
<div class="modal fade" id="jsonModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0">
            <div class="modal-header bg-light">
                <h5 class="modal-title">Raw Event Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="jsonViewer" class="bg-dark text-success p-3 rounded" style="max-height: 500px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabLogs = document.getElementById('tab-logs');
    const tabUsers = document.getElementById('tab-users');
    const logsSection = document.getElementById('logs-section');
    const usersSection = document.getElementById('users-section');

    tabLogs.addEventListener('click', function() {
        tabLogs.classList.add('active');
        tabUsers.classList.remove('active');
        logsSection.style.display = 'block';
        usersSection.style.display = 'none';
    });

    tabUsers.addEventListener('click', function() {
        tabUsers.classList.add('active');
        tabLogs.classList.remove('active');
        logsSection.style.display = 'none';
        usersSection.style.display = 'block';
        fetchUsers();
    });

    window.viewJson = function(data) {
        // Deep clone data to avoid modifying original
        const cleanData = JSON.parse(JSON.stringify(data));
        
        // Smart Timestamp Converter
        const formatTime = (ts) => {
            if (!ts) return null;
            const date = new Date(ts.toString().length === 13 ? ts : ts * 1000);
            return isNaN(date.getTime()) ? ts : date.toLocaleString();
        };

        if (cleanData.localTime) cleanData.localTime_formatted = formatTime(cleanData.localTime);
        if (cleanData.utcTime) cleanData.utcTime_formatted = formatTime(cleanData.utcTime);

        document.getElementById('jsonViewer').innerText = JSON.stringify(cleanData, null, 4);
        new bootstrap.Modal(document.getElementById('jsonModal')).show();
    };

    function fetchUsers() {
        const container = document.getElementById('users-container');
        fetch('api/dahua_proxy.php?action=get_users')
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    let html = `<table class="table table-hover align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th>Person ID</th>
                                <th>Name</th>
                                <th>Access Keys</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>`;
                    
                    data.users.forEach(u => {
                        let badges = '';
                        if (u.faceCount > 0) badges += '<span class="badge bg-success me-1"><i class="bi bi-person-bounding-box"></i> Face</span>';
                        if (u.cardCount > 0) badges += '<span class="badge bg-info me-1"><i class="bi bi-credit-card"></i> Card</span>';
                        if (u.fingerprintCount > 0) badges += '<span class="badge bg-warning text-dark me-1"><i class="bi bi-fingerprint"></i> Finger</span>';
                        if (u.passwordCount > 0) badges += '<span class="badge bg-secondary me-1"><i class="bi bi-key"></i> PIN</span>';

                        html += `<tr>
                            <td><code>${u.personId}</code></td>
                            <td><strong>${u.name}</strong></td>
                            <td>${badges || '<small class="text-muted">No Keys</small>'}</td>
                            <td><span class="badge bg-success-soft text-success">Synced</span></td>
                        </tr>`;
                    });
                    
                    html += '</tbody></table>';
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `<div class="alert alert-danger">Error: ${data.message}</div>`;
                }
            })
            .catch(e => {
                container.innerHTML = `<div class="alert alert-danger">Failed to fetch users. Check connection.</div>`;
            });
    }
});
</script>
<style>
.bg-primary-soft { background-color: rgba(67, 97, 238, 0.1); }
.bg-success-soft { background-color: rgba(46, 204, 113, 0.1); }
.avatar-sm { width: 32px; height: 32px; line-height: 32px; text-align: center; display: inline-block; }
</style>
<?php
require_once 'footer.php';
?>