<?php
// api/employee/list.php
require_once '../includes/api_header.php';

try {
    $stmt = $pdo->query("SELECT id, name, department, email, mobile, status FROM employees WHERE status = 'active' ORDER BY name ASC");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    sendResponse('success', 'Employees retrieved', ['employees' => $employees]);
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
