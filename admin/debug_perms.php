<?php
require_once '../includes/db.php';
requireLogin();

echo "<h3>Session Debugger</h3>";
echo "<strong>User ID:</strong> " . ($_SESSION['user_id'] ?? 'N/A') . "<br>";
echo "<strong>Role:</strong> " . ($_SESSION['role'] ?? 'N/A') . "<br>";
echo "<strong>Permissions Locked:</strong> " . ($_SESSION['permissions_locked'] ? 'Yes' : 'No') . "<br>";

echo "<h4>Custom Permissions (my_perms):</h4>";
echo "<pre>";
print_r($_SESSION['my_perms'] ?? []);
echo "</pre>";

echo "<h4>Role Permissions (role_perms):</h4>";
echo "<pre>";
print_r($_SESSION['role_perms'] ?? []);
echo "</pre>";

echo "<h4>Checking specifically for 'view_hardware_logs':</h4>";
if (canView('view_hardware_logs')) {
    echo "<span style='color:green; font-weight:bold;'>PASSED: You have this permission.</span>";
} else {
    echo "<span style='color:red; font-weight:bold;'>FAILED: You do NOT have this permission.</span>";
}

echo "<h4>Database Check:</h4>";
$stmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$db_perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "<strong>Actual Permissions in DB for this user:</strong><br>";
echo "<pre>";
print_r($db_perms);
echo "</pre>";
?>
