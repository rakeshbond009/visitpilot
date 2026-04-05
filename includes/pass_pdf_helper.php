<?php
/**
 * VMS - Pass PDF Lookup Helper
 * Strictly looks for existing PDF files in uploads/passes/
 * Returns the public URL if found, or null if missing.
 */

function generatePassPdf($visit_id, $pdo)
{
    // Try to generate it
    try {
        require_once __DIR__ . '/fpdf.php';

        // Fetch All Details first for tenant safety (visit_code is unique)
        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile as visitor_mobile, vis.photo_path, emp.name as host_name, emp.department, emp.mobile as host_mobile 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               LEFT JOIN employees emp ON v.employee_id = emp.id 
                               WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) {
            return null;
        }

        $pdfFileRelative = "uploads/passes/Pass_" . $visit['visit_code'] . ".pdf";
        $pdfAbsPath = __DIR__ . '/../' . $pdfFileRelative;

        $passDir = dirname($pdfAbsPath);
        if (!is_dir($passDir)) {
            mkdir($passDir, 0777, true);
        }

        // Check if it already exists
        if (file_exists($pdfAbsPath)) {
            return BASE_URL . $pdfFileRelative;
        }

        $pdf = new FPDF('P', 'mm', array(100, 150)); // Custom size for pass
        $pdf->AddPage();

        // Background Color (Header)
        $pdf->SetFillColor(17, 97, 238);
        $pdf->Rect(0, 0, 100, 40, 'F');

        // Company Name
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(100, 5, 'OFFICIAL VISITOR PASS', 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(100, 10, 'VISITOR PASS', 0, 1, 'C');

        // Content Area
        $pdf->SetY(45);

        // Photo
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : __DIR__ . '/../assets/img/visitor-icon.png';
        if (file_exists($photoPath)) {
            $pdf->Image($photoPath, 35, 45, 30, 30);
        }

        $pdf->SetY(80);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(100, 10, strtoupper($visit['visitor_name']), 0, 1, 'C');

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(17, 97, 238);
        $pdf->Cell(100, 5, '#' . $visit['visit_code'], 0, 1, 'C');

        // Details
        $pdf->SetY(100);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 100, 100);

        $pdf->Cell(50, 5, 'HOST:', 0, 0, 'C');
        $pdf->Cell(50, 5, 'PURPOSE:', 0, 1, 'C');

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(50, 5, $visit['host_name'] ?? 'N/A', 0, 0, 'C');
        $pdf->Cell(50, 5, $visit['purpose'], 0, 1, 'C');

        $pdf->Ln(2);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(50, 5, 'DATE:', 0, 0, 'C');
        $pdf->Cell(50, 5, 'ACCESS:', 0, 1, 'C');

        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(50, 5, date('d M Y', strtotime($visit['created_at'])), 0, 0, 'C');
        $pdf->Cell(50, 5, $visit['access_area'] ?: 'General', 0, 1, 'C');

        // QR Code
        $localQrPath = !empty($visit['qr_code_path']) ? __DIR__ . '/../' . $visit['qr_code_path'] : null;
        
        if ($localQrPath && file_exists($localQrPath)) {
            try {
                $pdf->Image($localQrPath, 40, 125, 20, 20, 'PNG');
            } catch (Exception $e) {
                // error_log("Failed to load local QR: " . $e->getMessage());
            }
        } else {
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . $visit['visit_code'];
            try {
                $pdf->Image($qrUrl, 40, 125, 20, 20, 'PNG');
            } catch (Exception $e) {
                // Skip QR if URL image fails
            }
        }

        // Footer
        $pdf->SetY(145);
        $pdf->SetFont('Arial', '', 7);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(100, 5, 'Powered by VisitPilot VMS', 0, 1, 'C');

        $pdf->Output('F', $pdfAbsPath);

        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        error_log("PDF Generation failed: " . $e->getMessage());
        return null;
    }
}
