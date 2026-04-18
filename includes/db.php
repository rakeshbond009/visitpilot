<?php
/**
 * Core Database & Session Configuration
 * Standardized for both Local (XAMPP) and Production (Hostinger)
 */
require_once __DIR__ . '/init.php';

// 1. SECURE OUTPUT BUFFERING
if (!ob_get_level()) {
    ob_start();
}

// 2. ROBUST HTTPS DETECTION (Local + Proxied Hostinger)
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? '') == 443
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';

// 3. GLOBAL DATE/TIME & HELPERS
date_default_timezone_set('Asia/Kolkata');

if (!function_exists('getIST')) {
    function getIST($format = 'Y-m-d H:i:s')
    {
        return gmdate($format, time() + 19800);
    }
}
function current_datetime()
{
    return getIST();
}
function current_date()
{
    return getIST('Y-m-d');
}

function sanitize($data)
{
    return htmlspecialchars(strip_tags(trim($data ?? '')));
}

// 4. SESSION INITIALIZATION
if (session_status() === PHP_SESSION_NONE) {
    // Shared Hosting optimization: only set custom path on local
    $is_local_env = (php_sapi_name() === 'cli')
        || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])
        || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])
        || preg_match('/^(192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|10\.|127\.)/', $_SERVER['REMOTE_ADDR'] ?? '')
        || preg_match('/^(192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|10\.|localhost|127\.)/', $_SERVER['HTTP_HOST'] ?? '');

    if ($is_local_env && !isset($_SERVER['HTTP_X_REAL_IP'])) { // Additional check for proxy
        $session_path = __DIR__ . '/sessions';
        if (!is_dir($session_path)) {
            @mkdir($session_path, 0777, true);
        }
        if (is_dir($session_path) && is_writable($session_path)) {
            session_save_path($session_path);
        }
    }

    ini_set('session.gc_maxlifetime', 2592000);

    session_set_cookie_params([
        'lifetime' => 2592000,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Support PHPSESSID in URL for mobile app redirects
    $sid = $_SERVER['HTTP_X_SESSION_ID'] ?? ($_GET['PHPSESSID'] ?? '');
    if (!empty($sid) && !headers_sent()) {
        session_id($sid);
    }

    if (!headers_sent()) {
        session_start();
    }
}

// 5. EARLY TENANT RESTORATION (Critical for Multi-Tenant)
if (!isset($_SESSION['tenant_key']) && isset($_COOKIE['vms_tenant'])) {
    $_SESSION['tenant_key'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $_COOKIE['vms_tenant']);
}

// 6. ENVIRONMENT DETECTION
$is_local = (php_sapi_name() === 'cli')
    || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'])
    || in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost', '127.0.0.1'])
    || preg_match('/^(192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|10\.|127\.)/', $_SERVER['REMOTE_ADDR'] ?? '')
    || preg_match('/^(192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.|10\.|localhost|127\.)/', $_SERVER['HTTP_HOST'] ?? '');

$db_debug_log = __DIR__ . '/db_debug.log';
function log_db_msg($msg)
{
    global $db_debug_log;
    $timestamp = date('[Y-m-d H:i:s] ');
    @file_put_contents($db_debug_log, $timestamp . $msg . "\n", FILE_APPEND);
}

/**
 * Global Helper to fetch system settings
 */
function get_setting($key, $default = null, $pdo_passed = null)
{
    global $pdo;
    $db = $pdo_passed ?: $pdo;
    if (!$db)
        return $default;

    static $settings_cache = null;
    if ($settings_cache === null) {
        try {
            $stmt = $db->query("SELECT setting_key, setting_value FROM system_settings");
            $settings_cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $settings_cache = [];
        }
    }
    return $settings_cache[$key] ?? $default;
}

// 7. DATABASE CONFIGURATION
if ($is_local) {
    $m_host = 'localhost';
    $m_user = 'root';
    $m_pass = '';
    $m_db = 'vms_master';

    // ========== CENTRALIZED SUPPORT DATABASE (LOCAL TESTING) ==========
    define('SUPPORT_DB_HOST', 'localhost');
    define('SUPPORT_DB_NAME', 'codepilotx');
    define('SUPPORT_DB_USER', 'root');
    define('SUPPORT_DB_PASS', '');
} else {
    $m_host = 'localhost';
    $m_user = 'u875321134_vms_master';
    $m_pass = 'Eu8~ieQH?Wzc';
    $m_db = 'u875321134_vms_master';

    // ========== CENTRALIZED SUPPORT DATABASE (PRODUCTION) ==========
    define('SUPPORT_DB_HOST', 'localhost');
    define('SUPPORT_DB_NAME', 'u875321134_mywebsite');
    define('SUPPORT_DB_USER', 'u875321134_rakeshwebsite');
    define('SUPPORT_DB_PASS', 'Mywebsite@2025');
}

define('CLIENT_APP_NAME', 'VisitPilot - VMS'); // Identifies which app reported the issue

try {
    $master_pdo = new PDO("mysql:host=$m_host;dbname=$m_db;charset=utf8mb4", $m_user, $m_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'"
    ]);
} catch (PDOException $e) {
    log_db_msg("Master DB Fail: " . $e->getMessage());
    if (!in_array(basename($_SERVER['PHP_SELF']), ['initialize_master.php', 'index.php'])) {
        die("System Maintenance. Please try again later.");
    }
}

