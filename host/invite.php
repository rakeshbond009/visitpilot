<?php
ob_start();
require_once 'header.php';

if (!canView('host_invite')) {
    die("Permission denied.");
}

$success = false;
$invite_details = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v_name = sanitize($_POST['name']);
    $v_mobile = sanitize($_POST['mobile']);
    $v_email = sanitize($_POST['email'] ?? '');
    $v_purpose = sanitize($_POST['purpose']);
    $v_date = sanitize($_POST['visit_date'] ?? date('Y-m-d'));

    if (!$host_employee_id) {
        $error = "System Error: Your user account is not linked to an Employee record. Please contact Admin.";
    }
    else {
        try {
            $pdo->beginTransaction();

            // 1. Check/Insert Visitor
            $stmt = $pdo->prepare("SELECT id FROM visitors WHERE mobile = ?");
            $stmt->execute([$v_mobile]);
            $visitor = $stmt->fetch();

            if ($visitor) {
                $visitor_id = $visitor['id'];
                // Only update email if it was previously empty and provided now
                // Do NOT overwrite name in "autofetch" mode
                if (empty($visitor['email']) && $v_email) {
                    $stmt = $pdo->prepare("UPDATE visitors SET email = ? WHERE id = ?");
                    $stmt->execute([$v_email, $visitor_id]);
                }
            }
            else {
                $stmt = $pdo->prepare("INSERT INTO visitors (name, mobile, email) VALUES (?, ?, ?)");
                $stmt->execute([$v_name, $v_mobile, $v_email]);
                $visitor_id = $pdo->lastInsertId();
            }

            // 2. Create Visit
            $visit_code = generateVisitCode();
            $qr_filename = 'uploads/qrcodes/INV_' . $visit_code . '.png';

            // Ensure directory exists
            if (!is_dir('../uploads/qrcodes/')) {
                mkdir('../uploads/qrcodes/', 0777, true);
            }

            // Generate QR Code via API
            $qr_content = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code);
            $qr_image = @file_get_contents($qr_content);
            if ($qr_image) {
                file_put_contents('../' . $qr_filename, $qr_image);
            }

            $stmt = $pdo->prepare("INSERT INTO visits (visitor_id, employee_id, purpose, visit_date, visit_code, status, approval_status, is_invited, qr_code_path, access_area, created_by, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, 'pending', 'approved', 1, ?, 'Not Assigned', ?, ?, NOW())");
            $stmt->execute([$visitor_id, $host_employee_id, $v_purpose, $v_date, $visit_code, $qr_filename, $_SESSION['user_id'], $_SESSION['user_id']]);
            $visit_id = $pdo->lastInsertId();

            $pdo->commit();
            logAction($pdo, $_SESSION['user_id'], "Created visitor invitation: $visit_code for $v_name");
            $success = true;
            $invite_details = [
                'name' => $v_name,
                'mobile' => $v_mobile,
                'code' => $visit_code,
                'date' => $v_date,
                'qr' => $qr_filename,
                'id' => $visit_id
            ];

            // WhatsApp Automation - Cloud API
            require_once '../includes/whatsapp_helper.php';
            $v_date_fmt = date('d-M-Y', strtotime($v_date));
            $waMessage = "Hello {$v_name}, you have been invited for a visit on {$v_date_fmt}. Your pass code is: {$visit_code}.";
            sendWhatsAppNotification($v_mobile, $waMessage, 'visitor_meet_notify', ["*{$v_name}*", "*{$v_date_fmt}*", "*{$visit_code}*"]);
        }
        catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error: " . $e->getMessage();
        }
    }

    if ($success && $visit_id) {
        header("Location: invite.php?success=1&visit_id=" . $visit_id);
        exit;
    }
}

