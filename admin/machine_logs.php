<?php

require_once '../includes/db.php';
require_once '../includes/dahua_helper.php';
require_once '../includes/dahua_management_helper.php';
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
        'pwd_count' => 'INT DEFAULT 0',
        'department' => 'VARCHAR(100)',
        'schedule_mode' => 'VARCHAR(100)',
        'permission_level' => 'VARCHAR(50)',
        'user_type' => 'VARCHAR(50)',
        'times_used' => 'VARCHAR(100)',
        'general_plan' => 'VARCHAR(100)',
        'holiday_plan' => 'VARCHAR(100)',
        'photo_path' => 'VARCHAR(255)',
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
            $list_response = DahuaHelper::getPeopleList($pdo, $target_machine);
            // Dahua wraps in data.pageData
            $list_data = $list_response['data'] ?? $list_response;
            $people_list = $list_data['pageData'] ?? (is_array($list_data) ? $list_data : []);
            $_SESSION['raw_debug'] = 'List returned ' . count($people_list) . ' people | raw keys: ' . implode(',', array_keys($list_response ?? []));

            if (!empty($people_list)) {
                $upsert = $pdo->prepare("INSERT INTO machine_users 
                    (device_id, person_id, name, card_no, face_count, fp_count, pwd_count, department, schedule_mode, permission_level, user_type, times_used, general_plan, holiday_plan, photo_path, validity_start, validity_end, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE 
                        name = VALUES(name), card_no = VALUES(card_no), 
                        face_count = VALUES(face_count), fp_count = VALUES(fp_count), pwd_count = VALUES(pwd_count),
                        department = VALUES(department), schedule_mode = VALUES(schedule_mode), permission_level = VALUES(permission_level),
                        user_type = VALUES(user_type), times_used = VALUES(times_used), general_plan = VALUES(general_plan), holiday_plan = VALUES(holiday_plan),
                        photo_path = VALUES(photo_path),
                        validity_start = VALUES(validity_start), validity_end = VALUES(validity_end),
                        updated_at = NOW()");

                $synced = 0;
                foreach ($people_list as $person_stub) {
                    $pid = $person_stub['personId'] ?? null;
                    if (!$pid)
                        continue;

                    // Fetch FULL detail per person — has card/pwd/face/fingerprint/photo
                    $detail = DahuaHelper::getPersonDetail($target_machine, $pid, $pdo);
                    $u = $detail ?: $person_stub; // fallback to list stub if detail fails

                    // --- Biometrics (Robust extraction for V1/V2) ---
                    $faceCount = isset($u['faceList']) ? count($u['faceList']) : ($u['hasFace'] ?? $u['faceCount'] ?? 0);
                    $fpCount = isset($u['fingerprintList']) ? count($u['fingerprintList']) : ($u['hasFingerprint'] ?? $u['fpCount'] ?? 0);
                    $pwdCount = isset($u['pwdList']) ? count($u['pwdList']) : ($u['hasPassword'] ?? ($u["password"] ? 1 : 0));
                    $cardNo = trim($u['cardNo'] ?? $u['cardNumber'] ?? ($u['cardList'][0]['cardNo'] ?? ''));
                    if ($cardNo == ($u['userId'] ?? $u['personId'] ?? '---')) $cardNo = ''; // Prevent ID as Card

                    // --- Meta (V1 vs V2 keys) ---
                    $dept = $u['deptName'] ?? $u['department'] ?? '1-Default';
                    $uType = $u['userType'] ?? $u['personType'] ?? 'General User';
                    $perm = $u['permission'] ?? $u['doorRight'] ?? 'User';
                    $schedule = $u['scheduleMode'] ?? 'Department Schedule';

                    // Map types based on Dahua Docs (Lines 7126-7132)
                    $types = [
                        0 => 'General', 
                        1 => 'Blacklist', 
                        2 => 'Guest', 
                        3 => 'Patrol', 
                        4 => 'VIP', 
                        5 => 'Extended Time'
                    ];
                    if (is_numeric($uType) && isset($types[(int)$uType])) {
                        $uType = $types[(int)$uType] . ' User';
                    }

                    // Map Permission/Authority (Lines 7142-7143)
                    $perm = $u['authority'] ?? ($u['permission'] ?? ($u['doorRight'] ?? 'User'));
                    if ($perm === 1 || $perm === '1') $perm = 'Admin';
                    if ($perm === 2 || $perm === '2') $perm = 'User';
                    
                    $tUsed = $u['timesUsed'] ?? 'Unlimited';
                    $gPlan = $u['generalPlan'] ?? '255-Default';
                    $hPlan = $u['holidayPlan'] ?? '255-Default';
                    $photoPath = $u['faceList'][0]['photoUrl'] ?? $u['photoPath'] ?? null;

                    // --- Validity ---
                    $v_start = null;
                    $v_end = null;
                    $vp = $u['validityPeriod'] ?? $u['validPeriod'] ?? '';
                    if ($vp && strpos($vp, '~') !== false) {
                        [$v_start, $v_end] = explode('~', $vp, 2);
                    }

                    $upsert->execute([
                        $target_machine,
                        $pid,
                        $u['name'] ?? $u['userName'] ?? $person_stub['name'] ?? 'Unknown',
                        $cardNo,
                        $faceCount,
                        $fpCount,
                        $pwdCount,
                        $dept,
                        $schedule,
                        $perm,
                        $uType ?: 'General User',
                        $tUsed,
                        $gPlan,
                        $hPlan,
                        $photoPath,
                        $v_start ?: null,
                        $v_end ?: null,
                    ]);
                }
                $_SESSION['app_msg'] = "Sync Successful: " . count($people_list) . " users updated.";
            } else {
                $_SESSION['sync_error'] = "No users returned from Dahua. Debug: " . htmlspecialchars(json_encode(array_slice((array) $list_response, 0, 3)));
            }
        } catch (Exception $e) {
            $_SESSION['sync_error'] = "Hardware Sync Failed: " . $e->getMessage();
        }
    }

    // Always fetch from OUR database (Instant)
    $u_where = ["device_id = ?"];
    $u_params = [$target_machine];
    if ($date_from) {
        $u_where[] = "created_at >= ?";
        $u_params[] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $u_where[] = "created_at <= ?";
        $u_params[] = $date_to . ' 23:59:59';
    }

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
                <div class="flex-grow-1">
                    <div class="fw-bold">Hardware Sync Warning</div>
                    <div class="small"><?php echo $_SESSION['sync_error']; ?></div>
                    <?php if (isset($_SESSION['raw_debug'])): ?>
                        <div class="mt-2 p-2 bg-dark text-warning rounded-3 font-monospace"
                            style="font-size: 10px; max-height: 100px; overflow: auto;">
                            RAW: <?php echo htmlspecialchars($_SESSION['raw_debug']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['sync_error'], $_SESSION['raw_debug']); ?>
    <?php endif; ?>

    <!-- Header with Tabs -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1 fw-bold text-dark"><i class="bi bi-cpu-fill text-primary me-2"></i>Hardware Management</h4>
            <p class="text-muted small mb-0">Logs and user identities stored for all devices.</p>
        </div>
        <div class="btn-group shadow-sm rounded-3 overflow-hidden">
            <a href="?tab=logs&machine_id=<?php echo urlencode($machine_id); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
                class="btn <?php echo $active_tab === 'logs' ? 'btn-primary' : 'btn-white border'; ?> px-4">Access
                Logs</a>
            <a href="?tab=users&machine_id=<?php echo urlencode($machine_id); ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"
                class="btn <?php echo $active_tab === 'users' ? 'btn-primary' : 'btn-white border'; ?> px-4">Machine
                Users</a>
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
                <div class="col-md-3"><label class="form-label small fw-bold text-muted">From</label><input type="date"
                        name="date_from" class="form-control" value="<?php echo $date_from; ?>"></div>
                <div class="col-md-3"><label class="form-label small fw-bold text-muted">To</label><input type="date"
                        name="date_to" class="form-control" value="<?php echo $date_to; ?>"></div>
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
                            <th class="ps-4">No.</th>
                            <th>Identity & Type</th>
                            <th>Plans & Config</th>
                            <th>Verification Modes</th>
                            <th>Validity Period</th>
                        </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php if ($active_tab === 'logs'): ?>
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">No logs recorded for this period.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold"><?php echo date('d-M-Y', strtotime($log['event_time'])); ?></div>
                                    <div class="small text-muted"><?php echo date('H:i:s', strtotime($log['event_time'])); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-primary fw-bold">
                                        <?php echo htmlspecialchars($log['person_name'] ?: 'Unknown'); ?></div>
                                    <div class="small text-muted">ID:
                                        <?php echo htmlspecialchars($log['person_id'] ?: 'N/A'); ?></div>
                                </td>
                                <td><span
                                        class="badge bg-light text-dark border"><?php echo htmlspecialchars($log['machine_id']); ?></span>
                                </td>
                                <td><span
                                        class="badge bg-info-subtle text-info border"><?php echo htmlspecialchars($log['event_type']); ?></span>
                                </td>
                                <td class="text-end pe-4">
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-3"
                                        onclick='viewRawPayload(<?php echo $log['raw_payload']; ?>)'>JSON</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php if (empty($machine_users)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">No users found on this machine in our
                                    database. Click "Sync" to fetch from hardware.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($machine_users as $user): ?>
                            <tr>
                                <td class="ps-4 fw-bold">#<?php echo htmlspecialchars($user['person_id']); ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-3">
                                            <?php if (!empty($user['photo_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($user['photo_path']); ?>" alt="Photo"
                                                    class="rounded-circle shadow-sm"
                                                    style="width: 50px; height: 50px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="rounded-circle border border-2 border-secondary border-opacity-25 d-flex justify-content-center align-items-center bg-light text-secondary"
                                                    style="width: 50px; height: 50px;">
                                                    <i class="bi bi-person fs-3"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($user['name']); ?>
                                            </div>
                                            <div class="small text-muted mt-1">
                                                <i
                                                    class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($user['user_type'] ?: 'General User'); ?><br>
                                                <i
                                                    class="bi bi-shield-lock me-1"></i><?php echo htmlspecialchars($user['permission_level'] ?: 'User'); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="small">
                                        <div class="mb-1"><span class="text-muted fw-bold">Dept:</span> <span
                                                class="badge bg-light text-dark border"><?php echo htmlspecialchars($user['department'] ?: '1-Default'); ?></span>
                                        </div>
                                        <div class="mb-1"><span class="text-muted fw-bold">Schedule:</span>
                                            <?php echo htmlspecialchars($user['schedule_mode'] ?: 'Department Schedule'); ?>
                                        </div>
                                        <div class="mb-1"><span class="text-muted fw-bold">General:</span>
                                            <?php echo htmlspecialchars($user['general_plan'] ?: '255-Default'); ?></div>
                                        <div class="mb-1"><span class="text-muted fw-bold">Holiday:</span>
                                            <?php echo htmlspecialchars($user['holiday_plan'] ?: '255-Default'); ?></div>
                                        <div><span class="text-muted fw-bold">Times Used:</span>
                                            <?php echo htmlspecialchars($user['times_used'] ?: 'Unlimited'); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-2">
                                        <div
                                            class="d-flex justify-content-between align-items-center p-2 rounded bg-light border <?php echo $user['face_count'] ? 'border-primary border-opacity-25' : ''; ?>">
                                            <span class="small fw-bold text-secondary"><i
                                                    class="bi bi-person-bounding-box me-2"></i>Face</span>
                                            <span
                                                class="badge <?php echo $user['face_count'] ? 'bg-primary' : 'bg-secondary bg-opacity-25 text-dark'; ?>">Added:
                                                <?php echo $user['face_count']; ?></span>
                                        </div>
                                        <div
                                            class="d-flex justify-content-between align-items-center p-2 rounded bg-light border <?php echo !empty($user['pwd_count']) ? 'border-warning border-opacity-25' : ''; ?>">
                                            <span class="small fw-bold text-secondary"><i
                                                    class="bi bi-key-fill me-2"></i>Password</span>
                                            <span
                                                class="badge <?php echo !empty($user['pwd_count']) ? 'bg-warning text-dark' : 'bg-secondary bg-opacity-25 text-dark'; ?>"><?php echo !empty($user['pwd_count']) ? 'Added' : 'Not Added'; ?></span>
                                        </div>
                                        <div
                                            class="d-flex justify-content-between align-items-center p-2 rounded bg-light border <?php echo !empty(trim($user['card_no'])) ? 'border-info border-opacity-25' : ''; ?>">
                                            <span class="small fw-bold text-secondary"><i
                                                    class="bi bi-credit-card-2-front-fill me-2"></i>Card</span>
                                            <span
                                                class="badge <?php echo !empty(trim($user['card_no'])) ? 'bg-info text-dark' : 'bg-secondary bg-opacity-25 text-dark'; ?>"><?php echo !empty(trim($user['card_no'])) ? 'Added' : 'Not Added'; ?></span>
                                        </div>
                                        <div
                                            class="d-flex justify-content-between align-items-center p-2 rounded bg-light border <?php echo $user['fp_count'] ? 'border-success border-opacity-25' : ''; ?>">
                                            <span class="small fw-bold text-secondary"><i
                                                    class="bi bi-fingerprint me-2"></i>Fingerprint</span>
                                            <span
                                                class="badge <?php echo $user['fp_count'] ? 'bg-success' : 'bg-secondary bg-opacity-25 text-dark'; ?>">Added:
                                                <?php echo $user['fp_count']; ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="pe-4">
                                    <div class="mb-2">
                                        <i class="bi bi-calendar-event me-1 text-muted"></i>
                                        <span
                                            class="fw-bold small"><?php echo ($user['validity_end']) ? date('d-m-Y H:i:s', strtotime($user['validity_end'])) : '31-12-2037 23:59:59'; ?></span>
                                    </div>
                                    <div class="small text-muted mb-2">Sync:
                                        <?php echo date('d-M-Y H:i', strtotime($user['updated_at'])); ?></div>
                                    <span class="badge bg-success px-3 py-1 rounded-pill">Active On Device</span>
                                </td>
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
                <div id="timeSummary"
                    class="mb-3 p-2 rounded bg-primary bg-opacity-10 text-primary small d-none border border-primary border-opacity-25">
                </div>
                <pre id="payloadContent" class="mb-0 p-3 bg-black rounded"
                    style="max-height: 500px; overflow-y: auto; font-size: 13px; color: #00ff00;"></pre>
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