<?php
require_once '../includes/db.php';
requireLogin();

// PHP logic starts before any output to support PRG pattern redirects

// Ensure Settings Table Exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) NOT NULL UNIQUE,
        setting_value TEXT
    )");
} catch (PDOException $e) { /* Ignore */
}

// Ensure Access Areas Table Exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS access_areas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        area_name VARCHAR(100) NOT NULL UNIQUE,
        machine_id VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Ensure access_areas has machine_id column
    $check_area_machine = $pdo->query("SHOW COLUMNS FROM access_areas LIKE 'machine_id'")->fetch();
    if (!$check_area_machine) {
        $pdo->exec("ALTER TABLE access_areas ADD COLUMN machine_id VARCHAR(100) NULL AFTER area_name");
    }

    // Ensure visits table has required columns
    $check_visit_photo = $pdo->query("SHOW COLUMNS FROM visits LIKE 'visit_photo'")->fetch();
    if (!$check_visit_photo) {
        $pdo->exec("ALTER TABLE visits ADD COLUMN visit_photo VARCHAR(255) NULL AFTER visitor_id");
    }

    $check_column = $pdo->query("SHOW COLUMNS FROM visits LIKE 'access_area'")->fetch();
    if (!$check_column) {
        $pdo->exec("ALTER TABLE visits ADD COLUMN access_area VARCHAR(100) NULL AFTER purpose");
    }
} catch (PDOException $e) { /* Ignore */
}

// Initialize System Messages from Session
$msg = $_SESSION['app_msg'] ?? '';
unset($_SESSION['app_msg']);

// Handle purpose management
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_purpose'])) {
        $purpose = sanitize($_POST['purpose_name']);

        // Prevent Duplicates
        $check = $pdo->prepare("SELECT id FROM visit_purposes WHERE purpose_name = ?");
        $check->execute([$purpose]);

        if ($check->fetch()) {
            $msg = "Error: Purpose '$purpose' already exists.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO visit_purposes (purpose_name) VALUES (?)");
            $stmt->execute([$purpose]);
            logAction($pdo, $_SESSION['user_id'], "Added visit purpose: $purpose");
            $msg = "Purpose added successfully!";
        }
    }

    if (isset($_POST['add_department'])) {
        $dept_name = sanitize($_POST['dept_name']);
        try {
            $stmt = $pdo->prepare("INSERT INTO departments (name, status) VALUES (?, 'active')");
            $stmt->execute([$dept_name]);
            logAction($pdo, $_SESSION['user_id'], "Added department: $dept_name (via settings)");
            $msg = "Department added successfully!";
        } catch (PDOException $e) {
            $msg = "Error: Department might already exist.";
        }
    }

    // Handle Email Settings Save
    if (isset($_POST['save_email'])) {
        $settings = [
            'smtp_host' => $_POST['smtp_host'],
            'smtp_port' => $_POST['smtp_port'],
            'smtp_user' => $_POST['smtp_user'],
            'smtp_pass' => $_POST['smtp_pass'], // In real app, encrypt this
            'smtp_from_email' => $_POST['smtp_from_email'],
            'smtp_from_name' => $_POST['smtp_from_name'],
            'smtp_enc' => $_POST['smtp_enc']
        ];

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $val) {
            $stmt->execute([$key, $val]);
        }
        logAction($pdo, $_SESSION['user_id'], "Updated SMTP Email Configuration");
        $msg = "Email config updated!";
    }

    // Handle Profile Update
    if (isset($_POST['save_profile'])) {
        $email = sanitize($_POST['email']);
        $mobile = sanitize($_POST['mobile']);
        $new_pass = $_POST['new_password'];

        try {
            // Fetch current to see what's changing for better logging
            $curr = $pdo->prepare("SELECT email, mobile FROM users WHERE id = ?");
            $curr->execute([$_SESSION['user_id']]);
            $oldData = $curr->fetch();

            $changes = [];
            if ($oldData['email'] !== $email)
                $changes[] = "email";
            if ($oldData['mobile'] !== $mobile)
                $changes[] = "mobile number";
            if (!empty($new_pass))
                $changes[] = "password";

            $pdo->beginTransaction();

            // 1. Update Users Table
            if (!empty($new_pass)) {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET email=?, mobile=?, password=? WHERE id=?");
                $stmt->execute([$email, $mobile, $hash, $_SESSION['user_id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET email=?, mobile=? WHERE id=?");
                $stmt->execute([$email, $mobile, $_SESSION['user_id']]);
            }

            // 2. Sync with Employees Table (if linked)
            $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $emp_id = $stmt->fetchColumn();

            if ($emp_id) {
                $stmt = $pdo->prepare("UPDATE employees SET email=?, mobile=? WHERE id=?");
                $stmt->execute([$email, $mobile, $emp_id]);
            }

            $pdo->commit();

            $msg_log = "Updated personal profile: " . (empty($changes) ? "no changes" : implode(", ", $changes));
            logAction($pdo, $_SESSION['user_id'], $msg_log);

            $msg = "Profile updated and synchronized successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $msg = "Error updating profile.";
        }
    }

    // Handle Company Settings Save
    if (isset($_POST['save_company'])) {
        $settings = [
            'company_name' => $_POST['company_name'],
            'company_address' => $_POST['company_address'] ?? '',
            'company_phone' => $_POST['company_phone'] ?? '',
            'company_email' => $_POST['company_email'] ?? '',
            'company_website' => $_POST['company_website'] ?? '',
        ];

        // Handle Tenant Profile Save (Operational Hours + Crowd Control)


        // Handle Logo Upload
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $filename = $_FILES['company_logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed)) {
                $root_dir = dirname(__DIR__); // Get VMS root
                $target_dir = $root_dir . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "company" . DIRECTORY_SEPARATOR;

                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }

                $logo_filename = "logo_" . time() . "." . $ext;
                $target_file = $target_dir . $logo_filename;

                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $target_file)) {
                    $settings['company_logo'] = "uploads/company/" . $logo_filename;
                } else {
                    $msg = "Warning: Failed to move uploaded logo to destination.";
                }
            } else {
                $msg = "Warning: Invalid logo file format. Allowed: " . implode(', ', $allowed);
            }
        } elseif (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] != 4) {
            $msg = "Error: Logo upload failed with error code " . $_FILES['company_logo']['error'];
        }

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $val) {
            $stmt->execute([$key, $val]);
        }
        logAction($pdo, $_SESSION['user_id'], "Updated Company Profile settings");
        $msg = "Company profile updated!";
    }

    // Handle Tenant Profile Save (Operational Hours + Crowd Control)
    if (isset($_POST['save_tenant'])) {
        $settings = [
            'office_start_hour' => $_POST['office_start_hour'],
            'office_end_hour' => $_POST['office_end_hour'],
            'max_capacity' => $_POST['max_capacity'] ?? 50,
            'tenant_industry' => $_POST['tenant_industry'] ?? '',
            'tenant_timezone' => $_POST['tenant_timezone'] ?? 'Asia/Kolkata',
            'tenant_company_name' => $_POST['tenant_company_name'] ?? '',
            'tenant_address' => $_POST['tenant_address'] ?? '',
            'tenant_contact_person' => $_POST['tenant_contact_person'] ?? '',
            'tenant_mobile' => $_POST['tenant_mobile'] ?? '',
            'tenant_email' => $_POST['tenant_email'] ?? '',
            'tenant_gst' => $_POST['tenant_gst'] ?? '',
        ];
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $val) {
            $stmt->execute([$key, $val]);
        }
        logAction($pdo, $_SESSION['user_id'], "Updated Tenant Profile settings");
        $msg = "Tenant profile updated!";
    }

    if (isset($_POST['add_access_area'])) {
        $area = sanitize($_POST['area_name']);
        $machine_id = sanitize($_POST['machine_id'] ?? '');

        try {
            $stmt = $pdo->prepare("INSERT INTO access_areas (area_name, machine_id) VALUES (?, ?)");
            $stmt->execute([$area, $machine_id]);
            logAction($pdo, $_SESSION['user_id'], "Added access area: $area (Machine ID: $machine_id)");
            $msg = "Access area added successfully!";
        } catch (PDOException $e) {
            $msg = "Error: Access area might already exist.";
        }
    }

    // Handle Dahua Settings Save
    if (isset($_POST['save_dahua'])) {
        $settings = [
            'dahua_app_id' => $_POST['dahua_app_id'],
            'dahua_app_secret' => $_POST['dahua_app_secret'],
            'dahua_device_sns' => $_POST['dahua_device_sns']
        ];

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $val) {
            $stmt->execute([$key, $val]);
        }
        logAction($pdo, $_SESSION['user_id'], "Updated Dahua API Configuration");
        $msg = "Dahua API configuration saved!";
    }

    // Handle WhatsApp Settings Save
    if (isset($_POST['save_whatsapp'])) {
        $enabled_processes = '[]';
        if (isset($_POST['whatsapp_process']) && is_array($_POST['whatsapp_process'])) {
            $enabled_processes = json_encode($_POST['whatsapp_process']);
        }

        $settings = [
            'whatsapp_access_token' => $_POST['whatsapp_access_token'],
            'whatsapp_phone_number_id' => $_POST['whatsapp_phone_number_id'],
            'whatsapp_waba_id' => $_POST['whatsapp_waba_id'],
            'whatsapp_app_id' => $_POST['whatsapp_app_id'],
            'whatsapp_template_language' => $_POST['whatsapp_template_language'] ?? 'en',
            'whatsapp_enabled_processes' => $enabled_processes
        ];

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $val) {
            $stmt->execute([$key, $val]);
        }
        logAction($pdo, $_SESSION['user_id'], "Updated WhatsApp Cloud API Configuration");
        $msg = "WhatsApp Cloud API configuration saved!";
    }

    // Handle AI Settings Save
    if (isset($_POST['save_ai'])) {
        $settings = [
            'ai_api_key' => trim($_POST['ai_api_key']),
            'ai_model' => trim($_POST['ai_model'] ?? 'gemini-1.5-flash')
        ];

        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($settings as $key => $val) {
            $stmt->execute([$key, $val]);
        }
        logAction($pdo, $_SESSION['user_id'], "Updated AI Intelligence Configuration");
        $msg = "AI Configuration saved successfully!";
    }

    if (isset($msg) && $msg != '') {
        $_SESSION['app_msg'] = $msg;
        header("Location: settings.php");
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    // Check Dependency
    $name = $pdo->query("SELECT purpose_name FROM visit_purposes WHERE id=" . intval($id))->fetchColumn();
    $in_use = $pdo->prepare("SELECT count(*) FROM visits WHERE purpose=?");
    $in_use->execute([$name]);

    if ($in_use->fetchColumn() > 0) {
        $_SESSION['app_msg'] = "Error: Cannot delete purpose '$name' as it is currently linked to existing visit records.";
        header("Location: settings.php");
        exit;
    } else {
        $stmt = $pdo->prepare("DELETE FROM visit_purposes WHERE id=?");
        $_SESSION['app_msg'] = "Purpose deleted successfully.";
        header("Location: settings.php");
        exit;
    }
}

