<?php
/**
 * VMS - Pass PDF Lookup Helper
 * Strictly looks for existing PDF files in uploads/passes/
 * Returns the public URL if found, or null if missing.
 */

function generatePassPdf($visit_id, $pdo)
{
    $pdfFileRelative = "uploads/passes/Pass_" . $visit_id . ".pdf";
    $pdfAbsPath = __DIR__ . '/../' . $pdfFileRelative;

    // Check if it already exists
    if (file_exists($pdfAbsPath)) {
        return BASE_URL . $pdfFileRelative;
    }

    // Debug logging consistent with your previous setup
    error_log("[".date('Y-m-d H:i:s')."] Starting generation for Visit ID: $visit_id");

    try {
        require_once __DIR__ . '/fpdf.php';
        
        // Fetch All Details
        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile as visitor_mobile, vis.photo_path, emp.name as host_name, emp.department, emp.mobile as host_mobile 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               JOIN employees emp ON v.employee_id = emp.id 
                               WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) {
            error_log("[".date('Y-m-d H:i:s')."] Visit not found for ID: $visit_id");
            return null;
        }

        error_log("[".date('Y-m-d H:i:s')."] Visit found: " . $visit['visit_code']);

        // Create PDF (Custom size: 100mm x 150mm)
        $pdf = new FPDF('P', 'mm', array(100, 150));
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);

        // Header Background
        $pdf->SetFillColor(17, 97, 238); // Match v.php blue
        $pdf->Rect(0, 0, 100, 45, 'F');

        // Header Text
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetY(10);
        $pdf->Cell(0, 5, 'VISITOR MANAGEMENT', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 12, 'VISITOR PASS', 0, 1, 'C');

        // Photo Border/Container
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(32, 35, 36, 36, 'F'); // White square under photo

        // Photo Integration
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : __DIR__ . '/../assets/img/visitor-icon.png';
        if (file_exists($photoPath)) {
            error_log("[".date('Y-m-d H:i:s')."] Adding photo from: " . realpath($photoPath));
            $pdf->Image($photoPath, 33, 36, 34, 34);
        }

        // Visitor Name
        $pdf->SetY(75);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, strtoupper($visit['visitor_name']), 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(17, 97, 238);
        $pdf->Cell(0, 5, $visit['visit_code'], 0, 1, 'C');

        // Details Grid (2 columns layout matching v.php)
        $pdf->SetY(100);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(180, 180, 180);
        
        // Row 1 Labels
        $pdf->SetX(15);
        $pdf->Cell(35, 4, 'VISITING:', 0, 0, 'L');
        $pdf->Cell(35, 4, 'PURPOSE:', 0, 1, 'L');

        // Row 1 Values
        $pdf->SetX(15);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(35, 5, substr($visit['host_name'], 0, 18), 0, 0, 'L');
        $pdf->Cell(35, 5, substr($visit['purpose'], 0, 18), 0, 1, 'L');

        $pdf->Ln(3);

        // Row 2 Labels
        $pdf->SetX(15);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(180, 180, 180);
        $pdf->Cell(35, 4, 'ACCESS AREA:', 0, 0, 'L');
        $pdf->Cell(35, 4, 'DATE:', 0, 1, 'L');

        // Row 2 Values
        $pdf->SetX(15);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(35, 5, substr($visit['access_area'] ?: 'General', 0, 18), 0, 0, 'L');
        $pdf->Cell(35, 5, date('d M Y', strtotime($visit['created_at'])), 0, 1, 'L');

        // QR Code - Use local path to avoid URL issues
        $qrPath = __DIR__ . '/../uploads/qrcodes/' . $visit['visit_code'] . '.png';
        if (file_exists($qrPath)) {
            error_log("[".date('Y-m-d H:i:s')."] Adding QR from local path: " . realpath($qrPath));
            $pdf->Image($qrPath, 40, 122, 20, 20);
        } else {
            // Fallback to API if local not found (though local is preferred)
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . $visit['visit_code'];
            try {
                $pdf->Image($qrUrl, 40, 122, 20, 20, 'PNG');
            } catch (Exception $e) { /* skip */ }
        }

        // Footer
        $pdf->SetY(145);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->Cell(0, 5, 'SECURE ENTRY SYSTEM', 0, 1, 'C');

        // Save
        $pdf->Output('F', $pdfAbsPath);
        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        error_log("[".date('Y-m-d H:i:s')."] CRITICAL EXCEPTION: FPDF error: " . $e->getMessage());
        return null;
    }
}
