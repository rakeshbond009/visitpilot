<?php
require 'includes/db.php';
require 'includes/dahua_helper.php';

echo "Requesting Token via SHA512 V2...\n";
$token = DahuaHelper::getAccessToken($pdo);

if ($token) {
    echo "SUCCESS! Token secured: $token\n";
} else {
    echo "FAILED. Check dahua_debug.txt for error.\n";
}