if (isset($_GET['delete_area'])) {
    $id = $_GET['delete_area'];
    // Check Dependency
    $name = $pdo->query("SELECT area_name FROM access_areas WHERE id=" . intval($id))->fetchColumn();
    $in_use = $pdo->prepare("SELECT count(*) FROM visits WHERE access_area=?");
    $in_use->execute([$name]);

    if ($in_use->fetchColumn() > 0) {
        $_SESSION['app_msg'] = "Error: Cannot delete access area '$name' as it is assigned to one or more visitors.";
        header("Location: settings.php");
        exit;
    } else {
        $stmt = $pdo->prepare("DELETE FROM access_areas WHERE id=?");
        $stmt->execute([$id]);
        $_SESSION['app_msg'] = "Access area removed.";
        header("Location: settings.php");
        exit;
    }
}

if (isset($_GET['delete_dept'])):
    $id = $_GET['delete_dept'];
    // Check Dependency
    $name = $pdo->query("SELECT name FROM departments WHERE id=" . intval($id))->fetchColumn();
    $in_use = $pdo->prepare("SELECT count(*) FROM employees WHERE department=?");
    $in_use->execute([$name]);

    if ($in_use->fetchColumn() > 0):
        $_SESSION['app_msg'] = "Error: Cannot delete '$name' department as it has active employees assigned to it.";
    else:
        try {
            $stmt = $pdo->prepare("DELETE FROM departments WHERE id=?");
            $stmt->execute([$id]);
            $_SESSION['app_msg'] = "Department deleted.";
        } catch (PDOException $e) {
            $_SESSION['app_msg'] = "Error: Cannot delete department in use.";
        }
    endif;
    header("Location: settings.php");
    exit;
endif;

if (isset($_GET['toggle_dept'])) {
    $id = $_GET['toggle_dept'];
    $current = $pdo->query("SELECT status FROM departments WHERE id=$id")->fetchColumn();
    $new = ($current == 'active') ? 'inactive' : 'active';
    $pdo->prepare("UPDATE departments SET status=? WHERE id=?")->execute([$new, $id]);
    logAction($pdo, $_SESSION['user_id'], "Toggled department ID: $id status to $new");
    $_SESSION['app_msg'] = "Department status changed to " . strtoupper($new);
    header("Location: settings.php");
    exit;
}

$purposes = $pdo->query("SELECT * FROM visit_purposes ORDER BY purpose_name")->fetchAll();
$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$access_areas = $pdo->query("SELECT * FROM access_areas ORDER BY area_name")->fetchAll();

// Fetch Current User
$my_profile = $pdo->prepare("SELECT u.*, e.email as emp_email, e.mobile as emp_mobile 
                             FROM users u 
                             LEFT JOIN employees e ON u.employee_id = e.id 
                             WHERE u.id = ?");
$my_profile->execute([$_SESSION['user_id']]);
$u = $my_profile->fetch();

// If blank in users table, use employee table values as fallback
if (empty($u['email']) && !empty($u['emp_email']))
    $u['email'] = $u['emp_email'];
if (empty($u['mobile']) && !empty($u['emp_mobile']))
    $u['mobile'] = $u['emp_mobile'];

// Fetch Current Settings
$raw_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$email_defaults = [
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_from_email' => '',
    'smtp_from_name' => 'VMS Admin',
    'smtp_enc' => 'tls',
    'company_name' => 'My Enterprise',
    'company_address' => '',
    'company_phone' => '',
    'company_email' => '',
    'company_website' => '',
    'company_logo' => '',
    'office_start_hour' => '8',
    'office_end_hour' => '18',
    'max_capacity' => '50',
    'tenant_industry' => '',
    'tenant_timezone' => 'Asia/Kolkata',
    'tenant_company_name' => '',
    'tenant_address' => '',
    'tenant_contact_person' => '',
    'tenant_mobile' => '',
    'tenant_email' => '',
    'tenant_gst' => '',
    'dahua_app_id' => '',
    'dahua_app_secret' => '',
    'dahua_device_sns' => '',
    'whatsapp_access_token' => '',
    'whatsapp_phone_number_id' => '',
    'whatsapp_waba_id' => '',
    'whatsapp_app_id' => '',
    'whatsapp_template_language' => 'en',
    'whatsapp_enabled_processes' => '["visitor_arrival_host_alert","visitor_otp_verification","visit_approval_visitor_notify","visit_rejection_visitor_notify","visitor_meet_notify","invite_cancelled"]',
    'ai_api_key' => '',
    'ai_model' => 'gemini-1.5-flash'
];
$config = array_merge($email_defaults, $raw_settings);

require_once 'header.php';
?>
<style>
    .transition-all {
        transition: all 0.2s ease-in-out;
    }

    .hover-shadow:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1) !important;
        border-color: var(--bs-primary) !important;
    }
</style>

<div class="row mb-3">
    <div class="col">
        <h3>System Settings</h3>
    </div>
</div>

<?php // Dynamic Notification Modal Triggered Below ?>