// Handle Success View from Redirect
if (isset($_GET['success']) && isset($_GET['visit_id'])) {
    $visit_id = (int)$_GET['visit_id'];
    $stmt = $pdo->prepare("SELECT v.*, vis.name, vis.mobile, vis.email FROM visits v 
                           JOIN visitors vis ON v.visitor_id = vis.id 
                           WHERE v.id = ? AND v.employee_id = ?");
    $stmt->execute([$visit_id, $host_employee_id]);
    $visit = $stmt->fetch();

    if ($visit) {
        $success = true;
        $invite_details = [
            'name' => $visit['name'],
            'mobile' => $visit['mobile'],
            'code' => $visit['visit_code'],
            'date' => $visit['visit_date'],
            'qr' => $visit['qr_code_path'],
            'id' => $visit['id']
        ];
    }
}

// Fetch Purposes
$purposes = $pdo->query("SELECT purpose_name FROM visit_purposes ORDER BY purpose_name")->fetchAll(PDO::FETCH_COLUMN);
?>

        <style>
            @media print {
                body * { visibility: hidden; }
                #printable-pass, #printable-pass * { visibility: visible; }
                #printable-pass {
                    position: absolute;
                    left: 0;
                    right: 0;
                    top: 0;
                    margin: auto;
                    width: 500px !important;
                    border: 1px solid #eee !important;
                    box-shadow: none !important;
                }
                .btn, .btn-link, .card-header i, .navbar, footer, #mainNav, .d-grid { display: none !important; }
                body { background: white !important; }
            }

            #fullPageLoader {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(255, 255, 255, 0.9);
                backdrop-filter: blur(8px);
                z-index: 99999;
                display: none;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }
        </style>

        <div id="fullPageLoader">
            <div class="spinner-border text-success shadow-sm" style="width: 4rem; height: 4rem; border-width: 0.4rem;" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div class="mt-4 text-center">
                <h4 class="fw-bold text-success mb-1">Creating Invitation...</h4>
                <p class="text-muted small px-3">Please wait while we generate the pass and notify the visitor.</p>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                <?php if ($error): ?>
                    <div class="alert alert-danger shadow-sm rounded-3">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $error; ?>
                    </div>
                <?php
endif; ?>

                <?php if ($success): ?>
                    <div class="card border-0 shadow-lg rounded-4 overflow-hidden mb-4 animate__animated animate__fadeIn" id="printable-pass">
                        <div class="card-header bg-success text-white text-center py-4">
                    <i class="bi bi-check-circle-fill display-4 d-block mb-2"></i>
                    <h3 class="fw-bold mb-0">Invitation Created!</h3>
                </div>
                <div class="card-body p-5 text-center">
                    <p class="lead text-muted">A pre-approved pass has been generated for
                        <strong><?php echo htmlspecialchars($invite_details['name']); ?></strong>.
                    </p>

                    <div class="bg-light rounded-4 p-4 d-inline-block mb-4 border">
                        <?php
    $local_qr = "../" . $invite_details['qr'];
    $display_qr = (file_exists($local_qr)) ? $local_qr : "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($invite_details['code']);
?>
                        <img src="<?php echo $display_qr; ?>" alt="QR" class="img-fluid mb-3" style="max-width: 180px;">
                        <div class="h4 fw-bold text-primary mb-1"><?php echo $invite_details['code']; ?></div>
                        <div class="small fw-bold text-muted text-uppercase mb-2">Visitor Pass Code</div>
                        <div class="badge bg-primary-subtle text-primary border border-primary px-3 py-2">
                            Scheduled: <?php echo date('d-M-Y', strtotime($invite_details['date'])); ?>
                        </div>
                    </div>

                    <div class="d-grid gap-2 col-md-8 mx-auto">
                        <button class="btn btn-success fw-bold py-3" id="btn-share-wa" onclick="sharePassAutomated(<?php echo $invite_details['id']; ?>, this)">
                            <i class="bi bi-whatsapp me-2"></i> Share on WhatsApp
                        </button>
                        <button class="btn btn-outline-primary" onclick="window.print()">
                            <i class="bi bi-printer me-2"></i> Print Invitation
                        </button>
                        <a href="invite.php" class="btn btn-light border py-2">
                            <i class="bi bi-plus-lg me-2"></i> Create Another
                        </a>
                        <a href="<?php echo $home_url; ?>" class="btn btn-link text-muted">Back to Dashboard</a>
                    </div>
                </div>
            </div>

            <script>
                function sharePassAutomated(vId, btn) {
                    const orgHtml = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';

                    fetch(`../api/visit/resend_whatsapp.php?visit_id=${vId}&type=invitation`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                if (data.skipped) {
                                    btn.innerHTML = '<i class="bi bi-whatsapp"></i> Share on WhatsApp';
                                    btn.disabled = false;
                                } else {
                                    btn.innerHTML = '<i class="bi bi-check-lg"></i> SENT!';
                                    btn.classList.replace('btn-success', 'btn-outline-success');
                                }
                                Swal.fire({
                                    title: data.skipped ? 'Notice' : 'Invitation Status',
                                    text: data.message || 'Action completed.',
                                    icon: data.skipped ? 'info' : 'success',
                                    confirmButtonText: 'OK'
                                });
                            } else {
                                throw new Error(data.message || 'API Error');
                            }
                        })
                        .catch(err => {
                            console.error(err);
                            Swal.fire('Error', err.message || 'Failed to send WhatsApp message', 'error');
                            btn.disabled = false;
                            btn.innerHTML = orgHtml;
                        });
                }
            </script>

        <?php