// 8. TENANT IDENTIFICATION & CONNECTION
if (isset($_GET['tenant'])) {
    $_SESSION['tenant_key'] = sanitize($_GET['tenant']);
}
$tenant_key = $_SESSION['tenant_key'] ?? 'default';

$tenant = null;
if (isset($master_pdo)) {
    try {
        $stmt = $master_pdo->prepare("SELECT * FROM tenants WHERE tenant_key = ? AND status = 'active'");
        $stmt->execute([$tenant_key]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
    }
}

if ($tenant) {
    try {
        $pdo = new PDO("mysql:host={$tenant['db_host']};dbname={$tenant['db_name']};charset=utf8mb4", $tenant['db_user'], $tenant['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'"
        ]);
    } catch (PDOException $e) {
        die("Tenant Connection Failed.");
    }
} else {
    $pdo = $master_pdo ?? null;
}

/**
 * Returns a connection to the centralized support database (PDO for VMS)
 */
function getSupportDatabaseConnection()
{
    try {
        $dsn = "mysql:host=" . SUPPORT_DB_HOST . ";dbname=" . SUPPORT_DB_NAME . ";charset=utf8mb4";
        $support_pdo = new PDO($dsn, SUPPORT_DB_USER, SUPPORT_DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'"
        ]);
        return $support_pdo;
    } catch (PDOException $e) {
        error_log("CENTRAL SUPPORT DB CONNECTION FAILED: " . $e->getMessage());
        return null;
    }
}

// AUTO-MIGRATION (Admin Only)
if ($tenant && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    require_once __DIR__ . '/migration_engine.php';
    $result = applyMigrations($pdo, $tenant_key, $tenant['schema_version']);
    if (($result['count'] ?? 0) > 0) {
        $upd = $master_pdo->prepare("UPDATE tenants SET schema_version = ? WHERE tenant_key = ?");
        $upd->execute([$result['new_version'], $tenant_key]);
    }
}

// 9. BASE URL & REDIRECTS
$protocol = $is_https ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Automatically detect the base folder (Safest Method)
$domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base_folder = '/';

// If on local XAMPP, use the subfolder. If on atithi.online, use root.
if (strpos($domain, 'localhost') !== false || strpos($_SERVER['SCRIPT_NAME'], '/visitpilot/') !== false) {
    $base_folder = '/visitpilot/';
}

if (!defined('BASE_URL')) {
    define('BASE_URL', $protocol . $domainName . $base_folder);
}

function redirect($url)
{
    if (!headers_sent()) {
        header("Location: $url");
    } else {
        echo "<script>window.location.href = '$url';</script>";
    }
    exit;
}