<?php
$tabs = [
    'settings_profile' => ['id' => 'profile', 'title' => 'My Profile', 'icon' => 'bi-person-circle'],
    'super_admin_company' => ['id' => 'company', 'title' => 'Company Profile', 'icon' => 'bi-briefcase'],
    'settings_tenant' => ['id' => 'tenant', 'title' => 'Tenant Profile', 'icon' => 'bi-building-fill-gear'],
    'settings_general' => ['id' => 'general', 'title' => 'General / Purposes', 'icon' => 'bi-list-check'],
    'settings_departments' => ['id' => 'departments', 'title' => 'Departments', 'icon' => 'bi-building'],
    'settings_access' => ['id' => 'access_areas', 'title' => 'Access Area', 'icon' => 'bi-geo-alt'],
    'settings_email' => ['id' => 'email', 'title' => 'Email Config', 'icon' => 'bi-envelope-gear'],
    'settings_dahua' => ['id' => 'dahua', 'title' => 'Dahua Integration', 'icon' => 'bi-shield-lock'],
    'settings_whatsapp' => ['id' => 'whatsapp', 'title' => 'WhatsApp Config', 'icon' => 'bi-whatsapp'],
    'settings_ai' => ['id' => 'ai', 'title' => 'AI Integration', 'icon' => 'bi-robot'],
    'admin_audit' => ['id' => 'info', 'title' => 'System Info', 'icon' => 'bi-info-circle'],
];

$active_tab_id = false;
?>
<ul class="nav nav-tabs mb-4" id="settingsTabs" role="tablist">
    <?php foreach ($tabs as $perm => $t):
        if (strpos($perm, 'super_admin_') === 0) {
            $allowed = !empty($_SESSION['is_super']);
        } else {
            $allowed = canView($perm);
        }

        if ($allowed):
            $is_active = !$active_tab_id;
            if ($is_active)
                $active_tab_id = $t['id'];
            ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $is_active ? 'active' : ''; ?>" id="<?php echo $t['id']; ?>-tab"
                    data-bs-toggle="tab" data-bs-target="#<?php echo $t['id']; ?>" type="button" role="tab">
                    <i class="bi <?php echo $t['icon']; ?> me-2"></i><?php echo $t['title']; ?>
                </button>
            </li>
            <?php
        endif;
    endforeach; ?>
</ul>

