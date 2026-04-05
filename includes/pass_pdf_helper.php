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

        if (!$visit)
            return null;

        // Fetch Company Name from settings
        $compStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
        $company_name = $compStmt ? $compStmt->fetchColumn() : 'VISITPILOT';

        $pdf = new FPDF('P', 'mm', array(100, 150)); // ID Card Size
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);

        // Header Background (#1161ee)
        $pdf->SetFillColor(17, 97, 238);
        $pdf->Rect(0, 0, 100, 45, 'F');

        // Company Name (Header)
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetY(15);
        $pdf->Cell(80, 5, strtoupper($company_name), 0, 1, 'C');

        // Pass Type
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(80, 10, 'VISITOR PASS', 0, 1, 'C');

        // Photo Positioning
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : __DIR__ . '/../assets/img/visitor-icon.png';
        if (file_exists($photoPath)) {
            // White border for photo container
            $pdf->SetFillColor(255, 255, 255);
            $pdf->RoundedRect(32, 35, 36, 36, 5, '34', 'F');
            $pdf->Image($photoPath, 33, 36, 34, 34);
        }

        // Visitor Name
        $pdf->SetY(75);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(80, 8, strtoupper($visit['visitor_name']), 0, 1, 'C');

        // Visitor Code
        $pdf->SetTextColor(17, 97, 238);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(80, 5, $visit['visit_code'], 0, 1, 'C');

        // Details Grid Background (#f8f9fa)
        $pdf->SetFillColor(248, 249, 250);
        $pdf->RoundedRect(10, 95, 80, 25, 3, '1234', 'F');

        // Grid Content
        $pdf->SetY(97);
        $pdf->SetX(12);
        
        // Row 1
        $pdf->SetTextColor(173, 181, 189);
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->Cell(38, 4, 'VISITING:', 0, 0);
        $pdf->Cell(38, 4, 'PURPOSE:', 0, 1);

        $pdf->SetX(12);
        $pdf->SetTextColor(51, 51, 51);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(38, 4, substr($visit['host_name'], 0, 20), 0, 0);
        $pdf->Cell(38, 4, substr($visit['purpose'], 0, 20), 0, 1);

        $pdf->Ln(2);
        $pdf->SetX(12);

        // Row 2
        $pdf->SetTextColor(173, 181, 189);
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->Cell(38, 4, 'ACCESS AREA:', 0, 0);
        $pdf->Cell(38, 4, 'DATE:', 0, 1);

        $pdf->SetX(12);
        $pdf->SetTextColor(51, 51, 51);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(38, 4, substr($visit['access_area'] ?: 'General', 0, 20), 0, 0);
        $pdf->SetTextColor(17, 97, 238);
        $pdf->Cell(38, 4, date('d M Y', strtotime($visit['created_at'])), 0, 1);

        // QR Code
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . $visit['visit_code'];
        try {
            $pdf->Image($qrUrl, 40, 122, 20, 20, 'PNG');
        } catch (Exception $e) {
            // Skip if error
        }

        // Footer
        $pdf->SetY(144);
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->Cell(80, 5, strtoupper($company_name), 0, 1, 'C');

        $pdf->Output('F', $pdfAbsPath);

        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        error_log("PDF Generation failed: " . $e->getMessage());
        return null;
    }
}
