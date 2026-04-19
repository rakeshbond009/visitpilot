<?php
require_once '../includes/db.php';
require_once '../includes/dahua_helper.php';
requireLogin();

// Permission Check
if (!canView('view_hardware_logs')) {
    $_SESSION['app_msg'] = "Access Denied: You do not have permission to view hardware logs.";
    $home = getHomeUrl($_SESSION['role'] ?? '');
    header("Location: $home");
    exit;
}

$page_title = "Hardware Management";
include 'header.php';

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
    $where[] = "event_time >= ?";
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to) {
    $where[] = "event_time <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$whereClause = implode(" AND ", $where);

// Count total logs
$countQuery = $pdo->prepare("SELECT COUNT(*) FROM machine_logs WHERE $whereClause");
$countQuery->execute($params);
$total_records = $countQuery->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch logs
$sql = "SELECT * FROM machine_logs WHERE $whereClause ORDER BY event_time DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique machine IDs for filter
$machines = $pdo->query("SELECT DISTINCT machine_id FROM machine_logs WHERE machine_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// Handle AJAX User Fetch
$machine_users = [];
$active_tab = $_GET['tab'] ?? 'logs';

if ($active_tab === 'users') {
    $target_machine = $machine_id ?: ($machines[0] ?? null);
    if ($target_machine) {
        $user_data = DahuaHelper::getPeopleList($target_machine);
        $machine_users = $user_data['pageData'] ?? [];
    }
}
?>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0 fw-bold"><i class="fas fa-microchip me-2 text-primary"></i>Hardware Center</h4>
        <div class="btn-group shadow-sm">
            <a href="?tab=logs" class="btn btn-<?php echo $active_tab === 'logs' ? 'primary' : 'outline-primary'; ?> px-4">
                <i class="fas fa-history me-2"></i>Access Logs
            </a>
            <a href="?tab=users" class="btn btn-<?php echo $active_tab === 'users' ? 'primary' : 'outline-primary'; ?> px-4">
                <i class="fas fa-users me-2"></i>Machine Users
            </a>
        </div>
    </div>

    <?php if ($active_tab === 'logs'): ?>
        <!-- ACCESS LOGS TAB -->
        <div class="card shadow-sm border-0">
            <div class="card-body bg-light border-bottom p-3">
                <form method="GET" class="row g-2">
                    <input type="hidden" name="tab" value="logs">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Select Machine</label>
                        <select name="machine_id" class="form-select form-select-sm">
                            <option value="">All Machines</option>
                            <?php foreach ($machines as $m): ?>
                                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $machine_id == $m ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100 shadow-sm">
                            <i class="fas fa-sync-alt me-1"></i>Refresh Logs
                        </button>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Time & Device</th>
                                <th>Person</th>
                                <th>Type / Mode</th>
                                <th>Snap</th>
                                <th class="text-end pe-4">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        No hardware activity recorded for this period.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold"><?php echo date('d-m-Y H:i:s', strtotime($log['event_time'])); ?></div>
                                        <span class="badge bg-secondary-subtle text-secondary border small" style="font-size: 10px;">
                                            <?php echo htmlspecialchars($log['machine_id']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-primary"><?php echo htmlspecialchars($log['person_name'] ?: 'Unknown'); ?></div>
                                        <div class="small text-muted">User ID: <?php echo htmlspecialchars($log['person_id'] ?: 'N/A'); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle px-2">
                                            <?php echo htmlspecialchars($log['event_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['image_path']): ?>
                                            <img src="<?php echo (strpos($log['image_path'], 'http') === 0) ? htmlspecialchars($log['image_path']) : 'data:image/jpeg;base64,' . $log['image_path']; ?>" 
                                                 class="rounded border hover-zoom cursor-pointer" style="width: 40px; height: 40px; object-fit: cover;"
                                                 onclick="viewLogImage(this.src)">
                                        <?php else: ?>
                                            <span class="text-muted small">No Image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button class="btn btn-sm btn-light border" onclick='viewRawPayload(<?php echo json_encode($log['raw_payload']); ?>)'>
                                            <i class="fas fa-code text-primary"></i> JSON
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white py-3">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                    <a class="page-link" href="?tab=logs&page=<?php echo $i; ?>&machine_id=<?php echo $machine_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab === 'users'): ?>
        <!-- MACHINE USERS TAB -->
        <div class="card shadow-sm border-0">
            <div class="card-body bg-light border-bottom p-3">
                <form method="GET" class="row g-2 align-items-center">
                    <input type="hidden" name="tab" value="users">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">Select Machine to Query</label>
                        <select name="machine_id" class="form-select form-select-sm">
                            <?php foreach ($machines as $m): ?>
                                <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $machine_id == $m ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 pt-4">
                        <button type="submit" class="btn btn-success btn-sm shadow-sm px-4">
                            <i class="fas fa-sync-alt me-2"></i>Fetch User List
                        </button>
                    </div>
                    <div class="col-md-5 text-end pt-4">
                        <span class="badge bg-warning text-dark"><i class="fas fa-info-circle me-1"></i> Live query from hardware</span>
                    </div>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">No.</th>
                                <th>Name</th>
                                <th>User ID</th>
                                <th>Validity Period</th>
                                <th>Credentials</th>
                                <th class="text-end pe-4">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($machine_users)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <div class="text-muted mb-2">No users found or hardware not responding.</div>
                                        <div class="small">Click "Fetch User List" to query the selected device.</div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($machine_users as $idx => $user): ?>
                                <tr>
                                    <td class="ps-4 text-muted"><?php echo str_pad($idx + 1, 2, '0', STR_PAD_LEFT); ?></td>
                                    <td><strong class="text-primary"><?php echo htmlspecialchars($user['name'] ?? 'N/A'); ?></strong></td>
                                    <td><code class="text-dark bg-light px-2 rounded"><?php echo htmlspecialchars($user['personId'] ?? ''); ?></code></td>
                                    <td>
                                        <div class="small">
                                            <i class="far fa-calendar-check me-1 text-success"></i>
                                            <?php echo !empty($user['validityPeriod']) ? date('d-m-Y', strtotime($user['validityPeriod'])) : 'Permanent'; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <?php if (!empty($user['faceList'])): ?>
                                                <i class="fas fa-smile text-primary" title="Face Profile"></i>
                                            <?php endif; ?>
                                            <?php if (!empty($user['fingerprintList'])): ?>
                                                <i class="fas fa-fingerprint text-success" title="Fingerprint"></i>
                                            <?php endif; ?>
                                            <?php if (!empty($user['cardNo'])): ?>
                                                <i class="fas fa-id-card text-warning" title="ID Card"></i>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-end pe-4">
                                        <span class="badge bg-success shadow-none">Active</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Raw Payload Modal with Smart Time Formatter -->
<div class="modal fade" id="payloadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white border-0">
                <h6 class="modal-title mb-0 fw-bold">Raw Hardware Event JSON</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-dark text-light border-0">
                <div id="timeSummary" class="mb-3 p-2 rounded bg-primary bg-opacity-10 text-primary small d-none border border-primary border-opacity-25"></div>
                <pre id="payloadContent" class="mb-0 p-3 bg-black rounded" style="max-height: 500px; overflow-y: auto; font-size: 13px; color: #00ff00;"></pre>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content bg-transparent border-0">
            <div class="modal-body text-center p-0">
                <img id="modalImage" src="" class="img-fluid rounded shadow-lg border border-light border-opacity-25">
            </div>
        </div>
    </div>
</div>

<script>
    function viewRawPayload(payload) {
        try {
            const json = typeof payload === 'string' ? JSON.parse(payload) : payload;
            
            // Smart Time Formatting for JSON View
            const timeSummary = document.getElementById('timeSummary');
            timeSummary.innerHTML = '';
            timeSummary.classList.add('d-none');
            
            let detectedTimes = [];
            const timeKeys = ['localTime', 'utcTime', 'time'];
            
            timeKeys.forEach(key => {
                if (json[key]) {
                    const ts = parseInt(json[key]);
                    if (ts > 100000000000) { // Milliseconds check
                        const d = new Date(ts);
                        const human = d.toLocaleString('en-IN');
                        detectedTimes.push(`<strong>${key}:</strong> ${human}`);
                    }
                }
            });
            
            if (detectedTimes.length > 0) {
                timeSummary.innerHTML = '<i class="fas fa-clock me-2"></i>' + detectedTimes.join(' | ');
                timeSummary.classList.remove('d-none');
            }

            document.getElementById('payloadContent').textContent = JSON.stringify(json, null, 4);
        } catch (e) {
            document.getElementById('payloadContent').textContent = payload;
        }
        new bootstrap.Modal(document.getElementById('payloadModal')).show();
    }

    function viewLogImage(src) {
        document.getElementById('modalImage').src = src;
        new bootstrap.Modal(document.getElementById('imageModal')).show();
    }
</script>

<style>
    .cursor-pointer { cursor: pointer; }
    .hover-zoom:hover { transform: scale(1.1); transition: transform 0.2s ease-in-out; }
    .nav-tabs .nav-link { font-weight: 700; color: #6c757d; border: none; padding: 10px 20px; }
    .nav-tabs .nav-link.active { color: #4361ee; border-bottom: 3px solid #4361ee; background: transparent; }
    pre { scrollbar-width: thin; }
</style>

<?php include 'footer.php'; ?>