<div class="tab-content" id="settingsTabContent">
    <!-- Tab: My Profile -->
    <?php if (canView('settings_profile')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'profile') ? 'show active' : ''; ?>" id="profile"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div class="card-header bg-primary text-white py-3 border-0 rounded-top-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-person-circle me-2"></i>Personal Credentials</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST">
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Full Identity</label>
                                    <div class="p-3 rounded-4 bg-light border d-flex align-items-center">
                                        <div class="rounded-circle bg-white p-2 me-3">
                                            <i class="bi bi-person-vcard fs-4 text-primary"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($u['full_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($u['department']); ?>
                                                Department</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Email
                                            Address</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-envelope-at text-primary"></i></span>
                                            <input type="email" name="email"
                                                class="form-control border-0 bg-light rounded-end"
                                                value="<?php echo htmlspecialchars($u['email']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Mobile
                                            Number</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-phone text-primary"></i></span>
                                            <input type="text" name="mobile"
                                                class="form-control border-0 bg-light rounded-end"
                                                value="<?php echo htmlspecialchars($u['mobile']); ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div
                                    class="mb-4 p-4 rounded-4 bg-warning bg-opacity-10 border border-warning border-opacity-25">
                                    <label class="form-label fw-bold text-dark small text-uppercase mb-3"><i
                                            class="bi bi-shield-lock me-2"></i>Security Update</label>
                                    <div class="input-group mb-2">
                                        <span class="input-group-text bg-white border-0"><i
                                                class="bi bi-key text-warning"></i></span>
                                        <input type="password" name="new_password"
                                            class="form-control border-0 bg-white rounded-end"
                                            placeholder="Set new password">
                                    </div>
                                    <small class="text-muted px-1">Leave blank if you don't want to change your
                                        password.</small>
                                </div>

                                <button type="submit" name="save_profile"
                                    class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow-sm">
                                    <i class="bi bi-check2-all me-2"></i> Update Security Profile
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div
                        class="card shadow-sm rounded-4 border-0 bg-primary bg-gradient text-white h-100 p-5 text-center d-flex flex-column justify-content-center overflow-hidden pos-relative">
                        <i class="bi bi-shield-check display-1 opacity-25 mb-4"></i>
                        <h2 class="fw-bold mb-3">Hi, <?php echo explode(' ', $_SESSION['full_name'])[0]; ?>!</h2>
                        <p class="op-75 mb-4 px-4">Keep your profile information up to date to ensure you receive guest
                            arrival alerts and system notifications without delay.</p>
                        <hr class="w-25 mx-auto opacity-25">
                        <div class="mt-4">
                            <div class="small opacity-75 text-uppercase ls-1">Account Role</div>
                            <div class="badge bg-white text-primary rounded-pill px-4 py-2 mt-2 fw-bold">
                                <?php echo strtoupper($_SESSION['role']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab: Company Profile (SUPER ADMIN ONLY) -->
    <?php if (!empty($_SESSION['is_super'])): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'company') ? 'show active' : ''; ?>" id="company"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div class="card-header bg-dark text-white py-3 border-0 rounded-top-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-briefcase me-2"></i>Master Company Identity</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-5 text-center">
                                    <div
                                        class="position-relative d-inline-block p-4 rounded-circle bg-light border shadow-inner">
                                        <img src="../<?php echo !empty($config['company_logo']) ? $config['company_logo'] : 'assets/img/visitor-icon.png'; ?>"
                                            id="logo-preview" class="rounded-4 shadow-sm"
                                            style="width: 140px; height: 140px; object-fit: contain; background: white;"
                                            onerror="this.src='../assets/img/visitor-icon.png'">
                                        <label for="company_logo"
                                            class="btn btn-dark btn-sm position-absolute bottom-0 start-50 translate-middle-x rounded-pill shadow-lg px-3 mb-n1">
                                            <i class="bi bi-camera-fill me-1"></i> UPLOAD LOGO
                                        </label>
                                        <input type="file" name="company_logo" id="company_logo" class="d-none"
                                            accept="image/*"
                                            onchange="document.getElementById('logo-preview').src = window.URL.createObjectURL(this.files[0])">
                                    </div>
                                    <p class="text-muted small mt-3">Professional enterprise branding for reports and
                                        notices.</p>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Legal Business
                                            Name</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0 px-3"><i
                                                    class="bi bi-building fs-5 text-dark"></i></span>
                                            <input type="text" name="company_name"
                                                class="form-control border-0 bg-light fw-bold py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['company_name']); ?>" required>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Corporate
                                            Contact</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0 px-3"><i
                                                    class="bi bi-telephone text-dark"></i></span>
                                            <input type="text" name="company_phone"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['company_phone']); ?>"
                                                placeholder="+91...">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Administrative
                                            Email</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0 px-3"><i
                                                    class="bi bi-envelope-at text-dark"></i></span>
                                            <input type="email" name="company_email"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['company_email']); ?>"
                                                placeholder="admin@company.com">
                                        </div>
                                    </div>

                                    <div class="col-md-12">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Office Presence
                                            (Website)</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0 px-3"><i
                                                    class="bi bi-globe text-dark"></i></span>
                                            <input type="url" name="company_website"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['company_website']); ?>"
                                                placeholder="https://...">
                                        </div>
                                    </div>

                                    <div class="col-md-12">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Primary
                                            Headquarters Address</label>
                                        <textarea name="company_address"
                                            class="form-control border-0 bg-light rounded-4 px-3 py-3" rows="3"
                                            placeholder="Full office address..."><?php echo htmlspecialchars($config['company_address']); ?></textarea>
                                    </div>
                                </div>

                                <div class="mt-5">
                                    <button type="submit" name="save_company"
                                        class="btn btn-dark fw-bold w-100 py-3 rounded-pill shadow">
                                        <i class="bi bi-shield-check me-2"></i> Finalize Company Identity
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div
                        class="card shadow-sm rounded-4 border-0 h-100 bg-white p-5 text-center d-flex flex-column justify-content-center">
                        <div class="rounded-circle bg-dark bg-opacity-10 p-4 mx-auto mb-4 text-dark">
                            <i class="bi bi-info-circle-fill display-4"></i>
                        </div>
                        <h4 class="fw-bold mb-3">Master Suite Overview</h4>
                        <p class="text-muted px-3">These core settings define how your enterprise is perceived by visitors
                            and employees alike. Your logo is automatically integrated into:</p>

                        <div class="row text-start g-3 mt-3">
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-4 border border-opacity-10 d-flex align-items-center">
                                    <i class="bi bi-qr-code me-2 text-dark"></i> <span class="small fw-bold">Visitor
                                        Passes</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-4 border border-opacity-10 d-flex align-items-center">
                                    <i class="bi bi-envelope me-2 text-dark"></i> <span class="small fw-bold">Email
                                        Alerts</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-4 border border-opacity-10 d-flex align-items-center">
                                    <i class="bi bi-card-image me-2 text-dark"></i> <span class="small fw-bold">UI
                                        Branding</span>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 bg-light rounded-4 border border-opacity-10 d-flex align-items-center">
                                    <i class="bi bi-file-pdf me-2 text-dark"></i> <span class="small fw-bold">PDF
                                        Reports</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 border-top pt-4">
                            <div class="small fw-bold text-dark text-uppercase mb-2 opacity-50">Image Compliance</div>
                            <div class="d-flex justify-content-center gap-2">
                                <span class="badge bg-light border text-dark rounded-pill">JPG</span>
                                <span class="badge bg-light border text-dark rounded-pill">PNG</span>
                                <span class="badge bg-light border text-dark rounded-pill">WEBP</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab: Tenant Profile -->
    <?php if (canView('settings_tenant')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'tenant') ? 'show active' : ''; ?>" id="tenant"
            role="tabpanel">
            <form method="POST">
                <div class="row g-4">
                    <!-- Tenant Profile -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm rounded-4 border-0 h-100 hover-shadow transition-all">
                            <div class="card-header bg-primary text-white py-3 border-0 rounded-top-4">
                                <h5 class="mb-0 fw-bold"><i class="bi bi-building me-2"></i>Tenant Company Profile</h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="text-muted small mb-4">Define your primary office identity for guest registrations
                                    and automated alerts.</p>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Legal Business
                                            Name</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-briefcase text-primary"></i></span>
                                            <input type="text" name="tenant_company_name"
                                                class="form-control border-0 bg-light py-2 rounded-end fw-bold"
                                                value="<?php echo htmlspecialchars($config['tenant_company_name']); ?>"
                                                placeholder="Enter company name">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Primary
                                            Liaison</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-person text-primary"></i></span>
                                            <input type="text" name="tenant_contact_person"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['tenant_contact_person']); ?>"
                                                placeholder="Contact person">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Direct
                                            Mobile</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-phone text-primary"></i></span>
                                            <input type="text" name="tenant_mobile"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['tenant_mobile']); ?>"
                                                placeholder="Phone number">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Office
                                            Email</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-envelope text-primary"></i></span>
                                            <input type="email" name="tenant_email"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['tenant_email']); ?>"
                                                placeholder="office@company.com">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Tax
                                            Identification</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-receipt text-primary"></i></span>
                                            <input type="text" name="tenant_gst"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['tenant_gst']); ?>"
                                                placeholder="GST/Tax ID">
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Registered Office
                                            Address</label>
                                        <textarea name="tenant_address"
                                            class="form-control border-0 bg-light rounded-4 px-3" rows="2"
                                            placeholder="Full address..."><?php echo htmlspecialchars($config['tenant_address']); ?></textarea>
                                    </div>
                                </div>

                                <div class="p-3 rounded-4 bg-primary bg-opacity-10 border border-primary border-opacity-10">
                                    <h6 class="fw-bold small text-uppercase text-primary mb-3"><i
                                            class="bi bi-clock-history me-2"></i>Operational Window</h6>
                                    <div class="row g-3">
                                        <div class="col-6">
                                            <label class="form-label small text-muted">Opens At</label>
                                            <select name="office_start_hour" class="form-select border-0 shadow-sm">
                                                <?php for ($h = 0; $h < 24; $h++): ?>
                                                    <option value="<?php echo $h; ?>" <?php echo $config['office_start_hour'] == $h ? 'selected' : ''; ?>>
                                                        <?php echo date('h:i A', strtotime("$h:00")); ?>
                                                    </option>
                                                    <?php
                                                endfor; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small text-muted">Closes At</label>
                                            <select name="office_end_hour" class="form-select border-0 shadow-sm">
                                                <?php for ($h = 0; $h < 24; $h++): ?>
                                                    <option value="<?php echo $h; ?>" <?php echo $config['office_end_hour'] == $h ? 'selected' : ''; ?>>
                                                        <?php echo date('h:i A', strtotime("$h:00")); ?>
                                                    </option>
                                                    <?php
                                                endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Environment & Capacity -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm rounded-4 border-0 h-100 d-flex flex-column hover-shadow transition-all">
                            <div class="card-header bg-danger text-white py-3 border-0 rounded-top-4">
                                <h5 class="mb-0 fw-bold"><i class="bi bi-people me-2"></i>Crowd & Localization</h5>
                            </div>
                            <div class="card-body p-4 flex-grow-1 d-flex flex-column">

                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Core
                                            Industry</label>
                                        <select name="tenant_industry"
                                            class="form-select border-0 bg-light py-2 shadow-inner">
                                            <?php
                                            $industries = ['IT / Software', 'Manufacturing', 'Healthcare', 'Finance / Banking', 'Education', 'Retail', 'Government', 'Real Estate', 'Logistics', 'Other'];
                                            foreach ($industries as $ind): ?>
                                                <option value="<?php echo $ind; ?>" <?php echo $config['tenant_industry'] == $ind ? 'selected' : ''; ?>><?php echo $ind; ?></option>
                                                <?php
                                            endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Base
                                            Timezone</label>
                                        <select name="tenant_timezone"
                                            class="form-select border-0 bg-light py-2 shadow-inner">
                                            <?php
                                            $zones = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Asia/Tokyo', 'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Los_Angeles', 'Australia/Sydney'];
                                            foreach ($zones as $tz): ?>
                                                <option value="<?php echo $tz; ?>" <?php echo $config['tenant_timezone'] == $tz ? 'selected' : ''; ?>><?php echo $tz; ?></option>
                                                <?php
                                            endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <hr class="my-4 opacity-10">

                                <h6 class="fw-bold small text-uppercase text-danger mb-4"><i
                                        class="bi bi-shield-exclamation me-2"></i>Congestion Management</h6>
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-muted">Max Simultaneous Building
                                        Capacity</label>
                                    <div class="input-group input-group-lg">
                                        <span class="input-group-text bg-light border-0"><i
                                                class="bi bi-person-workspace text-danger"></i></span>
                                        <input type="number" name="max_capacity"
                                            class="form-control border-0 bg-light fw-bold"
                                            value="<?php echo htmlspecialchars($config['max_capacity']); ?>" min="1"
                                            placeholder="e.g. 50">
                                        <span class="input-group-text bg-light border-0 text-muted small">VISITORS</span>
                                    </div>
                                </div>

                                <?php
                                $current_in = $pdo->query("SELECT COUNT(*) FROM visits WHERE status='checked_in' AND DATE(check_in_time) = CURDATE()")->fetchColumn();
                                $capacity = intval($config['max_capacity']) ?: 50;
                                $pct = min(100, round(($current_in / $capacity) * 100));
                                $bar_color = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning text-dark' : 'bg-success');
                                ?>
                                <div class="p-4 rounded-4 bg-light shadow-inner mb-4">
                                    <div class="d-flex justify-content-between small fw-bold mb-2">
                                        <span class="text-muted text-uppercase">Current Occupancy</span>
                                        <span class="text-dark"><?php echo $current_in; ?> / <?php echo $capacity; ?></span>
                                    </div>
                                    <div class="progress mb-2"
                                        style="height: 14px; border-radius: 20px; background: #e9ecef;">
                                        <div class="progress-bar <?php echo $bar_color; ?> progress-bar-striped progress-bar-animated"
                                            style="width: <?php echo $pct; ?>%;"></div>
                                    </div>
                                    <div class="text-end small">
                                        <span class="badge <?php echo $bar_color; ?> rounded-pill px-3"><?php echo $pct; ?>%
                                            capacity used</span>
                                    </div>
                                </div>

                                <div class="mt-auto pt-4">
                                    <button type="submit" name="save_tenant"
                                        class="btn btn-primary fw-bold w-100 py-3 rounded-pill shadow-sm transition-all">
                                        <i class="bi bi-cloud-upload-fill me-2"></i> Save & Broadcast Tenant Profile
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    endif; ?>

    <!-- Tab 1: General (Visit Purposes) -->
    <?php if (canView('settings_general')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'general') ? 'show active' : ''; ?>" id="general"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div
                            class="card-header bg-primary text-white py-3 border-0 rounded-top-4 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-card-checklist me-2"></i>Visit Purposes</h5>
                            <span class="badge bg-white text-primary rounded-pill"><?php echo count($purposes); ?>
                                Active</span>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" class="mb-4">
                                <div class="input-group p-1 bg-light rounded-pill border">
                                    <input type="text" name="purpose_name" class="form-control border-0 bg-transparent px-4"
                                        placeholder="Type new purpose..." required>
                                    <button type="submit" name="add_purpose"
                                        class="btn btn-primary rounded-pill px-4 shadow-sm">
                                        <i class="bi bi-plus-lg me-1"></i> Add
                                    </button>
                                </div>
                            </form>
                            <div class="list-group list-group-flush rounded-4 overflow-hidden border">
                                <?php foreach ($purposes as $p): ?>
                                    <div
                                        class="list-group-item d-flex justify-content-between align-items-center py-3 px-4 hover-bg-light transition-all">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-primary bg-opacity-10 p-2 me-3">
                                                <i class="bi bi-tag-fill text-primary"></i>
                                            </div>
                                            <span
                                                class="fw-semibold text-dark"><?php echo htmlspecialchars($p['purpose_name']); ?></span>
                                        </div>
                                        <a href="?delete=<?php echo $p['id']; ?>" class="btn btn-link text-danger p-0"
                                            onclick="return confirmAction(event, 'Remove this visit purpose?')">
                                            <i class="bi bi-trash3 fs-5"></i>
                                        </a>
                                    </div>
                                    <?php
                                endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div
                        class="card border-0 rounded-4 bg-primary bg-opacity-10 h-100 p-5 d-flex flex-column justify-content-center">
                        <i class="bi bi-journal-plus display-2 text-primary opacity-25 mb-4"></i>
                        <h4 class="fw-bold">Categorization</h4>
                        <p class="text-muted">Defining clear visit purposes helps in generating accurate analytic reports
                            and provides better context for the security team during guest check-ins.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab 2: Departments -->
    <?php if (canView('settings_departments')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'departments') ? 'show active' : ''; ?>" id="departments"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div class="card-header bg-info text-white py-3 border-0 rounded-top-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2"></i>Department Master List</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" class="mb-4">
                                <div class="row g-2">
                                    <div class="col">
                                        <div class="input-group p-1 bg-light rounded-pill border">
                                            <span class="input-group-text bg-transparent border-0 ps-3 text-info"><i
                                                    class="bi bi-plus-circle-dotted fs-5"></i></span>
                                            <input type="text" name="dept_name" class="form-control border-0 bg-transparent"
                                                placeholder="Define new department name..." required>
                                            <button type="submit" name="add_department"
                                                class="btn btn-info text-white rounded-pill px-4 shadow-sm">
                                                <i class="bi bi-save me-1"></i> Register Department
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </form>

                            <div class="table-responsive rounded-4 border overflow-hidden">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4 py-3 border-0 small text-uppercase fw-bold text-muted">
                                                Department Name</th>
                                            <th class="py-3 border-0 small text-uppercase fw-bold text-muted text-center">
                                                Current Status</th>
                                            <th class="pe-4 py-3 border-0 small text-uppercase fw-bold text-muted text-end">
                                                Control</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (count($departments) == 0): ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-5 text-muted">
                                                    <i class="bi bi-folder-x display-4 opacity-25 d-block mb-3"></i>
                                                    No departments have been defined yet.
                                                </td>
                                            </tr>
                                            <?php
                                        endif; ?>
                                        <?php foreach ($departments as $d): ?>
                                            <tr class="transition-all hover-bg-light">
                                                <td class="ps-4 py-3">
                                                    <div class="d-flex align-items-center">
                                                        <div class="rounded-circle bg-info bg-opacity-10 p-2 me-3 text-info">
                                                            <i class="bi bi-building-fill"></i>
                                                        </div>
                                                        <span
                                                            class="fw-bold text-dark fs-6"><?php echo htmlspecialchars($d['name']); ?></span>
                                                    </div>
                                                </td>
                                                <td class="py-3 text-center">
                                                    <span
                                                        class="badge rounded-pill px-3 py-2 <?php echo ($d['status'] == 'active') ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-muted'; ?> border">
                                                        <i class="bi bi-circle-fill me-1 small"></i>
                                                        <?php echo strtoupper($d['status']); ?>
                                                    </span>
                                                </td>
                                                <td class="pe-4 py-3 text-end">
                                                    <div
                                                        class="btn-group btn-group-sm rounded-pill overflow-hidden border shadow-sm">
                                                        <a href="?toggle_dept=<?php echo $d['id']; ?>"
                                                            class="btn <?php echo ($d['status'] == 'active') ? 'btn-light border-end text-warning' : 'btn-light border-end text-success'; ?>"
                                                            title="<?php echo ($d['status'] == 'active') ? 'Deactivate' : 'Activate'; ?>">
                                                            <i
                                                                class="bi <?php echo ($d['status'] == 'active') ? 'bi-pause-circle' : 'bi-play-circle'; ?>"></i>
                                                        </a>
                                                        <a href="?delete_dept=<?php echo $d['id']; ?>"
                                                            class="btn btn-light text-danger"
                                                            onclick="return confirmAction(event, 'Permanently Remove Department?')"
                                                            title="Delete">
                                                            <i class="bi bi-trash3"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php
                                        endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab 3: Email Config -->
    <?php if (canView('settings_email')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'email') ? 'show active' : ''; ?>" id="email"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div
                            class="card-header bg-secondary text-white py-3 border-0 rounded-top-4 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-envelope-paper me-2"></i>Mail Server (SMTP)</h5>
                            <div class="btn-group shadow-sm">
                                <button type="button" class="btn btn-sm btn-light fw-bold"
                                    onclick="fillGmail()">Gmail</button>
                                <button type="button" class="btn btn-sm btn-light fw-bold"
                                    onclick="fillOutlook()">Outlook</button>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST">
                                <div class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Outgoing SMTP
                                            Host</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-hdd-network text-secondary"></i></span>
                                            <input type="text" name="smtp_host" id="smtp_host"
                                                class="form-control border-0 bg-light py-2 rounded-end"
                                                value="<?php echo htmlspecialchars($config['smtp_host']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Port</label>
                                        <input type="text" name="smtp_port" id="smtp_port"
                                            class="form-control border-0 bg-light py-2"
                                            value="<?php echo htmlspecialchars($config['smtp_port']); ?>" required>
                                    </div>
                                </div>

                                <div class="row g-3 mb-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Encryption
                                            Protocol</label>
                                        <select name="smtp_enc" id="smtp_enc" class="form-select border-0 bg-light py-2">
                                            <option value="tls" <?php echo $config['smtp_enc'] == 'tls' ? 'selected' : ''; ?>>
                                                STRATTLS (Recommended)</option>
                                            <option value="ssl" <?php echo $config['smtp_enc'] == 'ssl' ? 'selected' : ''; ?>>
                                                SSL/TLS</option>
                                            <option value="none" <?php echo $config['smtp_enc'] == 'none' ? 'selected' : ''; ?>>None (Insecure)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Authentication
                                            User</label>
                                        <input type="email" name="smtp_user" class="form-control border-0 bg-light py-2"
                                            value="<?php echo htmlspecialchars($config['smtp_user']); ?>"
                                            placeholder="email@domain.com" required>
                                    </div>
                                </div>

                                <div class="mb-4 p-4 rounded-4 bg-light shadow-inner">
                                    <label class="form-label fw-bold small text-uppercase text-muted d-block mb-3">Secure
                                        App Password</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-0"><i
                                                class="bi bi-shield-lock text-secondary"></i></span>
                                        <input type="password" name="smtp_pass"
                                            class="form-control border-0 bg-white rounded-end"
                                            value="<?php echo htmlspecialchars($config['smtp_pass']); ?>"
                                            placeholder="Enter app password" required>
                                    </div>
                                    <small class="text-info mt-2 d-block"><i class="bi bi-info-circle me-1"></i> For Gmail,
                                        use "App Passwords" from your Google Security console.</small>
                                </div>

                                <div class="row g-3 mb-5">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Visible Sender
                                            Email</label>
                                        <input type="email" name="smtp_from_email" id="smtp_from_email"
                                            class="form-control border-0 bg-light py-2"
                                            value="<?php echo htmlspecialchars($config['smtp_from_email']); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Visible Sender
                                            Name</label>
                                        <input type="text" name="smtp_from_name" class="form-control border-0 bg-light py-2"
                                            value="<?php echo htmlspecialchars($config['smtp_from_name']); ?>" required>
                                    </div>
                                </div>

                                <button type="submit" name="save_email"
                                    class="btn btn-secondary fw-bold w-100 py-3 rounded-pill shadow">
                                    <i class="bi bi-send-check me-2"></i> Save SMTP Credentials
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div
                        class="card border-0 rounded-4 bg-dark bg-gradient text-white p-5 h-100 d-flex flex-column justify-content-center overflow-hidden pos-relative">
                        <i class="bi bi-envelope-heart display-1 opacity-25 mb-4"></i>
                        <h2 class="fw-bold mb-3">Communication Hub</h2>
                        <p class="op-75 mb-4">This server manages all outgoing communications including guest arrival
                            notifications, OTP verifications, and system alerts to hosts.</p>
                        <div class="alert bg-white bg-opacity-10 border-0 text-white small">
                            <i class="bi bi-lightbulb me-2"></i><strong>Pro Tip:</strong> Ensure your firewall allows
                            outbound connections on the specified SMTP port.
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab: Dahua (SUPER ADMIN ONLY) -->
    <?php if (canView('settings_dahua')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'dahua') ? 'show active' : ''; ?>" id="dahua"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div class="card-header bg-dark text-white py-3 border-0 rounded-top-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2"></i>Dahua Cloud Integration</h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="p-3 mb-4 rounded-4 bg-light border-start border-4 border-primary">
                                <p class="text-muted small mb-0">Synchronize visitor biometrics and QR access tokens
                                    directly to your Dahua DoLynk hardware endpoints via secure API.</p>
                            </div>
                            <form method="POST">
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Cloud App Identifier
                                        (Client ID)</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0"><i
                                                class="bi bi-fingerprint text-dark"></i></span>
                                        <input type="text" name="dahua_app_id"
                                            class="form-control border-0 bg-light py-2 rounded-end"
                                            value="<?php echo htmlspecialchars($config['dahua_app_id']); ?>"
                                            placeholder="Open Platform App ID">
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">App Secret Key</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0"><i
                                                class="bi bi-key text-dark"></i></span>
                                        <input type="password" name="dahua_app_secret"
                                            class="form-control border-0 bg-light py-2 rounded-end"
                                            value="<?php echo htmlspecialchars($config['dahua_app_secret']); ?>"
                                            placeholder="Enter App Secret">
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Device Serial Numbers
                                        (SN)</label>
                                    <textarea name="dahua_device_sns"
                                        class="form-control border-0 bg-light rounded-4 px-3 py-3" rows="3"
                                        placeholder="Enter comma-separated Serial Numbers..."><?php echo htmlspecialchars($config['dahua_device_sns']); ?></textarea>
                                    <small class="text-info mt-2 d-block"><i class="bi bi-info-circle me-1"></i> Data will
                                        be synced only to the devices listed above.</small>
                                </div>
                                <div class="d-grid gap-2">
                                    <button type="submit" name="save_dahua"
                                        class="btn btn-dark fw-bold py-3 rounded-pill shadow-sm">
                                        <i class="bi bi-hdd-network me-2"></i> Save Interface Configuration
                                    </button>
                                    <a href="../test_dahua.php" target="_blank"
                                        class="btn btn-outline-primary fw-bold py-3 rounded-pill">
                                        <i class="bi bi-cpu-fill me-2"></i> Launch Logic Diagnostic
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div
                        class="card border-0 rounded-4 bg-info bg-opacity-10 p-5 h-100 d-flex flex-column justify-content-center">
                        <i class="bi bi-hdd-rack display-2 text-info opacity-25 mb-4"></i>
                        <h4 class="fw-bold">Hardware Sync</h4>
                        <p class="text-muted mb-4">Integrate with the physical world. Your visitor's face and QR code will
                            be pushed to access control hardware for frictionless entry.</p>
                        <div class="bg-white p-4 rounded-4 shadow-sm">
                            <h6 class="fw-bold small text-uppercase mb-3">Integration Checklist</h6>
                            <ul class="list-unstyled small mb-0">
                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Register at
                                    DoLynk Portal</li>
                                <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Create Cloud
                                    Project</li>
                                <li><i class="bi bi-check-circle-fill text-success me-2"></i> Whitelist Application ID</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab WhatsApp Integration -->
    <?php if (canView('settings_whatsapp')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'whatsapp') ? 'show active' : ''; ?>" id="whatsapp"
            role="tabpanel">
            <form method="POST">
                <div class="row g-4">
                    <!-- Column 1: API Configuration -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm border-0 rounded-4 overflow-hidden h-100">
                            <div class="card-header bg-success text-white py-3">
                                <h5 class="mb-0 fw-bold"><i class="bi bi-whatsapp me-2"></i>Meta Cloud Configuration</h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="text-muted small mb-4">Core credentials for the Meta WhatsApp Cloud API. These
                                    allow the system to send automated notifications.</p>

                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Permanent Access
                                        Token</label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light border-0"><i
                                                class="bi bi-shield-lock text-success"></i></span>
                                        <textarea name="whatsapp_access_token"
                                            class="form-control border-0 bg-light rounded-end" rows="3"
                                            placeholder="EAAB..."><?php echo htmlspecialchars($config['whatsapp_access_token'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Phone Number
                                            ID</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-hash text-success"></i></span>
                                            <input type="text" name="whatsapp_phone_number_id"
                                                class="form-control border-0 bg-light rounded-end"
                                                value="<?php echo htmlspecialchars($config['whatsapp_phone_number_id'] ?? ''); ?>"
                                                placeholder="e.g. 1092...">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold small text-uppercase text-muted">WABA ID</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-building text-success"></i></span>
                                            <input type="text" name="whatsapp_waba_id"
                                                class="form-control border-0 bg-light rounded-end"
                                                value="<?php echo htmlspecialchars($config['whatsapp_waba_id'] ?? ''); ?>"
                                                placeholder="Business ID">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Meta App
                                            ID</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-window-sidebar text-success"></i></span>
                                            <input type="text" name="whatsapp_app_id"
                                                class="form-control border-0 bg-light rounded-end"
                                                value="<?php echo htmlspecialchars($config['whatsapp_app_id'] ?? ''); ?>"
                                                placeholder="App ID">
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <label class="form-label fw-bold small text-uppercase text-muted">Template
                                            Language</label>
                                        <div class="input-group">
                                            <span class="input-group-text bg-light border-0"><i
                                                    class="bi bi-translate text-success"></i></span>
                                            <select name="whatsapp_template_language"
                                                class="form-select border-0 bg-light rounded-end">
                                                <option value="en" <?php echo ($config['whatsapp_template_language'] ?? 'en') == 'en' ? 'selected' : ''; ?>>English (en)</option>
                                                <option value="en_US" <?php echo ($config['whatsapp_template_language'] ?? '') == 'en_US' ? 'selected' : ''; ?>>English US (en_US)</option>
                                                <option value="en_GB" <?php echo ($config['whatsapp_template_language'] ?? '') == 'en_GB' ? 'selected' : ''; ?>>English UK (en_GB)</option>
                                                <option value="hi" <?php echo ($config['whatsapp_template_language'] ?? '') == 'hi' ? 'selected' : ''; ?>>Hindi (hi)</option>
                                                <option value="ar" <?php echo ($config['whatsapp_template_language'] ?? '') == 'ar' ? 'selected' : ''; ?>>Arabic (ar)</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-info py-2 small border-0 bg-info bg-opacity-10 mb-0">
                                    <i class="bi bi-info-circle me-2"></i> Ensure your <strong>Permanent Token</strong>
                                    never expires. Temporary tokens from the 'Getting Started' page only last 24 hours.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Column 2: Process Control -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm border-0 rounded-4 overflow-hidden h-100">
                            <div class="card-header bg-primary text-white py-3">
                                <h5 class="mb-0 fw-bold"><i class="bi bi-toggles me-2"></i>Message Control Center</h5>
                            </div>
                            <div class="card-body p-4">
                                <p class="text-muted small mb-4">Enable or disable specific notification processes. Only
                                    checked processes will attempt to send messages.</p>

                                <div class="row g-3">
                                    <?php
                                    $processes = [
                                        'visitor_arrival_host_alert' => [
                                            'title' => 'Host Notification',
                                            'desc' => 'Alerts the host when a visitor arrives.',
                                            'icon' => 'bi-person-badge',
                                            'color' => 'bg-primary'
                                        ],
                                        'visitor_otp_verification' => [
                                            'title' => 'OTP Verification',
                                            'desc' => 'Sends OTP for phone verification.',
                                            'icon' => 'bi-shield-check',
                                            'color' => 'bg-info'
                                        ],
                                        'visit_approval_visitor_notify' => [
                                            'title' => 'Approval Notice',
                                            'desc' => 'Notifies visitor of host approval.',
                                            'icon' => 'bi-patch-check',
                                            'color' => 'bg-success'
                                        ],
                                        'visit_rejection_visitor_notify' => [
                                            'title' => 'Rejection Notice',
                                            'desc' => 'Notifies visitor of denied request.',
                                            'icon' => 'bi-x-circle',
                                            'color' => 'bg-danger'
                                        ],
                                        'visitor_meet_notify' => [
                                            'title' => 'Meeting Invite',
                                            'desc' => 'Sends QR & details to invited guests.',
                                            'icon' => 'bi-calendar-event',
                                            'color' => 'bg-warning'
                                        ],
                                        'invite_cancelled' => [
                                            'title' => 'Invite Cancellation',
                                            'desc' => 'Alerts visitor if invite is revoked.',
                                            'icon' => 'bi-calendar-x',
                                            'color' => 'bg-secondary'
                                        ]
                                    ];
                                    $enabledStr = $config['whatsapp_enabled_processes'] ?? '["visitor_arrival_host_alert","visitor_otp_verification","visit_approval_visitor_notify","visit_rejection_visitor_notify","visitor_meet_notify","invite_cancelled"]';
                                    $enabled = json_decode($enabledStr, true);
                                    if (!is_array($enabled))
                                        $enabled = [];

                                    foreach ($processes as $key => $meta): ?>
                                        <div class="col-12">
                                            <div
                                                class="p-3 rounded-4 border bg-white shadow-sm hover-shadow transition-all d-flex justify-content-between align-items-center">
                                                <div class="d-flex align-items-center">
                                                    <div
                                                        class="rounded-circle <?php echo $meta['color']; ?> bg-opacity-10 <?php echo str_replace('bg-', 'text-', $meta['color']); ?> p-3 me-3">
                                                        <i class="bi <?php echo $meta['icon']; ?> fs-4"></i>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-0 fw-bold small"><?php echo $meta['title']; ?></h6>
                                                        <small class="text-muted"
                                                            style="font-size: 0.7rem;"><?php echo $meta['desc']; ?></small>
                                                    </div>
                                                </div>
                                                <div class="form-check form-switch m-0">
                                                    <input class="form-check-input" type="checkbox" name="whatsapp_process[]"
                                                        value="<?php echo $key; ?>" id="wp_<?php echo $key; ?>" <?php echo in_array($key, $enabled) ? 'checked' : ''; ?>
                                                        style="width: 2.2rem; height: 1.1rem; cursor: pointer;">
                                                </div>
                                            </div>
                                        </div>
                                        <?php
                                    endforeach; ?>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" name="save_whatsapp"
                                        class="btn btn-success w-100 py-3 rounded-pill fw-bold shadow-sm">
                                        <i class="bi bi-check-circle-fill me-2"></i>Update WhatsApp Engine
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <hr class="my-5">
            <div class="card border-0 bg-warning bg-opacity-10 rounded-4">
                <div class="card-body p-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-exclamation-triangle-fill me-2 text-warning"></i>Required Meta
                        Templates</h6>
                    <p class="small text-muted mb-3">Ensure your Meta WABA account has the following templates approved. The
                        system will skip sending if templates are missing or rejected.</p>
                    <div class="row g-3">
                        <div class="col-md-4"><code
                                class="d-block p-2 bg-white rounded border">visitor_arrival_host_alert</code></div>
                        <div class="col-md-4"><code
                                class="d-block p-2 bg-white rounded border">visitor_otp_verification</code></div>
                        <div class="col-md-4"><code
                                class="d-block p-2 bg-white rounded border">visit_approval_visitor_notify</code></div>
                        <div class="col-md-4"><code
                                class="d-block p-2 bg-white rounded border">visit_rejection_visitor_notify</code></div>
                        <div class="col-md-4"><code class="d-block p-2 bg-white rounded border">visitor_meet_notify</code>
                        </div>
                        <div class="col-md-4"><code class="d-block p-2 bg-white rounded border">invite_cancelled</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab: AI Integration -->
    <?php if (canView('settings_ai')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'ai') ? 'show active' : ''; ?>" id="ai" role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div class="card-header bg-info text-white py-3 border-0 rounded-top-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-robot me-2"></i> Artificial Intelligence Settings</h5>
                        </div>
                        <div class="card-body p-4">
                            <p class="text-muted small mb-4">Integrate AI to enable Natural Language Visitor Search and AI
                                Chat with your visitor data.</p>
                            <form method="POST">
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Gemini API Key</label>
                                    <div class="input-group shadow-sm border rounded-pill overflow-hidden">
                                        <span class="input-group-text bg-white border-0"><i
                                                class="bi bi-key text-info"></i></span>
                                        <input type="password" name="ai_api_key" class="form-control border-0"
                                            value="<?php echo htmlspecialchars($config['ai_api_key'] ?? ''); ?>"
                                            placeholder="Enter Gemini API Key">
                                    </div>
                                    <small class="text-muted d-block mt-2 px-3">Don't have a key? <a
                                            href="https://aistudio.google.com/app/apikey" target="_blank">Get it here
                                            (Free)</a></small>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label fw-bold small text-uppercase text-muted">Model
                                        Selection</label>
                                    <select name="ai_model"
                                        class="form-select border-0 bg-light rounded-pill px-4 shadow-sm">
                                        <option value="gemini-flash-latest" <?php echo ($config['ai_model'] ?? '') == 'gemini-flash-latest' ? 'selected' : ''; ?>>Gemini 1.5 Flash (Latest -
                                            Recommended)</option>
                                        <option value="gemini-pro-latest" <?php echo ($config['ai_model'] ?? '') == 'gemini-pro-latest' ? 'selected' : ''; ?>>Gemini 1.5 Pro (Latest)</option>
                                        <option value="gemini-2.0-flash" <?php echo ($config['ai_model'] ?? '') == 'gemini-2.0-flash' ? 'selected' : ''; ?>>Gemini 2.0 Flash (Experimental /
                                            Quota Limited)</option>
                                    </select>
                                </div>
                                <button type="submit" name="save_ai"
                                    class="btn btn-info text-white w-100 py-3 rounded-pill fw-bold shadow-sm">
                                    <i class="bi bi-cloud-arrow-up-fill me-2"></i> Save AI Configuration
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div
                        class="card border-0 rounded-4 bg-info bg-opacity-10 h-100 p-5 d-flex flex-column justify-content-center">
                        <i class="bi bi-lightning-charge-fill display-2 text-info opacity-25 mb-4"></i>
                        <h4 class="fw-bold">AI Capabilities</h4>
                        <ul class="list-unstyled mt-3">
                            <li class="mb-3"><i class="bi bi-check-circle-fill text-info me-2"></i> <strong>Natural Language
                                    Search:</strong> Deep search through visitor history using plain English.</li>
                            <li class="mb-3"><i class="bi bi-check-circle-fill text-info me-2"></i> <strong>Data
                                    Intelligence:</strong> Get insights like visitor trends and anomalies.</li>
                            <li class="mb-3"><i class="bi bi-check-circle-fill text-info me-2"></i> <strong>Instant
                                    Answers:</strong> Chat with your database to find information quickly.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab 4: Info (SUPER ADMIN ONLY) -->
    <?php if (canView('admin_audit')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'info') ? 'show active' : ''; ?>" id="info"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div class="card-header bg-dark text-white py-3 border-0 rounded-top-4">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-cpu me-2"></i>System Diagnostics</h5>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <li class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                    <div class="text-muted"><i class="bi bi-database me-2"></i>Database</div>
                                    <span
                                        class="fw-bold text-dark badge bg-light border text-dark px-3 py-2 rounded-pill"><?php echo $dbname ?? 'vms_db'; ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                    <div class="text-muted"><i class="bi bi-file-earmark-code me-2"></i>PHP Environment
                                    </div>
                                    <span class="fw-bold text-dark font-monospace"><?php echo phpversion(); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                    <div class="text-muted"><i class="bi bi-clock me-2"></i>System Time</div>
                                    <span class="fw-bold text-dark"><?php echo date('d-M-Y H:i'); ?></span>
                                </li>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-3 px-4">
                                    <div class="text-muted"><i class="bi bi-hdd me-2"></i>Service Status</div>
                                    <span
                                        class="badge bg-success-subtle text-success border border-success border-opacity-25 px-3 py-2 rounded-pill">OPERATIONAL</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div
                        class="card border-0 rounded-4 bg-primary bg-gradient text-white p-5 h-100 d-flex flex-column justify-content-center overflow-hidden pos-relative">
                        <i class="bi bi-patch-check-fill display-1 opacity-25 mb-4"></i>
                        <h2 class="fw-bold mb-3">VMS Engine v2.0</h2>
                        <p class="op-75 mb-4">Your system is running the latest enterprise build. All automated processes
                            for visitor tracking, WhatsApp triggers, and facial recognition synchronization are active and
                            healthy.</p>
                        <div class="d-flex gap-3">
                            <button class="btn btn-white text-primary rounded-pill fw-bold px-4">Documentation</button>
                            <button class="btn btn-outline-light rounded-pill px-4">Check for Updates</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>

    <!-- Tab 5: Access Areas -->
    <?php if (canView('settings_access')): ?>
        <div class="tab-pane fade <?php echo ($active_tab_id === 'access_areas') ? 'show active' : ''; ?>" id="access_areas"
            role="tabpanel">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card shadow-sm rounded-4 border-0 hover-shadow transition-all">
                        <div
                            class="card-header bg-success text-white py-3 border-0 rounded-top-4 d-flex justify-content-between align-items-center">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-door-open me-2"></i>Physical Access Zones</h5>
                            <span class="badge bg-white text-success rounded-pill"><?php echo count($access_areas); ?>
                                Zones</span>
                        </div>
                        <div class="card-body p-4">
                            <p class="text-muted small mb-4">Define secure areas where visitors can be assigned permission
                                during their stay.</p>
                            <form method="POST" class="mb-4">
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <div class="input-group p-1 bg-light rounded-pill border">
                                            <span class="input-group-text bg-transparent border-0 ps-3 text-success"><i
                                                    class="bi bi-geo-alt fs-5"></i></span>
                                            <input type="text" name="area_name" class="form-control border-0 bg-transparent"
                                                placeholder="Area Name (e.g., VIP Lounge)" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="input-group p-1 bg-light rounded-pill border">
                                            <span class="input-group-text bg-transparent border-0 ps-3 text-success"><i
                                                    class="bi bi-cpu fs-5"></i></span>
                                            <input type="text" name="machine_id" class="form-control border-0 bg-transparent"
                                                placeholder="Machine ID (Optional)">
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" name="add_access_area"
                                            class="btn btn-success rounded-pill px-4 shadow-sm fw-bold w-100">
                                            <i class="bi bi-plus-lg me-1"></i> Add
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <div class="list-group list-group-flush border rounded-4 overflow-hidden">
                                <?php if (empty($access_areas)): ?>
                                    <div class="list-group-item text-center py-5 text-muted">
                                        <i class="bi bi-slash-circle display-4 d-block mb-3 opacity-25"></i>
                                        No restricted zones defined yet.
                                    </div>
                                    <?php
                                endif; ?>
                                <?php foreach ($access_areas as $area): ?>
                                    <div
                                        class="list-group-item d-flex justify-content-between align-items-center py-3 px-4 hover-bg-light transition-all">
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle bg-success bg-opacity-10 p-2 me-3 text-success">
                                                <i class="bi bi-geo-alt-fill"></i>
                                            </div>
                                            <div>
                                                <span class="fw-bold text-dark"><?php echo htmlspecialchars($area['area_name']); ?></span>
                                                <?php if (!empty($area['machine_id'])): ?>
                                                    <div class="small text-muted"><i class="bi bi-cpu me-1"></i>Machine ID: <?php echo htmlspecialchars($area['machine_id']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <a href="?delete_area=<?php echo $area['id']; ?>" class="btn btn-link text-danger p-0"
                                            onclick="return confirmAction(event, 'Remove this access zone?')">
                                            <i class="bi bi-x-circle fs-5"></i>
                                        </a>
                                    </div>
                                    <?php
                                endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div
                        class="card border-0 rounded-4 bg-success bg-opacity-10 h-100 p-5 d-flex flex-column justify-content-center">
                        <i class="bi bi-shield-check display-2 text-success opacity-25 mb-4"></i>
                        <h4 class="fw-bold">Zone Security</h4>
                        <p class="text-muted">These areas will appear as selectable options in the visitor check-in form.
                            You can use these to restrict visitor movement or generate location-specific visitor passes.</p>
                        <div class="mt-4">
                            <div class="fw-bold small text-uppercase text-success opacity-75 mb-2">Popular Tags</div>
                            <div class="d-flex flex-wrap gap-2">
                                <span
                                    class="badge bg-white text-success border border-success border-opacity-25 rounded-pill px-3 py-2">Office</span>
                                <span
                                    class="badge bg-white text-success border border-success border-opacity-25 rounded-pill px-3 py-2">Warehouse</span>
                                <span
                                    class="badge bg-white text-success border border-success border-opacity-25 rounded-pill px-3 py-2">Server
                                    Room</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    endif; ?>
</div>

<script>
    function fillGmail() {
        document.getElementById('smtp_host').value = 'smtp.gmail.com';
        document.getElementById('smtp_port').value = '587';
        document.getElementById('smtp_enc').value = 'tls';
    }

    function fillOutlook() {
        document.getElementById('smtp_host').value = 'smtp.office365.com';
        document.getElementById('smtp_port').value = '587';
        document.getElementById('smtp_enc').value = 'tls';
    }

    // Sync Username to From Email if empty
    let smtpUser = document.querySelector('input[name="smtp_user"]');
    if (smtpUser) {
        smtpUser.addEventListener('change', function () {
            let from = document.getElementById('smtp_from_email');
            if (from && from.value === '') from.value = this.value;
        });
    }

    // Tab Persistence Logic
    document.addEventListener('DOMContentLoaded', function () {
        const lastTab = localStorage.getItem('activeSettingsTab');
        if (lastTab) {
            const tabBtn = document.querySelector(`#settingsTabs button[data-bs-target="${lastTab}"]`);
            if (tabBtn) {
                // Ensure correct state before showing
                document.querySelectorAll('#settingsTabs .nav-link').forEach(n => n.classList.remove('active'));
                document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show', 'active'));

                tabBtn.classList.add('active');
                const targetPane = document.querySelector(lastTab);
                if (targetPane) {
                    targetPane.classList.add('show', 'active');
                }
            }
        }

        const tabButtons = document.querySelectorAll('#settingsTabs button[data-bs-toggle="tab"]');
        tabButtons.forEach(btn => {
            btn.addEventListener('shown.bs.tab', function (e) {
                localStorage.setItem('activeSettingsTab', e.target.getAttribute('data-bs-target'));
            });
        });

        // Handle Success/Error Messages via App Modal
        <?php if (isset($msg) && $msg): ?>
            AppDialog.show({
                text: '<?php echo addslashes($msg); ?>',
                icon: '<?php echo (strpos(strtolower($msg), "error") !== false || strpos(strtolower($msg), "warning") !== false || strpos(strtolower($msg), "cannot") !== false) ? "warning" : "success"; ?>'
            });
            <?php
        endif; ?>
    });
</script>

<?php require_once 'footer.php'; ?>