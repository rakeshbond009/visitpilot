<?php
require_once '../../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data['bg_mode'])) {
        $bg_mode = $data['bg_mode'] ? 1 : 0;

        try {
            $stmt = $pdo->prepare("UPDATE users SET bg_mode = ? WHERE id = ?");
            $executed = $stmt->execute([$bg_mode, $_SESSION['user_id']]);
            $affected = $stmt->rowCount();

            // Update session too
            $_SESSION['bg_mode'] = $bg_mode;

            echo json_encode([
                'success' => true,
                'bg_mode' => $bg_mode,
                'user_id' => $_SESSION['user_id'],
                'affected_rows' => $affected
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid data', 'received' => $data]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>