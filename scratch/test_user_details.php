<?php
require_once '../includes/dahua_helper.php';
require_once '../includes/db.php';

header('Content-Type: application/json');

$sn = $_GET['sn'] ?? 'BE10FCDPAJ955DE';
$config = $pdo->query("SELECT * FROM system_settings WHERE setting_key IN ('dahua_app_id', 'dahua_app_secret', 'dahua_device_sns')")->fetchAll(PDO::FETCH_KEY_PAIR);

echo "Starting Search-and-Collect for Device: $sn\n";

// STEP 1: Start Find Users
$startUrl = "https://sgp-dcloud.all-over-world.com/open-api/api-device/person/pageGetPerson"; 
// Wait, the doc says /cgi-bin/log.cgi or /cgi-bin/recordFinder.cgi
// But for Cloud API, we use their specific endpoints.

// Let's try the "doFind" approach but adapted for Cloud or use the correct Cloud aggregate.
// If pageGetPerson 500s, let's check if there's a different endpoint for "User List"

$results = DahuaHelper::getPeopleList($sn, 1, 100);

echo "API Response:\n";
print_r($results);

if (isset($results['data']['list'])) {
    echo "Found " . count($results['data']['list']) . " users.\n";
    foreach ($results['data']['list'] as $p) {
        echo "ID: " . ($p['personId'] ?? 'N/A') . " | Name: " . ($p['name'] ?? 'Unknown') . "\n";
    }
} else {
    echo "No user list found in response.\n";
}
