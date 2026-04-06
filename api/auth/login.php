<?php
// api/auth/login.php
require_once '../includes/api_header.php';

// Log request for debugging
$debug_log = __DIR__ . '/login_debug.log';
$input = file_get_contents('php://input');
$tenant = $_GET['tenant'] ?? 'none';
$log_msg = date('[Y-m-d H:i:s] ') . "Login attempt: " . $input . " Tenant: " . $tenant . "\n";
file_put_contents($debug_log, $log_msg, FILE_APPEND);
chmod($debug_log, 0666); // Ensure it's readable/writable by web server

$data = getPostData();

if (!isset($data['username']) || !isset($data['password'])) {
    sendResponse('error', 'Username and password are required');
}

$username = $data['username'];
$password = $data['password'];

try {
    $stmt = $pdo->prepare("SELECT id, username, password, role, full_name, employee_id, department, permissions_locked FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {

        // --- SYSTEM QUOTA CHECK: Mobile Device Limit (Set by Super Admin) ---
        // Fallback: If device_id is missing, use fcm_token as the unique identifier
        $device_id = $data['device_id'] ?? ($data['fcm_token'] ?? null);

        if ($device_id && !empty($tenant) && $tenant !== 'none') {
            global $master_pdo;
            if ($master_pdo) {
                // Fetch the quota set by Super Admin
                $tStmt = $master_pdo->prepare("SELECT max_devices FROM tenants WHERE tenant_key = ?");
                $tStmt->execute([$tenant]);
                $max_devices = (int) ($tStmt->fetchColumn() ?: 5);

                // Check Device Registry (Status & Record)
                $dCheck = $master_pdo->prepare("SELECT id, status FROM tenant_devices WHERE tenant_key = ? AND device_id = ?");
                $dCheck->execute([$tenant, $device_id]);
                $device_rec = $dCheck->fetch();

                if ($device_rec) {
                    // 🚨 CRITICAL: Deny if explicitly BLOCKED by System Admin
                    if ($device_rec['status'] === 'blocked') {
                        sendResponse('error', "Device Authorization Revoked: This mobile hardware has been blocked by the system administrator. Access denied.");
                    }
                    // Update heartbeat, user name, and latest device name for active device
                    $updStmt = $master_pdo->prepare("UPDATE tenant_devices SET last_login = NOW(), device_name = ?, last_user_name = ? WHERE id = ?");
                    $updStmt->execute([$data['device_name'] ?? 'Android Device', $user['full_name'], $device_rec['id']]);
                } else {
                    // New phone: Check if the client has any ACTIVE slots left in their quota
                    $dCount = $master_pdo->prepare("SELECT COUNT(*) FROM tenant_devices WHERE tenant_key = ? AND status = 'active'");
                    $dCount->execute([$tenant]);
                    if ((int) $dCount->fetchColumn() >= $max_devices) {
                        sendResponse('error', "Device Quota Reached: This company is limited to $max_devices active mobile devices. Please contact the system administrator to upgrade.");
                    }
                    // Register the new phone as 'active' with user name
                    $master_pdo->prepare("INSERT INTO tenant_devices (tenant_key, device_id, device_name, last_user_name, status) VALUES (?, ?, ?, ?, 'active')")
                        ->execute([$tenant, $device_id, $data['device_name'] ?? 'Android Device', $user['full_name']]);
                }
            }
        }

        // If department is blank in users table, try to get it from employees table
        if (empty($user['department']) && !empty($user['employee_id'])) {
            try {
                $emp_stmt = $pdo->prepare("SELECT department FROM employees WHERE id = ?");
                $emp_stmt->execute([$user['employee_id']]);
                $emp_dept = $emp_stmt->fetchColumn();
                if ($emp_dept) {
                    $user['department'] = $emp_dept;
                }
            } catch (PDOException $e) {
                // Ignore employee fetch error, keep original user data
            }
        }

        // Log successful login
        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "Login SUCCESS for user: " . $username . " Role: " . $user['role'] . "\n", FILE_APPEND);

        // Set session data
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['employee_id'] = $user['employee_id'];
        $_SESSION['department'] = $user['department'] ?? null;
        $_SESSION['tenant_key'] = $tenant; // Ensure tenant is in session
        $_SESSION['device_id'] = $device_id; // CRITICAL: Link hardware ID to session for instant blocking

        // --- NEW: Update FCM token if provided ---
        if (array_key_exists('fcm_token', $data)) {
            $token_val = $data['fcm_token'];
            file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "[V3-SYNC] FCM Token key present. Value: " . (is_null($token_val) ? "[NULL]" : (empty($token_val) ? "[EMPTY]" : substr($token_val, 0, 15) . "...")) . "\n", FILE_APPEND);

            if (!empty($token_val)) {
                try {
                    // Ensure table exists
                    require_once '../../includes/ensure_user_devices.php';

                    // --- NEW: ENSURE TOKEN UNIQUENESS ---
                    // Remove this token from ANY other user record to prevent "ghost" notifications
                    $clear_stmt = $pdo->prepare("DELETE FROM user_devices WHERE fcm_token = ? AND user_id != ?");
                    $clear_stmt->execute([$token_val, $user['id']]);

                    // Check if this token already exists for this user
                    $check_stmt = $pdo->prepare("SELECT id FROM user_devices WHERE user_id = ? AND fcm_token = ?");
                    $check_stmt->execute([$user['id'], $token_val]);
                    $existing_device = $check_stmt->fetch();

                    if ($existing_device) {
                        // Update timestamp
                        $fcm_stmt = $pdo->prepare("UPDATE user_devices SET last_updated = NOW() WHERE id = ?");
                        $fcm_stmt->execute([$existing_device['id']]);
                        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "[V3-SYNC] Device token timestamp updated for user: " . $user['id'] . "\n", FILE_APPEND);
                    } else {
                        // Insert new device token
                        $fcm_stmt = $pdo->prepare("INSERT INTO user_devices (user_id, fcm_token, platform) VALUES (?, ?, 'android')");
                        $fcm_stmt->execute([$user['id'], $token_val]);
                        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "[V3-SYNC] New device token inserted for user: " . $user['id'] . "\n", FILE_APPEND);
                    }

                    // Legacy support: also update users table for backward compatibility if needed, 
                    // but for now we migrate to user_devices. 
                    // Let's keep the main table updated with the *latest* token just in case other scripts rely on it.
                    // Also clear legacy token from others
                    $clear_legacy = $pdo->prepare("UPDATE users SET fcm_token = NULL WHERE fcm_token = ? AND id != ?");
                    $clear_legacy->execute([$token_val, $user['id']]);

                    $legacy_stmt = $pdo->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
                    $legacy_stmt->execute([$token_val, $user['id']]);

                } catch (PDOException $e) {
                    file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "[V3-SYNC] DB UPDATE ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
        } else {
            file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "[V3-SYNC] MISSING: fcm_token key in POST data\n", FILE_APPEND);
        }

        // --- PERMISSIONS LOGIC ---
        // Determine permissions for the user
        require_once '../includes/permission_utils.php';
        $permissions_locked = (bool) ($user['permissions_locked'] ?? 0);
        $permissions = getUserPermissions($pdo, $user['id'], $user['role'], $permissions_locked);
        $_SESSION['permissions'] = $permissions;
        $user['permissions'] = $permissions;

        // Fetch Mandatory Fields Config
        $mandatory_fields_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'mandatory_registration_fields'");
        $mandatory_fields_val = $mandatory_fields_stmt ? $mandatory_fields_stmt->fetchColumn() : null;
        $mandatory_fields = $mandatory_fields_val ? json_decode($mandatory_fields_val, true) : ["visitor_name", "mobile_number", "id_proof", "purpose", "meeting_host", "otp_check"];
        $user['mandatory_fields'] = $mandatory_fields;

        // Return user data (excluding password)
        unset($user['password']);
        $user['session_id'] = session_id();

        // Audit Log for Android/Mobile Login
        logAction($pdo, $user['id'], "Mobile App Login Successful (Android)");

        sendResponse('success', 'Login successful', $user);
    } else {
        // Log failed login
        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "Login FAILED for user: " . $username . "\n", FILE_APPEND);
        sendResponse('error', 'Invalid username or password');
    }
} catch (PDOException $e) {
    file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "Login DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
