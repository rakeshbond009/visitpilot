<?php
require_once '../includes/api_header.php';

$employee_id = $_GET['employee_id'] ?? '';
$direct_mobile = $_GET['mobile'] ?? '';

if (!$employee_id && !$direct_mobile) {
    sendResponse('error', 'Mobile number or Host selection is required');
}

try {
    $target_mobile = '';
    $host_name = '';

    if ($direct_mobile) {
        $target_mobile = preg_replace('/[^0-9]/', '', $direct_mobile);
    } else {
        // Get host mobile
        $stmt = $pdo->prepare("SELECT mobile, name FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $host = $stmt->fetch();
        if (!$host || empty($host['mobile'])) {
            sendResponse('error', 'Host mobile number not found');
        }
        $target_mobile = preg_replace('/[^0-9]/', '', $host['mobile']);
        $host_name = $host['name'];
    }

    $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    // Store OTP in DB
    $stmt = $pdo->prepare("INSERT INTO visit_otps (mobile, otp, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $stmt->execute([$target_mobile, $otp]);

    // --- WHATSAPP AUTOMATION ---
    // Must be called BEFORE sendResponse() because sendResponse calls exit().
    // The 5-second cURL timeout in whatsapp_helper prevents this from hanging users.
    try {
        require_once '../../includes/whatsapp_helper.php';
        $waMsg = "Your verification code is $otp";
        sendWhatsAppNotification($target_mobile, $waMsg, 'visitor_otp_verification', [$otp]);
    } catch (Exception $e) {
        error_log("WhatsApp OTP error: " . $e->getMessage());
        // Non-fatal: OTP is still saved in DB, continue to respond
    }

    $debug_info = [
        'debug_otp' => $otp,
        'target_mobile' => $target_mobile
    ];

    sendResponse('success', 'OTP sent to: ' . $target_mobile, $debug_info);

} catch (Exception $e) {
    sendResponse('error', 'Failed to send OTP: ' . $e->getMessage());
}
