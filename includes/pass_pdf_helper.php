<?php
/**
 * VMS - Pass PDF Helper
 * Generates and returns the public URL for the visitor pass PDF.
 * Matches the digital pass template from security/pass.php exactly.
 */

function log_pass_error($msg) {
    $log_file = __DIR__ . '/pass_pdf.log';
    $timestamp = date('[Y-m-d H:i:s] ');
    @file_put_contents($log_file, $timestamp . $msg . "\n", FILE_APPEND);
}

/**
 * Main Generation Function
 * @param int $visit_id
 * @param PDO $pdo
 * @return string|null Public URL of the generated PDF or null on failure
 */
function generatePassPdf($visit_id, $pdo)
{
    $pdfFileRelative = "uploads/passes/Pass_" . $visit_id . ".pdf";
    $pdfAbsPath = __DIR__ . '/../' . $pdfFileRelative;

    // 1. Check if it already exists (Optimization)
    if (file_exists($pdfAbsPath)) {
        return BASE_URL . $pdfFileRelative;
    }

    try {
        require_once __DIR__ . '/fpdf.php';

        // 2. Ensure directory exists to avoid "not getting generated" errors on new installs
        $pdfDir = dirname($pdfAbsPath);
        if (!is_dir($pdfDir)) {
            @mkdir($pdfDir, 0777, true);
        }

        // 3. Fetch Detailed Visit Info (aligned with security/pass.php schema)
        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile as visitor_mobile, vis.photo_path, emp.name as host_name, emp.department, emp.mobile as host_mobile 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               JOIN employees emp ON v.employee_id = emp.id 
                               WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) {
            log_pass_error("Visit not found for ID: " . $visit_id);
            return null;
        }

        // 4. Initialize PDF (Premium Card Size: 100x150mm)
        $pdf = new FPDF('P', 'mm', array(100, 150));
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        
        // Premium Color Palette
        $brandBlue = array(17, 97, 238); // #1161ee
        $darkGrey = array(51, 51, 51);
        $lightGrey = array(173, 181, 189);

        // 5. Main Card Border (Rounded Graphics)
        $pdf->SetDrawColor(230, 230, 230);
        $pdf->SetLineWidth(0.5);
        $pdf->RoundedRect(5, 5, 90, 140, 10, 'D');

        // 6. Header Section (Blue Branding Banner)
        $pdf->SetFillColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->RoundedRect(5, 5, 90, 35, 10, 'F', '12'); // Only top corners rounded
        
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetY(12);
        $pdf->SetFont('Arial', 'B', 8);
        $company = strtoupper($GLOBALS['company_settings']['name'] ?? 'VisitPilot VMS');
        $pdf->Cell(90, 5, $company, 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 22);
        $pdf->Cell(90, 15, 'VISITOR PASS', 0, 1, 'C');

        // 7. Photo Wrapper (White Rounded Container with "Shadow" feel)
        $photoY = 32;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(30, $photoY, 40, 40, 8, 'F');
        
        // Priority: Visit-specific photo -> Visitor profile photo -> Placeholder
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : (!empty($visit['photo_path']) ? __DIR__ . '/../' . $visit['photo_path'] : '');
        if (!empty($photoPath) && file_exists($photoPath)) {
            $pdf->Image($photoPath, 31, $photoY + 1, 38, 38);
        } else {
            $pdf->SetXY(30, $photoY + 13);
            $pdf->SetTextColor(240, 240, 240);
            $pdf->SetFont('Arial', 'B', 30);
            $pdf->Cell(40, 15, '?', 0, 0, 'C');
        }

        // 8. Identity Header
        $pdf->SetXY(5, 75);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(90, 10, strtoupper($visit['visitor_name']), 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->Cell(90, 5, '#' . ($visit['visit_code'] ?? $visit_id), 0, 1, 'C');

        // 9. Structured Details Grid (Label/Value Pattern)
        $detailY = 95;
        $drawDetail = function($pdf, $label, $value, $y) use ($lightGrey, $darkGrey) {
            $pdf->SetXY(15, $y);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetTextColor($lightGrey[0], $lightGrey[1], $lightGrey[2]);
            $pdf->Cell(35, 5, strtoupper($label), 0, 0, 'L');
            
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor($darkGrey[0], $darkGrey[1], $darkGrey[2]);
            $pdf->Cell(35, 5, $value, 0, 1, 'R');
            
            $pdf->SetDrawColor(245, 245, 245);
            $pdf->Line(15, $y + 6, 85, $y + 6);
        };

        $drawDetail($pdf, 'Meeting With', $visit['host_name'] ?? 'Staff', $detailY);
        $drawDetail($pdf, 'Department', $visit['department'] ?: 'General', $detailY + 8);
        $drawDetail($pdf, 'Purpose', $visit['purpose'] ?: 'Visit', $detailY + 16);
        $drawDetail($pdf, 'Date/Time', date('d M Y, h:i A', strtotime($visit['created_at'])), $detailY + 24);

        // 10. QR Code & Branding Footer
        $qrData = $visit['visit_code'] ?? $visit_id;
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qrData);
        
        // FPDF handles URL downloads automatically if server allow_url_fopen is enabled
        try {
            $pdf->Image($qrUrl, 15, 122, 18, 18, 'PNG');
        } catch (Exception $qrErr) {
            log_pass_error("QR download failed: " . $qrErr->getMessage());
        }

        $pdf->SetXY(35, 128);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->Cell(50, 5, 'POWERED BY VISITPILOT VMS', 0, 0, 'C');

        // 12. Finalize and Save
        $pdf->Output('F', $pdfAbsPath);
        
        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        log_pass_error("Major failure for Visit $visit_id: " . $e->getMessage());
        return null;
    }
}
