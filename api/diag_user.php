<?php
require_once '../includes/db.php';
header('Content-Type: application/json');

$query = "SELECT id, name, email, role FROM users WHERE name LIKE '%Sarah%' OR name LIKE '%Smith%'";
$result = $conn->query($query);

$users = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

echo json_encode([
    'success' => true,
    'found_users' => $users,
    'message' => count($users) > 0 ? "Found matching users." : "No users named 'Sarah' or 'Smith' found."
]);
?>
