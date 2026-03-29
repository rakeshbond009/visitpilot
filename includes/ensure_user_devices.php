<?php
// includes/ensure_user_devices.php

try {
    // Check if table exists to avoid overhead of CREATE TABLE IF NOT EXISTS on every request if possible, 
    // though IF NOT EXISTS is quite fast.
    // However, we want to be sure.
    
    $sql = "CREATE TABLE IF NOT EXISTS `user_devices` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `fcm_token` text NOT NULL,
      `platform` varchar(20) DEFAULT 'android',
      `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `user_id` (`user_id`),
      CONSTRAINT `user_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $pdo->exec($sql);
} catch (PDOException $e) {
    // Log error but don't stop execution if table already exists or other non-fatal error
    // If it's a permission error, we can't do much.
    if (isset($debug_log)) {
        file_put_contents($debug_log, date('[Y-m-d H:i:s] ') . "Ensure user_devices table failed: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}
?>