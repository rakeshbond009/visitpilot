<?php
require_once '../includes/api_header.php';

$employee_id = $_GET['employee_id'] ?? '';
$mobile = $_GET['mobile'] ?? '';
$otp = $_GET['otp'] ?? '';

if ((!$employee_id && !$mobile) || !$otp) {
    sendResponse('error', 'Missing verification data (mobile/host and otp required)');
}

try {
    $target_mobile = '';

    if ($mobile) {
        $target_mobile = preg_replace('/[^0-9]/', '', $mobile);
    }
    else {
        // Get host mobile
        $stmt = $pdo->prepare("SELECT mobile FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $host = $stmt->fetch();
        if (!$host) {
            sendResponse('error', 'Host not found');
        }
        $target_mobile = preg_replace('/[^0-9]/', '', $host['mobile']);
    }

    // Check OTP with detailed error reporting
    // Select the most recent OTP attempt
    $stmt = $pdo->prepare("
        SELECT id, verified, expires_at, 
               CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END as is_active 
        FROM visit_otps 
        WHERE mobile = ? AND otp = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$target_mobile, $otp]);
    $record = $stmt->fetch();

    if ($record) {
        if ($record['verified'] == 1) {
            sendResponse('error', 'OTP already verified');
        }
        elseif ($record['is_active'] == 0) {
            sendResponse('error', 'OTP has expired');
        }
        else {
            // Valid and Active
            $stmt = $pdo->prepare("UPDATE visit_otps SET verified = 1 WHERE id = ?");
            $stmt->execute([$record['id']]);
            sendResponse('success', 'OTP verified successfully');
        }
    }
    else {
        sendResponse('error', 'Invalid OTP');
    }

}
catch (Exception $e) {
    sendResponse('error', 'Verification error: ' . $e->getMessage());
}
