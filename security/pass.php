<?php
require_once '../includes/db.php';

// No requireLogin() here so visitors/hosts can access the pass link directly
if (!isset($_GET['id'])) {
    die("Pass ID missing");
}
$id = $_GET['id'];

$stmt = $pdo->prepare("SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile as visitor_mobile, vis.photo_path, emp.name as host_name, emp.department, emp.mobile as host_mobile 
                       FROM visits v 
                       JOIN visitors vis ON v.visitor_id = vis.id 
                       JOIN employees emp ON v.employee_id = emp.id 
                       WHERE v.id = ?");
$stmt->execute([$id]);
$visit = $stmt->fetch();

if (!$visit)
    die("Invalid Visit ID");

// Check if visit is approved
if ($visit['approval_status'] === 'rejected') {
    die("This visit has been rejected.");
}
if ($visit['approval_status'] !== 'approved') {
    die("Pass is pending host approval.");
}

$qrData = $visit['visit_code'];
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . urlencode($qrData);
$passUrl = BASE_URL . "security/pass.php?id=" . $id;

// Check if PDF already exists on server
$pdfFile = "Pass_" . $id . ".pdf";
$pdfPath = "../uploads/passes/" . $pdfFile;
$hasPdf = file_exists($pdfPath);
$pdfUrl = BASE_URL . "uploads/passes/" . $pdfFile;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Pass - <?php echo $visit['visitor_name']; ?></title>

    <!-- WhatsApp / Social Media Preview (Pro Appearance) -->
    <meta property="og:title" content="VISITOR PASS: <?php echo strtoupper($visit['visitor_name']); ?>">
    <meta property="og:description" content="Official Entry Pass for seeing <?php echo $visit['host_name']; ?>.">
    <?php $meta_photo = $visit['visit_photo'] ?: 'assets/img/visitor-icon.png'; ?>
    <meta property="og:image" content="<?php echo BASE_URL . $meta_photo; ?>">
    <meta property="og:type" content="website">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- PDF Generation Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        body {
            background: #f4f7fb;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
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
        }

        .id-details-table {
            max-width: 300px;
            margin: 0 auto;
        }

        .detail-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .detail-item .label {
            color: #adb5bd;
            font-weight: 800;
            font-size: 0.7rem;
            text-transform: uppercase;
            margin-right: auto;
        }

        .detail-item .value {
            font-weight: 800;
            color: #333;
            font-size: 0.85rem;
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
            border-bottom-left-radius: 35px;
            border-bottom-right-radius: 35px;
        }

        @media print {
            @page {
                size: portrait;
                margin: 0.5cm;
            }
            body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .container {
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            .no-print {
                display: none !important;
            }
            .vms-id-card {
                margin: 10px auto !important;
                box-shadow: none !important;
                border: 1px solid #eee !important;
                page-break-inside: avoid !important;
                -webkit-print-color-adjust: exact !important;
                min-height: auto !important;
                max-height: 25cm;
            }
            .id-header {
                background: #1161ee !important;
                padding: 15px 10px !important;
                -webkit-print-color-adjust: exact !important;
            }
            .qr-img {
                width: 80px !important;
                height: 80px !important;
                margin-top: 10px !important;
            }
            .d-value.text-primary {
                color: #0d6efd !important;
                -webkit-print-color-adjust: exact !important;
            }
        }
    </style>
</head>

<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-5 text-center px-4">
                <h5 class="text-muted mb-4 mt-3 no-print" style="letter-spacing:1px"><i class="bi bi-person-badge"></i>
                    OFFICIAL VISITOR PASS</h5>

                <div id="idCard" class="vms-id-card mx-auto mb-4">
                    <div class="id-header">
                        <div class="company-name"><?php echo htmlspecialchars($company_settings['name']); ?></div>
                        <div class="pass-type">VISITOR PASS</div>
                    </div>
                    <div class="id-body">
                        <div class="photo-wrapper">
                            <div class="photo-container">
                                <?php
$display_photo = $visit['visit_photo'];
if ($display_photo): ?>
                                    <img src="../<?php echo $display_photo; ?>" class="visitor-img">
                                <?php
else: ?>
                                    <div class="visitor-img-placeholder"><i class="bi bi-person"></i></div>
                                <?php
endif; ?>
                            </div>
                        </div>
                        <h2 class="visitor-name"><?php echo strtoupper(htmlspecialchars($visit['visitor_name'])); ?>
                        </h2>
                        <div class="visitor-code text-primary fw-bold"><?php echo $visit['visit_code']; ?></div>

                        <style>
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
                        </style>

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
                                    style="font-size: 0.85rem; color: #0d6efd; font-weight: 800; line-height: 1.2;"><?php echo date('d M Y', strtotime($visit['created_at'])); ?></span>
                            </div>
                        </div>

                        <img src="<?php echo $qrUrl; ?>" class="qr-img">
                    </div>
                    <div class="id-footer"><?php echo htmlspecialchars($company_settings['name']); ?></div>
                </div>

                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="d-grid gap-3 mb-5 no-print">
                        <div class="row g-2">
                            <div class="col-6"><button onclick="window.print()"
                                    class="btn btn-light border w-100 rounded-pill py-2">Print</button></div>
                            <div class="col-6">
                                <button onclick="handleClose()"
                                    class="btn btn-light border w-100 rounded-pill py-2">Close</button>
                            </div>
                        </div>
                    </div>
                <?php
endif; ?>
            </div>
        </div>
    </div>

    <!-- Loader -->
    <div id="genModal"
        style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); z-index:9999; flex-direction:column; align-items:center; justify-content:center; color:white;">
        <div class="spinner-border text-light mb-3" role="status"></div>
        <h4 id="genStatus" class="fw-bold">Generating PDF Pass...</h4>
    </div>

    <script>

        function handleClose() {
            window.history.back();
        }

    </script>
</body>

</html>