<?php
// api/includes/permission_utils.php

/**
 * Get permissions for a user based on their role and locked status.
 *
 * @param PDO $pdo Database connection
 * @param int $userId User ID
 * @param string $role User Role
 * @param bool $isLocked Whether user permissions are locked (custom)
 * @return array List of permission keys
 */
function getUserPermissions($pdo, $userId, $role, $isLocked)
{
    $permissions = [];

    if ($isLocked) {
        // User-specific permissions (Locked) - OVERRIDE everything
        try {
            $stmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$userId]);
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // Log error or ignore
            error_log("Permission fetch error: " . $e->getMessage());
        }
    } else {
        // Role-based permissions (Default)
        $role_lookup = $role;
        if ($role_lookup === 'host') {
            $role_lookup = 'employee'; // Normalize host to use employee defaults
        }

        try {
            $stmt = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role = ?");
            $stmt->execute([$role_lookup]);
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // Table might not exist, ignore
        }

        // If empty, use hardcoded fallbacks
        if (empty($permissions)) {
            if ($role === 'security') {
                $permissions = ['security_register', 'security_scan', 'security_search', 'view_employee_report', 'settings_profile'];
            } elseif ($role === 'host' || $role === 'employee') {
                $permissions = ['host_pending', 'host_history', 'host_invite', 'host_reports', 'view_employee_report', 'settings_profile'];
            }
        }
    }

    return $permissions;
}
?>