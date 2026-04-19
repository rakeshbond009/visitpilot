<?php
require_once 'includes/db.php';
requireLogin();

echo "<h3>Security & Permission Debugger</h3>";
echo "<strong>Current User ID:</strong> " . ($_SESSION['user_id'] ?? 'N/A') . "<br>";
echo "<strong>Session Role:</strong> " . ($_SESSION['role'] ?? 'N/A') . "<br>";
echo "<strong>Locked to Custom:</strong> " . (($_SESSION['permissions_locked'] ?? false) ? 'YES' : 'NO') . "<br>";

echo "<h4>1. Loaded Custom Permissions (from Session):</h4>";
echo "<pre style='background:#f4f4f4; padding:10px;'>";
print_r($_SESSION['my_perms'] ?? []);
echo "</pre>";

echo "<h4>2. Loaded Role Defaults (from Session):</h4>";
echo "<pre style='background:#f4f4f4; padding:10px;'>";
print_r($_SESSION['role_perms'] ?? []);
echo "</pre>";

echo "<h4>3. Real-time DB Lookup (Active Data):</h4>";
$stmt = $pdo->prepare("SELECT permission_key FROM user_permissions WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$live_perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "<strong>Explicit permissions in DB for this user:</strong><br>";
echo "<pre style='background:#eef; padding:10px;'>";
print_r($live_perms);
echo "</pre>";

echo "<h4>4. Access Test:</h4>";
$test_key = 'view_hardware_logs';
if (canView($test_key)) {
    echo "<div style='color:green; padding:15px; border:2px solid green;'><strong>SUCCESS:</strong> canView('$test_key') is TRUE.</div>";
} else {
    echo "<div style='color:red; padding:15px; border:2px solid red;'><strong>FAILED:</strong> canView('$test_key') is FALSE. User cannot see logs.</div>";
}
?>
