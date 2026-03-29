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

    // Try to generate it
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

        if (!$visit) return null;

        $pdf = new FPDF('P', 'mm', array(100, 150)); // Custom size for pass
        $pdf->AddPage();
        
        // Background Color (Header)
        $pdf->SetFillColor(17, 97, 238);
        $pdf->Rect(0, 0, 100, 40, 'F');
        
        // Company Name
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(80, 5, 'OFFICIAL VISITOR PASS', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(80, 10, 'VISITOR PASS', 0, 1, 'C');
        
        // Content Area
        $pdf->SetY(45);
        
        // Photo
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : __DIR__ . '/../assets/img/visitor-icon.png';
        if (file_exists($photoPath)) {
            // Try to keep it circular? No, FPDF is simple. Let's do a centered square.
            $pdf->Image($photoPath, 35, 45, 30, 30);
        }
        
        $pdf->SetY(80);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(80, 10, strtoupper($visit['visitor_name']), 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(17, 97, 238);
        $pdf->Cell(80, 5, '#' . $visit['visit_code'], 0, 1, 'C');
        
        // Details
        $pdf->SetY(100);
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 100, 100);
        
        $pdf->Cell(40, 5, 'HOST:', 0, 0, 'L');
        $pdf->Cell(40, 5, 'PURPOSE:', 0, 1, 'L');
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(40, 5, $visit['host_name'], 0, 0, 'L');
        $pdf->Cell(40, 5, $visit['purpose'], 0, 1, 'L');
        
        $pdf->Ln(2);
        
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(40, 5, 'DATE:', 0, 0, 'L');
        $pdf->Cell(40, 5, 'ACCESS:', 0, 1, 'L');
        
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(40, 5, date('d M Y', strtotime($visit['created_at'])), 0, 0, 'L');
        $pdf->Cell(40, 5, $visit['access_area'] ?: 'General', 0, 1, 'L');
        
        // QR Code
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . $visit['visit_code'];
        // Note: FPDF can't easily download from URL in some environments without allow_url_fopen
        // We'll try to use the local one if exists, or just skip if we must.
        // Actually, FPDF's Image() supports URLs if the wrapper is enabled.
        try {
            $pdf->Image($qrUrl, 40, 125, 20, 20, 'PNG');
        } catch (Exception $e) {
            // Skip QR if URL image fails
        }
        
        // Footer
        $pdf->SetY(145);
        $pdf->SetFont('Arial', 'I', 7);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell(80, 5, 'Powered by VisitPilot VMS', 0, 1, 'C');
        
        $pdf->Output('F', $pdfAbsPath);
        
        return BASE_URL . $pdfFileRelative;
        
    } catch (Exception $e) {
        error_log("PDF Generation failed: " . $e->getMessage());
        return null;
    }
}
