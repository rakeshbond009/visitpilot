<?php
// api/metadata/list.php (Sync Test)
require_once '../includes/api_header.php';

try {
    // Fetch Purposes (Unique names)
    $purposes_stmt = $pdo->query("SELECT MIN(id) as id, purpose_name FROM visit_purposes GROUP BY purpose_name ORDER BY purpose_name ASC");
    $purposes = $purposes_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Access Areas
    $areas_stmt = $pdo->query("SELECT id, area_name, machine_id FROM access_areas ORDER BY area_name ASC");
    $areas = $areas_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Default fallbacks if tables are empty
    if (empty($purposes)) {
        $purposes = [
            ['id' => 1, 'purpose_name' => 'Meeting'],
            ['id' => 2, 'purpose_name' => 'Interview'],
            ['id' => 3, 'purpose_name' => 'Delivery'],
            ['id' => 4, 'purpose_name' => 'Personal'],
            ['id' => 5, 'purpose_name' => 'Maintenance']
        ];
    }

    if (empty($areas)) {
        $areas = [
            ['id' => 1, 'area_name' => 'Reception'],
            ['id' => 2, 'area_name' => 'Conference Room'],
            ['id' => 3, 'area_name' => 'Office Floor'],
            ['id' => 4, 'area_name' => 'Server Room'],
            ['id' => 5, 'area_name' => 'Cafeteria']
        ];
    }

    // Fetch Mandatory Fields
    $mandatory_fields_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'mandatory_registration_fields'");
    $mandatory_fields_val = $mandatory_fields_stmt ? $mandatory_fields_stmt->fetchColumn() : null;
    $mandatory_fields = $mandatory_fields_val ? json_decode($mandatory_fields_val, true) : ["visitor_name","mobile_number","id_proof","purpose","meeting_host","otp_check"];

    sendResponse('success', 'Metadata retrieved', [
        'purposes' => $purposes,
        'areas' => $areas,
        'mandatory_fields' => $mandatory_fields
    ]);

} catch (Exception $e) {
    sendResponse('error', 'Failed to fetch metadata: ' . $e->getMessage());
}
