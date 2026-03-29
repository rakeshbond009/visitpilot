<?php
require_once '../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['fcm_token'])) {
        $token = trim((string) $data['fcm_token']);

        if (empty($token) || strlen($token) < 20) {
            echo json_encode(['success' => false, 'message' => 'Invalid token format']);
            exit;
        }

        try {
            // --- DEBUG LOGGING ---
            $debugLog = __DIR__ . '/fcm_update_debug.log';
            file_put_contents($debugLog, date('[Y-m-d H:i:s] ') . "Updating FCM for User ID {$_SESSION['user_id']}: $token\n", FILE_APPEND);

            // Ensure table exists
            require_once '../../includes/ensure_user_devices.php';

            // --- NEW: ENSURE TOKEN UNIQUENESS ---
            // Remove this token from ANY other user record to prevent "ghost" notifications
            $clear_stmt = $pdo->prepare("DELETE FROM user_devices WHERE fcm_token = ? AND user_id != ?");
            $clear_stmt->execute([$token, $_SESSION['user_id']]);

            // Check if exists
            $check_stmt = $pdo->prepare("SELECT id FROM user_devices WHERE user_id = ? AND fcm_token = ?");
            $check_stmt->execute([$_SESSION['user_id'], $token]);

            if ($check_stmt->fetch()) {
                // Update timestamp
                $stmt = $pdo->prepare("UPDATE user_devices SET last_updated = NOW() WHERE user_id = ? AND fcm_token = ?");
                $stmt->execute([$_SESSION['user_id'], $token]);
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO user_devices (user_id, fcm_token, platform) VALUES (?, ?, 'android')");
                $stmt->execute([$_SESSION['user_id'], $token]);
            }

            // Also update legacy column
            // Clear from others in legacy table too
            $clear_legacy = $pdo->prepare("UPDATE users SET fcm_token = NULL WHERE fcm_token = ? AND id != ?");
            $clear_legacy->execute([$token, $_SESSION['user_id']]);

            $legacy = $pdo->prepare("UPDATE users SET fcm_token = ? WHERE id = ?");
            $legacy->execute([$token, $_SESSION['user_id']]);

            echo json_encode(['success' => true, 'message' => 'FCM token updated successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'fcm_token is required']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>