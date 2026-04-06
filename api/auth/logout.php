<?php
/**
 * Logout API for Mobile and Web
 * Handles session destruction and optional FCM token removal
 */
require_once '../../includes/db.php';
header('Content-Type: application/json');

// Even if session is not set, we might want to allow FCM clearing if token is provided
// But usually, you need a session to know which user's token it is for extra security
// However, the mobile app might have an expired session but still have the token in storage.

$data = json_decode(file_get_contents('php://input'), true);
$fcm_token = !empty($data['fcm_token']) ? trim((string) $data['fcm_token']) : null;

try {
    // 1. If we have a session, we can definitely clear the token for this user
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];

        if ($fcm_token) {
            // Clear specifically this token for this user
            $stmt = $pdo->prepare("DELETE FROM user_devices WHERE user_id = ? AND fcm_token = ?");
            $stmt->execute([$user_id, $fcm_token]);

            // Clear legacy column if it matches
            $stmt_legacy = $pdo->prepare("UPDATE users SET fcm_token = NULL WHERE id = ? AND fcm_token = ?");
            $stmt_legacy->execute([$user_id, $fcm_token]);

            logAction($pdo, $user_id, "Mobile Logout: FCM token cleared ($fcm_token)");
        } else {
            // If no token provided, should we clear ALL tokens? Probably not, user might have other phones.
            logAction($pdo, $user_id, "Logout: Session destroyed");
        }
    }
    // 2. If NO session, but we have a token, we should still clear it from any user it belongs to
    // This handles cases where the session expired but the app is now logging out.
    elseif ($fcm_token) {
        $stmt = $pdo->prepare("DELETE FROM user_devices WHERE fcm_token = ?");
        $stmt->execute([$fcm_token]);

        $stmt_legacy = $pdo->prepare("UPDATE users SET fcm_token = NULL WHERE fcm_token = ?");
        $stmt_legacy->execute([$fcm_token]);
    }

    // 3. Destroy Session
    destroyPersistentSession();

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'message' => 'Logged out successfully'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Logout error: ' . $e->getMessage()
    ]);
}
?>