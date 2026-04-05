<?php
/**
 * Background Worker - Processes heavy async jobs
 *
 * Triggered by api/includes/async_dispatch.php via a non-blocking cURL POST.
 * Runs independently of the original request — the client is already done.
 *
 * Security: Only accepts requests from localhost (127.0.0.1 or ::1).
 * All job data comes from a temp file written by the dispatcher.
 */

// --- Security: Only localhost can call this ---
$caller_ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($caller_ip, ['127.0.0.1', '::1', '::ffff:127.0.0.1'])) {
    http_response_code(403);
    die('Forbidden');
}

// Prevent this from timing out
ignore_user_abort(true);
set_time_limit(120);

// Immediately respond 200 OK so the dispatcher's cURL doesn't wait
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: 2');
header('Connection: close');
echo '{}';
if (ob_get_level()) ob_end_flush();
flush();

// --- Load core ---
require_once __DIR__ . '/../includes/db.php';

// --- Read input ---
$input = json_decode(file_get_contents('php://input'), true);
$jobFile = $input['job_file'] ?? null;

if (!$jobFile || !file_exists($jobFile)) {
    error_log("[BG Worker] Job file not found: $jobFile");
    exit;
}

$payload = json_decode(file_get_contents($jobFile), true);
unlink($jobFile); // Clean up immediately

if (!$payload) {
    error_log("[BG Worker] Invalid payload in job file.");
    exit;
}

$jobType = $payload['__job_type'] ?? 'unknown';
error_log("[BG Worker] Processing job: $jobType (ID: " . ($payload['__job_id'] ?? '?') . ')');

// ===================================================================
// JOB HANDLERS
// ===================================================================

switch ($jobType) {

    // ------------------------------------------------------------------
    // Job: After new visitor registration
    // ------------------------------------------------------------------
    case 'register_visitor':
        $visit_id    = $payload['visit_id']    ?? 0;
        $visitor_id  = $payload['visitor_id']  ?? 0;
        $visit_code  = $payload['visit_code']  ?? '';
        $photo_path  = $payload['photo_path']  ?? '';
        $employee_id = $payload['employee_id'] ?? 0;
        $purpose     = $payload['purpose']     ?? '';
        $visitor_name = $payload['visitor_name'] ?? '';
        $visitor_mobile = $payload['visitor_mobile'] ?? '';
        $assets      = $payload['assets']      ?? 'None';
        $visitor_address = $payload['visitor_address'] ?? '';

        // 1. Generate & save QR code
        if ($visit_code) {
            _generateQrCode($visit_code, $visit_id, $pdo);
        }

        // 2. FCM Push to host
        try {
            require_once __DIR__ . '/../includes/push_helper.php';
            $pushData = [
                'visitor_id'     => (string) $visitor_id,
                'visit_id'       => (string) $visit_id,
                'visitor_name'   => $visitor_name,
                'visitor_mobile' => $visitor_mobile,
                'purpose'        => $purpose,
                'company'        => $visitor_address ?: 'General Visitor',
                'photo_url'      => $photo_path ? (defined('BASE_URL') ? BASE_URL : '') . $photo_path : '',
                'type'           => 'visitor_arrival',
                'assets_carried' => $assets,
            ];
            sendPushNotification($pdo, $employee_id, "New Visitor Arrival", "$visitor_name is waiting for your approval.", $pushData);
        } catch (Throwable $e) {
            error_log("[BG Worker] FCM error (register): " . $e->getMessage());
        }

        // 3. WhatsApp to host
        try {
            require_once __DIR__ . '/../includes/whatsapp_helper.php';
            $hStmt = $pdo->prepare("SELECT mobile, name FROM employees WHERE id = ?");
            $hStmt->execute([$employee_id]);
            $host = $hStmt->fetch(PDO::FETCH_ASSOC);
            if ($host && !empty($host['mobile'])) {
                sendWhatsAppNotification(
                    $host['mobile'],
                    "Visitor $visitor_name has arrived to meet you.",
                    'visitor_arrival_host_alert',
                    ["*{$host['name']}*", "*{$visitor_name}*", "*{$purpose}*"]
                );
            }
        } catch (Throwable $e) {
            error_log("[BG Worker] WhatsApp error (register): " . $e->getMessage());
        }

        // 4. Dahua sync only if visit is pre-approved (invitation flow)
        if (!empty($payload['sync_dahua'])) {
            _syncDahua($pdo, $visit_id, $visitor_id, $payload['visitor_name'], $photo_path, $visit_code);
        }
        break;

    // ------------------------------------------------------------------
    // Job: After host approves a visit
    // ------------------------------------------------------------------
    case 'approve_visit':
        $visit_id = $payload['visit_id'] ?? 0;

        // Fetch visit details
        $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name
                               FROM visits v
                               JOIN visitors vis ON v.visitor_id = vis.id
                               LEFT JOIN employees e ON v.employee_id = e.id
                               WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) break;

        // 1. Generate PDF pass
        try {
            require_once __DIR__ . '/../includes/pass_pdf_helper.php';
            $pdfUrl = generatePassPdf($visit_id, $pdo);
        } catch (Throwable $e) {
            error_log("[BG Worker] PDF error (approve): " . $e->getMessage());
            $pdfUrl = null;
        }

        // 2. WhatsApp to visitor
        try {
            require_once __DIR__ . '/../includes/whatsapp_helper.php';
            sendWhatsAppNotification(
                $visit['mobile'],
                "Your visit is approved",
                'visit_approval_visitor_notify',
                ["*{$visit['visitor_name']}*"],
                $pdfUrl
            );
        } catch (Throwable $e) {
            error_log("[BG Worker] WhatsApp error (approve): " . $e->getMessage());
        }

        // 3. Push to security
        try {
            require_once __DIR__ . '/../includes/push_helper.php';
            sendPushNotificationToRole($pdo, 'security', 'Visit Approved',
                "Host {$visit['host_name']} approved visit for {$visit['visitor_name']}.",
                ['visit_id' => (string) $visit_id, 'type' => 'approval_status']
            );
        } catch (Throwable $e) {
            error_log("[BG Worker] FCM error (approve): " . $e->getMessage());
        }

        // 4. Dahua sync
        _syncDahua($pdo, $visit_id, $visit['visitor_id'], $visit['visitor_name'], $visit['visit_photo'] ?? '', $visit['visit_code'] ?? '');
        break;

    // ------------------------------------------------------------------
    // Job: After host rejects a visit
    // ------------------------------------------------------------------
    case 'reject_visit':
        $visit_id = $payload['visit_id'] ?? 0;
        $reason   = $payload['reason']   ?? 'Host declined the visit.';

        $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name
                               FROM visits v
                               JOIN visitors vis ON v.visitor_id = vis.id
                               LEFT JOIN employees e ON v.employee_id = e.id
                               WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) break;

        // 1. WhatsApp to visitor
        try {
            require_once __DIR__ . '/../includes/whatsapp_helper.php';
            sendWhatsAppNotification(
                $visit['mobile'],
                "Your visit request has been declined.",
                'visit_rejection_visitor_notify',
                ["*{$visit['visitor_name']}*", "*{$reason}*"]
            );
        } catch (Throwable $e) {
            error_log("[BG Worker] WhatsApp error (reject): " . $e->getMessage());
        }

        // 2. Push to security
        try {
            require_once __DIR__ . '/../includes/push_helper.php';
            sendPushNotificationToRole($pdo, 'security', 'Visit Rejected',
                "Host {$visit['host_name']} REJECTED visit for {$visit['visitor_name']}.",
                ['visit_id' => (string) $visit_id, 'type' => 'approval_status']
            );
        } catch (Throwable $e) {
            error_log("[BG Worker] FCM error (reject): " . $e->getMessage());
        }
        break;

    // ------------------------------------------------------------------
    // Job: Cancel invitation
    // ------------------------------------------------------------------
    case 'cancel_invite':
        $visit_id = $payload['visit_id'] ?? 0;
        $visitor_name = $payload['visitor_name'] ?? '';
        $visitor_mobile = $payload['visitor_mobile'] ?? '';
        $host_name = $payload['host_name'] ?? 'your host';

        try {
            require_once __DIR__ . '/../includes/whatsapp_helper.php';
            sendWhatsAppNotification(
                $visitor_mobile,
                "Your meeting has been cancelled.",
                'invite_cancelled',
                [$visitor_name, $host_name]
            );
        } catch (Throwable $e) {
            error_log("[BG Worker] WhatsApp error (cancel): " . $e->getMessage());
        }

        try {
            require_once __DIR__ . '/../includes/push_helper.php';
            sendPushNotificationToRole($pdo, 'security', 'Invitation Cancelled',
                "Host $host_name CANCELLED invitation for $visitor_name.",
                ['visit_id' => (string) $visit_id, 'type' => 'approval_status']
            );
        } catch (Throwable $e) {
            error_log("[BG Worker] FCM error (cancel): " . $e->getMessage());
        }
        break;

    default:
        error_log("[BG Worker] Unknown job type: $jobType");
}

