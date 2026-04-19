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



// 1. EMERGENCY DATABASE & TABLE READY
try {
    // Create base table if missing
    $pdo->exec("CREATE TABLE IF NOT EXISTS machine_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(100),
        person_id VARCHAR(100),
        UNIQUE KEY (device_id, person_id)
    )");

    // Force add missing columns to existing table
    $columns = [
        'name' => 'VARCHAR(255)',
        'card_no' => 'VARCHAR(100)',
        'face_count' => 'INT DEFAULT 0',
        'fp_count' => 'INT DEFAULT 0',
        'validity_start' => 'DATETIME NULL',
        'validity_end' => 'DATETIME NULL',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];

    foreach ($columns as $col => $type) {
        $check = $pdo->query("SHOW COLUMNS FROM machine_users LIKE '$col'");
        if ($check->rowCount() == 0) {
            $pdo->exec("ALTER TABLE machine_users ADD COLUMN $col $type");
        }
    }
} catch (Exception $e) {
    log_db_msg("Auto-Migrate Fail: " . $e->getMessage());
}

// 2. FILTERS & PAGINATION
$active_tab = $_GET['tab'] ?? 'logs';
$machine_id = $_GET['machine_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Fetch Available Machines for Filter
$machines = $pdo->query("SELECT DISTINCT machine_id FROM machine_logs WHERE machine_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

$logs = [];
$total_pages = 0;
$machine_users = [];


if ($active_tab === 'logs') {
    // --- LOAD ACCESS LOGS ---
    $where = ["1=1"];
    $params = [];
    if ($machine_id) { $where[] = "machine_id = ?"; $params[] = $machine_id; }
    if ($date_from) { $where[] = "event_time >= ?"; $params[] = $date_from . ' 00:00:00'; }
    if ($date_to) { $where[] = "event_time <= ?"; $params[] = $date_to . ' 23:59:59'; }

    $whereClause = implode(" AND ", $where);
    $countQuery = $pdo->prepare("SELECT COUNT(*) FROM machine_logs WHERE $whereClause");
    $countQuery->execute($params);
    $total_records = $countQuery->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    $sql = "SELECT * FROM machine_logs WHERE $whereClause ORDER BY event_time DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

} else {
    // --- LOAD MACHINE USERS ---
    $target_machine = $machine_id ?: ($machines[0] ?? null);
    
    // Check if we should sync (clicked "Sync" or DB is empty for this machine)
    $db_count = $pdo->prepare("SELECT COUNT(*) FROM machine_users WHERE device_id = ?");
    $db_count->execute([$target_machine]);
    $is_empty = ($db_count->fetchColumn() == 0);



    if (($target_machine && isset($_GET['sync'])) || ($target_machine && $is_empty)) {
        try {
            $user_data = DahuaHelper::getPeopleList($target_machine);
            if (!empty($user_data['pageData'])) {
                $upsert = $pdo->prepare("INSERT INTO machine_users 
                    (device_id, person_id, name, card_no, face_count, fp_count, validity_start, validity_end, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), card_no = VALUES(card_no), 
                        face_count = VALUES(face_count), fp_count = VALUES(fp_count),
                        validity_start = VALUES(validity_start), validity_end = VALUES(validity_end),
                        updated_at = NOW()");
                
                foreach ($user_data['pageData'] as $u) {
                    $reg_time = isset($u['createTime']) ? date('Y-m-d H:i:s', $u['createTime']/1000) : date('Y-m-d H:i:s');
                    $vp = $u['validityPeriod'] ?? '';
                    $v_start = null; $v_end = null;
                    if (strpos($vp, '~') !== false) {
                        $parts = explode('~', $vp);
                        $v_start = $parts[0] ?? null;
                        $v_end = $parts[1] ?? null;
                    }
                    
                    $upsert->execute([
                        $target_machine, 
                        $u['personId'], 
                        $u['name'], 
                        $u['cardNo'] ?? '',
                        count($u['faceList'] ?? []),
                        count($u['fingerprintList'] ?? []),
                        $v_start,
                        $v_end,
                        $reg_time
                    ]);
                }
                $_SESSION['app_msg'] = "Sync Successful: " . count($user_data['pageData']) . " users updated.";
            } else {
                $_SESSION['sync_error'] = "No user data returned from Dahua Cloud for this device.";
            }
        } catch (Exception $e) { 
            $_SESSION['sync_error'] = "Hardware Sync Failed: " . $e->getMessage();
        }
    }

    // Always fetch from OUR database (Instant)
    $u_where = ["device_id = ?"];
    $u_params = [$target_machine];
    if ($date_from) { $u_where[] = "created_at >= ?"; $u_params[] = $date_from . ' 00:00:00'; }
    if ($date_to) { $u_where[] = "created_at <= ?"; $u_params[] = $date_to . ' 23:59:59'; }

    $u_sql = "SELECT * FROM machine_users WHERE " . implode(" AND ", $u_where) . " ORDER BY created_at DESC";
    $u_stmt = $pdo->prepare($u_sql);
    $u_stmt->execute($u_params);
    $machine_users = $u_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = "Hardware Management";
include 'header.php';
?>


<div class="container-fluid py-3 px-4">
    <?php if (isset($_SESSION['sync_error'])): ?>
        <div class="alert alert-warning shadow-sm border-0 rounded-4 mb-4">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                <div>
                    <div class="fw-bold">Hardware Sync Warning</div>
                    <div class="small"><?php echo $_SESSION['sync_error']; unset($_SESSION['sync_error']); ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Header with Tabs -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1 fw-bold text-dark"><i class="bi bi-cpu-fill text-primary me-2"></i>Hardware Management</h4>
            <p class="text-muted small mb-0">Logs and user identities stored for all devices.</p>
        </div>
        <div class="btn-group shadow-sm rounded-3 overflow-hidden">
            <a href="?tab=logs&machine_id=<?php echo urlencode($machine_id); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="btn <?php echo $active_tab === 'logs' ? 'btn-primary' : 'btn-white border'; ?> px-4">Access Logs</a>
            <a href="?tab=users&machine_id=<?php echo urlencode($machine_id); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>" 
               class="btn <?php echo $active_tab === 'users' ? 'btn-primary' : 'btn-white border'; ?> px-4">Machine Users</a>
        </div>
    </div>


    <!-- Filter Bar -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
        <div class="card-body p-3 bg-white">
            <form method="GET" class="row g-3 align-items-end">
                <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted">Device</label>
                    <select name="machine_id" class="form-select">
                        <option value="">All Devices (Master Config)</option>
                        <?php foreach ($machines as $m): ?>
                            <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $machine_id == $m ? 'selected' : ''; ?>><?php echo htmlspecialchars($m); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label small fw-bold text-muted">From</label><input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-bold text-muted">To</label><input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>"></div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-dark fw-bold shadow-sm flex-grow-1">Filter</button>
                    <?php if ($active_tab === 'users'): ?>
                        <button type="submit" name="sync" value="1" class="btn btn-success fw-bold shadow-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Sync
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light border-bottom">
                    <?php if ($active_tab === 'logs'): ?>
                        <tr class="text-uppercase small fw-bold text-muted">
                            <th class="ps-4">Time</th>
                            <th>Identity</th>
                            <th>Device</th>
                            <th>Type</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    <?php else: ?>
                        <tr class="text-uppercase small fw-bold text-muted">
                            <th class="ps-4">Person ID</th>
                            <th>Full Name</th>
                            <th>Validity / Biometrics</th>
                            <th>Card</th>
                            <th>Sync Date</th>
                            <th class="text-end pe-4">Status</th>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php if ($active_tab === 'logs'): ?>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No logs recorded for this period.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold"><?php echo date('d-M-Y', strtotime($log['event_time'])); ?></div>
                                    <div class="small text-muted"><?php echo date('H:i:s', strtotime($log['event_time'])); ?></div>
                                </td>
                                <td>
                                    <div class="text-primary fw-bold"><?php echo htmlspecialchars($log['person_name'] ?: 'Unknown'); ?></div>
                                    <div class="small text-muted">ID: <?php echo htmlspecialchars($log['person_id'] ?: 'N/A'); ?></div>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($log['machine_id']); ?></span></td>
                                <td><span class="badge bg-info-subtle text-info border"><?php echo htmlspecialchars($log['event_type']); ?></span></td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick='viewRawPayload(<?php echo $log['raw_payload']; ?>)'>JSON</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php if (empty($machine_users)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">No users found on this machine in our database. Click "Sync" to fetch from hardware.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($machine_users as $user): ?>
                            <tr>
                                <td class="ps-4 fw-bold">#<?php echo htmlspecialchars($user['person_id']); ?></td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($user['name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($user['device_id']); ?></div>
                                </td>
                                <td>
                                    <div class="mb-1">
                                        <i class="bi bi-clock-history me-1 text-muted"></i>
                                        <small><?php echo ($user['validity_end']) ? date('d/m/Y', strtotime($user['validity_end'])) : 'Permanent Access'; ?></small>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <span class="badge <?php echo $user['face_count'] ? 'bg-primary-subtle text-primary' : 'bg-light text-muted'; ?> border-0">
                                            <i class="bi bi-person-bounding-box me-1"></i>Face: <?php echo $user['face_count']; ?>
                                        </span>
                                        <span class="badge <?php echo $user['fp_count'] ? 'bg-success-subtle text-success' : 'bg-light text-muted'; ?> border-0">
                                            <i class="bi bi-fingerprint me-1"></i>FP: <?php echo $user['fp_count']; ?>
                                        </span>
                                    </div>
                                </td>
                                <td><code class="text-dark bg-light px-2 rounded"><?php echo htmlspecialchars($user['card_no'] ?: 'N/A'); ?></code></td>
                                <td><?php echo date('d-M-Y H:i', strtotime($user['updated_at'])); ?></td>
                                <td class="text-end pe-4"><span class="badge bg-success-subtle text-success px-3 py-2 rounded-pill fw-bold">Active On Device</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
            timeSummary.innerHTML = '<i class="bi bi-clock me-2"></i>' + detectedTimes.join(' | ');
            timeSummary.classList.remove('d-none');
        }

        document.getElementById('payloadContent').textContent = JSON.stringify(json, null, 4);
    } catch (e) {
        document.getElementById('payloadContent').textContent = payload;
    }
    new bootstrap.Modal(document.getElementById('payloadModal')).show();
}
</script>

<?php include 'footer.php'; ?>