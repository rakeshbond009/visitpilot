<?php
require 'includes/db.php';
require 'includes/push_helper.php';

try {
    sendPushNotificationToUserId($pdo, 1, 'Test Title', 'Test Body', ['visit_id' => '123']);
    echo "Done";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
