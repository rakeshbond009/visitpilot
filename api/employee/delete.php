<?php
// api/employee/delete.php
require_once '../includes/api_header.php';

$id = $_POST['id'] ?? null;

if (!$id) {
    sendResponse('error', 'Employee ID is required');
}

try {
    $stmt = $pdo->prepare("UPDATE employees SET status = 'inactive' WHERE id = ?");
    $stmt->execute([$id]);
    sendResponse('success', 'Employee deactivated successfully');
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
