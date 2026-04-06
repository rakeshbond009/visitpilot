<?php
require_once 'header.php';
require_once '../includes/migration_engine.php';

// --- SUPER ADMIN SECURITY ---
// STRICT PROTECTION: Only the System Super Administrator (on default database) can manage other clients.
if (!isset($_SESSION['is_super']) || !$_SESSION['is_super']) {
    echo '<div class="container py-5 text-center">
            <div class="alert alert-danger rounded-4 shadow-sm">
                <i class="bi bi-shield-lock display-1 d-block mb-3"></i>
                <h3>Access Denied</h3>
                <p>Only the System Super Administrator can manage clients and global database routing.</p>
                <a href="dashboard.php" class="btn btn-primary rounded-pill px-4 mt-3">Return to Dashboard</a>
            </div>
          </div>';
    require_once 'footer.php';
    exit;
}

$action_msg = $_SESSION['tenant_msg'] ?? "";
$action_icon = $_SESSION['tenant_icon'] ?? "success";
unset($_SESSION['tenant_msg'], $_SESSION['tenant_icon']);

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. ADD / EDIT TENANT
    if (isset($_POST['save_tenant'])) {
        $id = $_POST['tenant_id'] ?? '';
        $key = sanitize($_POST['tenant_key']);
        $db_host = sanitize($_POST['db_host']);
        $db_name = sanitize($_POST['db_name']);
        $db_user = sanitize($_POST['db_user']);
        $db_pass = $_POST['db_pass'];
        $status = $_POST['status'] ?? 'active';

        try {
            // Attempt to create database automatically if permissions allow
            try {
                $server_pdo = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
                $server_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $server_pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $db_e) {
                // Ignore creator error, proceed to save metadata
            }

            if ($id) {
                $max_devices = (int)($_POST['max_devices'] ?? 5);
                $stmt = $master_pdo->prepare("UPDATE tenants SET tenant_key=?, db_host=?, db_name=?, db_user=?, db_pass=?, status=?, max_devices=? WHERE id=?");
                $stmt->execute([$key, $db_host, $db_name, $db_user, $db_pass, $status, $max_devices, $id]);
                logAction($pdo, $_SESSION['user_id'], "Updated Tenant: $key (ID: $id, Quota: $max_devices)");
                $_SESSION['tenant_msg'] = "Tenant '$key' config updated.";
            } else {
                $max_devices = (int)($_POST['max_devices'] ?? 5);
                $stmt = $master_pdo->prepare("INSERT INTO tenants (tenant_key, db_host, db_name, db_user, db_pass, status, max_devices) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$key, $db_host, $db_name, $db_user, $db_pass, $status, $max_devices]);
                $new_id = $master_pdo->lastInsertId();
                logAction($pdo, $_SESSION['user_id'], "Registed New Tenant: $key (ID: $new_id, Quota: $max_devices)");

                // Generate random secure password for tenant admin
                $random_password = bin2hex(random_bytes(8)); // 16 character random password
                $hashed_password = password_hash($random_password, PASSWORD_DEFAULT);

                // Create admin user in tenant database
                try {
                    $tenant_pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
                    $tenant_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                    // Apply migrations first
                    $tenant_id = $master_pdo->lastInsertId();
                    applyMigrations($tenant_pdo, $key, 0);

                    // Create admin user with random password
                    $stmt = $tenant_pdo->prepare("INSERT INTO users (username, password, role, full_name) VALUES (?, ?, 'admin', ?)");
                    $stmt->execute(['admin', $hashed_password, ucfirst($key) . ' Administrator']);

                    // Update schema version
                    $master_pdo->prepare("UPDATE tenants SET schema_version = 2 WHERE id = ?")->execute([$tenant_id]);

                    logAction($pdo, $_SESSION['user_id'], "Created new Tenant: $key (ID: $tenant_id)");

                    $_SESSION['tenant_msg'] = "New tenant '$key' added successfully!<br><br><strong>Admin Credentials:</strong><br>Username: <code>admin</code><br>Password: <code>$random_password</code><br><br><span class='text-danger'>⚠️ Save this password securely - it cannot be recovered!</span>";
                } catch (PDOException $e) {
                    $_SESSION['tenant_msg'] = "Tenant added but admin creation failed: " . $e->getMessage();
                    $_SESSION['tenant_icon'] = "warning";
                }
            }
        } catch (PDOException $e) {
            $_SESSION['tenant_msg'] = "Error saving tenant: " . $e->getMessage();
            $_SESSION['tenant_icon'] = "warning";
        }
        redirect('tenants.php');
    }

    // 2. UPGADE ALL TENANTS
    if (isset($_POST['update_all'])) {
        $m_stmt = $master_pdo->query("SELECT * FROM tenants WHERE status = 'active'");
        $active_tenants = $m_stmt->fetchAll(PDO::FETCH_ASSOC);

        $updatedCount = 0;
        $all_errors = "";
        foreach ($active_tenants as $t) {
            try {
                try {
                    // Try to connect normally
                    $t_pdo = new PDO("mysql:host={$t['db_host']};dbname={$t['db_name']};charset=utf8mb4", $t['db_user'], $t['db_pass']);
                } catch (PDOException $e) {
                    if ($e->getCode() == 1049 || strpos($e->getMessage(), 'Unknown database') !== false) {
                        // Database missing - Join the host without selecting a DB
                        $server_pdo = new PDO("mysql:host={$t['db_host']};charset=utf8mb4", $t['db_user'], $t['db_pass']);
                        $server_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        $server_pdo->exec("CREATE DATABASE IF NOT EXISTS `{$t['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                        // Retry connection
                        $t_pdo = new PDO("mysql:host={$t['db_host']};dbname={$t['db_name']};charset=utf8mb4", $t['db_user'], $t['db_pass']);
                    } else {
                        throw $e;
                    }
                }

                $t_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $res = applyMigrations($t_pdo, $t['tenant_key'], $t['schema_version']);

                if ($res['count'] > 0 || $t['schema_version'] == 0) {
                    $upd = $master_pdo->prepare("UPDATE tenants SET schema_version = ? WHERE id = ?");
                    $upd->execute([$res['new_version'], $t['id']]);
                    $updatedCount++;
                }
            } catch (Exception $e) {
                $all_errors .= "Error updating {$t['tenant_key']}: " . $e->getMessage() . "<br>";
            }
        }
        logAction($pdo, $_SESSION['user_id'], "Triggered bulk upgrade for $updatedCount active tenants");
        $_SESSION['tenant_msg'] = "Successfully updated $updatedCount tenants.<br>" . $all_errors;
        redirect('tenants.php');
    }
}

// 4. FETCH ALL TENANTS (Prioritize display metadata)
$t_stmt = $master_pdo->query("SELECT t.*, 
    (SELECT COUNT(*) FROM tenant_devices WHERE tenant_key = t.tenant_key AND status = 'active') as active_devices 
    FROM tenants t ORDER BY t.id DESC");
$tenants = $t_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-0">Tenant & Database Directory</h3>
        <p class="text-muted small mb-0">Manage client databases and global schema updates.</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-dark rounded-pill px-4 shadow-sm" onclick="resetTenantForm()">
            <i class="bi bi-plus-circle me-1"></i> Add Client
        </button>
        <form method="POST">
            <button type="submit" name="update_all" class="btn btn-primary rounded-pill px-4 shadow-sm">
                <i class="bi bi-arrow-up-circle me-1"></i> Upgrade All
            </button>
        </form>
    </div>
</div>

<?php if ($action_msg): ?>
    <div class="alert alert-<?php echo ($action_icon == 'success') ? 'info' : 'warning'; ?> alert-dismissible fade show rounded-4 shadow-sm border-0 mb-4"
        role="alert">
        <?php echo $action_msg; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php
endif; ?>

<div class="card shadow-sm border-0 rounded-4 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4 small text-uppercase">Client / Tenant Key</th>
                    <th class="small text-uppercase">Database Connection</th>
                    <th class="small text-uppercase">Mobile Quota</th>
                    <th class="small text-uppercase">Version</th>
                    <th class="small text-uppercase">Status</th>
                    <th class="text-end pe-4 small text-uppercase">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $t): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-primary"><?php echo htmlspecialchars($t['tenant_key'] ?? ''); ?></div>
                            <small class="text-muted">ID: <?php echo $t['id'] ?? ''; ?> | Created:
                                <?php echo isset($t['created_at']) ? date('d M Y', strtotime($t['created_at'])) : ''; ?></small>
                        </td>
                        <td>
                            <div class="small">
                                <i class="bi bi-hdd-network me-1"></i>
                                <code><?php echo htmlspecialchars($t['db_host'] ?? ''); ?></code><br>
                                <i class="bi bi-database me-1"></i>
                                <code><?php echo htmlspecialchars($t['db_name'] ?? ''); ?></code>
                            </div>
                        </td>
                        <td class="fw-bold text-primary">
                            <i class="bi bi-phone me-1"></i>
                            <?php echo (int)($t['active_devices'] ?? 0); ?> / <?php echo (int)($t['max_devices'] ?? 5); ?>
                        </td>
                        <td>
                            <span class="badge bg-dark rounded-pill">v<?php echo $t['schema_version'] ?? '0'; ?></span>
                        </td>
                        <td>
                            <span
                                class="badge bg-<?php echo (($t['status'] ?? 'active') == 'active') ? 'success' : 'danger'; ?>-opacity text-<?php echo (($t['status'] ?? 'active') == 'active') ? 'success' : 'danger'; ?> px-3">
                                <?php echo strtoupper($t['status'] ?? 'ACTIVE'); ?>
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <a href="tenant_devices.php?tenant=<?php echo urlencode($t['tenant_key']); ?>"
                                class="btn btn-sm btn-outline-dark rounded-pill px-3 me-1" title="Manage Hardware">
                                <i class="bi bi-phone me-1"></i> Devices
                            </a>
                            <a href="<?php echo BASE_URL; ?>switch_tenant.php?tenant=<?php echo urlencode($t['tenant_key']); ?>"
                                class="btn btn-sm btn-success rounded-pill px-3 me-2" title="Login as this tenant"
                                target="_blank">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Login
                            </a>
                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3"
                                onclick="editTenant(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                                <i class="bi bi-pencil me-1"></i> Edit
                            </button>
                        </td>
                    </tr>
                    <?php
                endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Hostinger DB Pre-Check Modal -->
<div class="modal fade" id="hostingerCheckModal" tabindex="-1" aria-hidden="true" style="z-index: 11000;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-danger text-white border-0 py-3">
                <h5 class="modal-title fw-bold m-0"><i class="bi bi-exclamation-octagon-fill me-2"></i>MANDATORY STEP
                </h5>
            </div>
            <div class="modal-body text-center p-5">
                <div class="mb-4 text-danger">
                    <i class="bi bi-database-fill-x display-1"></i>
                </div>
                <h4 class="fw-bold text-dark">Hostinger Database Ready?</h4>
                <p class="text-muted fs-6 px-3">
                    You MUST first go to your <strong>Hostinger Control Panel</strong> and manually create the database
                    & user.<br><br>
                    Is the database created and ready for credentials?
                </p>
            </div>
            <div class="modal-footer bg-light border-0 justify-content-center py-3">
                <button type="button" class="btn btn-light border px-4 rounded-pill" data-bs-dismiss="modal">No, not
                    yet</button>
                <button type="button" id="confirmDbBtn" class="btn btn-danger px-5 rounded-pill fw-bold shadow-sm">Yes,
                    I have created it</button>
            </div>
        </div>
    </div>
</div>

<!-- Tenant Add/Edit Modal -->
<div class="modal fade" id="tenantModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title fw-bold" id="modalTitle">Add New Client</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="tenant_id" id="t_id">


                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Tenant Unique Key</label>
                        <input type="text" name="tenant_key" id="t_key" class="form-control fw-bold"
                            placeholder="e.g. apple, google, default" required>
                        <div class="form-text">This matches the subdomain or folder name.</div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label small fw-bold text-muted text-uppercase">Database Host</label>
                            <input type="text" name="db_host" id="t_host" class="form-control" value="localhost"
                                required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-bold text-muted text-uppercase">Mobile Quota</label>
                            <input type="number" name="max_devices" id="t_max_devices" class="form-control fw-bold" value="5" min="1" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Status</label>
                        <select name="status" id="t_status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>

                    <div class="mt-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Database Name</label>
                        <input type="text" name="db_name" id="t_db" class="form-control" placeholder="e.g. vms_client_1"
                            required>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">DB Username</label>
                            <input type="text" name="db_user" id="t_user" class="form-control" value="root" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted text-uppercase">DB Password</label>
                            <div class="input-group">
                                <input type="text" name="db_pass" id="t_pass" class="form-control"
                                    placeholder="Optional">
                                <button class="btn btn-outline-secondary" type="button"
                                    onclick="document.getElementById('t_pass').value = generateRandomPass(12)">
                                    <i class="bi bi-shuffle"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4"
                        data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_tenant" class="btn btn-dark rounded-pill px-4 fw-bold">Save Client
                        Config</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function generateRandomPass(length = 12) {
        const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*";
        let retVal = "";
        for (let i = 0, n = charset.length; i < length; ++i) {
            retVal += charset.charAt(Math.floor(Math.random() * n));
        }
        return retVal;
    }

    let pendingAction = null;

    function resetTenantForm() {
        pendingAction = 'add';
        const checkModal = new bootstrap.Modal(document.getElementById('hostingerCheckModal'));
        checkModal.show();
    }

    function editTenant(t) {
        pendingAction = { type: 'edit', data: t };
        const checkModal = new bootstrap.Modal(document.getElementById('hostingerCheckModal'));
        checkModal.show();
    }

    // Handle Confirmation in Pre-Check Modal
    document.getElementById('confirmDbBtn').addEventListener('click', function () {
        // Hide pre-check
        const checkModalEl = document.getElementById('hostingerCheckModal');
        bootstrap.Modal.getInstance(checkModalEl).hide();

        if (pendingAction === 'add') {
            showAddForm();
        } else if (pendingAction && pendingAction.type === 'edit') {
            showEditForm(pendingAction.data);
        }
    });

    function showAddForm() {
        document.getElementById('modalTitle').innerText = 'Add New Client';
        document.getElementById('t_id').value = '';
        document.getElementById('t_key').value = '';
        document.getElementById('t_host').value = 'localhost';
        document.getElementById('t_db').value = ''; 
        document.getElementById('t_user').value = 'root';
        document.getElementById('t_max_devices').value = '5';

        const isLocal = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';
        document.getElementById('t_pass').value = isLocal ? '' : generateRandomPass(10);
        document.getElementById('t_status').value = 'active';

        new bootstrap.Modal(document.getElementById('tenantModal')).show();
    }

    function showEditForm(t) {
        document.getElementById('modalTitle').innerText = 'Edit Client: ' + t.tenant_key;
        document.getElementById('t_id').value = t.id;
        document.getElementById('t_key').value = t.tenant_key;
        document.getElementById('t_host').value = t.db_host;
        document.getElementById('t_db').value = t.db_name;
        document.getElementById('t_user').value = t.db_user;
        document.getElementById('t_pass').value = t.db_pass;
        document.getElementById('t_status').value = t.status;
        document.getElementById('t_max_devices').value = t.max_devices || 5;

        new bootstrap.Modal(document.getElementById('tenantModal')).show();
    }
</script>

<?php require_once 'footer.php'; ?>