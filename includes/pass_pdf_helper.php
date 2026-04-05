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

        // 2. Ensure directory exists
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
        
        // Premium Color Palette from pass.php
        $brandBlue = array(17, 97, 238); // #1161ee
        $darkText = array(51, 51, 51);
        $mutedText = array(173, 181, 189);

        // 5. Overall Card Surface (Clean White rounded card)
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(5, 5, 90, 140, 10, 'F');
        $pdf->SetDrawColor(240, 240, 240);
        $pdf->SetLineWidth(0.4);
        $pdf->RoundedRect(5, 5, 90, 140, 10, 'D');

        // 6. Header Section (Blue Branding Banner)
        $pdf->SetFillColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->RoundedRect(5, 5, 90, 45, 10, 'F', '12'); // Only top corners rounded
        
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetY(12);
        $pdf->SetFont('Arial', 'B', 7);
        $company = strtoupper($GLOBALS['company_settings']['name'] ?? 'VisitPilot VMS');
        $pdf->Cell(90, 5, $company, 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 24);
        $pdf->Cell(90, 15, 'VISITOR PASS', 0, 1, 'C');

        // 7. Floating Photo Container (Centered, overlapping the header)
        $photoY = 38;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(30, $photoY, 40, 40, 8, 'F'); // Shadow/Border Box
        
        // Priority: Visit-specific photo -> Visitor profile photo -> Placeholder
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : (!empty($visit['photo_path']) ? __DIR__ . '/../' . $visit['photo_path'] : '');
        if (!empty($photoPath) && file_exists($photoPath)) {
            $pdf->Image($photoPath, 31.5, $photoY + 1.5, 37, 37);
        } else {
            $pdf->SetXY(30, $photoY + 13);
            $pdf->SetTextColor(245, 245, 245);
            $pdf->SetFont('Arial', 'B', 30);
            $pdf->Cell(40, 15, '?', 0, 0, 'C');
        }

        // 8. Identity Header (Name and Code)
        $pdf->SetXY(5, 82);
        $pdf->SetTextColor(17, 17, 17);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(90, 10, strtoupper($visit['visitor_name']), 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 11);
        $pdf->SetTextColor(102, 102, 102);
        $pdf->Cell(90, 5, '#' . ($visit['visit_code'] ?? '000000'), 0, 1, 'C');

        // 9. Structured Details List (Vertical stack with lines)
        $detailY = 100;
        $drawListItem = function($pdf, $label, $value, $y) use ($mutedText, $darkText) {
            $pdf->SetXY(15, $y);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetTextColor($mutedText[0], $mutedText[1], $mutedText[2]);
            $pdf->Cell(35, 5, strtoupper($label), 0, 0, 'L');
            
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->SetTextColor(17, 17, 17);
            $pdf->Cell(35, 5, $value, 0, 1, 'R');
            
            $pdf->SetDrawColor(245, 245, 245);
            $pdf->Line(15, $y + 6, 85, $y + 6);
        };

        $drawListItem($pdf, 'Meeting With', $visit['host_name'] ?? 'Staff', $detailY);
        $drawListItem($pdf, 'Department', $visit['department'] ?: 'General', $detailY + 8);
        $drawListItem($pdf, 'Purpose', $visit['purpose'] ?: 'Meeting', $detailY + 16);

        // 10. Centered QR Code with protective border
        $visit_code = $visit['visit_code'] ?? '000000';
        $localQr = __DIR__ . '/../uploads/qrcodes/' . $visit_code . '.png';
        
        $pdf->SetDrawColor(240, 240, 240);
        $pdf->RoundedRect(39, 126, 22, 22, 3, 'D'); // QR Border box
        
        try {
            if (file_exists($localQr)) {
                $pdf->Image($localQr, 40, 127, 20, 20);
            } else {
                $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code);
                $pdf->Image($qrUrl, 40, 127, 20, 20, 'PNG');
            }
        } catch (Exception $qrErr) {
            log_pass_error("QR placement failed: " . $qrErr->getMessage());
        }

        // 11. Footer Branding
        $pdf->SetXY(5, 140);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(210, 210, 210);
        $pdf->Cell(90, 8, 'POWERED BY VISITPILOT VMS', 0, 0, 'C');

        // 12. Finalize and Save
        $pdf->Output('F', $pdfAbsPath);
        
        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        log_pass_error("Major failure for Visit $visit_id: " . $e->getMessage());
        return null;
    }
}