error_log("[BG Worker] Job complete: $jobType");
exit;

// ===================================================================
// SHARED HELPERS
// ===================================================================

function _generateQrCode($visit_code, $visit_id, $pdo)
{
    try {
        $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code);
        $ch = curl_init($qr_api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $qr_image = curl_exec($ch);
        curl_close($ch);

        if ($qr_image) {
            $qr_dir = __DIR__ . '/../uploads/qrcodes/';
            if (!is_dir($qr_dir)) @mkdir($qr_dir, 0777, true);

            $qr_filename = 'uploads/qrcodes/' . $visit_code . '.png';
            file_put_contents(__DIR__ . '/../' . $qr_filename, $qr_image);

            $pdo->prepare("UPDATE visits SET qr_code_path = ? WHERE id = ?")->execute([$qr_filename, $visit_id]);
            error_log("[BG Worker] QR saved: $qr_filename");
        }
    } catch (Throwable $e) {
        error_log("[BG Worker] QR error: " . $e->getMessage());
    }
}

function _syncDahua($pdo, $visit_id, $visitor_id, $name, $photo_path, $visit_code)
{
    try {
        require_once __DIR__ . '/../includes/dahua_helper.php';
        $raw_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

        if (empty($raw_settings['dahua_app_id']) || empty($raw_settings['dahua_app_secret'])) {
            return; // Dahua not configured — skip silently
        }

        if (empty($photo_path)) return;

        $localFacePath = realpath(__DIR__ . '/../' . $photo_path);
        if (!$localFacePath) return;

        $dahua = new DahuaHelper($raw_settings['dahua_app_id'], $raw_settings['dahua_app_secret']);
        $deviceSnsList = array_map('trim', array_filter(explode(',', $raw_settings['dahua_device_sns'] ?? '')));

        $syncResult = $dahua->syncVisitor([
            'visitor_id' => $visitor_id,
            'name'       => $name,
            'face_path'  => $localFacePath,
            'qr_code'    => $visit_code,
            'start_time' => date('Y-m-d H:i:s'),
            'end_time'   => date('Y-m-d 23:59:59'),
            'device_sns' => $deviceSnsList,
        ]);

        if (isset($syncResult['success']) && !$syncResult['success']) {
            error_log("[BG Worker] Dahua sync failed for visit $visit_id: " . ($syncResult['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        error_log("[BG Worker] Dahua error: " . $e->getMessage());
    }
}
