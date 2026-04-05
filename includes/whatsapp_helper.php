<?php
/**
 * VMS WhatsApp Helper
 * Automates sending WhatsApp messages via Meta WhatsApp Cloud API
 */

function sendWhatsAppNotification($mobile, $message, $templateName = null, $templateParams = [], $headerDocumentUrl = null, $headerText = null)
{
    // Clean mobile number (remove everything except digits)
    $cleanMobile = preg_replace('/[^0-9]/', '', $mobile);

    // Indian logic: If 10 digits, add '91' country code
    // Special case: If 10 digits and starts with '91', it's ambiguous. 
    // But most Indian numbers start with 7, 8, or 9.
    if (strlen($cleanMobile) === 10) {
        $cleanMobile = '91' . $cleanMobile;
    }
    // If 12 digits and starts with 91, it's already full
    elseif (strlen($cleanMobile) === 12 && strpos($cleanMobile, '91') === 0) {
        // Keep as is
    }

    // --- CONFIGURATION ---
    // Fetch settings from DB
    global $pdo;
    $config = [];
    if (isset($pdo) && $pdo) {
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'whatsapp_%'");
            $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            error_log("WhatsApp settings fetch error: " . $e->getMessage());
        }
    }

    $accessToken = $config['whatsapp_access_token'] ?? '';
    $phoneNumberId = $config['whatsapp_phone_number_id'] ?? '';
    $enabledProcessesStr = $config['whatsapp_enabled_processes'] ?? '["visitor_arrival_host_alert","visitor_otp_verification","visit_approval_visitor_notify","visit_rejection_visitor_notify","visitor_meet_notify","invite_cancelled"]';

    $isLive = !empty($accessToken) && !empty($phoneNumberId);

    // Check if process is enabled
    $isProcessEnabled = false;
    if ($templateName) {
        $enabledOptions = json_decode($enabledProcessesStr, true);
        if (is_array($enabledOptions)) {
            $isProcessEnabled = in_array($templateName, $enabledOptions);
        } else {
            // If settings are missing or invalid, we default to false for security/compliance
            $isProcessEnabled = false;
        }
    } else {
        // If no template (direct message), allow it if live
        $isProcessEnabled = true;
    }

    if ($isLive && $templateName) {
        if (!$isProcessEnabled) {
            $log_skip = "[" . date('Y-m-d H:i:s') . "] SKIP: Template $templateName to $cleanMobile (Process disabled in admin settings)\n";
            file_put_contents(__DIR__ . '/../whatsapp_log.txt', $log_skip, FILE_APPEND);
            return 'skipped_disabled'; // Return standard string instead of true so caller knows
        }

        $log_entry = "[" . date('Y-m-d H:i:s') . "] CALL: Template $templateName to $cleanMobile\n";
        file_put_contents(__DIR__ . '/../whatsapp_log.txt', $log_entry, FILE_APPEND);

        // Use configured template language from DB, default to 'en'
        $lang = $config['whatsapp_template_language'] ?? 'en';

        // Specific override if needed, though DB setting should generally rule
        if ($lang === 'en_US' && str_contains($templateName, 'meet')) {
            $lang = 'en';
        }

        return sendWhatsAppTemplate($cleanMobile, $templateName, $templateParams, $accessToken, $phoneNumberId, $headerDocumentUrl, $lang, $headerText);
    }

    if (!$isLive) {
        $log_entry = "[" . date('Y-m-d H:i:s') . "] NOTICE: WhatsApp Config Incomplete (Token: " . (empty($accessToken) ? 'MISSING' : 'OK') . ", ID: " . (empty($phoneNumberId) ? 'MISSING' : 'OK') . ")\n";
        file_put_contents(__DIR__ . '/../whatsapp_log.txt', $log_entry, FILE_APPEND);
        return 'skipped_not_live';
    }

    // Fallback: Log for debugging if not live or no template provided
    $log_type = $templateName ? "Template: $templateName" : "Message";
    $log_params = !empty($templateParams) ? " | Params: " . implode(', ', $templateParams) : "";
    $log_message = "[" . date('Y-m-d H:i:s') . "] FALLBACK_LOG: To: $cleanMobile | $log_type: $message $log_params\n";
    file_put_contents(__DIR__ . '/../whatsapp_log.txt', $log_message, FILE_APPEND);

    return true;
}

/**
 * Uploads a file to Meta Media API and returns the media ID
 */
