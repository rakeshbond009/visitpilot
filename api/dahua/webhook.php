<?php
/**
 * Dahua DoLynk Webhook Listener
 * Receives real-time access events from Dahua hardware via Cloud Message.
 */

// Load database and helper
require_once '../../includes/db.php';
require_once '../../includes/dahua_helper.php';

// Log incoming request for debugging
$payload = file_get_contents('php://input');
$headers = getallheaders();

// Dahua sends JSON payloads
$data = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    die('Invalid payload');
}

// Get tenant from URL (?tenant=siddhi)
$tenant_key = $_GET['tenant'] ?? 'default';

// Process the event
try {
    $result = DahuaHelper::processEvent($data, $tenant_key);
    
    if ($result) {
        http_response_code(200);
        echo json_encode(['status' => 'success']);
    } else {
        // Event received but not processed (e.g., person not found)
        http_response_code(200); 
        echo json_encode(['status' => 'ignored']);
    }
} catch (Exception $e) {
    error_log("Dahua Webhook Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
