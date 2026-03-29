<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=vms_db', 'root', '');
$password = password_hash('password123', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'mobiletest'");
if ($stmt->execute([$password])) {
    echo "Password updated for mobiletest\n";
} else {
    echo "Failed to update password\n";
}
?>