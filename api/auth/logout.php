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
$fcm_token = !empty($data['fcm_token']) ? trim((string)$data['fcm_token']) : null;

try {
    // 1. If we have a session, we can definitely clear the tokens for this user
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        
        // AGGRESSIVE CLEANUP: Clear ALL tokens for this user upon logout
        // This ensures no orphaned tokens remain in user_devices (Tenant DB)
        $stmt = $pdo->prepare("DELETE FROM user_devices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Also clear the legacy column
        $stmt_legacy = $pdo->prepare("UPDATE users SET fcm_token = NULL WHERE id = ?");
        $stmt_legacy->execute([$user_id]);

        logAction($pdo, $user_id, "Logout: All FCM tokens and security entries cleared for User $user_id");
    } 
    // 2. If NO session (expired), but we have a token, clear that specific token
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
