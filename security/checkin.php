<?php
require_once 'header.php';

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $mobile = sanitize($_POST['mobile']);

    // Find visitor's latest registered visit
    $sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.photo_path, emp.name as host_name 
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            JOIN employees emp ON v.employee_id = emp.id 
            WHERE vis.mobile = ? AND v.status = 'registered'
            ORDER BY v.created_at DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$mobile]);
    $result = $stmt->fetch();

    if (!$result) {
        $error = "No pending visit found for this mobile number.";
    }
}

// Handle Quick Check-in
if (isset($_GET['confirm']) && isset($_GET['id'])) {
    $stmt = $pdo->prepare("UPDATE visits SET status='checked_in', check_in_time=? WHERE id=?");
    $stmt->execute([current_datetime(), $_GET['id']]);
    logAction($pdo, $_SESSION['user_id'], "Checked in visitor ID: " . $_GET['id']);
    redirect($home_url);
}
?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card shadow">
            <div class="card-header bg-success text-white">
                <h4 class="mb-0"><i class="bi bi-box-arrow-in-right"></i> Quick Check-In</h4>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Enter visitor's mobile number to check them in.
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <?php echo $error; ?>
                    </div>
                <?php
endif; ?>

                <form method="POST" class="mb-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <input type="text" name="mobile" class="form-control form-control-lg"
                                placeholder="Enter Mobile Number" required autofocus pattern="[0-9]{10}"
                                title="Please enter 10 digit mobile number">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-success btn-lg w-100">
                                <i class="bi bi-search"></i> Search
                            </button>
                        </div>
                    </div>
                </form>

                <?php if ($result): ?>
                    <div class="card border-success">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-3 text-center">
                                    <?php
    $display_photo = $result['visit_photo'];
    if ($display_photo): ?>
                                        <img src="../<?php echo $display_photo; ?>"
                                            class="img-fluid rounded-circle border border-3 border-success"
                                            style="width:120px; height:120px; object-fit:cover;">
                                    <?php
    else: ?>
                                        <div class="rounded-circle bg-light border border-3 border-success d-inline-flex align-items-center justify-content-center"
                                            style="width:120px; height:120px;">
                                            <i class="bi bi-person display-4 text-secondary"></i>
                                        </div>
                                    <?php
    endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <h3 class="mb-2">
                                        <?php echo htmlspecialchars($result['visitor_name']); ?>
                                    </h3>
                                    <p class="mb-1"><strong>Host:</strong>
                                        <?php echo htmlspecialchars($result['host_name']); ?>
                                    </p>
                                    <p class="mb-1"><strong>Purpose:</strong>
                                        <?php echo htmlspecialchars($result['purpose']); ?>
                                    </p>
                                    <p class="mb-1"><strong>Visit Code:</strong> <span class="badge bg-primary">
                                            <?php echo $result['visit_code']; ?>
                                        </span></p>
                                </div>
                                <div class="col-md-3 text-center">
                                    <a href="?confirm=1&id=<?php echo $result['id']; ?>"
                                        class="btn btn-success btn-lg w-100"
                                        onclick="return confirmAction(event, 'Confirm check-in for this visitor?')">
                                        <i class="bi bi-check-circle"></i><br>
                                        Check In
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
endif; ?>

                <div class="mt-4 text-center">
                    <p class="text-muted">Or use alternative methods:</p>
                    <div class="d-grid gap-2 d-md-flex justify-content-center">
                        <a href="scan_qr.php" class="btn btn-outline-primary">
                            <i class="bi bi-qr-code-scan"></i> Scan QR Code
                        </a>
                        <a href="register.php" class="btn btn-outline-secondary">
                            <i class="bi bi-person-plus"></i> New Registration
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>