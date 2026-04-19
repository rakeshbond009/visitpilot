<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/dahua_helper.php';

header('Content-Type: application/json');

try {
    // Step 1: Get people list
    $list = DahuaHelper::getPeopleList($pdo, null);
    $list_data   = $list['data'] ?? $list;
    $people_list = $list_data['pageData'] ?? (is_array($list_data) ? $list_data : []);

    $output = [
        'list_raw_keys' => array_keys((array)$list),
        'data_keys'     => array_keys((array)$list_data),
        'people_count'  => count($people_list),
        'people_stubs'  => $people_list,
        'details'       => []
    ];

    // Step 2: For first 3 users, get full detail
    foreach (array_slice($people_list, 0, 3) as $stub) {
        $pid = $stub['personId'] ?? null;
        if (!$pid) continue;
        $detail = DahuaHelper::getPersonDetail($stub['deviceId'] ?? '', $pid);
        $output['details'][$pid] = $detail;
    }

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
}