else: ?>
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 py-3">
                    <h4 class="fw-bold mb-0"><i class="bi bi-person-plus-fill text-success me-2"></i>Invite Visitor</h4>
                </div>
                <div class="card-body p-4">
                    <form method="POST" onsubmit="
                        document.getElementById('fullPageLoader').style.display='flex';
                        document.getElementById('btnSpinner').classList.remove('d-none');
                        document.getElementById('btnIcon').classList.add('d-none');
                        document.getElementById('submitBtn').disabled = true;
                    ">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-bold">Visitor Name <span id="visitor-status" class="badge bg-success d-none ms-2">Registered</span></label>
                                <input type="text" name="name" id="visitor_name" class="form-control form-control-lg rounded-3"
                                    placeholder="Enter Full Name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Mobile Number</label>
                                <input type="tel" name="mobile" id="visitor_mobile" class="form-control form-control-lg rounded-3"
                                    placeholder="10-digit mobile" required maxlength="10" pattern="[0-9]{10}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Email (Optional)</label>
                                <input type="email" name="email" id="visitor_email" class="form-control form-control-lg rounded-3"
                                    placeholder="email@address.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Scheduled Date</label>
                                <input type="date" name="visit_date" class="form-control form-control-lg rounded-3"
                                    value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold">Purpose</label>
                                <select name="purpose" class="form-select form-select-lg rounded-3" required>
                                    <?php foreach ($purposes as $p): ?>
                                        <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?>
                                        </option>
                                    <?php
    endforeach; ?>

                                </select>
                            </div>
                            <div class="col-md-12 mt-4">
                                <button type="submit" id="submitBtn"
                                    class="btn btn-success btn-lg w-100 rounded-pill shadow-sm py-3 fw-bold">
                                    <span class="spinner-border spinner-border-sm d-none me-2" id="btnSpinner"></span>
                                    <i class="bi bi-send-fill me-2" id="btnIcon"></i> GENERATE INVITATION & PRE-APPROVE
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <script>
document.getElementById('visitor_mobile').addEventListener('input', function(e) {
                        const mobile = e.target.value;
                        if (mobile.length === 10) {
                            fetch(`api/get_visitor_by_mobile.php?mobile=${mobile}`)
                                .then(res => res.json())
                                .then(res => {
                                    if (res.success && res.found) {
                                        const nameInput = document.getElementById('visitor_name');
                                        const emailInput = document.getElementById('visitor_email');
                                        const statusBadge = document.getElementById('visitor-status');

                                        nameInput.value = res.data.name;
                                        nameInput.readOnly = true;
                                        nameInput.classList.add('bg-light');

                                        emailInput.value = res.data.email || '';
                                        if (res.data.email) {
                                            emailInput.readOnly = true;
                                            emailInput.classList.add('bg-light');
                                        }

                                        statusBadge.classList.remove('d-none');
                                    }
                                });
                        } else {
                            // Reset if number is changed
                            const nameInput = document.getElementById('visitor_name');
                            const emailInput = document.getElementById('visitor_email');
                            const statusBadge = document.getElementById('visitor-status');

                            if (nameInput.readOnly) {
                                nameInput.value = '';
                                nameInput.readOnly = false;
                                nameInput.classList.remove('bg-light');

                                emailInput.value = '';
                                emailInput.readOnly = false;
                                emailInput.classList.remove('bg-light');

                                statusBadge.classList.add('d-none');
                            }
                        }
                    });
                </script>
            </div>
        <?php
endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>