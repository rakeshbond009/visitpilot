<?php
// api/employee/save.php
require_once '../includes/api_header.php';

$id = $_POST['id'] ?? null;
$name = $_POST['name'] ?? '';
$department = $_POST['department'] ?? '';
$email = $_POST['email'] ?? '';
$mobile = $_POST['mobile'] ?? '';

if (empty($name) || empty($department)) {
    sendResponse('error', 'Name and Department are required');
}

try {
    if ($id) {
        // Update
        $stmt = $pdo->prepare("UPDATE employees SET name = ?, department = ?, email = ?, mobile = ? WHERE id = ?");
        $stmt->execute([$name, $department, $email, $mobile, $id]);
        sendResponse('success', 'Employee updated successfully');
    } else {
        // Add
        $stmt = $pdo->prepare("INSERT INTO employees (name, department, email, mobile, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute([$name, $department, $email, $mobile]);
        sendResponse('success', 'Employee added successfully');
    }
} catch (Exception $e) {
    sendResponse('error', 'Database error: ' . $e->getMessage());
}
