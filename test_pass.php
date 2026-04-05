<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pass_pdf_helper.php';

// Try to find a recent visit
$stmt = $pdo->prepare("SELECT id FROM visits ORDER BY id DESC LIMIT 1");
$stmt->execute();
$visit = $stmt->fetch();

if ($visit) {
    $visit_id = $visit['id'];
    echo "Testing PDF generation for Visit ID: $visit_id...\n";
    $url = generatePassPdf($visit_id, $pdo);
    if ($url) {
        echo "SUCCESS! PDF URL: $url\n";
    } else {
        echo "FAILED generation.\n";
    }
} else {
    echo "No visits found in database.\n";
}
