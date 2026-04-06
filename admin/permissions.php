<?php
require_once '../includes/db.php';
requireLogin();
// Role check handled in header.php

$error = '';
$success = '';
$form_data = []; // To preserve form values on error

// Handle Permission Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id'])) {
    $user_id = (int) $_POST['user_id'];

    // Check if reset requested
    if (isset($_POST['reset_defaults'])) {
        try {
            $pdo->prepare("UPDATE users SET permissions_locked = 0 WHERE id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$user_id]);
            logAction($pdo, $_SESSION['user_id'], "Reset permissions for user ID: $user_id to defaults");
            redirect("permissions.php?success=" . urlencode("Permissions reset to Role Defaults."));
        } catch (Exception $e) {
            $error = "Error resetting permissions: " . $e->getMessage();
        }
    } else {
        // Normal Save (Lock permissions)
        $perms = $_POST['permissions'] ?? []; // Array of keys
        try {
            $pdo->beginTransaction();

            // Clear existing permissions
            $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$user_id]);

            // Insert new permissions
            if (!empty($perms)) {
                $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key) VALUES (?, ?)");
                foreach ($perms as $key) {
                    $stmt->execute([$user_id, $key]);
                }
            }

            // Mark that permissions have been configured for this user
            $stmt = $pdo->prepare("UPDATE users SET permissions_locked = 1 WHERE id = ?");
            $stmt->execute([$user_id]);

            logAction($pdo, $_SESSION['user_id'], "Updated custom permissions for user ID: $user_id");

            $pdo->commit();
            redirect("permissions.php?success=" . urlencode("Custom permissions saved (User is now 'Locked' to these rules)."));
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error updating permissions: " . $e->getMessage();
        }
    }
}

// Handle Add New User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_username'])) {
    $username = sanitize($_POST['new_username']);
    $password_raw = $_POST['new_password'];
    $full_name = sanitize($_POST['new_full_name']);
    $role = sanitize($_POST['new_role']);
    $department = sanitize($_POST['new_department']);
    $email = sanitize($_POST['new_email'] ?? '');
    $mobile = sanitize($_POST['new_mobile'] ?? '');
    $status = sanitize($_POST['new_status'] ?? 'active');

    // Store for form persistence
    $form_data = $_POST;

    try {
        // Validation
        if (!empty($mobile) && !preg_match("/^[0-9]{10}$/", $mobile)) {
            throw new Exception("Please enter a valid 10-digit mobile number.");
        }

        // Check if username already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            throw new Exception("Username '$username' is already taken. Please choose a different username.");
        }

        $pdo->beginTransaction();

        $employee_id = null;

        // Ensure every user has a linked employee record (irrespective of role)
        // Proactive check: Check if employee with same Name + (Email or Mobile) exists
        $checkEmp = $pdo->prepare("SELECT id FROM employees WHERE name = ? AND (email = ? OR mobile = ?)");
        $checkEmp->execute([$full_name, $email, $mobile]);
        $existingEmp = $checkEmp->fetch();

        if ($existingEmp) {
            $employee_id = $existingEmp['id'];
            // Update their info just in case
            $stmt = $pdo->prepare("UPDATE employees SET department = ?, email = ?, mobile = ?, status = ? WHERE id = ?");
            $stmt->execute([$department, $email, $mobile, $status, $employee_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO employees (name, department, email, mobile, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$full_name, $department, $email, $mobile, $status]);
            $employee_id = $pdo->lastInsertId();
        }

        // Create user record
        $password = password_hash($password_raw, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, department, employee_id, email, mobile, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $password, $full_name, $role, $department, $employee_id, $email, $mobile, $status]);

        logAction($pdo, $_SESSION['user_id'], "Created new user: $username (Role: $role)");

        $pdo->commit();
        redirect("permissions.php?success=" . urlencode("New user '$username' created successfully!" . ($employee_id ? " (Employee record linked/created)" : "")));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Handle Edit User
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user_id'])) {
    $id = (int) $_POST['edit_user_id'];
    $username = sanitize($_POST['edit_username']);
    $full_name = sanitize($_POST['edit_full_name']);
    $role = sanitize($_POST['edit_role']);
    $department = sanitize($_POST['edit_department']);
    $status = sanitize($_POST['edit_status'] ?? 'active');
    $password = $_POST['edit_password'];

    try {
        // Change Detection for Audit Log
        $curr = $pdo->prepare("SELECT username, full_name, role, department, status, employee_id FROM users WHERE id = ?");
        $curr->execute([$id]);
        $old = $curr->fetch();

        $changes = [];
        if ($old['username'] !== $username)
            $changes[] = "username";
        if ($old['full_name'] !== $full_name)
            $changes[] = "full name";
        if ($old['role'] !== $role)
            $changes[] = "role ({$old['role']} to $role)";
        if ($old['department'] !== $department)
            $changes[] = "department";
        if ($old['status'] !== $status)
            $changes[] = "status";
        if (!empty($password))
            $changes[] = "password";

        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, department=?, status=?, password=? WHERE id=?");
            $stmt->execute([$username, $full_name, $role, $department, $status, $hash, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, department=?, status=? WHERE id=?");
            $stmt->execute([$username, $full_name, $role, $department, $status, $id]);
        }

        // Sync with employee record if linked
        if ($old['employee_id']) {
            $stmt = $pdo->prepare("UPDATE employees SET name=?, department=?, status=? WHERE id=?");
            $stmt->execute([$full_name, $department, $status, $old['employee_id']]);
        }

        // --- SYNC Hardware Status ---
        if ($old['status'] !== $status) {
            toggleUserMobileAccess($id, $status);
        }

        $msg_log = "Updated user $username (ID: $id): " . (empty($changes) ? "no profile changes" : implode(", ", $changes));
        logAction($pdo, $_SESSION['user_id'], $msg_log);

        redirect("permissions.php?success=" . urlencode("User details updated successfully."));
    } catch (PDOException $e) {
        $error = "Error updating user: " . $e->getMessage();
    }
}

