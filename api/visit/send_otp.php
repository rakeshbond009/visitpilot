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

    // Send response immediately to allow UI flow to continue
    sendBackgroundResponse('success', 'OTP sent to: ' . $target_mobile, [
        'debug_otp' => $otp,
        'target_mobile' => $target_mobile
    ]);

    // --- BACKGROUND WHATSAPP ---
    try {
        require_once '../../includes/whatsapp_helper.php';
        sendWhatsAppNotification($target_mobile, "Your verification code is $otp", 'visitor_otp_verification', [$otp]);
    } catch (Throwable $e) {
        error_log("Background WhatsApp OTP error: " . $e->getMessage());
    }

} catch (Exception $e) {
    sendResponse('error', 'Failed to send OTP: ' . $e->getMessage());
}
