<?php
/**
 * VMS Email Helper
 * Uses SMTP settings from system_settings to send emails.
 */

require_once __DIR__ . '/db.php';

function sendVMSEmail($to, $subject, $body, $replyTo = null) {
    global $pdo;

    // 1. Fetch Email Settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'smtp_%' OR setting_key = 'company_email'");
    $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $smtp_host = $config['smtp_host'] ?? '';
    $smtp_port = $config['smtp_port'] ?? '587';
    $smtp_user = $config['smtp_user'] ?? '';
    $smtp_pass = $config['smtp_pass'] ?? '';
    $smtp_enc  = $config['smtp_enc'] ?? 'tls';
    $from_email = $config['smtp_from_email'] ?? ($config['company_email'] ?? '');
    $from_name = $config['smtp_from_name'] ?? 'VisitPilot';

    if (empty($smtp_host) || empty($smtp_user)) {
        // Fallback to mail() if SMTP not configured
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: " . $from_name . " <" . $from_email . ">" . "\r\n";
        if ($replyTo) {
            $headers .= "Reply-To: " . $replyTo . "\r\n";
        }
        return @mail($to, $subject, $body, $headers);
    }

    return sendBasicSMTP($to, $subject, $body, $from_email, $from_name, $smtp_host, $smtp_port, $smtp_user, $smtp_pass, $smtp_enc, $replyTo);
}

function _vms_get_smtp_response($socket) {
    $res = "";
    while ($str = fgets($socket, 515)) {
        $res .= $str;
        if (substr($str, 3, 1) == " ") break;
    }
    return $res;
}

function sendBasicSMTP($to, $subject, $body, $from_email, $from_name, $host, $port, $user, $pass, $enc, $replyTo = null) {
    $timeout = 10;
    $localhost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $newLine = "\r\n";

    $secure_host = ($enc === 'ssl') ? 'ssl://' . $host : $host;
    $socket = @fsockopen($secure_host, $port, $errno, $errstr, $timeout);

    if (!$socket) {
        error_log("SMTP Error: $errstr ($errno)");
        return false;
    }

    _vms_get_smtp_response($socket); // 220
    fwrite($socket, "EHLO $localhost" . $newLine);
    _vms_get_smtp_response($socket);

    if ($enc === 'tls') {
        fwrite($socket, "STARTTLS" . $newLine);
        _vms_get_smtp_response($socket);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_ANY_CLIENT)) {
            fclose($socket);
            return false;
        }
        fwrite($socket, "EHLO $localhost" . $newLine);
        _vms_get_smtp_response($socket);
    }

    fwrite($socket, "AUTH LOGIN" . $newLine);
    _vms_get_smtp_response($socket);
    fwrite($socket, base64_encode($user) . $newLine);
    _vms_get_smtp_response($socket);
    fwrite($socket, base64_encode($pass) . $newLine);
    $res = _vms_get_smtp_response($socket);
    if (substr($res, 0, 3) != "235") {
        fwrite($socket, "QUIT" . $newLine);
        fclose($socket);
        return false;
    }

    fwrite($socket, "MAIL FROM: <$from_email>" . $newLine);
    _vms_get_smtp_response($socket);
    fwrite($socket, "RCPT TO: <$to>" . $newLine);
    _vms_get_smtp_response($socket);
    fwrite($socket, "DATA" . $newLine);
    _vms_get_smtp_response($socket);

    $headers = "MIME-Version: 1.0" . $newLine;
    $headers .= "Content-type: text/html; charset=UTF-8" . $newLine;
    $headers .= "To: <$to>" . $newLine;
    $headers .= "From: $from_name <$from_email>" . $newLine;
    $headers .= "Subject: $subject" . $newLine;
    if ($replyTo) {
        $headers .= "Reply-To: $replyTo" . $newLine;
    }
    $headers .= "Date: " . date('r') . $newLine;

    fwrite($socket, $headers . $newLine . $body . $newLine . "." . $newLine);
    _vms_get_smtp_response($socket);
    fwrite($socket, "QUIT" . $newLine);
    fclose($socket);

    return true;
}