function getMetaMediaId($filePath, $accessToken, $phoneNumberId)
{
    if (!file_exists($filePath)) {
        error_log("Meta Media Upload Error: File does not exist at $filePath");
        file_put_contents(__DIR__ . '/../whatsapp_log.txt', "[" . date('Y-m-d H:i:s') . "] TRACE: Media file not found: $filePath\n", FILE_APPEND);
        return null;
    }

    $url = "https://graph.facebook.com/v18.0/$phoneNumberId/media";
    $mimeType = mime_content_type($filePath);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // PHP 5.5+ curl_file_create
    $curlFile = curl_file_create($filePath, $mimeType, basename($filePath));
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'file' => $curlFile,
        'messaging_product' => 'whatsapp'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resData = json_decode($response, true);
    if ($httpCode === 200 && isset($resData['id'])) {
        return $resData['id'];
    }

    $errDetail = "[" . date('Y-m-d H:i:s') . "] TRACE: Meta Media Upload Error ($httpCode): $response | Path: $filePath\n";
    file_put_contents(__DIR__ . '/../whatsapp_log.txt', $errDetail, FILE_APPEND);
    error_log("Meta Media Upload Error ($httpCode): " . $response);
    return null;
}

/**
 * Sends a WhatsApp Template using Meta Cloud API
 */
