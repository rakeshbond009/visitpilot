<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/dahua_helper.php';

try {
    $res = DahuaHelper::getPeopleList($pdo, null);
    header('Content-Type: application/json');
    echo json_encode($res, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
