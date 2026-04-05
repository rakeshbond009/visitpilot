<?php
/**
 * VMS - Pass PDF Helper
 * Generates the Visitor Pass PDF to match the provided screenshot EXACTLY.
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

        // Custom taller page to fit exterior title + card
        $pdf = new FPDF('P', 'mm', array(100, 175)); 
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        
        $brandBlue = array(17, 97, 238); // #1161ee
        $bgGrey = array(248, 249, 250);
        $labelGrey = array(173, 181, 189);

        // 1. Exterior Header Text
        $pdf->SetY(8);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(100, 5, 'OFFICIAL VISITOR PASS', 0, 1, 'C');

        // 2. Main Card Surface (Rounded White Card)
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(10, 18, 80, 150, 15, 'F');
        
        // 3. Premium Blue Top Banner
        $pdf->SetFillColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->RoundedRect(10, 18, 80, 42, 15, 'F', '12'); 

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 28);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->Cell(80, 5, 'VISITPILOT', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 26);
        $pdf->Cell(80, 15, 'VISITOR PASS', 0, 1, 'C');

        // 4. Centered Photo with the WHITE WRAP BORDER effect
        // We first draw the larger white container, then the image inside it
        $photoY = 48;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(28, $photoY, 44, 44, 12, 'F'); // The "Wrap"
        
        $photoPath = !empty($visit['visit_photo']) ? __DIR__ . '/../' . $visit['visit_photo'] : (!empty($visit['photo_path']) ? __DIR__ . '/../' . $visit['photo_path'] : '');
        if (!empty($photoPath) && file_exists($photoPath)) {
            // Image is 40mm inside a 44mm wrapper (creating a 2mm thick white border)
            $pdf->Image($photoPath, 30, $photoY + 2, 40, 40);
        }

        // 5. Visitor Name and blue unique ID
        $pdf->SetXY(10, 96);
        $pdf->SetTextColor(17, 17, 17);
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->Cell(80, 12, strtoupper($visit['visitor_name']), 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->SetTextColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->Cell(80, 5, $visit['visit_code'], 0, 1, 'C');

        // 6. Grey Details Box (The 2x2 Grid)
        $boxY = 118;
        $pdf->SetFillColor($bgGrey[0], $bgGrey[1], $bgGrey[2]);
        $pdf->RoundedRect(15, $boxY, 70, 30, 8, 'F');

        // Grid Cell Helper
        $drawGridCell = function($label, $value, $x, $y, $isBlue = false) use ($pdf, $labelGrey, $brandBlue) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetTextColor($labelGrey[0], $labelGrey[1], $labelGrey[2]);
            $pdf->Cell(35, 4, strtoupper($label), 0, 0, 'L');
            
            $pdf->SetXY($x, $y + 4);
            $pdf->SetFont('Arial', 'B', 9);
            if ($isBlue) { $pdf->SetTextColor($brandBlue[0], $brandBlue[1], $brandBlue[2]); }
            else { $pdf->SetTextColor(17, 17, 17); }
            $pdf->Cell(35, 5, $value, 0, 0, 'L');
        };

        // Grid Row 1
        $drawGridCell('Visiting:', $visit['host_name'], 18, $boxY + 4);
        $drawGridCell('Purpose:', $visit['purpose'] ?: 'Meeting', 48, $boxY + 4);
        // Grid Row 2
        $drawGridCell('Access Area:', $visit['access_area'] ?: 'General', 18, $boxY + 18);
        $drawGridCell('Date:', date('d M Y', strtotime($visit['created_at'])), 48, $boxY + 18, true);

        // 7. QR Code (Centered bottom)
        $localQr = __DIR__ . '/../uploads/qrcodes/' . $visit['visit_code'] . '.png';
        if (file_exists($localQr)) {
            $pdf->Image($localQr, 40, 152, 20, 20);
        }

        // 8. Inner Card Footer
        $pdf->SetXY(10, 178);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(210, 210, 210);
        $pdf->Cell(80, 5, 'VISITPILOT', 0, 0, 'C');

        // Final Save
        $pdf->Output('F', $pdfAbsPath);
        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        log_pass_error("Major failure: " . $e->getMessage());
        return null;
    }
}
