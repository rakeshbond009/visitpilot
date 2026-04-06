<?php
/**
 * Background Job Functions
 * Called inline on Hostinger (FastCGI) OR via background_worker.php on Apache.
 */

if (!defined('BASE_URL')) {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if ($host === 'localhost' && !empty($_SERVER['SERVER_NAME']))
        $host = $_SERVER['SERVER_NAME'];
    define('BASE_URL', $protocol . $host . '/');
}

// Ensure all background dependencies are loaded
require_once __DIR__ . '/../../includes/push_helper.php';
require_once __DIR__ . '/../../includes/dahua_helper.php';


function runJob_registerVisitor($pdo, $payload)
{
    $visit_id = $payload['visit_id'] ?? 0;
    $visitor_id = $payload['visitor_id'] ?? 0;
    $visit_code = $payload['visit_code'] ?? '';
    $photo_path = $payload['photo_path'] ?? '';
    $employee_id = $payload['employee_id'] ?? 0;
    $purpose = $payload['purpose'] ?? '';
    $visitor_name = $payload['visitor_name'] ?? '';
    $visitor_mobile = $payload['visitor_mobile'] ?? '';
    $visitor_address = $payload['visitor_address'] ?? '';
    $assets = $payload['assets'] ?? 'None';

    _vms_log("Job registerVisitor starting: visit_id $visit_id");

    // 1. QR Code
    if ($visit_code) {
        _vms_log("Generating QR for $visit_code");
        bgHelper_generateQrCode($visit_code, $visit_id, $pdo);
    }

    // 2. FCM Push to host
    try {
        _vms_log("Sending FCM Push to host $employee_id");
        require_once dirname(__DIR__) . '/../includes/push_helper.php';
        $pushData = [
            'visitor_id' => (string) $visitor_id,
            'visit_id' => (string) $visit_id,
            'visitor_name' => $visitor_name,
            'visitor_mobile' => $visitor_mobile,
            'purpose' => $purpose,
            'company' => $visitor_address ?: 'General Visitor',
            'photo_url' => $photo_path ? (defined('BASE_URL') ? BASE_URL : '') . $photo_path : '',
            'type' => 'visitor_arrival',
            'assets_carried' => $assets,
        ];
        sendPushNotification($pdo, $employee_id, "New Visitor Arrival", "$visitor_name is waiting for your approval.", $pushData);
        _vms_log("FCM Push sent to host $employee_id");
    } catch (Throwable $e) {
        _vms_log("FCM register error: " . $e->getMessage());
    }

    // 3. WhatsApp to host
    try {
        _vms_log("Sending WhatsApp to host $employee_id");
        require_once dirname(__DIR__) . '/../includes/whatsapp_helper.php';
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
            _vms_log("WhatsApp sent to host mobile " . $host['mobile']);
        }
    } catch (Throwable $e) {
        _vms_log("WhatsApp register error: " . $e->getMessage());
    }

    // 4. Dahua sync (invitation flow only)
    if (!empty($payload['sync_dahua'])) {
        bgHelper_syncDahua($pdo, $visit_id, $visitor_id, $visitor_name, $photo_path, $visit_code);
    }
}

function runJob_approveVisit($pdo, $payload)
{
    $visit_id = $payload['visit_id'] ?? 0;

    $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name
                           FROM visits v
                           JOIN visitors vis ON v.visitor_id = vis.id
                           LEFT JOIN employees e ON v.employee_id = e.id
                           WHERE v.id = ?");
    $stmt->execute([$visit_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit)
        return;

    // 1. PDF
    $pdfUrl = null;
    try {
        require_once dirname(__DIR__) . '/../includes/pass_pdf_helper.php';
        $pdfUrl = generatePassPdf($visit_id, $pdo);
    } catch (Throwable $e) {
        error_log("[BG] PDF error: " . $e->getMessage());
    }

    // 2. WhatsApp to visitor
    try {
        require_once dirname(__DIR__) . '/../includes/whatsapp_helper.php';
        sendWhatsAppNotification(
            $visit['mobile'],
            "Your visit is approved",
            'visit_approval_visitor_notify',
            ["*{$visit['visitor_name']}*"],
            $pdfUrl
        );
    } catch (Throwable $e) {
        error_log("[BG] WhatsApp approve error: " . $e->getMessage());
    }

    // 3. FCM to security
    try {
        require_once __DIR__ . '/../../includes/push_helper.php';
        sendPushNotificationToRole(
            $pdo,
            'security',
            "Visitor Approved",
            "Host {$visit['host_name']} approved visit for {$visit['visitor_name']}.",
            ['visit_id' => (string) $visit_id, 'type' => 'visit_update'],
            $visit['created_by'] ?? null
        );
    } catch (Throwable $e) {
        error_log("[BG] FCM approve error: " . $e->getMessage());
    }

    // 5. FCM to Creator (Notify the person who created the visit)
    try {
        $creator_id = $visit['created_by'] ?? null;
        if ($creator_id) {
            // --- DEBUG LOGGING ---
            $logFile = __DIR__ . '/../../approval_debug.log';
            file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "[BG] Notifying Creator $creator_id for Approval of Visit $visit_id\n", FILE_APPEND);
            
            require_once __DIR__ . '/../../includes/push_helper.php';
            sendPushNotificationToUser(
                $pdo,
                $creator_id,
                'Visit Approved',
                "Your visit for {$visit['visitor_name']} has been approved by the host.",
                ['visit_id' => (string) $visit_id, 'type' => 'visit_update']
            );
        }
    } catch (Throwable $e) {
        error_log("[BG] FCM creator approve error: " . $e->getMessage());
    }

    // 4. Dahua
    bgHelper_syncDahua($pdo, $visit_id, $visit['visitor_id'], $visit['visitor_name'], $visit['visit_photo'] ?? '', $visit['visit_code'] ?? '');
}