// Handle Role Permission Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['role_permission_update'])) {
    $target_role = $_POST['target_role'];
    $perms = $_POST['role_permissions'] ?? [];

    try {
        // Ensure table exists (Lazy Init)
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role VARCHAR(50) NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            UNIQUE KEY role_perm_unique (role, permission_key)
        )");

        // Ensure user_permissions table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            permission_key VARCHAR(100) NOT NULL,
            UNIQUE KEY user_perm_unique (user_id, permission_key)
        )");

        // Ensure users table has permissions_locked column
        try {
            $pdo->query("SELECT permissions_locked FROM users LIMIT 1");
        } catch (Exception $e) {
            $pdo->exec("ALTER TABLE users ADD COLUMN permissions_locked TINYINT(1) DEFAULT 0");
        }

        $pdo->beginTransaction();
        // Clear existing for role
        $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role = ?");
        $stmt->execute([$target_role]);

        // Add new
        $stmt = $pdo->prepare("INSERT INTO role_permissions (role, permission_key) VALUES (?, ?)");
        foreach ($perms as $key) {
            $stmt->execute([$target_role, $key]);
        }
        logAction($pdo, $_SESSION['user_id'], "Updated default permissions for role: $target_role. New permissions: " . (empty($perms) ? 'NONE' : implode(', ', $perms)));
        $pdo->commit();
        redirect("permissions.php?success=" . urlencode("Default permissions for role '" . strtoupper($target_role) . "' updated."));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = "Error updating role permissions: " . $e->getMessage();
    }
}

