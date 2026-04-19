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
file_put_contents(dirname(__DIR__, 2) . '/dahua_webhook_log.txt', "[" . date('Y-m-d H:i:s') . "] Payload: " . $payload . "\n", FILE_APPEND);

// Dahua sends JSON payloads
$data = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    die('Invalid payload');
}

// Get tenant from URL (?tenant=siddhi)
$tenant_key = $_GET['tenant'] ?? 'default';

// ── Auto-populate machine_users from webhook event ──────────────────────────
// Since Dahua's management API returns 500 for this device, we build
// machine_users incrementally from the events Dahua already pushes to us.
try {
    $msgBody = $data['msgBody'] ?? [];
    $events  = $msgBody['data'] ?? (isset($msgBody['personId']) ? [$msgBody] : 
               (isset($data['personId']) ? [$data] : []));

    foreach ($events as $event) {
        $personId   = $event['userId'] ?? $event['personId'] ?? null;
        $personName = $event['userName'] ?? $event['name'] ?? null;
        $deviceId   = $event['deviceId'] ?? null;
        $cardNo     = $event['cardNo'] ?? '';

        if ($personId && $deviceId) {
            $pdo->prepare(
                "INSERT INTO machine_users (device_id, person_id, name, card_no, created_at)
                 VALUES (?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                     name    = COALESCE(NULLIF(VALUES(name), ''), name),
                     card_no = COALESCE(NULLIF(VALUES(card_no), ''), card_no),
                     updated_at = NOW()"
            )->execute([$deviceId, $personId, $personName ?: 'Unknown', $cardNo]);
        }
    }
} catch (Exception $e) {
    error_log("machine_users upsert error: " . $e->getMessage());
}
// ────────────────────────────────────────────────────────────────────────────

// Process the event
try {
    $result = DahuaHelper::processEvent($data, $pdo);
    
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