function generateVisitCode()
{
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

/**
 * Quota Enforcement: Check if user limit is reached for the current tenant.
 * (Excludes 'admin' role, only counts 'active' users)
 */
function isUserQuotaReached()
{
    global $pdo, $tenant;
    if (!$tenant || !isset($tenant['max_users']) || $tenant['max_users'] <= 0) {
        return false;
    }

    try {
        // Only count active users who are NOT admins
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role != 'admin' AND status = 'active'");
        $stmt->execute();
        $active_users = (int) $stmt->fetchColumn();

        return $active_users >= $tenant['max_users'];
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Hardware Sync: Block or Unblock all mobile devices associated with a user.
 * Triggered whenever a user's status is changed manually or via employee revoke.
 */
function toggleUserMobileAccess($user_id, $status)
{
    global $pdo, $master_pdo, $tenant_key;
    if (!$pdo || !$master_pdo || !$tenant_key)
        return;

    try {
        // 1. Identify all tokens/device IDs for this user in the tenant DB
        $stmt = $pdo->prepare("SELECT fcm_token FROM user_devices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Include legacy token check
        $stmt = $pdo->prepare("SELECT fcm_token FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $legacy = $stmt->fetchColumn();
        if ($legacy && !in_array($legacy, $tokens)) {
            $tokens[] = $legacy;
        }

        if (!empty($tokens)) {
            $new_device_status = ($status === 'active') ? 'active' : 'blocked';

            // 2. Batch update device status in the Central Registry (Master DB)
            $placeholders = implode(',', array_fill(0, count($tokens), '?'));
            $msql = "UPDATE tenant_devices SET status = ? WHERE tenant_key = ? AND device_id IN ($placeholders)";

            $params = array_merge([$new_device_status, $tenant_key], $tokens);
            $mStmt = $master_pdo->prepare($msql);
            $mStmt->execute($params);

            error_log("SYNC: Toggled mobile access to '$new_device_status' for User ID $user_id on " . count($tokens) . " devices.");
        }
    } catch (Exception $e) {
        if (function_exists('log_db_msg')) {
            log_db_msg("Device toggle error for User $user_id: " . $e->getMessage());
        }
    }
}

// 10. SECURE PERSISTENT AUTHENTICATION & PERMISSIONS
function handlePersistentLogin()
{
    global $pdo;

    // Detect Session ID from Custom Header (for Mobile Apps)
    if (!isset($_SESSION['user_id'])) {
        $headerSessionId = $_SERVER['HTTP_X_SESSION_ID'] ?? null;
        if ($headerSessionId) {
            session_id($headerSessionId);
            @session_start();
        }
    }

    if (isset($_SESSION['user_id'])) {
        loadUserPermissions();
        if (!isset($_COOKIE['vms_token']) && $pdo) {
            try {
                createPersistentSession($_SESSION['user_id']);
            } catch (Exception $e) {
            }
        }
        return true;
    }
    if (!isset($_COOKIE['vms_token']) || !$pdo)
        return false;
    $token = (string) $_COOKIE['vms_token'];

    try {
        $stmt = $pdo->prepare("
            SELECT us.*, u.username, u.full_name, u.role, u.is_super, u.bg_mode 
            FROM user_sessions us 
            JOIN users u ON us.user_id = u.id 
            WHERE us.token = ? AND us.expires_at > ?
        ");
        $stmt->execute([$token, current_datetime()]);
        $row = $stmt->fetch();

        if ($row) {
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['bg_mode'] = $row['bg_mode'];
            $_SESSION['is_super'] = (bool) (($row['is_superadmin'] ?? $row['is_super'] ?? false));

            $upd = $pdo->prepare("UPDATE user_sessions SET last_activity = ? WHERE id = ?");
            $upd->execute([current_datetime(), $row['id']]);

            loadUserPermissions();
            return true;
        } else {
            setcookie('vms_token', '', time() - 3600, '/', '');
            setcookie('vms_tenant', '', time() - 3600, '/', '');
        }
    } catch (Exception $e) {
    }
    return false;
}

/**
 * Load User and Role permissions into Session
 */
function loadUserPermissions()
{
    global $pdo;
    if (!isset($_SESSION['user_id']) || !$pdo)
        return;

    // 0. Update Role and Lock Status (Real-time Sync)
    // CRITICAL FIX: Only sync if we are NOT a Super Admin managing a tenant.
    // If a Super Admin is managing a tenant, we MUST NOT overwrite their Master DB identity with Tenant DB data.
    $is_super = $_SESSION['is_super'] ?? false;
    if (!$is_super) {
        $stmt = $pdo->prepare("SELECT role, full_name, permissions_locked, status FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userRow = $stmt->fetch();

        if ($userRow) {
            // Immediate Revocation if account is disabled
            if (($userRow['status'] ?? 'active') === 'inactive') {
                destroyPersistentSession();
                redirect(BASE_URL . 'index.php?msg=account_disabled');
            }

            $_SESSION['role'] = $userRow['role'];
            $_SESSION['full_name'] = $userRow['full_name'];
            $_SESSION['permissions_locked'] = (bool) ($userRow['permissions_locked'] ?? false);
        } else {
            // If the user record is missing (revoked), force an immediate logout
            destroyPersistentSession();
            redirect(BASE_URL . 'index.php?msg=access_revoked');
        }
    }

    // 1. Fetch User Explicit Permissions
    $stmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['my_perms'] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    // 3. Fetch Role Defaults
    $role_lookup = $_SESSION['role'];
    $role_list = [$role_lookup];
    if (in_array($role_lookup, ['host', 'employee'])) {
        $role_list = ['host', 'employee']; // Sync permissions for both
    }

    $placeholders = implode(',', array_fill(0, count($role_list), '?'));
    $stmt = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role IN ($placeholders)");
    try {
        $stmt->execute($role_list);
        $_SESSION['role_perms'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $_SESSION['role_perms'] = [];
    }

    // Consolidate for unified permission checks in API files
    $_SESSION['permissions'] = array_unique(array_merge($_SESSION['my_perms'] ?: [], $_SESSION['role_perms'] ?: []));
    $_SESSION['permissions_loaded'] = true;
}

/**
 * Check if current user has a specific permission
 */
function canView($key)
{
    if (!isset($_SESSION['user_id']))
        return false;

    // Always allow Admin to see everything unless it's a Super Admin only check handled elsewhere
    if (($_SESSION['role'] ?? '') === 'admin')
        return true;

    $my_perms = $_SESSION['my_perms'] ?? [];
    $role_perms = $_SESSION['role_perms'] ?? [];
    $locked = $_SESSION['permissions_locked'] ?? false;

    if ($locked)
        return in_array($key, $my_perms);
    if (!empty($role_perms))
        return in_array($key, $role_perms);

    return false;
}

/**
 * Enforce Page-Level Security based on Directory and Filename
 */
function enforcePageSecurity()
{
    // 1. Ensure user is logged in
    requireLogin();

    $role = $_SESSION['role'] ?? '';
    $user_id = $_SESSION['user_id'] ?? null;
    $is_super = $_SESSION['is_super'] ?? false;
    $script = $_SERVER['SCRIPT_NAME'];
    $filename = basename($script);

    // 2. Identify Section
    $isAdminSec = (strpos($script, '/admin/') !== false);
    $isSecuritySec = (strpos($script, '/security/') !== false);
    $isHostSec = (strpos($script, '/host/') !== false);

    // 3. Define Global Path mapping for shared files
    if ($filename === 'change_password.php') {
        return; // Allow any logged-in user to change their own password
    }

    // 4. Define Page -> Multiple Permissions Mapping
    // If user has ANY of these permissions, they are allowed into the file
    $pagePermissions = [
        'settings.php' => ['settings_profile', 'settings_company', 'settings_general', 'settings_departments', 'settings_access', 'settings_email', 'settings_tenant', 'settings_dahua', 'settings_whatsapp', 'settings_ai'],
        'reports.php' => ['admin_reports', 'host_reports'],
        'employees.php' => ['admin_employees'],
        'departments.php' => ['settings_departments'],
        'permissions.php' => ['admin_users'],
        'audit_logs.php' => ['admin_audit'],
        'register.php' => ['security_register'],
        'scan_qr.php' => ['security_scan'],
        'search.php' => ['security_search'],
        'invite.php' => ['host_invite'],
        'pending_approvals.php' => ['host_pending'],
        'my_visitors.php' => ['host_history'],
        'process_visit.php' => [], // Allow any logged-in user to process visits
        'ai_chat.php' => ['access_ai_rag_chat'],
        'app_issues.php' => ['report_issue']
    ];

    // 4. Critical Super Admin Only - Full Page Block (Explicit Request)
    $superAdminOnlyFiles = ['tenants.php', 'audit_logs.php'];

    // 5. Critical Super Admin Only - Tabs within Page
    $requestedTab = $_GET['tab'] ?? '';
    $isRestrictedTab = ($filename === 'settings.php' && in_array($requestedTab, ['company']));

    $denied = false;
    $reason = "";

    // A. Strict Super Admin Check
    if ((in_array($filename, $superAdminOnlyFiles) || $isRestrictedTab) && !$is_super) {
        $denied = true;
        $reason = "Super Admin Only Restriction for $filename" . ($isRestrictedTab ? " (Tab: $requestedTab)" : "");
    }

    // B. Page-Level Permission Overrides (Allow Entry based on specific permissions)
    if (!$denied && isset($pagePermissions[$filename])) {
        $allowedPerms = $pagePermissions[$filename];
        if (empty($allowedPerms)) {
            return; // Open to all logged-in users
        }

        $hasAny = false;
        foreach ($allowedPerms as $p) {
            if (canView($p)) {
                $hasAny = true;
                break;
            }
        }

        if ($hasAny) {
            // User allowed by specific page-level permission
            return;
        } else {
            // User specifically lacks ANY of the required permissions for this page
            $denied = true;
            $reason = "User lacks required permission (" . implode(',', $allowedPerms) . ") for $filename";
        }
    }

    // C. Folder-Level Default Fallback (General Security for unmapped pages)
    if (!$denied) {
        if ($isAdminSec && $role !== 'admin') {
            $denied = true;
            $reason = "Admin Folder Default Access Denied for role: $role";
        } elseif ($isSecuritySec && !in_array($role, ['security', 'admin', 'host', 'employee'])) {
            $denied = true;
            $reason = "Security Folder Default Access Denied for role: $role";
        } elseif ($isHostSec && !in_array($role, ['host', 'employee', 'admin'])) {
            $denied = true;
            $reason = "Host Folder Default Access Denied for role: $role";
        }
    }

    // 6. Handle Denial
    if ($denied) {
        // Log this unauthorized attempt
        global $pdo;
        logAction($pdo, $user_id, "UNAUTHORIZED ACCESS ATTEMPT to $filename. Reason: $reason");

        $home = getHomeUrl($role);
        redirect($home . "?security_alert=unauthorized_access&page=" . urlencode($filename));
    }
}

function getHomeUrl($role)
{
    if ($role === 'admin')
        return BASE_URL . 'admin/dashboard.php';
    if ($role === 'security')
        return BASE_URL . 'security/dashboard.php';
    if ($role === 'host' || $role === 'employee')
        return BASE_URL . 'host/dashboard.php';
    return BASE_URL . 'index.php';
}

function createPersistentSession($user_id)
{
    global $pdo, $is_https, $tenant_key;
    if (!$pdo)
        return false;
    try {
        $token = bin2hex(random_bytes(32));
        $expire_ts = time() + (365 * 24 * 60 * 60);
        $expires_date = date('Y-m-d H:i:s', $expire_ts);
        $now = current_datetime();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $stmt = $pdo->prepare("INSERT INTO user_sessions (user_id, token, device_info, ip_address, created_at, expires_at, last_activity) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $token, $ua, $ip, $now, $expires_date, $now]);

        setcookie('vms_token', $token, $expire_ts, '/', '', $is_https, true);
        setcookie('vms_tenant', $tenant_key, $expire_ts, '/', '', $is_https, true);
        return $token;
    } catch (Exception $e) {
        return false;
    }
}

function destroyPersistentSession()
{
    global $pdo;
    if (isset($_SESSION['user_id'])) {
        logAction($pdo, $_SESSION['user_id'], "User logged out");
    }

    if (isset($_COOKIE['vms_token'])) {
        $token = (string) $_COOKIE['vms_token'];
        if ($pdo) {
            try {
                $stmt = $pdo->prepare("DELETE FROM user_sessions WHERE token = ?");
                $stmt->execute([$token]);
            } catch (Exception $e) {
            }
        }
    }
    setcookie('vms_token', '', time() - 3600, '/', '');
    setcookie('vms_tenant', '', time() - 3600, '/', '');
    session_destroy();
}

function requireLogin()
{
    handlePersistentLogin();
    if (!isset($_SESSION['user_id'])) {
        $path = BASE_URL . 'index.php';
        redirect($path);
    }
}

function logAction($pdo, $user_id, $action, $old = null, $new = null)
{
    global $master_pdo, $tenant_key;

    // 1. Identify User ID
    $final_user_id = $user_id ?: ($_SESSION['user_id'] ?? 0);

    // 2. Identify User Name (Capture from session or look up in source DB)
    $performed_by = "System";
    if (isset($_SESSION['full_name'])) {
        $performed_by = $_SESSION['full_name'] . " (@" . ($_SESSION['username'] ?? 'user') . ")";
    } elseif ($final_user_id > 0 && $pdo) {
        try {
            $uStmt = $pdo->prepare("SELECT full_name, username FROM users WHERE id = ?");
            $uStmt->execute([$final_user_id]);
            $u = $uStmt->fetch();
            if ($u) {
                $performed_by = $u['full_name'] . " (@" . $u['username'] . ")";
            }
        } catch (Exception $e) {
        }
    }

    // Centralized logging: Always record to the Master database
    $log_db = $master_pdo ?? $pdo;
    if (!$log_db)
        return;

    try {
        $cur_tenant = $tenant_key ?? ($_SESSION['tenant_key'] ?? 'master');
        $old_json = $old ? json_encode($old) : null;
        $new_json = $new ? json_encode($new) : null;

        $stmt = $log_db->prepare("INSERT INTO audit_logs (user_id, performed_by, action, old_value, new_value, ip_address, tenant_key, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$final_user_id, $performed_by, $action, $old_json, $new_json, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $cur_tenant, current_datetime()]);
    } catch (Exception $e) {
        // Fallback for older schema structures (Missing performed_by or tenant_key)
        try {
            $stmt = $log_db->prepare("INSERT INTO audit_logs (user_id, performed_by, action, ip_address, tenant_key, created_at) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$final_user_id, $performed_by, $action, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $cur_tenant, current_datetime()]);
        } catch (Exception $e2) {
            $stmt = $log_db->prepare("INSERT INTO audit_logs (user_id, action, ip_address, created_at) VALUES (?, ?, ?, ?)");
            $stmt->execute([$final_user_id, $action, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', current_datetime()]);
        }
    }
}

// 11. COMPANY SETTINGS
$company_settings = ['name' => 'VisitPilot VMS', 'logo' => 'assets/img/logo.png'];
// ALWAYS Pull from Master DB for Global Branding (Requested by User)
if (isset($master_pdo)) {
    try {
        $stmt = $master_pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_logo')");
        if ($stmt) {
            $db_settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($db_settings['company_name']))
                $company_settings['name'] = $db_settings['company_name'];
            if (!empty($db_settings['company_logo']))
                $company_settings['logo'] = $db_settings['company_logo'];
        }
    } catch (Exception $e) {
        // Fallback to default if table missing in master
    }
}

require_once __DIR__ . '/datetime_helper.php';

// 12. LIVE HARDWARE BLOCK CHECK (Instant Effect for Quota Enforcement)
// Only check if user is logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['tenant_key'])) {
    $is_api = (strpos($_SERVER['REQUEST_URI'], '/api/') !== false);

    // --- FORCE STAMP: Only for mobile-facing roles (Security/Employee/Host) ---
    $is_web_admin = (isset($_SESSION['is_super']) && $_SESSION['is_super']) || (($_SESSION['role'] ?? '') === 'admin');

    if ($is_api && !$is_web_admin && !isset($_SESSION['device_id']) && !isset($_COOKIE['vms_token'])) {
        session_unset();
        session_destroy();
        header('Content-Type: application/json');
        http_response_code(401);
        die(json_encode(['status' => 'error', 'message' => 'Security Update: Your session has been refreshed. Please login again.']));
    }

    // --- INSTANT BLOCK: Check if the stamped hardware is blocked ---
    if (isset($_SESSION['device_id']) && !$is_web_admin) {
        global $master_pdo;
        if ($master_pdo) {
            try {
                $checkStmt = $master_pdo->prepare("SELECT status FROM tenant_devices WHERE tenant_key = ? AND device_id = ?");
                $checkStmt->execute([$_SESSION['tenant_key'], $_SESSION['device_id']]);
                if ($checkStmt->fetchColumn() === 'blocked') {
                    // 🛑 KICK!
                    session_unset();
                    session_destroy();

                    if ($is_api) {
                        header('Content-Type: application/json');
                        die(json_encode(['status' => 'error', 'message' => 'Device Blocked: Access has been revoked by the system administrator.']));
                    } else {
                        header("Location: " . BASE_URL . "index.php?error=device_blocked");
                        exit;
                    }
                }
            } catch (Exception $e) {
            }
        }
    }
}