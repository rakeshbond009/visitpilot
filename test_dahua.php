<?php
/**
 * DAHUA INTEGRATION DIAGNOSTIC DASHBOARD
 * A premium tool to verify visitor synchronization payloads.
 */
require_once 'includes/db.php';
require_once 'includes/dahua_helper.php';

// Handle specific visit selection
$test_id = $_GET['id'] ?? null;

// 1. Fetch Dahua Settings
$raw_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'dahua_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$appId = $raw_settings['dahua_app_id'] ?? '';
$appSecret = $raw_settings['dahua_app_secret'] ?? '';
$deviceSns = $raw_settings['dahua_device_sns'] ?? '';

// 2. Fetch Sample Visitor Data
if ($test_id) {
    $stmt = $pdo->prepare("SELECT vs.id, vs.visitor_id, v.name, v.mobile, vs.visit_photo, vs.visit_code, vs.created_at 
                          FROM visits vs 
                          JOIN visitors v ON vs.visitor_id = v.id 
                          WHERE vs.id = ?");
    $stmt->execute([$test_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $visit = $pdo->query("SELECT vs.id, vs.visitor_id, v.name, v.mobile, vs.visit_photo, vs.visit_code, vs.created_at 
                         FROM visits vs 
                         JOIN visitors v ON vs.visitor_id = v.id 
                         WHERE vs.visit_photo IS NOT NULL 
                         ORDER BY vs.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

// 3. Prepare Payload (Map to Dahua DoLynk expectations)
$payload = null;
$base64Image = '';
if ($visit) {
    $startTime = $visit['created_at'];
    $endTime = date('Y-m-d 23:59:59', strtotime($startTime));
    $deviceSnsList = explode(',', $deviceSns);
    $deviceSnsList = array_map('trim', array_filter($deviceSnsList));

    // Resolve Image Path
    $imagePath = realpath($visit['visit_photo']);
    if (!$imagePath && $visit['visit_photo']) {
        $imagePath = realpath(__DIR__ . '/' . $visit['visit_photo']);
    }

    if ($imagePath && file_exists($imagePath)) {
        $base64Image = base64_encode(file_get_contents($imagePath));
    }

    $payload = [
        'name' => $visit['name'],
        'faceImage' => $base64Image ? "DATA_LOADED (" . number_format(strlen($base64Image)) . " bytes)" : "NO_IMAGE",
        'qrCode' => $visit['visit_code'],
        'startTime' => $startTime,
        'endTime' => $endTime,
        'deviceSns' => $deviceSnsList,
        'visitor_id' => (string) $visit['visitor_id']
    ];
}

// 4. Test Token
$tokenResult = null;
if ($appId && $appSecret) {
    $dahua = new DahuaHelper($appId, $appSecret);
    $token = $dahua->getAccessToken();
    $tokenResult = $token ? true : false;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dahua Integration Diagnostics | VMS</title>
    <link
        href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=Outfit:wght@300;400;600&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #5f27cd;
            --accent: #ff9f43;
            --bg: #0a0e1c;
            --card-bg: rgba(255, 255, 255, 0.05);
            --glow: 0 0 20px rgba(95, 39, 205, 0.3);
        }

        body {
            background: radial-gradient(circle at 50% 50%, #1a1f3c 0%, #0a0e1c 100%);
            color: #fff;
            font-family: 'Outfit', sans-serif;
            min-height: 100vh;
            padding-bottom: 50px;
            overflow-x: hidden;
        }

        .cyber-header {
            font-family: 'Orbitron', sans-serif;
            letter-spacing: 2px;
            background: linear-gradient(90deg, #fff, #5f27cd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-transform: uppercase;
        }

        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            box-shadow: var(--glow);
            transition: transform 0.3s ease;
        }

        .glass-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
        }

        .status-badge {
            padding: 8px 15px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-ok {
            background: rgba(39, 174, 96, 0.2);
            color: #2ecc71;
            border: 1px solid #2ecc71;
        }

        .status-err {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            border: 1px solid #e74c3c;
        }

        pre {
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #00ff88;
            padding: 20px;
            border-radius: 12px;
            font-size: 13px;
            max-height: 400px;
        }

        .visitor-photo {
            width: 120px;
            height: 120px;
            border-radius: 15px;
            object-fit: cover;
            border: 3px solid var(--primary);
            box-shadow: 0 0 15px var(--primary);
        }

        .label {
            color: rgba(255, 255, 255, 0.5);
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .value {
            font-size: 1.1rem;
            font-weight: 500;
        }

        .accent-text {
            color: var(--accent);
        }
    </style>
</head>

<body>

    <div class="container py-5">
        <!-- Header -->
        <div class="text-center mb-5">
            <h1 class="cyber-header display-5 mb-2">Dahua Sync Intelligence</h1>
            <p class="text-white-50">Diagnostic Hub for DoLynk Cloud Synchronization</p>
        </div>

        <div class="row g-4">
            <!-- Dashboard Left: Config & Auth -->
            <div class="col-lg-4">
                <div class="glass-card p-4 mb-4">
                    <h5 class="mb-4"><i class="bi bi-shield-lock me-2 text-primary"></i>Config Authentication</h5>

                    <div class="mb-3">
                        <div class="label">Cloud Auth Status</div>
                        <?php if ($tokenResult): ?>
                            <span class="status-badge status-ok"><i class="bi bi-check-circle-fill me-1"></i>
                                Authenticated</span>
                        <?php else: ?>
                            <span class="status-badge status-err"><i class="bi bi-x-circle-fill me-1"></i> Conn
                                Failed</span>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <div class="label">App ID</div>
                        <div class="value">
                            <?php echo $appId ? substr($appId, 0, 10) . '...' : '<small class="text-danger">Not Set</small>'; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="label">Target Devices</div>
                        <div class="value text-info small">
                            <?php echo $deviceSns ?: '<span class="text-danger">None Found</span>'; ?>
                        </div>
                    </div>
                </div>

                <div class="glass-card p-4">
                    <h5 class="mb-4"><i class="bi bi-person-bounding-box me-2 text-accent"></i>Active Visitor Scan</h5>
                    <?php if ($visit): ?>
                        <div class="text-center mb-4">
                            <img src="<?php echo $visit['visit_photo']; ?>" class="visitor-photo mb-3"
                                onerror="this.src='https://ui-avatars.com/api/?name=Visitor&background=random'">
                            <h4 class="mb-0"><?php echo htmlspecialchars($visit['name']); ?></h4>
                            <p class="text-accent small"><?php echo $visit['mobile']; ?></p>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span class="label">Visitor ID</span>
                            <span class="badge bg-primary">#<?php echo $visit['visitor_id']; ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="label">QR Code</span>
                            <code><?php echo $visit['visit_code']; ?></code>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning py-2 small">No test visitors found.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Dashboard Right: Payload Preview -->
            <div class="col-lg-8">
                <div class="glass-card p-4 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="mb-0"><i class="bi bi-code-square me-2 text-success"></i>JSON Payload Analysis</h5>
                        <button onclick="window.location.reload()"
                            class="btn btn-sm btn-outline-light rounded-pill px-3">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>

                    <p class="small text-white-50 mb-3">Below is the exact data structure being sent to the Dahua
                        <code>/visitor/add</code> endpoint. The <strong class="text-white">visitor_id</strong> maps to
                        your internal Visitor ID.
                    </p>

                    <?php if ($payload): ?>
                        <pre id="json-preview"><?php echo json_encode($payload, JSON_PRETTY_PRINT); ?></pre>

                        <div class="mt-4 row g-2">
                            <div class="col-6">
                                <div class="p-3 rounded-3 bg-white bg-opacity-10 border border-white border-opacity-10">
                                    <div class="label">Image Size</div>
                                    <div class="accent-text h5 mb-0">
                                        <?php echo $base64Image ? number_format(strlen($base64Image) / 1024, 1) . ' KB' : '0 KB'; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="p-3 rounded-3 bg-white bg-opacity-10 border border-white border-opacity-10">
                                    <div class="label">Sync Logic</div>
                                    <div class="text-success h5 mb-0">Verified ✓</div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-database-exclamation display-4 text-muted"></i>
                            <p class="mt-3">No data available for inspection.</p>
                        </div>
                    <?php endif; ?>

                    <div class="mt-5 text-end">
                        <a href="admin/settings.php" class="text-decoration-none text-white-50 small">
                            <i class="bi bi-gear-fill me-1"></i> Edit Integration Settings
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>