function runJob_rejectVisit($pdo, $payload)
{
    $visit_id = $payload['visit_id'] ?? 0;
    $reason = $payload['reason'] ?? 'Host declined the visit.';

    $stmt = $pdo->prepare("SELECT v.*, vis.mobile, vis.name as visitor_name, e.name as host_name
                           FROM visits v
                           JOIN visitors vis ON v.visitor_id = vis.id
                           LEFT JOIN employees e ON v.employee_id = e.id
                           WHERE v.id = ?");
    $stmt->execute([$visit_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$visit)
        return;

    try {
        require_once dirname(__DIR__) . '/../includes/whatsapp_helper.php';
        sendWhatsAppNotification(
            $visit['mobile'],
            "Your visit request has been declined.",
            'visit_rejection_visitor_notify',
            ["*{$visit['visitor_name']}*", "*{$reason}*"]
        );
    } catch (Throwable $e) {
        error_log("[BG] WhatsApp reject error: " . $e->getMessage());
    }

    try {
        require_once __DIR__ . '/../../includes/push_helper.php';
        sendPushNotificationToRole(
            $pdo,
            'security',
            "Visitor Rejected",
            "Host {$visit['host_name']} REJECTED visit for {$visit['visitor_name']}.",
            ['visit_id' => (string) $visit_id, 'type' => 'visit_update'],
            $visit['created_by'] ?? null
        );
    } catch (Throwable $e) {
        error_log("[BG] FCM reject error: " . $e->getMessage());
    }

    // 3. FCM to Creator (Notify the person who created the visit)
    try {
        $creator_id = $visit['created_by'] ?? null;
        if ($creator_id) {
            // --- DEBUG LOGGING ---
            $logFile = __DIR__ . '/../../approval_debug.log';
            file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "[BG] Notifying Creator $creator_id for Rejection of Visit $visit_id\n", FILE_APPEND);

            require_once __DIR__ . '/../../includes/push_helper.php';
            sendPushNotificationToUser(
                $pdo,
                $creator_id,
                'Visit Rejected',
                "Your visit request for {$visit['visitor_name']} has been rejected by the host. Reason: $reason",
                ['visit_id' => (string) $visit_id, 'type' => 'visit_update']
            );
        }
    } catch (Throwable $e) {
        error_log("[BG] FCM creator reject error: " . $e->getMessage());
    }
}

function runJob_cancelInvite($pdo, $payload)
{
    $visit_id = $payload['visit_id'] ?? 0;
    $visitor_name = $payload['visitor_name'] ?? '';
    $visitor_mobile = $payload['visitor_mobile'] ?? '';
    $host_name = $payload['host_name'] ?? 'your host';

    try {
        require_once dirname(__DIR__) . '/../includes/whatsapp_helper.php';
        sendWhatsAppNotification(
            $visitor_mobile,
            "Your meeting has been cancelled.",
            'invite_cancelled',
            [$visitor_name, $host_name]
        );
    } catch (Throwable $e) {
        error_log("[BG] WhatsApp cancel error: " . $e->getMessage());
    }

    try {
        require_once dirname(__DIR__) . '/../includes/push_helper.php';
        sendPushNotificationToRole(
            $pdo,
            'security',
            'Invitation Cancelled',
            "Host $host_name CANCELLED invitation for $visitor_name.",
            ['visit_id' => (string) $visit_id, 'type' => 'approval_status']
        );
    } catch (Throwable $e) {
        error_log("[BG] FCM cancel error: " . $e->getMessage());
    }
}

// ── Shared helpers ──────────────────────────────────────────────────────────

function bgHelper_generateQrCode($visit_code, $visit_id, $pdo)
{
    try {
        $ch = curl_init("https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10
        ]);
        $img = curl_exec($ch);
        curl_close($ch);
        if ($img) {
            $dir = dirname(__DIR__) . '/../uploads/qrcodes/';
            if (!is_dir($dir))
                @mkdir($dir, 0777, true);
            $rel = 'uploads/qrcodes/' . $visit_code . '.png';
            file_put_contents($dir . $visit_code . '.png', $img);
            $pdo->prepare("UPDATE visits SET qr_code_path=? WHERE id=?")->execute([$rel, $visit_id]);
        }
    } catch (Throwable $e) {
        error_log("[BG] QR error: " . $e->getMessage());
    }
}

function bgHelper_syncDahua($pdo, $visit_id, $visitor_id, $name, $photo_path, $visit_code)
{
    try {
        require_once dirname(__DIR__) . '/../includes/dahua_helper.php';
        $s = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (empty($s['dahua_app_id']) || empty($s['dahua_app_secret']) || empty($photo_path))
            return;
        $face = realpath(dirname(__DIR__) . '/../' . $photo_path);
        if (!$face)
            return;
        $dahua = new DahuaHelper($s['dahua_app_id'], $s['dahua_app_secret']);
        $dahua->syncVisitor([
            'visitor_id' => $visitor_id,
            'name' => $name,
            'face_path' => $face,
            'qr_code' => $visit_code,
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => date('Y-m-d 23:59:59'),
            'device_sns' => array_map('trim', array_filter(explode(',', $s['dahua_device_sns'] ?? '')))
        ]);
    } catch (Throwable $e) {
        error_log("[BG] Dahua error: " . $e->getMessage());
    }
}
