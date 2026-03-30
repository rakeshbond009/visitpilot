<?php
require_once '../../includes/db.php';
requireLogin();

if ($_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized Access.']);
    exit;
}

if (isset($_POST['save_webhook'])) {
    $webhook = $_POST['hostinger_webhook'] ?? '';
    $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('hostinger_webhook', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$webhook]);

    logAction($pdo, $_SESSION['user_id'], "Updated Hostinger Webhook configuration to: $webhook");

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Webhook URL saved successfully!']);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Invalid request.']);
exit;
