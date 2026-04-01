<?php
require_once 'header.php';

$visitor = [
    'name' => '',
    'email' => '',
    'address' => '',
    'id_proof_type' => '',
    'id_proof_number' => '',
    'mobile' => ''
];
$mobile = '';

// Pre-fill if mobile search
if (isset($_GET['mobile'])) {
    $mobile = sanitize($_GET['mobile']);
    $stmt = $pdo->prepare("SELECT * FROM visitors WHERE mobile = ?");
    $stmt->execute([$mobile]);
    $found = $stmt->fetch();
    if ($found) {
        $visitor = $found;
    }
}

// Fetch Employees
$employees = $pdo->query("SELECT * FROM employees WHERE status='active' ORDER BY name")->fetchAll();
// Fetch Purposes
$purposes = $pdo->query("SELECT * FROM visit_purposes")->fetchAll();
// Fetch Access Areas
$access_areas = $pdo->query("SELECT * FROM access_areas ORDER BY area_name")->fetchAll();

// Check if WhatsApp OTP is enabled
$wa_processes_stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'whatsapp_enabled_processes'");
$wa_processes_val = $wa_processes_stmt ? $wa_processes_stmt->fetchColumn() : null;
$wa_processes = $wa_processes_val ? json_decode($wa_processes_val, true) : [];
$is_otp_enabled = is_array($wa_processes) && in_array('visitor_otp_verification', $wa_processes);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = sanitize($_POST['name']);
    $mobile = sanitize($_POST['mobile']);
    $email = sanitize($_POST['email']);
    $address = sanitize($_POST['address']);
    $employee_id = $_POST['employee_id'];
    $purpose = sanitize($_POST['purpose']);
    $id_proof_type = sanitize($_POST['id_proof_type']);
    $id_proof_number = sanitize($_POST['id_proof_number']);
    $photo_data = $_POST['photo_data']; // Base64
    $assets_carried = sanitize($_POST['assets_carried'] ?? '');
    $access_area = sanitize($_POST['access_area'] ?? '');
    if (empty($access_area))
        $access_area = 'Not Assigned';

    try {
        $pdo->beginTransaction();

        // Check if visitor exists
        $stmt = $pdo->prepare("SELECT id FROM visitors WHERE mobile = ?");
        $stmt->execute([$mobile]);
        $exist = $stmt->fetch();

        $visitor_id = 0;
        $photo_path = '';

        // Handle Photo Upload / Save
        if (!empty($photo_data)) {
            $data = explode(',', $photo_data); // base64,....
            $content = base64_decode($data[1]);
            $filename = 'uploads/photos/' . uniqid() . '.jpg';
            file_put_contents('../' . $filename, $content);
            $photo_path = $filename;
        }

        if ($exist) {
            $visitor_id = $exist['id'];
            // Update details
            $sql = "UPDATE visitors SET name=?, email=?, address=?, id_proof_type=?, id_proof_number=?";
            $params = [$name, $email, $address, $id_proof_type, $id_proof_number];

            if ($photo_path) {
                $sql .= ", photo_path=?";
                $params[] = $photo_path;
            }
            $sql .= " WHERE id=?";
            $params[] = $visitor_id;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        } else {
            // New Visitor
            $stmt = $pdo->prepare("INSERT INTO visitors (name, mobile, email, address, photo_path, id_proof_type, id_proof_number) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $mobile, $email, $address, $photo_path, $id_proof_type, $id_proof_number]);
            $visitor_id = $pdo->lastInsertId();
        }

        $invited_visit_id = $_POST['invited_visit_id'] ?? null;

        if ($invited_visit_id) {
            // Update existing Invitation - Set to 'approved' status but 'pending' approval_status 
            // so host can "Acknowledge" it. PDF pass will be sent upon acknowledgement.
            $visit_id = $invited_visit_id;
            $current_time = current_datetime();
            $stmt = $pdo->prepare("UPDATE visits SET status='approved', approval_status='pending', assets_carried=?, id_proof_type=?, id_proof_number=?, access_area=?, visit_photo=?, created_at=?, created_by=? WHERE id=?");
            $stmt->execute([$assets_carried, $id_proof_type, $id_proof_number, $access_area, $photo_path, $current_time, $_SESSION['user_id'], $visit_id]);
            logAction($pdo, $_SESSION['user_id'], "Invited visitor arrived, awaiting host acknowledgement. (Visit ID: $visit_id)");
        } else {
            // Create New Visit with pending status (requires host approval)
            $visit_code = generateVisitCode();

            // Generate and Save QR Code
            $qr_content = @file_get_contents("https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($visit_code));
            $qr_code_path = '';
            if ($qr_content) {
                $qr_filename = 'uploads/qrcodes/' . $visit_code . '.png';
                file_put_contents('../' . $qr_filename, $qr_content);
                $qr_code_path = $qr_filename;
            }

            $current_time = current_datetime();
            // Explicitly set created_at from PHP to ensure correct timezone (overriding DB default)
            $stmt = $pdo->prepare("INSERT INTO visits (visitor_id, visit_photo, employee_id, purpose, visit_code, status, approval_status, assets_carried, id_proof_type, id_proof_number, qr_code_path, created_at, access_area, created_by) VALUES (?, ?, ?, ?, ?, 'pending', 'pending', ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$visitor_id, $photo_path, $employee_id, $purpose, $visit_code, $assets_carried, $id_proof_type, $id_proof_number, $qr_code_path, $current_time, $access_area, $_SESSION['user_id']]);
            $visit_id = $pdo->lastInsertId();
            logAction($pdo, $_SESSION['user_id'], "Registered new visitor visit (Pending Approval) ID: $visitor_id (Visit ID: $visit_id)");
        }

        // Handle Accompanying Members
        $members = $_POST['members'] ?? [];
        $valid_members = array_filter($members, fn($m) => !empty(trim($m)));
        $total_visitors = 1 + count($valid_members);

        // Update total count
        $pdo->prepare("UPDATE visits SET total_visitors = ? WHERE id = ?")->execute([$total_visitors, $visit_id]);

        // Insert members
        if (!empty($valid_members)) {
            // Remove existing members if any (for invited visits) at this point it's safer
            $pdo->prepare("DELETE FROM visit_members WHERE visit_id = ?")->execute([$visit_id]);

            $stmtMem = $pdo->prepare("INSERT INTO visit_members (visit_id, name) VALUES (?, ?)");
            foreach ($valid_members as $memName) {
                $stmtMem->execute([$visit_id, sanitize($memName)]);
            }
        }

        $pdo->commit();

        // --- MOBILE PUSH NOTIFICATIONS ---
        try {
            require_once '../includes/push_helper.php';
            // Fetch visitor name if not available
            if (!isset($name)) {
                $stmt = $pdo->prepare("SELECT name FROM visitors WHERE id = ?");
                $stmt->execute([$visitor_id]);
                $name = $stmt->fetchColumn();
            }

            // Fetch full visitor details for payload
            $stmtVis = $pdo->prepare("SELECT * FROM visitors WHERE id = ?");
            $stmtVis->execute([$visitor_id]);
            $visitorObj = $stmtVis->fetch(PDO::FETCH_ASSOC);

            $pushData = [
                'visitor_id' => (string) $visitor_id,
                'visit_id' => (string) $visit_id,
                'visitor_name' => (string) $name,
                'visitor_mobile' => (string) ($visitorObj['mobile'] ?? ''),
                'purpose' => (string) $purpose,
                'company' => (string) ($visitorObj['company'] ?? 'General Visitor'),
                'photo_url' => $photo_path ? BASE_URL . $photo_path : '',
                'type' => 'visitor_arrival'
            ];

            if ($invited_visit_id) {
                // Fetch employee_id for invited visit
                $stmt = $pdo->prepare("SELECT employee_id FROM visits WHERE id = ?");
                $stmt->execute([$visit_id]);
                $employee_id = $stmt->fetchColumn();
                sendPushNotification($pdo, $employee_id, "Invited Visitor Arrived", "$name has arrived at the security gate.", $pushData);
            } else {
                sendPushNotification($pdo, $employee_id, "New Visitor Waiting", "$name is at the gate for $purpose. Tap to open.", $pushData);
            }
        } catch (Exception $e) {
            error_log("Push Error: " . $e->getMessage());
        }

        // --- WHATSAPP NOTIFICATIONS (NEW) ---
        $waResponse = true; // default
        try {
            require_once '../includes/whatsapp_helper.php';
            // Fetch host details for WhatsApp
            $stmtHost = $pdo->prepare("SELECT mobile, name FROM employees WHERE id = ?");
            $stmtHost->execute([$employee_id]);
            $host = $stmtHost->fetch(PDO::FETCH_ASSOC);

            if ($host && !empty($host['mobile'])) {
                $trace_msg = "[" . current_datetime() . "] TRACE (security/register): Attempting WA to {$host['name']} ({$host['mobile']}) using visitor_arrival_host_alert\n";
                file_put_contents(__DIR__ . '/../whatsapp_log.txt', $trace_msg, FILE_APPEND);

                $waResponse = sendWhatsAppNotification(
                    $host['mobile'],
                    "Visitor $name has arrived to meet you.",
                    'visitor_arrival_host_alert',
                    ["*{$host['name']}*", "*$name*", "*$purpose*"]
                );
            } else {
                $trace_msg = "[" . current_datetime() . "] TRACE (security/register): Host NOT found or mobile empty for ID: $employee_id\n";
                file_put_contents(__DIR__ . '/../whatsapp_log.txt', $trace_msg, FILE_APPEND);
            }
        } catch (Exception $e) {
            error_log("WhatsApp System Error in security/register: " . $e->getMessage());
            $trace_msg = "[" . current_datetime() . "] TRACE (security/register): Error: " . $e->getMessage() . "\n";
            file_put_contents(__DIR__ . '/../whatsapp_log.txt', $trace_msg, FILE_APPEND);
            $waResponse = false;
        }

        // Redirect directly to dashboard
        $waStatusParam = "";
        if ($waResponse === 'skipped_disabled') {
            $waStatusParam = "&wa_status=skipped_disabled";
        } else if ($waResponse === 'skipped_not_live') {
            $waStatusParam = "&wa_status=skipped_not_live";
        }

        if ($invited_visit_id) {
            redirect($home_url . "?new_visit_id=$visit_id&msg=" . urlencode("Visitor arrival registered. Awaiting host acknowledgement.") . $waStatusParam);
        } else {
            redirect($home_url . "?new_visit_id=$visit_id" . $waStatusParam);
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>
<!-- Redundant library removed for AppDialog consistency -->
<style>
    .reg-card {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        border: 1px solid rgba(255, 255, 255, 0.3);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }

    .reg-header {
        background: linear-gradient(135deg, #4361ee 0%, #4895ef 100%);
        color: white;
        padding: 2.5rem 2rem;
        text-align: center;
    }

    .reg-header h2 {
        font-weight: 800;
        letter-spacing: -0.5px;
        margin-bottom: 0.5rem;
    }

    .reg-body {
        padding: 2.5rem;
    }

    .section-title {
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: #6c757d;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-title::after {
        content: "";
        height: 1px;
        background: #e9ecef;
        flex-grow: 1;
    }

    .camera-box {
        width: 100%;
        height: 240px;
        background: #1a1a1a;
        border-radius: 15px;
        overflow: hidden;
        position: relative;
        border: 4px solid #fff;
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }

    .camera-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(0, 0, 0, 0.4);
        color: white;
        transition: 0.3s;
    }

    /* Enhanced Form Controls */
    .form-floating>.form-control,
    .form-floating>.form-select {
        border: 2px solid #dee2e6;
        border-radius: 12px;
        background-color: #f8f9fa;
        height: 60px;
        padding-top: 1.7rem;
        padding-bottom: 0.5rem;
        font-weight: 600;
        color: #212529;
        transition: all 0.2s ease-in-out;
    }

    .form-floating>.form-control:focus,
    .form-floating>.form-select:focus {
        border-color: #4361ee;
        background-color: #fff;
        box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15);
    }

    .form-floating>label {
        padding-left: 1.25rem;
        font-weight: 500;
        color: #6c757d;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
        padding-top: 1rem;
        z-index: 5;
    }

    /* Select2 Modern Styling */
    .select2-container--default .select2-selection--single {
        border: 2px solid #dee2e6 !important;
        border-radius: 12px !important;
        background-color: #f8f9fa !important;
        height: 60px !important;
        padding-top: 1.2rem !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 40px !important;
        padding-left: 1.25rem !important;
        font-weight: 600 !important;
        color: #212529 !important;
    }

    .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 58px !important;
    }

    .select2-container--default.select2-container--focus .select2-selection--single {
        border-color: #4361ee !important;
        background-color: #fff !important;
        box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.15) !important;
    }

    .select2-dropdown {
        border: none !important;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1) !important;
        border-radius: 12px !important;
    }

    .select2-search__field {
        border-radius: 8px !important;
        padding: 8px !important;
    }

    /* Section Styling */
    .section-card {
        background: #fff;
        border-radius: 16px;
        padding: 1.5rem;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
        border: 1px solid #e9ecef;
        margin-bottom: 1.5rem;
    }

    .section-header {
        background: linear-gradient(to right, rgba(67, 97, 238, 0.08), transparent);
        color: #4361ee;
        font-weight: 800;
        font-size: 0.95rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1.5rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.8rem 1rem;
        border-radius: 8px;
        border-left: 5px solid #4361ee;
    }

    .section-header i {
        font-size: 1.25rem;
        color: #4361ee;
    }

    /* Active Label Color */
    .form-floating>.form-control:focus~label,
    .form-floating>.form-select:focus~label {
        color: #4361ee;
        font-weight: 700;
    }

    .form-floating>.form-control:hover,
    .form-floating>.form-select:hover {
        border-color: #b1b7c1;
    }

    .otp-modal {
        background: rgba(0, 0, 0, 0.8);
        backdrop-filter: blur(15px);
    }

    .otp-card {
        background: white;
        border-radius: 24px;
        padding: 2.5rem;
        max-width: 400px;
        width: 100%;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }

    .otp-input,
    .otp-box {
        width: 45px;
        height: 55px;
        text-align: center;
        font-size: 1.5rem;
        font-weight: 700;
        margin: 0 5px;
        border-radius: 12px;
        border: 2px solid #e9ecef;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        background: white;
    }

    .otp-box.active {
        border-color: #4361ee;
        box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
    }

    .verification-status {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 1.5rem;
        font-size: 2rem;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-verified {
        background: #d4edda;
        color: #155724;
    }

    .status-failed {
        background: #f8d7da;
        color: #721c24;
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
    <div class="spinner-border text-primary shadow-sm" style="width: 4rem; height: 4rem; border-width: 0.4rem;"
        role="status">
        <span class="visually-hidden">Loading...</span>
    </div>
    <div class="mt-4 text-center">
        <h4 class="fw-bold text-primary mb-1">Checking Records...</h4>
        <p class="text-muted small px-3">Please wait while we secure your entry and notify the host.</p>
    </div>
</div>

<div class="row justify-content-center py-4">
    <div class="col-xl-9">
        <div class="reg-card animate__animated animate__fadeIn">
            <div class="reg-header">
                <h2><i class="bi bi-person-plus-fill me-2"></i>Visitor Registration</h2>
                <p class="opacity-75 mb-0">Experience a modern way of managing visitor entries</p>
            </div>

            <div class="reg-body">
                <!-- Invitation / Pre-Approval Search -->
                <div class="section-card mb-4 border-primary border-2 shadow-sm animate__animated animate__fadeInDown">
                    <div class="section-header bg-primary text-white border-0"><i class="bi bi-qr-code-scan"></i>
                        Pre-Approved? Look up Invitation</div>
                    <div class="p-2">
                        <div class="input-group input-group-lg">
                            <input type="text" id="invitationSearch"
                                class="form-control border-primary border-2 rounded-start-pill px-4"
                                placeholder="Enter Mobile # or Visit Code">
                            <button class="btn btn-primary rounded-end-pill px-4" type="button"
                                onclick="checkInvitation()">
                                <i class="bi bi-search me-2"></i> FIND INVITE
                            </button>
                        </div>
                        <div id="inviteResult" class="mt-3" style="display:none;"></div>
                    </div>
                </div>

                <form method="POST" id="regForm" onsubmit="return handleRegistration(event)">
                    <input type="hidden" name="photo_data" id="photo_data">
                    <input type="hidden" name="otp_verified" id="otp_verified_flag" value="0">

                    <div class="row g-4">
                        <!-- Left Column: Forms -->
                        <div class="col-lg-8">
                            <!-- Visitor Info Card -->
                            <div class="section-card mb-4">
                                <div class="section-header"><i class="bi bi-person-badge-fill"></i> Visitor Information
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-12">
                                        <div class="form-floating">
                                            <input type="text" name="name" class="form-control" id="nameInput"
                                                placeholder="Full Name" required
                                                value="<?php echo htmlspecialchars($visitor['name']); ?>">
                                            <label for="nameInput">Full Name</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="tel" name="mobile" class="form-control" id="mobileInput"
                                                placeholder="Mobile" required pattern="[0-9]{10}" maxlength="10"
                                                minlength="10" title="Please enter exactly 10 digits"
                                                oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 10)"
                                                value="<?php echo htmlspecialchars($mobile); ?>">
                                            <label for="mobileInput">Mobile Number</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <input type="email" name="email" class="form-control" id="emailInput"
                                                placeholder="Email"
                                                value="<?php echo htmlspecialchars($visitor['email']); ?>">
                                            <label for="emailInput">Email Address (Optional)</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <input type="text" name="address" class="form-control" id="addressInput"
                                                placeholder="Address"
                                                value="<?php echo htmlspecialchars($visitor['address']); ?>">
                                            <label for="addressInput">Company / Address</label>
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="form-check form-switch p-2 bg-light rounded border">
                                            <input class="form-check-input ms-0 me-2" type="checkbox"
                                                id="captureIdToggle" onchange="toggleIdProof(this)">
                                            <label class="form-check-label fw-bold lead-sm mt-1"
                                                for="captureIdToggle">Capture ID Proof (Optional)</label>
                                        </div>
                                    </div>

                                    <div class="row g-3 mt-0" id="idProofContainer" style="display:none;">
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <select name="id_proof_type" class="form-select" id="idProofSelect">
                                                    <option value="Aadhaar" <?php echo ($visitor['id_proof_type'] == 'Aadhaar') ? 'selected' : ''; ?>>
                                                        Aadhaar Card</option>
                                                    <option value="PAN" <?php echo ($visitor['id_proof_type'] == 'PAN') ? 'selected' : ''; ?>>PAN Card</option>
                                                    <option value="Driving License" <?php echo ($visitor['id_proof_type'] == 'Driving License') ? 'selected' : ''; ?>>Driving License</option>
                                                    <option value="Voter ID" <?php echo ($visitor['id_proof_type'] == 'Voter ID') ? 'selected' : ''; ?>>
                                                        Voter ID</option>
                                                    <option value="Other" <?php echo ($visitor['id_proof_type'] == 'Other') ? 'selected' : ''; ?>>Other
                                                    </option>
                                                </select>
                                                <label for="idProofSelect">ID Proof Type</label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" name="id_proof_number" class="form-control"
                                                    id="idNumInput" placeholder="ID Number"
                                                    value="<?php echo htmlspecialchars($visitor['id_proof_number']); ?>">
                                                <label for="idNumInput">ID Number</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Accompanying Visitors -->
                            <div class="section-card mb-4">
                                <div class="section-header">
                                    <i class="bi bi-people-fill"></i> Accompanying Visitors (Optional)
                                </div>
                                <div id="membersContainer">
                                    <!-- Dynamic Rows -->
                                </div>
                                <button type="button" class="btn btn-outline-primary w-100 border-2 border-dashed py-2"
                                    onclick="addMemberRow()">
                                    <i class="bi bi-person-plus-fill me-2"></i> Add Additional Member
                                </button>
                            </div>

                            <!-- Visit Details Card (Moved from Right) -->
                            <div class="section-card">
                                <div class="section-header"><i class="bi bi-building-fill-check"></i> Visit Details
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <select name="employee_id" class="form-select" id="hostSelect" required>
                                                <option value="">Select Host...</option>
                                                <?php foreach ($employees as $emp): ?>
                                                    <option value="<?php echo $emp['id']; ?>">
                                                        <?php echo htmlspecialchars($emp['name'] . ' (' . $emp['department'] . ')'); ?>
                                                    </option>
                                                    <?php
                                                endforeach; ?>
                                            </select>
                                            <label for="hostSelect">Who to Meet?</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-floating">
                                            <select name="purpose" class="form-select" id="purposeSelect" required>
                                                <?php foreach ($purposes as $p): ?>
                                                    <option value="<?php echo htmlspecialchars($p['purpose_name']); ?>">
                                                        <?php echo htmlspecialchars($p['purpose_name']); ?>
                                                    </option>
                                                    <?php
                                                endforeach; ?>
                                                <option value="Other">Other Reason</option>
                                            </select>
                                            <label for="purposeSelect">Purpose</label>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="form-floating">
                                            <select name="access_area" class="form-select" id="areaSelect">
                                                <option value="">None / Not Specified</option>
                                                <?php foreach ($access_areas as $aa): ?>
                                                    <option value="<?php echo htmlspecialchars($aa['area_name']); ?>">
                                                        <?php echo htmlspecialchars($aa['area_name']); ?>
                                                    </option>
                                                    <?php
                                                endforeach; ?>
                                            </select>
                                            <label for="areaSelect">Designated Access Area (Optional)</label>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="form-floating">
                                            <textarea name="assets_carried" class="form-control" id="assetsField"
                                                placeholder="Assets" style="height: 100px"></textarea>
                                            <label for="assetsField">Assets Carried (Optional)</label>
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>

                        <!-- Right Column: Camera Only -->
                        <div class="col-lg-4">
                            <div class="section-card mb-4">
                                <div class="section-header"><i class="bi bi-camera-video-fill"></i> Live Photo</div>
                                <div class="camera-box mb-3 shadow-sm border-0">
                                    <div id="camera_view" style="width:100%; height:100%;"></div>
                                    <div id="photo_preview" style="display:none; width:100%; height:100%;">
                                        <img id="captured_image" src=""
                                            style="width:100%; height:100%; object-fit:cover;">
                                    </div>
                                </div>
                                <button type="button"
                                    class="btn btn-primary btn-lg w-100 py-3 shadow-sm pulse-animation"
                                    onclick="takeSnapshot()">
                                    <i class="bi bi-camera me-2"></i> CAPTURE PHOTO
                                </button>
                            </div>

                            <!-- OTP Toggle at the end of right column -->
                            <div class="section-card border-primary">
                                <div class="form-check form-switch">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" id="requireOtpToggle"
                                        name="require_otp" <?php echo !$is_otp_enabled ? 'disabled' : ''; ?>>
                                    <label class="form-check-label fw-bold small text-uppercase mt-1"
                                        for="requireOtpToggle">
                                        Enable OTP Check
                                        <?php if (!$is_otp_enabled): ?>
                                            <span class="text-danger ms-2" style="font-size: 0.7rem;">(WhatsApp
                                                Disabled)</span>
                                            <?php
                                        endif; ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 text-center">
                        <button type="submit" id="mainSubmitBtn"
                            class="btn btn-success btn-lg px-5 py-3 rounded-pill shadow-lg fw-bold">
                            <span class="spinner-border spinner-border-sm me-2 d-none" id="btnLoader" role="status"
                                aria-hidden="true"></span>
                            <span id="btnText">COMPLETE REGISTRATION & CHECK-IN</span> <i
                                class="bi bi-arrow-right-circle ms-2" id="btnIcon"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- OTP Verification Modal -->
<div class="modal fade" id="otpModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="otp-card text-center animate__animated animate__zoomIn">
            <div id="otpLoading" class="mb-3">
                <div class="spinner-border text-primary" role="status"></div>
                <p class="mt-2 fw-bold text-muted">Sending OTP to Host...</p>
            </div>

            <div id="otpInputArea" style="display:none;">
                <div class="verification-status status-pending" id="statusIcon">
                    <i class="bi bi-shield-lock-fill"></i>
                </div>
                <h4 class="fw-bold mb-1">Verify Entry</h4>
                <p class="text-muted small mb-4">Host has been sent a 6-digit OTP. Please enter it below to authorize
                    entry.</p>

                <div class="position-relative d-flex justify-content-center mb-4">
                    <input type="text" id="realOtpInput" maxlength="6" pattern="\d*" inputmode="numeric"
                        style="position: absolute; opacity: 0; left: 0; top: 0; width: 100%; height: 100%; z-index: 10; cursor: pointer;">
                    <div class="d-flex" id="otpBoxDisplay">
                        <div class="otp-box" id="box-0"></div>
                        <div class="otp-box" id="box-1"></div>
                        <div class="otp-box" id="box-2"></div>
                        <div class="otp-box" id="box-3"></div>
                        <div class="otp-box" id="box-4"></div>
                        <div class="otp-box" id="box-5"></div>
                    </div>
                </div>

                <div id="otpError" class="alert alert-danger py-2 small d-none">Invalid OTP. Please try again.</div>

                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-primary fw-bold" id="verifyBtn"
                        onclick="verifyOTP(event)">VERIFY &
                        PROCEED</button>
                    <button type="button" class="btn btn-link btn-sm text-muted" onclick="resendOTP()">Resend
                        OTP</button>
                </div>

            </div>

            <div class="mt-3">
                <button type="button" class="btn btn-light btn-sm w-100" data-bs-dismiss="modal">Cancel
                    Verification</button>
            </div>
        </div>
    </div>
</div>

<script src="../assets/js/app.js"></script>
<script>
    let otpModalInstance = null;
    let currentOtp = null;
    let currentMobile = null;


    // Add immediate trigger when toggle is clicked
    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.getElementById('requireOtpToggle');
        if (toggle) {
            toggle.addEventListener('change', function () {
                if (this.checked) {
                    const mobile = document.getElementById('mobileInput').value;
                    const photoData = document.getElementById('photo_data').value;

                    if (!mobile || mobile.length < 10) {
                        AppDialog.show('Mobile Required', 'Please enter a valid Visitor Mobile number first.', 'warning');
                        this.checked = false;
                        return;
                    }

                    // User Request: Confirm fill status
                    Swal.fire({
                        title: 'Are you ready?',
                        text: "Have you filled all required details and Photo click?",
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: '#4361ee',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Yes, All filled!',
                        cancelButtonText: 'No, let me check'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            startOTPVerification(true); // pass true to indicate it's from toggle
                        } else {
                            this.checked = false;
                        }
                    });
                }
            });
        }
    });

    // Simplified OTP Logic using hidden master input
    const realInput = document.getElementById('realOtpInput');
    const displayBoxes = document.querySelectorAll('.otp-box');

    document.getElementById('otpModal').addEventListener('shown.bs.modal', function () {
        realInput.focus();
        updateDisplay();
    });

    // Ensure focus returns to input if modal is clicked
    document.getElementById('otpModal').addEventListener('click', function () {
        realInput.focus();
    });

    realInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9]/g, '');
        updateDisplay();
        if (this.value.length === 6) {
            verifyOTP();
        }
    });

    realInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && this.value.length === 6) {
            verifyOTP();
        }
    });

    function updateDisplay() {
        const val = realInput.value;
        displayBoxes.forEach((box, i) => {
            box.innerText = val[i] || '';
            box.classList.toggle('active', i === val.length);
        });
    }



    // Initialize Select2 for Host Search
    $(document).ready(function () {
        $('#hostSelect').select2({
            placeholder: "Search Host by Name or Dept...",
            allowClear: true,
            width: '100%',
            dropdownParent: $('#hostSelect').parent()
        });

        // Trigger invitation check if code is in URL
        const urlParams = new URLSearchParams(window.location.search);
        const code = urlParams.get('code');
        if (code) {
            document.getElementById('invitationSearch').value = code;
            checkInvitation();
        }
    });

    // Auto-fill logic
    document.getElementById('mobileInput').addEventListener('blur', function () {
        const mobile = this.value;
        if (mobile.length >= 10) {
            fetch(`../api/visitor/search.php?mobile=${mobile}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        const v = data.data;
                        document.getElementById('nameInput').value = v.name || '';
                        document.getElementById('emailInput').value = v.email || '';
                        document.getElementById('addressInput').value = v.address || '';
                        
                        // Force NEW photo capture for every visit - CLEAR old one
                        const photoPreview = document.getElementById('photo_preview');
                        const cameraView = document.getElementById('camera_view');
                        if (photoPreview) photoPreview.style.display = 'none';
                        if (cameraView) cameraView.style.display = 'block';
                        document.getElementById('photo_data').value = '';
                        // Also clear the captured image src to be safe
                        const capturedImg = document.getElementById('captured_image');
                        if (capturedImg) capturedImg.src = '';

                        // Populate ID Proof
                        if (v.id_proof_number) {
                            document.getElementById('idProofSelect').value = v.id_proof_type || 'Aadhaar';
                            document.getElementById('idNumInput').value = v.id_proof_number;
                            const toggle = document.getElementById('captureIdToggle');
                            if (toggle) { // Ensure the toggle element exists
                                toggle.checked = true;
                                toggleIdProof(toggle);
                            }
                        } else {
                            // If no ID proof, ensure the toggle is off and container hidden
                            const toggle = document.getElementById('captureIdToggle');
                            if (toggle) {
                                toggle.checked = false;
                                toggleIdProof(toggle);
                            }
                        }

                        // If last host exists, auto-select it in Select2
                        if (v.last_visit && v.last_visit.employee_id) {
                            $('#hostSelect').val(v.last_visit.employee_id).trigger('change');
                        }


                        let visitInfo = `Welcome back, <b>${v.name}</b>. Your details have been auto-filled.`;

                        if (v.last_visit) {
                            visitInfo += `
                                <div class="mt-3 text-start p-3 bg-light rounded border">
                                    <h6 class="text-muted text-uppercase small fw-bold mb-2">Last Visit Details:</h6>
                                    <div class="small">
                                        <div><i class="bi bi-calendar-check me-2"></i> ${v.last_visit.check_in_time}</div>
                                        <div><i class="bi bi-person me-2"></i> Met: ${v.last_visit.host_name}</div>
                                        <div><i class="bi bi-card-text me-2"></i> Purpose: ${v.last_visit.purpose}</div>
                                    </div>
                                </div>`;
                        }

                        AppDialog.show({
                            title: 'Existing Visitor Found!',
                            html: visitInfo,
                            icon: 'info',
                            confirmButtonText: 'OK, Continue'
                        });
                    }
                })
                .catch(console.error);
        }
    });

    // Auto-start camera on load
    window.addEventListener('load', startCamera);

    function toggleIdProof(toggle) {
        const container = document.getElementById('idProofContainer');
        if (toggle.checked) {
            container.style.display = 'flex';
            container.classList.add('animate__animated', 'animate__fadeIn');
        } else {
            container.style.display = 'none';
        }
    }

    function addMemberRow() {
        const container = document.getElementById('membersContainer');
        const count = container.children.length;
        const div = document.createElement('div');
        div.className = 'row g-2 mb-3 align-items-center animate__animated animate__fadeIn';
        div.innerHTML = `
            <div class="col-10">
                <div class="form-floating">
                    <input type="text" name="members[]" class="form-control" placeholder="Member Name">
                    <label>Member Name</label>
                </div>
            </div>
            <div class="col-2">
                <button type="button" class="btn btn-outline-danger w-100 h-100 d-flex align-items-center justify-content-center" onclick="this.closest('.row').remove()" style="height: 58px !important;">
                    <i class="bi bi-trash fs-5"></i>
                </button>
            </div>
        `;
        container.appendChild(div);
    }

    async function handleRegistration(e) {
        if (e) e.preventDefault();

        const mobile = document.getElementById('mobileInput').value;
        if (!mobile || mobile.length !== 10 || isNaN(mobile)) {
            AppDialog.show({
                title: 'Invalid Mobile Number',
                text: 'Please enter a valid 10-digit mobile number.',
                icon: 'error'
            });
            return false;
        }

        const requireOtp = document.getElementById('requireOtpToggle').checked;
        const verifiedFlag = document.getElementById('otp_verified_flag').value;

        if (requireOtp && verifiedFlag == "0") {
            startOTPVerification();
            return false;
        }

        // Proceed to AJAX submission
        submitRegistrationForm();
        return false;
    }

    async function submitRegistrationForm() {
        const fullLoader = document.getElementById('fullPageLoader');
        if (fullLoader) {
            fullLoader.style.display = 'flex';
        }

        const btn = document.getElementById('mainSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> PROCESSING...';
        }

        const form = document.getElementById('regForm');
        const formData = new FormData(form);

        try {
            const response = await fetch('register.php', {
                method: 'POST',
                body: formData
            });

            if (response.ok) {
                // Transform loader to Success state
                if (fullLoader) {
                    fullLoader.innerHTML = `
                        <div class="text-center animate__animated animate__zoomIn">
                            <div class="mb-4">
                                <i class="bi bi-check-circle-fill text-success" style="font-size: 5rem; filter: drop-shadow(0 10px 15px rgba(25, 135, 84, 0.2));"></i>
                            </div>
                            <h2 class="fw-bold text-dark mb-1">Registration Complete!</h2>
                            <p class="text-muted fs-5">Visitor has been registered successfully.</p>
                            <div class="spinner-border spinner-border-sm text-primary mt-2" role="status"></div>
                            <small class="text-muted d-block mt-1">Redirecting to Dashboard...</small>
                        </div>
                    `;
                }

                // Wait 2 seconds for visual confirmation then redirect
                setTimeout(() => {
                    if (response.redirected) {
                        window.location.href = response.url;
                    } else {
                        window.location.href = 'dashboard.php';
                    }
                }, 2000);
            } else {
                const text = await response.text();
                throw new Error("Server returned an error state.");
            }
        } catch (err) {
            console.error("Submission Error:", err);
            if (fullLoader) fullLoader.style.display = 'none';
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = 'COMPLETE REGISTRATION & CHECK-IN <i class="bi bi-arrow-right-circle ms-2"></i>';
            }
            AppDialog.show('Registration Error', 'Something went wrong. Please try again.', 'error');
        }
    }

    async function startOTPVerification(fromToggle = false) {
        const visitorMobile = document.getElementById('mobileInput').value;
        if (!visitorMobile || visitorMobile.length < 10) {
            AppDialog.show('Mobile Required', 'Please enter a valid Visitor Mobile number first.', 'warning');
            if (fromToggle) document.getElementById('requireOtpToggle').checked = false;
            return;
        }

        // Initialize Modal if not exists
        if (!otpModalInstance) {
            otpModalInstance = new bootstrap.Modal(document.getElementById('otpModal'));
        }
        otpModalInstance.show();

        document.getElementById('otpLoading').style.display = 'block';
        document.getElementById('otpInputArea').style.display = 'none';

        try {
            // Send OTP to Visitor's Mobile
            const res = await fetch(`../api/visit/send_otp.php?mobile=${visitorMobile}`);
            const data = await res.json();

            if (data.status === 'success') {
                document.getElementById('otpLoading').style.display = 'none';
                document.getElementById('otpInputArea').style.display = 'block';

                if (data.data) {
                    currentOtp = data.data.debug_otp;
                    currentMobile = data.data.target_mobile;
                }
            } else {
                AppDialog.show('OTP Error', data.message, 'error');
                otpModalInstance.hide();
                if (fromToggle) document.getElementById('requireOtpToggle').checked = false;
            }
        } catch (err) {
            AppDialog.show('Connection Error', 'Failed to send OTP. Please check server connection.', 'error');
            otpModalInstance.hide();
            if (fromToggle) document.getElementById('requireOtpToggle').checked = false;
        }
    }

    async function verifyOTP() {
        const otp = realInput.value;

        if (otp.length < 6) {
            AppDialog.show('Invalid OTP', 'Please enter all 6 digits.', 'warning');
            return;
        }

        const btn = document.getElementById('verifyBtn');
        if (!btn) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verifying...';

        try {
            // Verify using visitor mobile
            const res = await fetch(`../api/visit/verify_otp.php?mobile=${currentMobile}&otp=${otp}`);
            const data = await res.json();

            if (data.status === 'success') {
                const icon = document.getElementById('statusIcon');
                icon.classList.replace('status-pending', 'status-verified');
                icon.innerHTML = '<i class="bi bi-check-lg"></i>';

                document.getElementById('otpError').classList.add('d-none');

                setTimeout(() => {
                    document.getElementById('otp_verified_flag').value = "1";
                    const m = bootstrap.Modal.getInstance(document.getElementById('otpModal'));
                    if (m) m.hide();

                    // Final submit via AJAX
                    submitRegistrationForm();
                }, 800);
            } else {
                document.getElementById('otpError').classList.remove('d-none');
                btn.disabled = false;
                btn.innerHTML = 'VERIFY & PROCEED';
                realInput.value = '';
                updateDisplay();
                realInput.focus();
            }
        } catch (err) {
            Swal.fire('Error', 'Verification Error. Please try again.', 'error');
            btn.disabled = false;
            btn.innerHTML = 'VERIFY & PROCEED';
        }
    }

    async function resendOTP() {
        startOTPVerification();
    }

    // Invitation System
    async function checkInvitation() {
        const query = document.getElementById('invitationSearch').value;
        if (!query) return;

        const resDiv = document.getElementById('inviteResult');
        resDiv.innerHTML = '<div class="text-center p-3"><div class="spinner-border spinner-border-sm text-primary"></div> Searching...</div>';
        resDiv.style.display = 'block';

        try {
            const res = await fetch(`../api/visit/check_invitation.php?query=${query}`);
            const data = await res.json();

            if (data.status === 'success') {
                const inv = data.data;
                resDiv.innerHTML = `
                    <div class="alert alert-success border-2 shadow-sm rounded-4 p-4 animate__animated animate__pulse">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="bg-white rounded-circle p-3 shadow-sm">
                                    <i class="bi bi-patch-check-fill text-success fs-1"></i>
                                </div>
                            </div>
                            <div class="col">
                                <h5 class="fw-bold mb-1">Invitation Found!</h5>
                                <div class="mb-1 text-dark">Visitor: <strong>${inv.visitor_name}</strong></div>
                                <div class="text-muted small">Invited by: ${inv.host_name} (${inv.host_dept})</div>
                                <div class="mt-1 small text-primary fw-bold">Scheduled for: ${new Date(inv.visit_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}</div>
                                <div class="mt-2">
                                    <span class="badge bg-success-subtle text-success border border-success">PRE-APPROVED</span>
                                </div>
                            </div>
                            <div class="col-md-auto mt-3 mt-md-0">
                                <button type="button" class="btn btn-success btn-lg rounded-pill px-4" onclick='applyInvitation(${JSON.stringify(inv).replace(/'/g, "&apos;")})'>
                                    <i class="bi bi-check-all me-1"></i> FAST TRACK ENTRY
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                resDiv.style.display = 'none';
                AppDialog.show({
                    title: 'Invitation Error',
                    text: data.message,
                    icon: 'warning',
                    confirmButtonColor: '#e74a3b'
                });
            }
        } catch (err) {
            resDiv.innerHTML = `<div class="alert alert-danger">Error connecting to server.</div>`;
        }
    }

    function applyInvitation(inv) {
        // Auto-fill form and scroll down
        document.getElementById('nameInput').value = inv.visitor_name;
        document.getElementById('mobileInput').value = inv.mobile;
        document.getElementById('emailInput').value = inv.email || '';
        document.getElementById('addressInput').value = inv.address || '';

        // Populate ID Proof
        if (inv.id_proof_number) {
            document.getElementById('idProofSelect').value = inv.id_proof_type || 'Aadhaar';
            document.getElementById('idNumInput').value = inv.id_proof_number;
            const toggle = document.getElementById('captureIdToggle');
            if (toggle) { // Ensure the toggle element exists
                toggle.checked = true;
                toggleIdProof(toggle);
            }
        } else {
            // If no ID proof, ensure the toggle is off and container hidden
            const toggle = document.getElementById('captureIdToggle');
            if (toggle) {
                toggle.checked = false;
                toggleIdProof(toggle);
            }
        }

        // Host Selection (Select2 compatibility check)
        const hostSel = document.getElementById('hostSelect');
        if (hostSel) {
            console.log("Setting Host ID:", inv.host_id);
            $(hostSel).val(inv.host_id.toString()).trigger('change');
        }

        document.getElementById('purposeSelect').value = inv.purpose;

        // Set a hidden field to indicate this is a check-in for an existing pre-approved visit
        let hiddenVisitId = document.getElementById('invited_visit_id');
        if (!hiddenVisitId) {
            hiddenVisitId = document.createElement('input');
            hiddenVisitId.type = 'hidden';
            hiddenVisitId.name = 'invited_visit_id';
            hiddenVisitId.id = 'invited_visit_id';
            document.getElementById('regForm').appendChild(hiddenVisitId);
        }
        hiddenVisitId.value = inv.id;

        // Visual feedback
        AppDialog.show({
            title: 'Invite Applied!',
            text: 'Form has been pre-filled with the invitation details. Just capture a photo to complete.',
            icon: 'success',
            confirmButtonText: 'OK'
        });

        // Hide search result
        document.getElementById('inviteResult').style.display = 'none';

        // Focus on capture
        document.querySelector('.camera-box').scrollIntoView({ behavior: 'smooth' });
    }
</script>

<?php require_once 'footer.php'; ?>