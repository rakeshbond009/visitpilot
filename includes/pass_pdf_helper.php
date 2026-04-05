<?php
require_once __DIR__ . '/fpdf.php';

/**
 * Premium Visitor Pass PDF Helper
 * Generates a high-quality, branded visitor pass matching the digital template.
 */

function log_pass_msg($msg) {
    $log_file = __DIR__ . '/pass_pdf.log';
    $timestamp = date('[Y-m-d H:i:s] ');
    @file_put_contents($log_file, $timestamp . $msg . "\n", FILE_APPEND);
}

function generateVisitPass($visit) {
    try {
        $visit_id = $visit['visit_id'] ?? $visit['id'];
        $pdfFileRelative = "uploads/passes/Pass_" . $visit_id . ".pdf";
        $pdfAbsPath = __DIR__ . '/../' . $pdfFileRelative;

        // Ensure directory exists to prevent "not getting generated" errors
        $pdfDir = dirname($pdfAbsPath);
        if (!is_dir($pdfDir)) {
            @mkdir($pdfDir, 0777, true);
        }

        log_pass_msg("Generating Premium Pass for Visit ID: " . $visit_id);

        // 1. Initialize PDF (100x150mm tailored card size)
        $pdf = new FPDF('P', 'mm', array(100, 150));
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        
        // Colors
        $brandBlue = array(17, 97, 238); // #1161ee
        $darkGrey = array(51, 51, 51);
        $lightGrey = array(173, 181, 189);
        $borderGrey = array(240, 240, 240);

        // 2. Main Card Border with rounded corners
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->RoundedRect(5, 5, 90, 140, 10, 'D');

        // 3. Header Section (Blue Banner)
        $pdf->SetFillColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->RoundedRect(5, 5, 90, 35, 10, 'F', '12'); // Only top corners
        
        // Company Branding
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetY(12);
        $pdf->SetFont('Arial', 'B', 8);
        $company = strtoupper($GLOBALS['company_settings']['name'] ?? 'VisitPilot VMS');
        $pdf->Cell(90, 5, $company, 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 22);
        $pdf->Cell(90, 15, 'VISITOR PASS', 0, 1, 'C');

        // 4. Photo Container (Rounded White Box)
        $photoY = 32;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(30, $photoY, 40, 40, 8, 'F');
        
        // Load Photo
        $photoPath = '';
        if (!empty($visit['visit_photo'])) {
            $photoPath = __DIR__ . '/../' . $visit['visit_photo'];
        } elseif (!empty($visit['photo_path'])) {
             $photoPath = __DIR__ . '/../' . $visit['photo_path'];
        }

        if (!empty($photoPath) && file_exists($photoPath)) {
            // Rounded image effect (using a white mask or just placement)
            $pdf->Image($photoPath, 31, $photoY + 1, 38, 38);
        } else {
            // Placeholder Icon
            $pdf->SetXY(30, $photoY + 12);
            $pdf->SetTextColor(240, 240, 240);
            $pdf->SetFont('Arial', 'B', 30);
            $pdf->Cell(40, 15, '?', 0, 0, 'C');
        }

        // 5. Visitor Name and ID
        $pdf->SetXY(5, 75);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(90, 10, strtoupper($visit['visitor_name']), 0, 1, 'C');
        
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->Cell(90, 5, 'Visit Code: ' . ($visit['visit_code'] ?? 'PENDING'), 0, 1, 'C');

        // 6. Detailed Information Grid
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

        $drawDetail($pdf, 'Meeting With', $visit['host_name'] ?? 'N/A', $detailY);
        $drawDetail($pdf, 'Department', $visit['department'] ?? 'General', $detailY + 8);
        
        $visitDate = !empty($visit['created_at']) ? date('d M Y', strtotime($visit['created_at'])) : date('d M Y');
        $visitTime = !empty($visit['created_at']) ? date('h:i A', strtotime($visit['created_at'])) : date('H:i');
        
        $drawDetail($pdf, 'Date', $visitDate, $detailY + 16);
        $drawDetail($pdf, 'Time', $visitTime, $detailY + 24);

        // 7. Dynamic QR Code & Powered By
        $qrData = $visit['visit_code'] ?? $visit_id;
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qrData);
        
        // Add QR using local path caching (optional) or direct URL
        $pdf->Image($qrUrl, 15, 122, 18, 18, 'PNG');
        
        $pdf->SetXY(35, 128);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->Cell(50, 5, 'POWERED BY VISITPILOT VMS', 0, 0, 'C');

        // Output and Save
        $pdf->Output('F', $pdfAbsPath);
        
        log_pass_msg("SUCCESS: Pass generated at " . $pdfFileRelative);
        return $pdfFileRelative;

    } catch (Exception $e) {
        log_pass_msg("CRITICAL ERROR: " . $e->getMessage());
        return false;
    }
}