function sendWhatsAppTemplate($mobile, $templateName, $parameters, $accessToken, $phoneNumberId, $headerDocumentUrl = null, $languageCode = 'en_US', $headerText = null)
{
    global $pdo;
    $url = "https://graph.facebook.com/v18.0/$phoneNumberId/messages";

    $components = [];

    // List of templates known to require a DOCUMENT header
    $templatesThatNeedDoc = ['visit_approval_visitor_notify'];
    $needsDocHeader = in_array($templateName, $templatesThatNeedDoc);

    // Check for Text Header
    if (!empty($headerText)) {
        $components[] = [
            'type' => 'header',
            'parameters' => [
                [
                    'type' => 'text',
                    'text' => $headerText
                ]
            ]
        ];
    }

    // SAFETY ABORT: If this template requires a PDF but none was found in the folder,
    // stop here to avoid sending a broken template or generating a fallback.
    if ($needsDocHeader && empty($headerDocumentUrl)) {
        $abortMsg = "[" . date('Y-m-d H:i:s') . "] ABORT: WhatsApp skipped for '$templateName'. PDF not found in uploads/passes/.\n";
        file_put_contents(__DIR__ . '/../whatsapp_log.txt', $abortMsg, FILE_APPEND);
        return false;
    }

    if ($needsDocHeader) {

        $mediaId = null;

        // If a local URL was provided, try to resolve to local path and upload to Meta
        if (!empty($headerDocumentUrl)) {
            // Resolve URL to local path by looking for 'uploads/'
            if (str_contains($headerDocumentUrl, 'uploads/')) {
                $relativePath = substr($headerDocumentUrl, strpos($headerDocumentUrl, 'uploads/'));
                // Normalize slashes for Windows/Linux consistency
                $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
            }

            if (!empty($relativePath)) {
                $basePath = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR;
                $localPathAttempt = $basePath . $relativePath;
                $localPath = realpath($localPathAttempt);

                if ($localPath) {
                    $mediaId = getMetaMediaId($localPath, $accessToken, $phoneNumberId);
                    if ($mediaId) {
                        $mediaLog = "[" . date('Y-m-d H:i:s') . "] TRACE: PDF Uploaded to Meta. Media ID: $mediaId\n";
                        file_put_contents(__DIR__ . '/../whatsapp_log.txt', $mediaLog, FILE_APPEND);
                    } else {
                        $mediaLog = "[" . date('Y-m-d H:i:s') . "] TRACE: PDF Upload FAILED at Meta API.\n";
                        file_put_contents(__DIR__ . '/../whatsapp_log.txt', $mediaLog, FILE_APPEND);
                    }
                } else {
                    $localPathAttempt = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . $relativePath;
                    $mediaLog = "[" . date('Y-m-d H:i:s') . "] TRACE: Media file not found (realpath failed): $localPathAttempt | CWD: " . getcwd() . "\n";
                    file_put_contents(__DIR__ . '/../whatsapp_log.txt', $mediaLog, FILE_APPEND);
                }
            }
        }

        // Prepare the header component
        $headerComponent = ['type' => 'header', 'parameters' => []];

        if ($mediaId) {
            $headerComponent['parameters'][] = [
                'type' => 'document',
                'document' => [
                    'id' => $mediaId,
                    'filename' => 'VisitorPass.pdf'
                ]
            ];
        } else {
            // FALLBACK: If upload failed or no local path, use a public link or the template handle
            // Note: Public links often fail on localhost, handles expire. Media ID is preferred.
            $fallbackUrl = 'https://scontent.whatsapp.net/v/t61.29466-34/637101546_1464613275160503_7644617372404680691_n.pdf?ccb=1-7&_nc_sid=8b1bef&_nc_ohc=pnI5cUdZg3gQ7kNvwF4M2NH&_nc_oc=AdkduWU3Fjw1NboB33ypyovUkR-JM4dlIm8mInI7DhLRn1onzcdl4LwPYVWMqVT2wfIOrYT1H6IxUD3xwPXjbsqh&_nc_zt=3&_nc_ht=scontent.whatsapp.net&edm=AH51TzQEAAAA&_nc_gid=cuz9hKTBJhDU5HhCj9_IyA&_nc_tpa=Q5bMBQFvvLsa5o-mkuqsxPtslymL_cczI28Iyh0SFm6MN3CfClqJRpKOFdY-3v-fJjbmVSOXccIg1wHSWw&oh=01_Q5Aa4AFb7G1LXPKMbxWa8DyOqYcT9008RIlRJaZz-sV1SDCgeQ&oe=69DCCFF9';
            $headerComponent['parameters'][] = [
                'type' => 'document',
                'document' => [
                    'link' => $fallbackUrl,
                    'filename' => 'VisitorPass.pdf'
                ]
            ];
        }
        $components[] = $headerComponent;
    }

    if (!empty($parameters)) {
        $params = [];
        foreach ($parameters as $param) {
            $params[] = ['type' => 'text', 'text' => (string) $param];
        }
        $components[] = [
            'type' => 'body',
            'parameters' => $params
        ];

        // Ensure the button parameter is present for the visitor_otp_verification template
        if ($templateName === 'visitor_otp_verification' && isset($parameters[0])) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => '0',
                'parameters' => [
                    ['type' => 'text', 'text' => (string) $parameters[0]]
                ]
            ];
        }
    }

    // Language Fallback Logic: Try multiple common languages if the requested one fails
    // Code 132001 means "Template name does not exist in the translation"
    $languagesToTry = [$languageCode, 'en', 'en_US', 'en_GB'];
    $languagesToTry = array_unique($languagesToTry);

    $lastResponse = null;
    $lastHttpCode = 0;

    foreach ($languagesToTry as $currentLangCode) {
        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $mobile,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $currentLangCode],
                'components' => $components
            ]
        ];

        $jsonPayload = json_encode($data);

        // TRACE: Log exact payload for debugging
        $traceLog = "[" . date('Y-m-d H:i:s') . "] TRACE (Payload): To: $mobile | Language: $currentLangCode | JSON: $jsonPayload\n";
        file_put_contents(__DIR__ . '/../whatsapp_log.txt', $traceLog, FILE_APPEND);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $lastResponse = $response;
        $lastHttpCode = $httpCode;

        $resData = json_decode($response, true);
        $errorCode = $resData['error']['code'] ?? null;

        if ($httpCode >= 200 && $httpCode < 300) {
            $log_ok = "[" . date('Y-m-d H:i:s') . "] SUCCESS ($httpCode): Template $templateName sent to $mobile (Lang: $currentLangCode)\n";
            file_put_contents(__DIR__ . '/../whatsapp_log.txt', $log_ok, FILE_APPEND);

            try {
                $user_id = $_SESSION['user_id'] ?? null;
                $msg = "WhatsApp Sent: Template [$templateName] to [$mobile] (Lang: $currentLangCode)";
                $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, ip_address) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $msg, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
            } catch (Exception $log_e) {
            }

            return true;
        }

        // Check for template translation error
        if ($errorCode == 132001) {
            $log_retry = "[" . date('Y-m-d H:i:s') . "] RETRY: Template not found in '$currentLangCode'. Trying fallback...\n";
            file_put_contents(__DIR__ . '/../whatsapp_log.txt', $log_retry, FILE_APPEND);
            continue;
        }

        // Any other error (Auth, Invalid Number), break the loop
        break;
    }

    $error_msg = "[" . date('Y-m-d H:i:s') . "] API ERROR ($lastHttpCode): $lastResponse | Template: $templateName\n";
    file_put_contents(__DIR__ . '/../whatsapp_log.txt', $error_msg, FILE_APPEND);

    try {
        $user_id = $_SESSION['user_id'] ?? null;
        $msg = "WhatsApp FAILED: Template [$templateName] to [$mobile]. Error: $lastHttpCode";
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, ip_address) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $msg, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);
    } catch (Exception $log_e) {
    }

    return false;
}

/**
 * Helper for string operations
 */
function vms_str_after($haystack, $needle)
{
    $pos = strpos($haystack, $needle);
    return $pos === false ? '' : substr($haystack, $pos + strlen($needle));
}
