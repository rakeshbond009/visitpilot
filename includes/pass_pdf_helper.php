<?php
/**
 * VMS - Pass PDF Helper
 * Generates the Visitor Pass PDF to match the provided high-quality digital reference EXACTLY.
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

    // Check if it already exists
    if (file_exists($pdfAbsPath)) {
        return BASE_URL . $pdfFileRelative;
    }

    try {
        require_once __DIR__ . '/fpdf.php';

        $pdfDir = dirname($pdfAbsPath);
        if (!is_dir($pdfDir)) {
            @mkdir($pdfDir, 0777, true);
        }

        // Fetch Data
        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, emp.name as host_name, emp.department 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               JOIN employees emp ON v.employee_id = emp.id 
                               WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) return null;

        // Page setup
        $pdf = new FPDF('P', 'mm', array(100, 185)); 
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        
        $brandBlue = array(17, 97, 238); // #1161ee
        $bgGrey = array(248, 249, 250);   // Details box background
        $pageGrey = array(244, 247, 246); // Exterior page background
        $labelGrey = array(173, 181, 189); // Muted labels

        // 1. Page Background
        $pdf->SetFillColor($pageGrey[0], $pageGrey[1], $pageGrey[2]);
        $pdf->Rect(0, 0, 100, 185, 'F');

        // 2. Exterior Header Text
        $pdf->SetY(8);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(100, 5, 'OFFICIAL VISITOR PASS', 0, 1, 'C');

        // 3. Main Highly-Rounded Card (20mm corners at bottom)
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(10, 20, 80, 155, 20, 'F');
        
        // 4. Blue Top Banner (Rounded top corners)
        $pdf->SetFillColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->RoundedRect(10, 20, 80, 48, 20, 'F', '12'); 

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 32);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(80, 5, 'VISITPILOT', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 28);
        $pdf->Cell(80, 15, 'VISITOR PASS', 0, 1, 'C');

        // 5. Oversized Photo Wrap container (48mm wide)
        $photoY = 56;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(26, $photoY, 48, 48, 15, 'F'); 
        
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : (!empty($visit['photo_path']) ? __DIR__ . '/../' . $visit['photo_path'] : '');
        if (!empty($photoPath) && file_exists($photoPath)) {
            // 40mm image inside the 48mm wrapper
            $pdf->Image($photoPath, 30, $photoY + 4, 40, 40);
        }

        // 6. Name and blue ID code
        $pdf->SetXY(10, 110);
        $pdf->SetTextColor(17, 17, 17);
        $pdf->SetFont('Arial', 'B', 24);
        $pdf->Cell(80, 12, strtoupper($visit['visitor_name']), 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->SetTextColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->Cell(80, 5, $visit['visit_code'], 0, 1, 'C');

        // 7. Unified Grey Details Box
        $boxY = 132;
        $pdf->SetFillColor($bgGrey[0], $bgGrey[1], $bgGrey[2]);
        $pdf->RoundedRect(15, $boxY, 70, 32, 10, 'F');

        // Grid helper
        $drawGridCell = function($label, $value, $x, $y, $isBlue = false) use ($pdf, $labelGrey, $brandBlue) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetTextColor($labelGrey[0], $labelGrey[1], $labelGrey[2]);
            $pdf->Cell(35, 4, strtoupper($label), 0, 0, 'L');
            
            $pdf->SetXY($x, $y + 4.5);
            $pdf->SetFont('Arial', 'B', 10);
            if ($isBlue) { $pdf->SetTextColor($brandBlue[0], $brandBlue[1], $brandBlue[2]); }
            else { $pdf->SetTextColor(33, 33, 33); }
            $pdf->Cell(35, 5, $value, 0, 0, 'L');
        };

        // Grid Cells
        $drawGridCell('Visiting:', $visit['host_name'], 18, $boxY + 5);
        $drawGridCell('Purpose:', $visit['purpose'] ?: 'Delivery', 48, $boxY + 5);
        $drawGridCell('Access Area:', $visit['access_area'] ?: 'General', 18, $boxY + 19);
        $drawGridCell('Date:', date('d M Y', strtotime($visit['created_at'])), 48, $boxY + 19, true);

        // 8. Centered QR (No border)
        $localQr = __DIR__ . '/../uploads/qrcodes/' . $visit['visit_code'] . '.png';
        if (file_exists($localQr)) {
            $pdf->Image($localQr, 40, 166, 22, 22);
        }

        // 9. Card Footer
        $pdf->SetXY(10, 188);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(190, 190, 190);
        $pdf->Cell(80, 5, 'VISITPILOT', 0, 0, 'C');

        $pdf->Output('F', $pdfAbsPath);
        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        log_pass_error("Final precision match failure: " . $e->getMessage());
        return null;
    }
}
