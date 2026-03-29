<?php
header('Content-Type: application/json');
require_once '../../includes/db.php';

// Allow from host, employee, admin
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$mobile = $_GET['mobile'] ?? '';

if (empty($mobile)) {
    echo json_encode(['success' => false, 'error' => 'Mobile number missing']);
    exit;
}

// Clean mobile (remove 91 prefix if sent as 12 digits, we store clean numbers or handle them uniformly)
// In invite.php, the input is 10 digits.
$cleanMobile = preg_replace('/[^0-9]/', '', $mobile);
if (strlen($cleanMobile) > 10 && substr($cleanMobile, 0, 2) === '91') {
    $cleanMobile = substr($cleanMobile, 2);
}

try {
    $stmt = $pdo->prepare("SELECT name, email FROM visitors WHERE mobile = ? OR mobile = ? LIMIT 1");
    // Check both clean and with 91 just in case of inconsistent storage
    $stmt->execute([$cleanMobile, '91' . $cleanMobile]);
    $visitor = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($visitor) {
        echo json_encode([
            'success' => true,
            'found' => true,
            'data' => $visitor
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'found' => false
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
