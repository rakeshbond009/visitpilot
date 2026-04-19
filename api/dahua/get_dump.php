<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/dahua_helper.php';

header('Content-Type: application/json');

try {
    // Step 1: Get people list
    $list = DahuaHelper::getPeopleList($pdo, null);
    $list_data   = $list['data'] ?? $list;
    $people_list = $list_data['pageData'] ?? $list_data['list'] ?? (is_array($list_data) ? array_values($list_data) : []);

    $output = [
        'list_raw_keys' => array_keys((array)$list),
        'data_keys'     => array_keys((array)$list_data),
        'people_count'  => count($people_list),
        'people_stubs'  => array_slice($people_list, 0, 3),
        'details'       => []
    ];

    // Step 2: For first 3 users, get full detail
    foreach (array_slice($people_list, 0, 3) as $stub) {
        $pid = $stub['personId'] ?? $stub['userId'] ?? null;
        $dev = $stub['deviceId'] ?? null;
        if (!$pid) continue;
        $detail = DahuaHelper::getPersonDetail($dev, $pid, $pdo);
        $output['details'][$pid] = $detail;
    }

    // Also read debug log tail
    $logFile = dirname(dirname(__DIR__)) . '/dahua_debug.txt';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $output['recent_logs'] = array_slice($lines, -20);
    }

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
