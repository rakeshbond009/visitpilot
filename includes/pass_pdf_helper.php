<?php
/**
 * VMS - Pass PDF Helper
 * VERSION 1.0.6 - CLIPPED PHOTO & PREMIUM BORDERS.
 * Matches Image 12 exactly by using Clipping Masks for rounded images.
 */

function log_pass_error($msg) {
    if (!$msg) return;
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

    // Fresh generation every time
    if (file_exists($pdfAbsPath)) {
        @unlink($pdfAbsPath);
    }

    try {
        require_once __DIR__ . '/fpdf.php';

        $pdfDir = dirname($pdfAbsPath);
        if (!is_dir($pdfDir)) {
            @mkdir($pdfDir, 0777, true);
        }

        // Fetch Data
        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, emp.name as host_name 
                               FROM visits v 
                               JOIN visitors vis ON v.visitor_id = vis.id 
                               JOIN employees emp ON v.employee_id = emp.id 
                               WHERE v.id = ?");
        $stmt->execute([$visit_id]);
        $visit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$visit) return null;

        // Custom canvas for mobile-safe pass rendering
        $pdf = new FPDF('P', 'mm', array(100, 210)); 
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage();
        
        $brandBlue = array(17, 97, 238); // #1161ee
        $bgGrey = array(242, 243, 245);  
        $pageGrey = array(244, 247, 246); 
        $labelGrey = array(155, 155, 155); 

        // 1. Page Backdrop
        $pdf->SetFillColor($pageGrey[0], $pageGrey[1], $pageGrey[2]);
        $pdf->Rect(0, 0, 100, 210, 'F');

        // 2. Exterior Branding with [ ] Icon
        $pdf->SetY(8);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(100, 5, '[ ] OFFICIAL VISITOR PASS', 0, 1, 'C');

        // 3. Main Premium Card (Rounding=8mm for 1:1 look)
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(10, 15, 80, 185, 8, 'F');
        
        // 4. Blue Identity Banner
        $pdf->SetFillColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->RoundedRect(10, 15, 80, 48, 8, 'F', '12'); 

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 22);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(80, 5, 'VISITPILOT', 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 24); 
        $pdf->Cell(80, 15, 'VISITOR PASS', 0, 1, 'C');

        // 5. THE WRAP: BROAD WHITE BORDERED PHOTO WITH CLIPPING
        $photoY = 57;
        $pdf->SetFillColor(255, 255, 255);
        // Draw the white frame box (Rounded Square r=8 as per reference)
        $pdf->RoundedRect(26, $photoY, 48, 48, 8, 'F'); 
        
        // Path Resolver
        $finalPhotoPath = '';
        $potentialPaths = [
            !empty($visit['visit_photo']) ? $visit['visit_photo'] : '',
            !empty($visit['photo_path']) ? $visit['photo_path'] : '',
            'uploads/visitors/default.png'
        ];
        
        foreach($potentialPaths as $p) {
            if(!empty($p)) {
                $abs = __DIR__ . '/../' . ltrim($p, '/');
                if(file_exists($abs)) {
                    $finalPhotoPath = $abs;
                    break;
                }
            }
        }

        if (!empty($finalPhotoPath)) {
            // APPLY CLIPPING MASK TO ROUND THE IMAGE ITSELF (FIXED)
            // We use a 2mm white bleed to create the "Broad Border" look
            $pdf->ClippingRoundedRect(28, $photoY + 2, 44, 44, 8); 
            $pdf->Image($finalPhotoPath, 28, $photoY + 2, 44, 44);
            $pdf->UnsetClipping(); // Release mask
        }

        // 6. Identity Headers
        $pdf->SetXY(10, 110);
        $pdf->SetTextColor(17, 17, 17);
        $pdf->SetFont('Arial', 'B', 16); 
        $pdf->Cell(80, 8, strtoupper($visit['visitor_name']), 0, 1, 'C');
        
        $pdf->SetFont('Arial', 'B', 12); 
        $pdf->SetTextColor($brandBlue[0], $brandBlue[1], $brandBlue[2]);
        $pdf->Cell(80, 6, $visit['visit_code'], 0, 1, 'C');

        // 7. Details Grid Container (Sharp Corners r=4)
        $boxY = 132;
        $pdf->SetFillColor($bgGrey[0], $bgGrey[1], $bgGrey[2]);
        $pdf->RoundedRect(15, $boxY, 70, 32, 4, 'F');

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

        // Grid Rows
        $drawGridCell('Visiting:', $visit['host_name'], 18, $boxY + 5);
        $drawGridCell('Purpose:', $visit['purpose'] ?: 'Meeting', 48, $boxY + 5);
        $drawGridCell('Access Area:', $visit['access_area'] ?: 'General Area', 18, $boxY + 19);
        $drawGridCell('Date:', date('d M Y', strtotime($visit['created_at'])), 48, $boxY + 19, true);

        // 8. Centered QR Code
        $localQr = __DIR__ . '/../uploads/qrcodes/' . $visit['visit_code'] . '.png';
        if (file_exists($localQr)) {
            $pdf->Image($localQr, 40, 172, 20, 20);
        }

        // 9. Card Footer v1.0.6
        $pdf->SetXY(10, 198);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(190, 190, 190);
        $pdf->Cell(80, 5, 'VISITPILOT v1.0.6', 0, 0, 'C');

        $pdf->Output('F', $pdfAbsPath);
        return BASE_URL . $pdfFileRelative;

    } catch (Exception $e) {
        log_pass_error("v1.0.6 failure: " . $e->getMessage());
        return null;
    }
}
