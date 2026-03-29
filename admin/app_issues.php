<?php
/**
 * Report App Issue / Bug - Centralized Logging (VMS Version)
 */
require_once '../includes/db.php';
requireLogin();

// Function to initialize centralized table if not exists (using Support PDO)
function ensureAppIssuesTable($support_pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS `app_issues` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `app_name` VARCHAR(100) NOT NULL,
      `client_name` VARCHAR(100),
      `client_contact` VARCHAR(100),
      `reported_by` VARCHAR(50),
      `issue_type` ENUM('Bug', 'Feature Request', 'UI Issue', 'Access Issue', 'Other') DEFAULT 'Bug',
      `priority` ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
      `description` TEXT NOT NULL,
      `photo_url` VARCHAR(255),
      `status` ENUM('Pending', 'In Progress', 'Resolved', 'Closed', 'Invalid') DEFAULT 'Pending',
      `admin_remarks` TEXT,
      `status_history` TEXT,
      `reported_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_app` (`app_name`),
      INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        $support_pdo->exec($sql);
        
        // Migration: Add status_history if it doesn't exist
        $check = $support_pdo->query("SHOW COLUMNS FROM app_issues LIKE 'status_history'");
        if ($check->rowCount() == 0) {
            $support_pdo->exec("ALTER TABLE app_issues ADD COLUMN status_history TEXT AFTER admin_remarks");
        }
    } catch (Exception $e) {
        error_log("Issue Table Init Failed: " . $e->getMessage());
    }
}

// Get Support DB connection
$support_pdo = getSupportDatabaseConnection();
if ($support_pdo) {
    ensureAppIssuesTable($support_pdo);
}

// Check for session-based messages
$success_msg = $_SESSION['issue_reported_success'] ?? null;
unset($_SESSION['issue_reported_success']);
$error_msg = $_SESSION['issue_reported_error'] ?? null;
unset($_SESSION['issue_reported_error']);

// Fetch Client Details from Master Database (Official registration info)
$client_name = $company_settings['name'] ?? 'Unnamed Client';
$client_contact = 'No contact info';

if (isset($master_pdo) && isset($_SESSION['tenant_key'])) {
    try {
        $stmt = $master_pdo->prepare("SELECT customer_name, contact_email, contact_phone FROM tenants WHERE tenant_key = ? LIMIT 1");
        $stmt->execute([$_SESSION['tenant_key']]);
        if ($t_row = $stmt->fetch()) {
            $client_name = $t_row['customer_name'];
            $c_email = trim($t_row['contact_email'] ?? '');
            $c_phone = trim($t_row['contact_phone'] ?? '');
            $client_contact = trim("$c_email $c_phone") ?: 'No contact info';
        }
    } catch (Exception $e) {}
}