// Handle Status Toggle (Quick Action)
if (isset($_GET['toggle_status'])) {
    $uid = (int)$_GET['toggle_status'];
    $new_status = sanitize($_GET['status'] ?? 'active');
    
    try {
        $stmt = $pdo->prepare("SELECT employee_id, username FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $uInfo = $stmt->fetch();
        
        if ($uInfo) {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("UPDATE users SET status=? WHERE id=?");
            $stmt->execute([$new_status, $uid]);
            
            if ($uInfo['employee_id']) {
                $stmt = $pdo->prepare("UPDATE employees SET status=? WHERE id=?");
                $stmt->execute([$new_status, $uInfo['employee_id']]);
            }
            
            // --- SYNC Hardware Status ---
            toggleUserMobileAccess($uid, $new_status);
            
            logAction($pdo, $_SESSION['user_id'], "Quick toggled status for user @{$uInfo['username']} to $new_status (Synchronized mobile hardware: $new_status)");
            $pdo->commit();
            redirect("permissions.php?success=" . urlencode("User status updated to $new_status."));
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = "Error toggling status: " . $e->getMessage();
    }
}

// Quota Check
$is_quota_reached = isUserQuotaReached();
$quota_limit = $tenant['max_users'] ?? 0;

// Capture success from GET
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Note: We need to also fetch department in the SQL query below
$sql = "SELECT u.id, u.username, u.full_name, u.role, u.department, u.permissions_locked, u.status, u.employee_id,
               GROUP_CONCAT(up.permission_key) as perms 
        FROM users u 
        LEFT JOIN user_permissions up ON u.id = up.user_id 
        GROUP BY u.id 
        ORDER BY u.username";
$users = $pdo->query($sql)->fetchAll();

// Fetch departments for dropdown
$departments = [];
try {
    $departments = $pdo->query("SELECT name FROM departments WHERE status = 'active' ORDER BY name")->fetchAll();
} catch (Exception $e) {
    // Departments table might not exist
}

// Fetch Role Permissions
$role_perms = [];
try {
    $stmt = $pdo->query("SELECT * FROM role_permissions");
    while ($row = $stmt->fetch()) {
        $role_perms[$row['role']][] = $row['permission_key'];
    }
} catch (Exception $e) {
    // Table might not exist yet, that's fine
}

// Define Grouped Permissions for UI
$permission_groups = [
    'analytics' => [
        'title' => 'Analytics & Reporting',
        'icon' => 'bi bi-graph-up-arrow',
        'color' => 'primary',
        'perms' => [
            'admin_reports' => 'Reports & Analytics',
            'view_employee_report' => 'Employee-wise Report',
        ]
    ],
    'visitor_ops' => [
        'title' => 'Security & Visitor Ops',
        'icon' => 'bi bi-shield-shaded',
        'color' => 'info',
        'perms' => [
            'security_register' => 'Visitor Registration',
            'security_scan' => 'Access Point QR Scanning',
            'security_search' => 'Global History Search',
        ]
    ],
    'host_module' => [
        'title' => 'Host / Employee Access',
        'icon' => 'bi bi-person-workspace',
        'color' => 'success',
        'perms' => [
            'host_pending' => 'Pending Requests',
            'host_invite' => 'Invites',
            'host_history' => 'My Visitors History',
        ]
    ],
    'management' => [
        'title' => 'Management & Staff',
        'icon' => 'bi bi-people-fill',
        'color' => 'warning',
        'perms' => [
            'admin_employees' => 'Staff / Employee Master',
            'admin_users' => 'Users & Permissions',
        ]
    ],
    'ai_intelligence' => [
        'title' => 'AI Intelligence',
        'icon' => 'bi bi-robot',
        'color' => 'info',
        'perms' => [
            'access_ai_rag_chat' => 'AI Data Chat (RAG)',
            'settings_ai' => 'AI System & API Config',
        ]
    ],
    'settings' => [
        'title' => 'System Configuration',
        'icon' => 'bi bi-gear-wide-connected',
        'color' => 'danger',
        'perms' => [
            'settings_profile' => 'User Profile & Personal Account',
            'settings_tenant' => 'Tenant Profile (Hours, Capacity, GST)',
            'settings_general' => 'Visit Purposes & Configuration',
            'settings_departments' => 'Department Master Control',
            'settings_access' => 'Access Zones & Area Config',
            'settings_email' => 'SMTP & Email Delivery Setup',
            'settings_whatsapp' => 'WhatsApp Cloud API Setup',
            'report_issue' => 'Report App Issue / Bug',
        ]
    ]
];

// Flatten for compatibility with existing saving logic
$available_permissions = [];
foreach ($permission_groups as $group) {
    foreach ($group['perms'] as $key => $label) {
        $available_permissions[$key] = $label;
    }
}

require_once 'header.php';
?>

<style>
    /* Premium Form Styles borrowed from Visitor Registration */
    .modal-content {
        border: none;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.1);
    }
    .modal-header {
        border-bottom: 1px solid #f0f0f0;
        padding: 1.5rem;
        background: #fff;
        border-radius: 16px 16px 0 0;
    }
    .modal-title {
        font-weight: 800;
        color: #333;
        letter-spacing: -0.5px;
    }
    .modal-body {
        padding: 2rem;
        background: #f8f9fa;
    }
    
    /* Section Headers */
    .section-header {
        background: linear-gradient(to right, rgba(67, 97, 238, 0.08), transparent);
        color: #4361ee;
        font-weight: 800;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.8rem 1rem;
        border-radius: 8px;
        border-left: 4px solid #4361ee;
        margin-top: 0.5rem;
    }
    
    /* Form Floating Controls */
    .form-floating > .form-control,
    .form-floating > .form-select {
        border: 2px solid #e9ecef;
        border-radius: 12px;
        background-color: #fff;
        height: 60px;
        padding-top: 1.7rem;
        padding-bottom: 0.5rem;
        font-weight: 600;
        color: #212529;
        transition: all 0.2s ease-in-out;
    }
    
    .form-floating > .form-control:focus,
    .form-floating > .form-select:focus {
        border-color: #4361ee;
        background-color: #fff;
        box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
    }
    
    .form-floating > label {
        padding-left: 1.25rem;
        font-weight: 600;
        color: #adb5bd;
        text-transform: uppercase;
        font-size: 0.7rem;
        letter-spacing: 0.5px;
        padding-top: 1rem;
    }
    
    .form-floating > .form-control:focus ~ label,
    .form-floating > .form-select:focus ~ label {
        color: #4361ee;
    }
    
    /* Buttons (Enhanced) */
    .btn-primary {
        background: #4361ee;
        border: none;
        padding: 0.8rem 1.5rem;
        font-weight: 700;
        border-radius: 10px;
        letter-spacing: 0.5px;
        box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .btn-primary:hover {
        background: #3a56d4;
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(67, 97, 238, 0.4);
    }

    /* Permission UI Enhancements */
    .perm-group-container {
        background: #fff;
        border-radius: 12px;
        padding: 1.25rem;
        margin-bottom: 1.5rem;
        border: 1px solid #eef2f7;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    .perm-group-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 1rem;
        padding-bottom: 0.75rem;
        border-bottom: 1px dashed #e2e8f0;
    }
    .perm-group-header i {
        font-size: 1.25rem;
    }
    .perm-group-header h6 {
        margin: 0;
        font-weight: 800;
        text-transform: uppercase;
        font-size: 0.8rem;
        letter-spacing: 0.5px;
    }
    .permission-item {
        margin-bottom: 0.5rem;
        transition: all 0.2s ease;
        border-radius: 8px;
    }
    .permission-item:hover {
        background-color: #f8fafc;
    }
    .form-check-input {
        width: 1.25em;
        height: 1.25em;
        margin-top: 0.15em;
        cursor: pointer;
    }
    .form-check-label {
        cursor: pointer;
        font-weight: 600;
        color: #444;
        padding-left: 0.5rem;
        font-size: 0.9rem;
    }
</style>

    <h3>User Permissions Management</h3>
    <div>
        <button class="btn btn-info text-white me-2" data-bs-toggle="modal" data-bs-target="#rolePermModal">
            <i class="bi bi-diagram-3-fill"></i> Manage Roles
        </button>
        <button class="btn btn-success" onclick="handleAddUserClick()">
            <i class="bi bi-person-plus-fill"></i> Add New User
        </button>
    </div>
</div>

<!-- Role Permissions Modal -->
<div class="modal fade" id="rolePermModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="bi bi-diagram-3-fill me-2 text-primary"></i>Default Role Permissions</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <div class="alert alert-warning border-0 shadow-sm">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Note:</strong> These settings apply to <u>ALL</u> users of that role who do have "locked" custom permissions. 
                    If a user has specific permissions assigned, those will override these defaults.
                </div>

                <div class="row g-4">
                    <!-- Security Role -->
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden">
                            <div class="card-header bg-primary text-white p-3 border-0">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5 class="mb-0 fw-bold"><i class="bi bi-shield-lock-fill me-2"></i>Security Role</h5>
                                    <span class="badge bg-white text-primary rounded-pill">Reception</span>
                                </div>
                            </div>
                            <div class="card-body p-4">
                                <form method="POST">
                                    <input type="hidden" name="role_permission_update" value="1">
                                    <input type="hidden" name="target_role" value="security">
                                    
                                    <?php foreach ($permission_groups as $group_key => $group): ?>
                                            <div class="perm-group-container">
                                                <div class="perm-group-header text-<?php echo $group['color']; ?>">
                                                    <i class="<?php echo $group['icon']; ?>"></i>
                                                    <h6><?php echo $group['title']; ?></h6>
                                                </div>
                                                <div class="row g-2">
                                                    <?php foreach ($group['perms'] as $key => $label):
                                                        $checked = (isset($role_perms['security']) && in_array($key, $role_perms['security'])) ? 'checked' : '';
                                                        ?>
                                                            <div class="col-12">
                                                                <div class="form-check permission-item p-2">
                                                                    <input class="form-check-input" type="checkbox" name="role_permissions[]" 
                                                                           value="<?php echo $key; ?>" id="r_sec_<?php echo $key; ?>" <?php echo $checked; ?>>
                                                                    <label class="form-check-label" for="r_sec_<?php echo $key; ?>">
                                                                        <?php echo $label; ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php
                                                    endforeach; ?>
                                                </div>
                                            </div>
                                        <?php
                                    endforeach; ?>

                                    <div class="mt-4 pt-3 border-top d-grid">
                                        <button type="submit" class="btn btn-primary fw-bold py-2">
                                            <i class="bi bi-save2 me-2"></i>Update Security Defaults
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Host/Employee Role -->
                    <div class="col-md-6">
                        <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden">
                            <div class="card-header bg-success text-white p-3 border-0">
                                <div class="d-flex align-items-center justify-content-between">
                                    <h5 class="mb-0 fw-bold"><i class="bi bi-person-badge-fill me-2"></i>Host / Employee Role</h5>
                                    <span class="badge bg-white text-success rounded-pill">Staff</span>
                                </div>
                            </div>
                            <div class="card-body p-4">
                                <form method="POST">
                                    <input type="hidden" name="role_permission_update" value="1">
                                    <input type="hidden" name="target_role" value="employee">
                                    
                                    <?php foreach ($permission_groups as $group_key => $group): ?>
                                            <div class="perm-group-container">
                                                <div class="perm-group-header text-<?php echo $group['color']; ?>">
                                                    <i class="<?php echo $group['icon']; ?>"></i>
                                                    <h6><?php echo $group['title']; ?></h6>
                                                </div>
                                                <div class="row g-2">
                                                    <?php foreach ($group['perms'] as $key => $label):
                                                        $checked = (isset($role_perms['employee']) && in_array($key, $role_perms['employee'])) ? 'checked' : '';
                                                        ?>
                                                            <div class="col-12">
                                                                <div class="form-check permission-item p-2">
                                                                    <input class="form-check-input" type="checkbox" name="role_permissions[]" 
                                                                           value="<?php echo $key; ?>" id="r_emp_<?php echo $key; ?>" <?php echo $checked; ?>>
                                                                    <label class="form-check-label" for="r_emp_<?php echo $key; ?>">
                                                                        <?php echo $label; ?>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        <?php
                                                    endforeach; ?>
                                                </div>
                                            </div>
                                        <?php
                                    endforeach; ?>

                                    <div class="mt-4 pt-3 border-top d-grid">
                                        <button type="submit" class="btn btn-success fw-bold py-2">
                                            <i class="bi bi-save2 me-1"></i>Update Host Defaults
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Assigned Permissions</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                    <small class="text-muted">@<?php echo htmlspecialchars($u['username']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo strtoupper($u['role']); ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo ($u['status'] == 'active') ? 'success' : 'danger'; ?>">
                                        <?php echo ucfirst($u['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $userPerms = $u['perms'] ? explode(',', $u['perms']) : [];
                                    if ($u['permissions_locked']) {
                                        $p_cnt = count($userPerms);
                                        echo "<span class='badge bg-warning text-dark shadow-sm border border-warning-subtle'><i class='bi bi-lock-fill me-1'></i> User-Specific ({$p_cnt} Rules)</span>";
                                        echo "<div class='small text-muted mt-1'>Role defaults are ignored</div>";
                                    } else {
                                        echo "<span class='badge bg-light text-muted border'><i class='bi bi-diagram-3-fill me-1'></i> Role Defaults</span>";
                                        echo "<div class='small text-muted mt-1'>Following {$u['role']} rules</div>";
                                    }
                                    ?>
                                </td>
                                <td class="text-end">
                                    <!-- Quick Status Toggle -->
                                    <?php if ($u['status'] == 'active'): ?>
                                        <button type="button" 
                                           class="btn btn-sm btn-outline-danger" title="Deactivate"
                                           onclick="toggleUserStatus(<?php echo $u['id']; ?>, 'inactive')">
                                            <i class="bi bi-slash-circle"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" 
                                           class="btn btn-sm btn-outline-success" title="Activate"
                                           onclick="toggleUserStatus(<?php echo $u['id']; ?>, 'active')">
                                            <i class="bi bi-check-circle"></i>
                                        </button>
                                    <?php endif; ?>

                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                        data-bs-target="#permModal<?php echo $u['id']; ?>" title="Manage Permissions">
                                        <i class="bi bi-shield-lock"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning text-dark" data-bs-toggle="modal"
                                        data-bs-target="#editUserModal<?php echo $u['id']; ?>" title="Edit Details">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php
                    endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modals generated outside table to prevent layout issues -->
<?php foreach ($users as $u):
    $userPerms = $u['perms'] ? explode(',', $u['perms']) : [];
    ?>
        <div class="modal fade" id="permModal<?php echo $u['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <form method="POST">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Permissions: <?php echo htmlspecialchars($u['full_name']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                            <?php if ($u['permissions_locked']): ?>
                                    <div class="alert alert-warning small border-0 shadow-sm">
                                        <i class="bi bi-lock-fill me-2"></i>
                                        <strong>Custom Permissions Active.</strong> This user has specific access rules that override the Defaults.
                                    </div>
                                <?php
                            else: ?>
                                    <div class="alert alert-success small border-0 shadow-sm">
                                        <i class="bi bi-diagram-3-fill me-2"></i>
                                        <strong>Inheriting Defaults.</strong> This user is currently following the Role Default rules.
                                        <br>Checking boxes below and saving will switch them to Custom Mode.
                                    </div>
                                <?php
                            endif; ?>

                            <div class="row g-3">
                                <?php foreach ($permission_groups as $group_key => $group): ?>
                                        <div class="col-md-6">
                                            <div class="perm-group-container h-100">
                                                <div class="perm-group-header text-<?php echo $group['color']; ?>">
                                                    <i class="<?php echo $group['icon']; ?>"></i>
                                                    <h6><?php echo $group['title']; ?></h6>
                                                </div>
                                                <?php foreach ($group['perms'] as $key => $label):
                                                    $checked = in_array($key, $userPerms) ? 'checked' : '';
                                                    ?>
                                                        <div class="form-check permission-item p-2">
                                                            <input class="form-check-input" type="checkbox" name="permissions[]"
                                                                value="<?php echo $key; ?>" id="perm_<?php echo $u['id']; ?>_<?php echo $key; ?>"
                                                                <?php echo $checked; ?>>
                                                            <label class="form-check-label"
                                                                for="perm_<?php echo $u['id']; ?>_<?php echo $key; ?>">
                                                                <?php echo $label; ?>
                                                            </label>
                                                        </div>
                                                    <?php
                                                endforeach; ?>
                                            </div>
                                        </div>
                                    <?php
                                endforeach; ?>
                            </div>
                        </div>
                        <div class="modal-footer d-flex justify-content-between">
                            <?php if ($u['permissions_locked']): ?>
                                    <button type="button" class="btn btn-outline-warning text-dark" data-bs-toggle="modal" data-bs-target="#confirmResetModal<?php echo $u['id']; ?>">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i> Reset to Defaults
                                    </button>
                                <?php
                            else: ?>
                                    <button type="button" class="btn btn-light text-muted" disabled>
                                        <i class="bi bi-check2-circle me-1"></i> Using Defaults
                                    </button>
                                <?php
                            endif; ?>
                        
                            <div>
                                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Close</button>
                                <button type="submit" class="btn btn-primary">Save Custom Rules</button>
                            </div>
                        </div>


                    </div>
                </form>
            </div>
        </div>
    
        <!-- Edit User Modal -->
        <div class="modal fade" id="editUserModal<?php echo $u['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <form method="POST">
                    <input type="hidden" name="edit_user_id" value="<?php echo $u['id']; ?>">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit User Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Personal Details -->
                            <div class="section-header">
                                <i class="bi bi-person-badge"></i> Profile Info
                            </div>
                            <div class="row g-3 mb-4">
                                <div class="col-md-7">
                                    <div class="form-floating">
                                        <input type="text" name="edit_full_name" class="form-control" id="eName<?php echo $u['id']; ?>" 
                                               value="<?php echo htmlspecialchars($u['full_name']); ?>" required>
                                        <label for="eName<?php echo $u['id']; ?>">Full Name</label>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <div class="form-floating">
                                        <select name="edit_department" class="form-select" id="eDept<?php echo $u['id']; ?>">
                                            <option value="">Select Department...</option>
                                            <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo htmlspecialchars($dept['name']); ?>" 
                                                            <?php echo ($u['department'] == $dept['name']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                <?php
                                            endforeach; ?>
                                        </select>
                                        <label for="eDept<?php echo $u['id']; ?>">Department</label>
                                    </div>
                                </div>
                            </div>

                            <!-- Login Details -->
                            <div class="section-header">
                                <i class="bi bi-shield-lock-fill"></i> Login Credentials
                            </div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" name="edit_username" class="form-control" id="eUser<?php echo $u['id']; ?>" 
                                               value="<?php echo htmlspecialchars($u['username']); ?>" required>
                                        <label for="eUser<?php echo $u['id']; ?>">Username</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="password" name="edit_password" class="form-control" id="ePass<?php echo $u['id']; ?>" 
                                               placeholder="Leave blank to keep same">
                                        <label for="ePass<?php echo $u['id']; ?>">New Password (Optional)</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-floating">
                                        <select name="edit_role" class="form-select" id="eRole<?php echo $u['id']; ?>" required>
                                            <option value="security" <?php echo ($u['role'] == 'security') ? 'selected' : ''; ?>>Security / Reception</option>
                                            <option value="employee" <?php echo ($u['role'] == 'employee') ? 'selected' : ''; ?>>Employee</option>
                                            <option value="admin" <?php echo ($u['role'] == 'admin') ? 'selected' : ''; ?>>Administrator</option>
                                        </select>
                                        <label for="eRole<?php echo $u['id']; ?>">User Role</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <select name="edit_status" class="form-select" id="eStatus<?php echo $u['id']; ?>" required>
                                            <option value="active" <?php echo ($u['status'] == 'active') ? 'selected' : ''; ?>>Active (Enabled)</option>
                                            <option value="inactive" <?php echo ($u['status'] == 'inactive') ? 'selected' : ''; ?>>Inactive (Disabled)</option>
                                        </select>
                                        <label for="eStatus<?php echo $u['id']; ?>">Account Status</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-white border-top-0 pb-4 pe-4">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" onclick="this.disabled=true; this.form.submit(); this.innerHTML='<span class spinners-border spinner-border-sm></span> Saving...';">
                                <i class="bi bi-save2 me-2"></i>Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <!-- Confirmation Modal for Reset -->
        <div class="modal fade" id="confirmResetModal<?php echo $u['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <form method="POST">
                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                    <div class="modal-content text-center">
                        <div class="modal-body p-4">
                            <div class="mb-3 text-warning">
                                 <i class="bi bi-exclamation-circle-fill display-4"></i>
                            </div>
                            <h5 class="fw-bold mb-3">Reset Permissions?</h5>
                            <p class="text-muted small mb-4">
                                This will clear all custom overrides for <b><?php echo htmlspecialchars($u['full_name']); ?></b>. 
                                They will immediately revert to the default access rules for their role (<?php echo strtoupper($u['role']); ?>).
                            </p>
                            <div class="d-grid gap-2">
                                <button type="submit" name="reset_defaults" class="btn btn-warning fw-bold">
                                    Yes, Reset to Defaults
                                </button>
                                <button type="button" class="btn btn-light" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#permModal<?php echo $u['id']; ?>">
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    <?php
endforeach; ?>
<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<span class=\'spinner-border spinner-border-sm me-2\'></span>Creating...';">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2 text-primary"></i>New User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Personal Details -->
                    <div class="section-header">
                        <i class="bi bi-person-vcard"></i> Personal Information
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-7">
                            <div class="form-floating">
                                <input type="text" name="new_full_name" class="form-control" id="uName" placeholder="Full Name" 
                                       value="<?php echo htmlspecialchars($form_data['new_full_name'] ?? ''); ?>" required>
                                <label for="uName">Full Name</label>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="form-floating">
                                <select name="new_department" class="form-select" id="uDept">
                                    <option value="">Select Department...</option>
                                    <?php foreach ($departments as $dept): ?>
                                            <option value="<?php echo htmlspecialchars($dept['name']); ?>" 
                                                    <?php echo (isset($form_data['new_department']) && $form_data['new_department'] == $dept['name']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($dept['name']); ?>
                                            </option>
                                        <?php
                                    endforeach; ?>
                                </select>
                                <label for="uDept">Department</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="email" name="new_email" class="form-control" id="uEmail" placeholder="Email"
                                       value="<?php echo htmlspecialchars($form_data['new_email'] ?? ''); ?>">
                                <label for="uEmail">Email</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="tel" name="new_mobile" class="form-control" id="uMobile" placeholder="Mobile" 
                                       pattern="[0-9]{10}" maxlength="10" title="Please enter a 10-digit mobile number"
                                       value="<?php echo htmlspecialchars($form_data['new_mobile'] ?? ''); ?>"
                                       oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                                <label for="uMobile">Mobile (10 digits)</label>
                            </div>
                        </div>
                    </div>

                    <!-- Security Details -->
                    <div class="section-header">
                        <i class="bi bi-shield-lock"></i> Security & Access
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="text" name="new_username" class="form-control" id="uUser" placeholder="Username" 
                                       value="<?php echo htmlspecialchars($form_data['new_username'] ?? ''); ?>" required>
                                <label for="uUser">Username</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating">
                                <input type="password" name="new_password" class="form-control" id="uPass" placeholder="Password" 
                                       <?php echo !empty($error) ? '' : 'required'; ?>>
                                <label for="uPass">Password</label>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="form-floating">
                                <select name="new_role" class="form-select" id="uRole" required>
                                    <option value="" disabled <?php echo !isset($form_data['new_role']) ? 'selected' : ''; ?>>Select Access Level...</option>
                                    <option value="security" <?php echo (isset($form_data['new_role']) && $form_data['new_role'] == 'security') ? 'selected' : ''; ?>>Security / Reception</option>
                                    <option value="employee" <?php echo (isset($form_data['new_role']) && $form_data['new_role'] == 'employee') ? 'selected' : ''; ?>>Employee (Host)</option>
                                    <option value="admin" <?php echo (isset($form_data['new_role']) && $form_data['new_role'] == 'admin') ? 'selected' : ''; ?>>Administrator</option>
                                </select>
                                <label for="uRole">System Role</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-floating">
                                <select name="new_status" class="form-select" id="uStatus" required>
                                    <option value="active" selected>Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                                <label for="uStatus">Status</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top-0 pb-4 pe-4">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-2"></i>Create Account
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function checkQuota() {
    var reached = <?php echo $is_quota_reached ? 'true' : 'false'; ?>;
    if (reached) {
        AppDialog.show({
            icon: 'warning',
            title: 'User Limit Reached!',
            text: 'Your current plan only allows <?php echo $quota_limit; ?> active users (non-admins). Please deactivate existing accounts or contact support to upgrade.'
        });
        return false;
    }
    return true;
}

function handleAddUserClick() {
    if (!checkQuota()) return;
    var myModal = new bootstrap.Modal(document.getElementById('addUserModal'));
    myModal.show();
}

function toggleUserStatus(uid, status) {
    // If activating, check quota first
    if (status === 'active' && !checkQuota()) return;

    var title = status === 'active' ? 'Activate User?' : 'Deactivate User?';
    var text = status === 'active' 
        ? 'This will restore login access for the user.' 
        : 'Deactivating this user will log them out immediately and prevent further access.';
    var confirmBtn = status === 'active' ? 'Yes, Activate' : 'Yes, Deactivate';
    var icon = status === 'active' ? 'success' : 'warning';

    AppDialog.confirm({
        title: title,
        text: text,
        confirmText: confirmBtn,
        icon: icon
    }).then(function(result) {
        if (result.isConfirmed) {
            window.location.href = 'permissions.php?toggle_status=' + uid + '&status=' + status;
        }
    });
}
</script>

<?php if ($success || $error): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($success): ?>
            AppDialog.show({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo addslashes($success); ?>'
            }).then(function() {
                // Redirect to clean the URL (remove ?success=...)
                window.location.href = 'permissions.php';
            });
            <?php
        elseif ($error): ?>
            // Re-open the modal so they can fix the error
            var addModal = new bootstrap.Modal(document.getElementById('addUserModal'));
            addModal.show();
    
            AppDialog.show({
                icon: 'error',
                title: 'Error',
                text: '<?php echo addslashes($error); ?>'
            });
            <?php
        endif; ?>
    });
    </script>
    <?php
endif; ?>

<?php require_once 'footer.php'; ?>