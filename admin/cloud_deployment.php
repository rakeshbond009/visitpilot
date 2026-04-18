<?php
require_once '../includes/db.php';
requireLogin();

// Enforce Super Admin only (assuming role 'admin' is super admin, or we check a specific permission)
// For now, mirroring the screenshot's requirement.
if (!isset($_SESSION['is_super']) || !$_SESSION['is_super']) {
    header("Location: dashboard.php");
    exit;
}

$success = '';
$error = '';

// Handle Webhook Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_webhook'])) {
    $webhook = $_POST['hostinger_webhook'];
    try {
        // Ensure table exists in MASTER database
        $master_pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $stmt = $master_pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                               VALUES ('hostinger_webhook', ?) 
                               ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$webhook, $webhook]);
        $success = "Hostinger Webhook URL saved successfully (Global Setting).";
    } catch (Exception $e) {
        $error = "Error saving webhook: " . $e->getMessage();
    }
}

// Fetch current webhook
$current_webhook = '';
try {
    $stmt = $master_pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'hostinger_webhook'");
    $stmt->execute();
    $res = $stmt->fetch();
    if ($res)
        $current_webhook = $res['setting_value'];
} catch (Exception $e) {
}

include_once 'header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="card-header bg-white border-0 py-4 px-4 d-flex align-items-center justify-content-between">
                    <h4 class="mb-0 fw-bold text-dark flex-grow-1">
                        🚀 Cloud Deployment
                        <span
                            class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill ms-2 text-uppercase"
                            style="font-size: 0.6rem; letter-spacing: 0.5px;">Super Admin Only</span>
                    </h4>
                </div>
                <div class="card-body p-4 pt-0">
                    <p class="text-secondary mb-4">Use this tool to save your latest changes to the cloud. This will
                        automatically prepare your code for deployment to Hostinger.</p>

                    <div class="bg-primary-subtle bg-opacity-10 border border-primary-subtle rounded-4 p-4 mb-5">
                        <ul class="list-unstyled mb-0">
                            <li class="d-flex align-items-center mb-3 text-primary-emphasis">
                                <i class="bi bi-dot fs-1 me-1 text-primary"></i>
                                <span><strong>Step 1:</strong> Prepares all modified files on your laptop.</span>
                            </li>
                            <li class="d-flex align-items-center mb-3 text-primary-emphasis">
                                <i class="bi bi-dot fs-1 me-1 text-primary"></i>
                                <span><strong>Step 2:</strong> Creates a secure version snapshot.</span>
                            </li>
                            <li class="d-flex align-items-center text-primary-emphasis">
                                <i class="bi bi-dot fs-1 me-1 text-primary"></i>
                                <span><strong>Step 3:</strong> Securely uploads to the GitHub repository.</span>
                            </li>
                        </ul>
                    </div>

                    <?php if ($success): ?>
                        <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center">
                            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $success; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="mb-5">
                        <label class="form-label fw-semibold text-dark mb-2">Hostinger Webhook URL (Optional):</label>
                        <div class="input-group mb-2">
                            <input type="url" name="hostinger_webhook"
                                class="form-control bg-light border-0 py-2 ps-3 shadow-none"
                                placeholder="https://webhooks.hostinger.com/deploy/..."
                                value="<?php echo htmlspecialchars($current_webhook); ?>">
                            <button type="submit" name="save_webhook"
                                class="btn btn-secondary px-4 fw-bold text-uppercase" style="font-size: 0.85rem;">Save
                                URL</button>
                        </div>
                        <span class="text-muted small">(Stored in database table system_settings under key
                            hostinger_webhook)</span>
                    </form>

                    <form id="pushForm">
                        <div class="mb-4">
                            <label class="form-label fw-semibold text-dark mb-2">Commit Remarks / Version Details
                                (Optional):</label>
                            <input type="text" id="commit_remarks"
                                class="form-control bg-light border-0 py-2 ps-3 shadow-none"
                                placeholder="E.g., Fixed login bug, added new report section...">
                            <span class="text-muted small mt-2 d-block">(This will be attached to your code's history
                                log on GitHub.)</span>
                        </div>

                        <div class="mt-4 d-flex flex-column gap-2">
                            <button type="button" id="pushBtn"
                                class="btn btn-primary w-100 py-3 rounded-3 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-cloud-upload"></i> Push to Cloud (Main/Codepilotx)
                            </button>

                            <button type="button" id="atithiPushBtn"
                                class="btn btn-success w-100 py-3 rounded-3 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                                <i class="bi bi-cloud-arrow-up"></i> Sync Directly to Atithi
                            </button>

                            <a href="api/repair_sync.php"
                                onclick="return confirm('WARNING: This will discard any manual changes on the SERVER and force it to match your latest GitHub code. Are you sure?')"
                                class="btn btn-outline-danger w-100 py-2 rounded-3 fw-bold small d-flex align-items-center justify-content-center gap-2"
                                style="font-size: 0.8rem;">
                                <i class="bi bi-tools"></i> Stuck? Repair Hostinger Sync Conflict
                            </a>
                        </div>

                        <?php if (empty($current_webhook)): ?>
                            <p class="text-muted small italic mt-3 mb-0 text-center">*Note: If Webhook is set, deployment is
                                automated. Otherwise, manual deploy on Hostinger is needed.</p>
                        <?php else: ?>
                            <p class="text-success small italic mt-3 mb-0 text-center"><i
                                    class="bi bi-check-circle-fill me-1"></i> Webhook configured: Automated deployment is
                                active.</p>
                        <?php endif; ?>
                    </form>

                    <!-- Deployment Logs/Output -->
                    <div id="deploymentOutput" class="mt-5 d-none">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0">Deployment Process</h6>
                            <span id="pushStatus" class="badge bg-warning rounded-pill px-3">In Progress...</span>
                        </div>
                        <div class="bg-dark text-success p-3 rounded-4 overflow-auto" id="logContent"
                            style="height: 250px; font-family: 'Courier New', Courier, monospace; font-size: 0.85rem;">
                            <!-- Output will stream here -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('pushBtn').addEventListener('click', function () {
        const remarks = document.getElementById('commit_remarks').value;
        const outputDiv = document.getElementById('deploymentOutput');
        const logContent = document.getElementById('logContent');
        const statusBadge = document.getElementById('pushStatus');
        const btn = this;

        // UI Feedback
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Syncing to Cloud...';
        outputDiv.classList.remove('d-none');
        logContent.innerHTML = 'Initialising deployment sequence...\n';

        // API Call
        fetch('api/cloud_push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'remarks=' + encodeURIComponent(remarks)
        })
            .then(response => {
                const reader = response.body.getReader();
                const decoder = new TextDecoder();

                function read() {
                    return reader.read().then(({ done, value }) => {
                        if (done) {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Push to Cloud (Sync GitHub)';
                            return;
                        }
                        const chunk = decoder.decode(value, { stream: true });
                        logContent.innerHTML += chunk;
                        logContent.scrollTop = logContent.scrollHeight;

                        if (chunk.includes('FAILED') || chunk.includes('Error')) {
                            statusBadge.className = 'badge bg-danger rounded-pill px-3';
                            statusBadge.innerText = 'Failed';
                        } else if (chunk.includes('SUCCESSFULLY COMPLETED')) {
                            statusBadge.className = 'badge bg-success rounded-pill px-3';
                            btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Push to Cloud (Main/Codepilotx)';
                        }
                        return read();
                    });
                }
                return read();
            })
            .catch(err => {
                logContent.innerHTML += '\n[Error]: ' + err.message;
                statusBadge.className = 'badge bg-danger rounded-pill px-3';
                statusBadge.innerText = 'Failed';
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-cloud-upload"></i> Push to Cloud (Main/Codepilotx)';
            });
    });

    // Atithi Sync Listener
    document.getElementById('atithiPushBtn').addEventListener('click', function () {
        const remarks = document.getElementById('commit_remarks').value;
        const outputDiv = document.getElementById('deploymentOutput');
        const logContent = document.getElementById('logContent');
        const statusBadge = document.getElementById('pushStatus');
        const btn = this;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Shipping to Atithi...';
        outputDiv.classList.remove('d-none');
        logContent.innerHTML = 'Initialising Atithi shipment...\n';

        fetch('api/atithi_push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'remarks=' + encodeURIComponent(remarks)
        })
        .then(response => {
            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            function read() {
                return reader.read().then(({ done, value }) => {
                    if (done) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-cloud-arrow-up"></i> Sync Directly to Atithi';
                        return;
                    }
                    const chunk = decoder.decode(value, { stream: true });
                    logContent.innerHTML += chunk;
                    logContent.scrollTop = logContent.scrollHeight;
                    if (chunk.includes('FAILURE') || chunk.includes('WARN')) {
                        statusBadge.className = 'badge bg-warning rounded-pill px-3';
                        statusBadge.innerText = 'Warnings';
                    } else if (chunk.includes('COMPLETED')) {
                        statusBadge.className = 'badge bg-success rounded-pill px-3';
                        statusBadge.innerText = 'Completed';
                    }
                    return read();
                });
            }
            return read();
        });
    });
</script>

<?php include_once 'footer.php'; ?>