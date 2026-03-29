<?php
// api/support_request.php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

try {
    // 1. Ensure support_requests table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS support_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100),
        email VARCHAR(100),
        subject VARCHAR(100),
        message TEXT,
        created_at DATETIME
    )");

    $name = sanitize($_POST['name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $subject = sanitize($_POST['subject'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    if (empty($name) || empty($email) || empty($subject) || empty($message)) {
        throw new Exception('All fields are required.');
    }

    $current_time = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO support_requests (name, email, subject, message, created_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $email, $subject, $message, $current_time]);

    // --- IMMEDIATE SUCCESS RESPONSE ---
    // This allows the user to navigate away while emails send in background
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    set_time_limit(0);

    ob_start();
    echo json_encode(['status' => 'success', 'message' => 'Support request submitted successfully']);
    $size = ob_get_length();
    header("Content-Length: $size");
    header("Connection: close");
    ob_end_flush();
    ob_flush();
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    flush();

    // From here on, script runs in background
    require_once '../includes/email_helper.php';

    // Fetch config for email content
    $sett_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE (setting_key LIKE 'smtp_%' OR setting_key = 'company_email')");
    $email_config = $sett_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $admin_email = $email_config['company_email'] ?? 'hello@codepilotx.com';
    $from_name = (!empty($email_config['smtp_from_name'])) ? $email_config['smtp_from_name'] : 'VisitPilot';

    $admin_body = "
    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; border: 1px solid #eee; padding: 20px; border-radius: 10px;'>
        <h2 style='color: #0d6efd;'>New Support Request</h2>
        <p>A new support message has been received.</p>
        <hr style='border: 0; border-top: 1px solid #eee;'>
        <p><strong>From:</strong> {$name} &lt;{$email}&gt;</p>
        <p><strong>Subject:</strong> {$subject}</p>
        <p><strong>Message:</strong></p>
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #0d6efd;'>
            " . nl2br($message) . "
        </div>
        <hr style='border: 0; border-top: 1px solid #eee;'>
        <p style='font-size: 0.8rem; color: #999;'>Sent via VisitPilot Management System Infrastructure.</p>
    </div>";

    @sendVMSEmail($admin_email, "Support Request: " . $subject, $admin_body, $name . " <" . $email . ">");

    $customer_body = "
    <div style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; border: 1px solid #eee; padding: 20px; border-radius: 10px;'>
        <h2 style='color: #198754;'>Hello {$name},</h2>
        <p>Thank you for contacting <strong>{$from_name}</strong>. We have received your message regarding '<strong>{$subject}</strong>'.</p>
        <p>Our support team is reviewing your request and will get back to you shortly at this email address.</p>
        <hr style='border: 0; border-top: 1px solid #eee;'>
        <div style='background: #f8f9fa; padding: 15px; border-radius: 8px;'>
            <p style='margin-bottom: 5px; font-weight: bold;'>Summary for your records:</p>
            <p style='font-style: italic; color: #555;'>" . nl2br($message) . "</p>
        </div>
        <p style='margin-top: 20px;'>Best Regards,<br><strong>The {$from_name} Team</strong></p>
    </div>";

    @sendVMSEmail($email, "Acknowledgement: " . $subject, $customer_body);
}
catch (Exception $e) {
    if (!headers_sent()) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } else {
        error_log("BG Support Email Error: " . $e->getMessage());
    }
}
