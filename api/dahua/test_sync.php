<?php
require '../../includes/db.php';
require '../../includes/dahua_helper.php';

$id = $_GET['id'] ?? 395;

echo "--- DAHUA REMOTE SYNC TEST (HOSTED) ---\n";
echo "Target Visit ID: $id\n";

$success = DahuaHelper::syncVisitor($id, $pdo);

if ($success) {
    echo "SUCCESS: The fixed V1 Face Sync has been pushed to your hardware from the hosted server.\n";
} else {
    echo "FAILED: Check your hosted dahua_debug.txt for the specific error.\n";
}
