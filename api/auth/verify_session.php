<?php
// api/auth/verify_session.php
require_once '../includes/api_header.php';

if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'];

    // --- REFRESH ROLE & LOCKED STATUS FROM DB ---
    // The session data might be stale if admin updated it in the web panel.
    try {
        $stmt_user = $pdo->prepare("SELECT role, permissions_locked FROM users WHERE id = ?");
        $stmt_user->execute([$user_id]);
        $user_refresh = $stmt_user->fetch();

        if ($user_refresh) {
            $role = $user_refresh['role'];
            $_SESSION['role'] = $role; // Update session
            $locked = (bool) $user_refresh['permissions_locked'];
        } else {
            // User might have been deleted
            session_destroy();
            http_response_code(401);
            sendResponse('error', 'User no longer exists');
            exit(); // Terminate script after sending response
        }
    } catch (Exception $e) {
        // Fallback to session data if DB check fails
        $locked = isset($_SESSION['permissions_locked']) ? (bool) $_SESSION['permissions_locked'] : false;
    }

    // --- PERMISSIONS LOGIC ---
    $permissions = [];
    try {
        require_once '../includes/permission_utils.php';
        $permissions = getUserPermissions($pdo, $user_id, $role, $locked);
        $_SESSION['permissions'] = $permissions; // Update session
    } catch (Exception $e) {
        $permissions = $_SESSION['permissions'] ?? [];
    }

    sendResponse('success', 'Session valid', [
        'user_id' => $_SESSION['user_id'],
        'role' => $_SESSION['role'],
        'full_name' => $_SESSION['full_name'],
        'department' => $_SESSION['department'] ?? null,
        'tenant' => $_SESSION['tenant_key'] ?? 'default',
        'permissions' => $permissions
    ]);
} else {
    http_response_code(401);
    sendResponse('error', 'Session invalid or expired');
}