// Handle Form Submission
if (isset($_POST['submit_issue'])) {
    if (!$support_pdo) {
        $_SESSION['issue_reported_error'] = "❌ Could not connect to the centralized support server. Please try again later.";
    } else {
        $type = $_POST['issue_type'];
        $priority = $_POST['priority'];
        $desc = $_POST['description'];
        $reported_by = $_SESSION['username'] ?? 'Anonymous';
        $app_name = CLIENT_APP_NAME;

        // Photo Upload
        $photo_path = '';
        if (isset($_FILES['issue_photo']) && $_FILES['issue_photo']['error'] == 0) {
            $upload_dir = '../uploads/issues/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['issue_photo']['name'], PATHINFO_EXTENSION);
            $filename = 'issue_vms_' . time() . '_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['issue_photo']['tmp_name'], $upload_dir . $filename)) {
                // Return path relative to document root for the AMC system to pick up
                $photo_path = 'uploads/issues/' . $filename;
            }
        }

        try {
            $sql = "INSERT INTO app_issues (app_name, client_name, client_contact, reported_by, issue_type, priority, description, photo_url) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $support_pdo->prepare($sql);
            if ($stmt->execute([$app_name, $client_name, $client_contact, $reported_by, $type, $priority, $desc, $photo_path])) {
                $_SESSION['issue_reported_success'] = "✅ Issue reported successfully! Our technical team will review it.";
                logAction($pdo, $_SESSION['user_id'], "Reported a $type: " . substr($desc, 0, 50));
            } else {
                $_SESSION['issue_reported_error'] = "❌ Error saving issue.";
            }
        } catch (Exception $e) {
            $_SESSION['issue_reported_error'] = "❌ DB Error: " . $e->getMessage();
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Handle Admin Update (Status & Remarks)
if (isset($_POST['update_issue_status']) && $support_pdo) {
    if ($_SESSION['role'] == 'admin' || $_SESSION['is_super']) {
        $issue_id = intval($_POST['issue_id']);
        $new_status = $_POST['new_status'];
        $new_remarks = $_POST['admin_remarks'];
        $admin_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';

        try {
            // Fetch current history
            $stmt = $support_pdo->prepare("SELECT status_history FROM app_issues WHERE id = ?");
            $stmt->execute([$issue_id]);
            $curr_row = $stmt->fetch();
            $old_history = $curr_row['status_history'] ?? '';

            $timestamp = date('d M, h:i A');
            $log_entry = "[$timestamp] $new_status: $new_remarks (by $admin_name)";
            $updated_history = $log_entry . ($old_history ? "\n" . $old_history : "");

            $upd = $support_pdo->prepare("UPDATE app_issues SET status = ?, admin_remarks = ?, status_history = ? WHERE id = ?");
            if ($upd->execute([$new_status, $new_remarks, $updated_history, $issue_id])) {
                $_SESSION['issue_reported_success'] = "✅ Issue updated successfully!";
            } else {
                $_SESSION['issue_reported_error'] = "❌ Failed to update issue status.";
            }
        } catch (Exception $e) {
            $_SESSION['issue_reported_error'] = "❌ Update Error: " . $e->getMessage();
        }
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Fetch Previous Reports
$my_issues = [];
if ($support_pdo) {
    try {
        $stmt = $support_pdo->prepare("SELECT * FROM app_issues WHERE app_name = ? AND client_name = ? ORDER BY reported_at DESC");
        $stmt->execute([CLIENT_APP_NAME, $client_name]);
        $my_issues = $stmt->fetchAll();
    } catch (Exception $e) {}
}

include 'header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-10 col-xl-9">
        <div style="background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%); border-radius: 20px; padding: 30px; margin-bottom: 25px; color: white; box-shadow: 0 10px 25px rgba(67, 97, 238, 0.3);">
            <div style="display: flex; align-items: center; gap: 20px;">
                <div style="font-size: 50px;">🔧</div>
                <div>
                    <h1 style="margin: 0; font-size: 28px; font-weight: 800; letter-spacing: -0.5px;">Report an Issue</h1>
                    <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 15px;">Found a bug or need a feature? Let our developers know.</p>
                </div>
            </div>
        </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success border-0 shadow-sm" style="border-radius: 12px; border-left: 6px solid #10b981 !important;">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $success_msg; ?>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm" style="border-radius: 12px; border-left: 6px solid #ef4444 !important;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm" style="border-radius: 20px;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="mb-0 fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>New Issue Details</h3>
                <button type="button" onclick="openReportsModal()" class="btn btn-light border fw-bold text-primary px-3 rounded-3 shadow-sm">
                    <i class="bi bi-archive me-1"></i> View History (<?php echo count($my_issues); ?>)
                </button>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Issue Category *</label>
                        <select name="issue_type" class="form-select py-2 px-3 border-2" required>
                            <option value="Bug">🪲 App Bug / Error</option>
                            <option value="UI Issue">🎨 Display/Design Problem</option>
                            <option value="Access Issue">🔐 Login/Permission Issue</option>
                            <option value="Feature Request">💡 Feature Request</option>
                            <option value="Other">❓ Other</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Priority Level *</label>
                        <select name="priority" class="form-select py-2 px-3 border-2" required>
                            <option value="Low">Low - Improvement</option>
                            <option value="Medium" selected>Medium - Minor Issue</option>
                            <option value="High">High - Hindering Work</option>
                            <option value="Critical">Critical - System Down</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Description *</label>
                    <textarea name="description" class="form-control border-2" rows="5" required placeholder="Describe the issue..."></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Attachment (Optional)</label>
                    <div class="border-2 border-dashed p-4 text-center rounded-4 bg-light cursor-pointer position-relative" onclick="document.getElementById('issue_photo').click()" style="border-style: dashed !important;">
                        <i class="bi bi-camera shadow-sm p-3 bg-white rounded-circle d-inline-block fa-2x mb-3 text-primary" style="font-size: 2rem;"></i>
                        <p class="text-muted small mb-0">Click to take a photo or upload screenshot</p>
                        <input type="file" name="issue_photo" id="issue_photo" accept="image/*" class="d-none" onchange="previewImage(this)">
                        <div id="photo_preview" class="mt-3 d-none">
                            <img id="preview_img" class="img-fluid rounded shadow-sm border" style="max-height: 200px;">
                        </div>
                    </div>
                </div>

                <button type="submit" name="submit_issue" class="btn btn-primary w-100 py-3 rounded-4 fw-bold shadow">
                    <i class="bi bi-send me-1"></i> Submit Report
                </button>
            </form>
        </div>
    </div>
</div>
</div>

<!-- History Modal -->
<div id="reportsModal" class="modal-shadow-overlay" style="display: none;">
    <div class="modal-history-container">
        <div class="modal-header-fixed bg-white border-bottom p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="mb-0 fw-bold text-dark"><i class="bi bi-collection-play me-2 text-primary"></i>Issue History</h2>
                    <p class="text-muted small mb-0">Track the resolution of your reports.</p>
                </div>
                <button onclick="closeReportsModal()" class="btn-close shadow-none"></button>
            </div>
            
            <div class="row g-2 align-items-center bg-light p-3 rounded-4">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                        <input type="text" id="reportSearch" class="form-control border-start-0" placeholder="Search issues...">
                    </div>
                </div>
                <div class="col-md-2">
                    <input type="date" id="dateFrom" class="form-control" title="From">
                </div>
                <div class="col-md-2">
                    <input type="date" id="dateTo" class="form-control" title="To">
                </div>
                <div class="col-md-3">
                    <select id="statusFilter" class="form-select fw-bold">
                        <option value="all">All Status</option>
                        <option value="Pending">Pending</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Resolved">Resolved</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>
                <div class="col-md-1 text-end text-nowrap">
                    <span class="badge bg-primary px-3 py-2 rounded-3 shadow-sm"><span id="visibleCount"><?php echo count($my_issues); ?></span> issues</span>
                </div>
            </div>
        </div>

        <div id="modalReportsList" class="modal-body-scrollable p-4 bg-light-subtle">
            <div class="row g-4">
                <?php if (empty($my_issues)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-cloud-slash display-1 opacity-25"></i>
                        <p class="mt-4">No issues have been reported yet.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($my_issues as $iss): ?>
                        <div class="col-xl-6 report-card-box" 
                             data-status="<?php echo htmlspecialchars($iss['status']); ?>" 
                             data-content="<?php echo htmlspecialchars(strtolower($iss['description'] . ' ' . $iss['issue_type'])); ?>"
                             data-date="<?php echo date('Y-m-d', strtotime($iss['reported_at'])); ?>">
                            <div class="card h-100 border-0 shadow-sm rounded-4 overflow-hidden">
                                <div class="card-body p-4">
                                    <div class="d-flex justify-content-between mb-3">
                                        <span class="badge py-2 px-3 rounded-3 shadow-sm text-uppercase" style="background: <?php echo getStatusColor($iss['status']); ?>; font-size: 10px; letter-spacing: 0.5px;">
                                            <?php echo $iss['status']; ?>
                                        </span>
                                        <div class="text-end text-muted small lh-1">
                                            <div class="fw-bold text-dark"><?php echo date('d M Y', strtotime($iss['reported_at'])); ?></div>
                                            <div><?php echo date('h:i A', strtotime($iss['reported_at'])); ?></div>
                                        </div>
                                    </div>

                                    <h5 class="fw-bold text-primary mb-1"><?php echo $iss['issue_type']; ?></h5>
                                    <div class="d-flex align-items-center gap-2 mb-3 text-muted small">
                                        <span class="fw-bold text-danger-emphasis">Priority: <?php echo $iss['priority']; ?></span>
                                        <span>&bull;</span>
                                        <span>ID: #<?php echo $iss['id']; ?></span>
                                    </div>

                                    <div class="p-3 bg-light rounded-3 mb-3 border text-dark" style="font-size: 14px; line-height: 1.6; max-height: 80px; overflow-y: auto;">
                                        <?php echo nl2br(htmlspecialchars($iss['description'])); ?>
                                    </div>

                                    <?php if (!empty($iss['status_history'])): ?>
                                        <div class="mt-4 pt-3 border-top">
                                            <div class="text-uppercase fw-bold text-muted mb-3" style="font-size: 10px; letter-spacing: 1px;">Update Timeline</div>
                                            <div class="position-relative ps-4 border-start border-2 ms-2">
                                                <?php 
                                                $logs = explode("\n", $iss['status_history']);
                                                foreach($logs as $log): 
                                                    if(trim($log) == '') continue;
                                                    $dot_color = (stripos($log, 'Resolved') !== false) ? '#10b981' : '#4f46e5';
                                                ?>
                                                    <div class="mb-3 position-relative">
                                                        <div class="position-absolute bg-white rounded-circle border-3" style="width: 12px; height: 12px; left: -29.5px; top: 2px; border: solid <?php echo $dot_color; ?>;"></div>
                                                        <div class="small text-dark lh-sm"><?php echo htmlspecialchars($log); ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="mt-4 d-flex justify-content-between align-items-center">
                                        <?php if (!empty($iss['photo_url'])): ?>
                                            <a href="<?php echo htmlspecialchars($iss['photo_url']); ?>" target="_blank" class="btn btn-xs btn-outline-info rounded-pill px-3" style="font-size: 11px;">
                                                <i class="bi bi-image me-1"></i>View Photo
                                            </a>
                                        <?php else: ?>
                                            <span></span>
                                        <?php endif; ?>

                                        <?php if ($_SESSION['role'] == 'admin' || $_SESSION['is_super']): ?>
                                            <button onclick="openUpdateModal(<?php echo $iss['id']; ?>, '<?php echo $iss['status']; ?>', <?php echo htmlspecialchars(json_encode($iss['admin_remarks'] ?? ''), ENT_QUOTES); ?>)" class="btn btn-sm btn-outline-primary rounded-pill px-4">
                                                Update Status
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Admin Quick Action Modal -->
<div id="updateModal" class="modal-shadow-overlay" style="display: none; z-index: 11000;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 450px; width: 95%;">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden bg-white">
            <div class="modal-header bg-primary text-white p-3 border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-gear-fill me-2"></i>Status Update</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" onclick="closeUpdateModal()"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4 bg-white">
                    <input type="hidden" name="issue_id" id="update_issue_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">New Status</label>
                        <select name="new_status" id="update_status_sel" class="form-select border-2" required>
                            <option value="Pending">Pending</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Closed">Closed</option>
                            <option value="Invalid">Invalid</option>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label fw-bold small text-muted">Admin Remarks</label>
                        <textarea name="admin_remarks" id="update_remarks_txt" class="form-control border-2" rows="4" required placeholder="Add resolution notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3 border-0">
                    <button type="button" class="btn btn-link text-muted text-decoration-none fw-bold small" onclick="closeUpdateModal()">Cancel</button>
                    <button type="submit" name="update_issue_status" class="btn btn-primary px-4 rounded-3 shadow fw-bold">Save Updates</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('preview_img').src = e.target.result;
            document.getElementById('photo_preview').classList.remove('d-none');
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function openReportsModal() {
    document.getElementById('reportsModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeReportsModal() {
    document.getElementById('reportsModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function openUpdateModal(id, status, remarks) {
    document.getElementById('update_issue_id').value = id;
    document.getElementById('update_status_sel').value = status;
    document.getElementById('update_remarks_txt').value = remarks || '';
    document.getElementById('updateModal').style.display = 'flex';
}

function closeUpdateModal() {
    document.getElementById('updateModal').style.display = 'none';
}

function filterReports() {
    const searchText = document.getElementById('reportSearch').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;
    const fromDate = document.getElementById('dateFrom').value;
    const toDate = document.getElementById('dateTo').value;
    const cards = document.querySelectorAll('.report-card-box');
    let visibleCount = 0;

    cards.forEach(card => {
        const content = card.getAttribute('data-content');
        const status = card.getAttribute('data-status');
        const dateStr = card.getAttribute('data-date');
        
        const matchesSearch = content.includes(searchText);
        const matchesStatus = (statusFilter === 'all' || status === statusFilter);
        let matchesDate = true;
        if (fromDate && dateStr < fromDate) matchesDate = false;
        if (toDate && dateStr > toDate) matchesDate = false;

        if (matchesSearch && matchesStatus && matchesDate) {
            card.classList.remove('d-none');
            visibleCount++;
        } else {
            card.classList.add('d-none');
        }
    });
    document.getElementById('visibleCount').textContent = visibleCount;
}

document.getElementById('reportSearch').addEventListener('input', filterReports);
document.getElementById('statusFilter').addEventListener('change', filterReports);
document.getElementById('dateFrom').addEventListener('change', filterReports);
document.getElementById('dateTo').addEventListener('change', filterReports);

window.onclick = function(e) {
    if (e.target == document.getElementById('reportsModal')) closeReportsModal();
    if (e.target == document.getElementById('updateModal')) closeUpdateModal();
}
</script>

<style>
/* Modal System */
.modal-shadow-overlay {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6); backdrop-filter: blur(8px);
    display: flex; justify-content: center; align-items: center; z-index: 9999;
}
.modal-history-container {
    background: #fff; width: 98%; max-width: 1400px; height: 95vh;
    border-radius: 30px; display: flex; flex-direction: column; overflow: hidden;
    box-shadow: 0 50px 100px -20px rgba(0,0,0,0.4);
}
.modal-body-scrollable { flex: 1; overflow-y: auto; }
.card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
.card[onClick]:hover { transform: translateY(-5px); cursor: pointer; }
.form-control:focus, .form-select:focus { border-color: #6610f2 !important; box-shadow: 0 0 0 4px rgba(102, 16, 242, 0.1) !important; }
</style>

<?php 
function getStatusColor($status) {
    switch ($status) {
        case 'Resolved': return '#10b981';
        case 'In Progress': return '#3b82f6';
        case 'Closed': return '#64748b';
        case 'Invalid': return '#ef4444';
        default: return '#f59e0b';
    }
}
include 'footer.php'; ?>
