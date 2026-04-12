<?php
require_once 'includes/db.php';

// Public Viewer for Digital Visitor Pass
if (!isset($_GET['code']) && !isset($_GET['v'])) {
    die("Access Denied");
}

$code = sanitize($_GET['code'] ?? $_GET['v']);

$stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.photo_path, emp.name as host_name, emp.department 
                       FROM visits v 
                       JOIN visitors vis ON v.visitor_id = vis.id 
                       JOIN employees emp ON v.employee_id = emp.id 
                       WHERE v.visit_code = ? AND v.approval_status = 'approved'");
$stmt->execute([$code]);
$visit = $stmt->fetch();

if (!$visit) {
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'>
            <h2 style='color:red;'>Invalid or Expired Pass</h2>
            <p>Please contact security for assistance.</p>
         </div>");
}

require_once 'includes/dahua_helper.php';
$dahuaQr = DahuaHelper::getQRCode($visit['visit_code'], $pdo);

// Testing multiple formats if official QR fails
$qrDirect = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit['visit_code']);
$qrJson = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode(json_encode(["cardNo" => $visit['visit_code']]));
$qrPass = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit['visit_code'] . "#");
$qrOfficial = $dahuaQr ? "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($dahuaQr) : null;

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Pass - <?php echo $visit['visit_code']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: #f4f7fb;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .vms-id-card {
            width: 380px;
            min-height: 560px;
            background: #ffffff;
            border-radius: 35px;
            overflow: hidden;
            color: #333;
            position: relative;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
            border: 1px solid rgba(0, 0, 0, 0.05);
            margin: 0 auto;
        }

        .id-header {
            background: #1161ee;
            color: white;
            padding: 35px 20px;
            text-align: center;
        }

        .company-name {
            font-size: 0.65rem;
            letter-spacing: 2.5px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 5px;
            opacity: 0.95;
        }

        .pass-type {
            font-size: 2.1rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .id-body {
            padding: 10px 30px;
            background: #fff;
            text-align: center;
        }

        .photo-wrapper {
            margin-top: -30px;
        }

        .photo-container {
            background: #fff;
            display: inline-block;
            border-radius: 30px;
            padding: 5px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
        }

        .visitor-img {
            width: 170px;
            height: 170px;
            border-radius: 25px;
            object-fit: cover;
            border: 2px solid #fff;
        }

        .visitor-img-placeholder {
            width: 170px;
            height: 170px;
            border-radius: 25px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 5rem;
            color: #eee;
        }

        .visitor-name {
            font-size: 1.8rem;
            font-weight: 800;
            color: #111;
            letter-spacing: -0.5px;
            margin-top: 15px;
        }

        .visitor-code {
            font-size: 1.1rem;
            letter-spacing: 1px;
            margin-bottom: 20px;
            font-weight: bold;
            color: #0d6efd;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            text-align: left;
            max-width: 320px;
            margin: 0 auto;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 20px;
            /* Added spacing */
        }

        .d-item {
            display: flex;
            flex-direction: column;
        }

        .d-label {
            font-size: 0.6rem;
            color: #adb5bd;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        .d-value {
            font-size: 0.8rem;
            color: #333;
            font-weight: 800;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .qr-img {
            width: 100px;
            height: 100px;
            border: 1px solid #f0f0f0;
            padding: 5px;
            border-radius: 12px;
            margin-top: 20px;
        }

        .id-footer {
            background: #fbfbfc;
            padding: 15px 0;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-size: 0.7rem;
            font-weight: 900;
            color: #ccc;
            border-top: 1px solid #eee;
            text-align: center;
            border-bottom-left-radius: 35px;
            border-bottom-right-radius: 35px;
        }
    </style>
</head>

<body>
    <div class="vms-id-card">
        <div class="id-header">
            <div class="company-name">VISITOR MANAGEMENT</div>
            <div class="pass-type">VISITOR PASS</div>
        </div>
        <div class="id-body">
            <div class="photo-wrapper">
                <div class="photo-container">
                    <?php
                    $display_photo = $visit['visit_photo'];
                    if ($display_photo): ?>
                        <img src="<?php echo $display_photo; ?>" class="visitor-img">
                    <?php else: ?>
                        <div class="photo-placeholder">
                            <i class="bi bi-person-fill"></i>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <h2 class="visitor-name"><?php echo strtoupper(htmlspecialchars($visit['visitor_name'])); ?></h2>
            <div class="visitor-code"><?php echo $visit['visit_code']; ?></div>

            <div class="details-grid"
                style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; text-align: left; max-width: 320px; margin: 0 auto 20px; padding: 15px; background: #f8f9fa; border-radius: 12px;">
                <div class="d-item" style="display: flex; flex-direction: column;">
                    <span class="d-label"
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">VISITING:</span>
                    <span class="d-value"
                        style="font-size: 0.85rem; color: #333; font-weight: 800; line-height: 1.2;"><?php echo htmlspecialchars($visit['host_name']); ?></span>
                </div>
                <div class="d-item" style="display: flex; flex-direction: column;">
                    <span class="d-label"
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">PURPOSE:</span>
                    <span class="d-value"
                        style="font-size: 0.85rem; color: #333; font-weight: 800; line-height: 1.2;"><?php echo htmlspecialchars($visit['purpose']); ?></span>
                </div>
                <div class="d-item" style="display: flex; flex-direction: column;">
                    <span class="d-label"
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">ACCESS
                        AREA:</span>
                    <span class="d-value"
                        style="font-size: 0.85rem; color: #333; font-weight: 800; line-height: 1.2;"><?php echo htmlspecialchars($visit['access_area'] ?? 'General'); ?></span>
                </div>
                <div class="d-item" style="display: flex; flex-direction: column;">
                    <span class="d-label"
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">DATE:</span>
                    <span class="d-value text-primary"
                        style="font-size: 0.85rem; color: #0d6efd; font-weight: 800; line-height: 1.2;"><?php echo date('d M Y', strtotime($visit['visit_date'] ?? $visit['created_at'])); ?></span>
                </div>
            </div>

                        <div class="qr-test-container" style="display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-top: 20px;">
                <div style="text-align: center;">
                    <img src="<?php echo $qrDirect; ?>" class="qr-img" style="width: 80px; height: 80px; margin: 0;">
                    <div style="font-size: 0.6rem; font-weight: bold; margin-top: 5px;">DIRECT</div>
                </div>
                <div style="text-align: center;">
                    <img src="<?php echo $qrJson; ?>" class="qr-img" style="width: 80px; height: 80px; margin: 0;">
                    <div style="font-size: 0.6rem; font-weight: bold; margin-top: 5px;">JSON</div>
                </div>
                <div style="text-align: center;">
                    <img src="<?php echo $qrPass; ?>" class="qr-img" style="width: 80px; height: 80px; margin: 0;">
                    <div style="font-size: 0.6rem; font-weight: bold; margin-top: 5px;"># PASS</div>
                </div>
                <?php if($qrOfficial): ?>
                <div style="text-align: center;">
                    <img src="<?php echo $qrOfficial; ?>" class="qr-img" style="width: 80px; height: 80px; margin: 0; border-color: #28a745;">
                    <div style="font-size: 0.6rem; font-weight: bold; margin-top: 5px; color: #28a745;">OFFICIAL</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="id-footer">
            SECURE ENTRY SYSTEM
        </div>
    </div>
</body>

</html>