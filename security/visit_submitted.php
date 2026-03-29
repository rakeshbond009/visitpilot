<?php
require_once 'header.php';

if (!isset($_GET['id']))
    redirect($home_url);
$id = $_GET['id'];

$stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile, emp.name as host_name, emp.mobile as host_mobile 
                       FROM visits v 
                       JOIN visitors vis ON v.visitor_id = vis.id 
                       JOIN employees emp ON v.employee_id = emp.id 
                       WHERE v.id = ?");
$stmt->execute([$id]);
$visit = $stmt->fetch();

if (!$visit)
    die("Invalid Visit ID");
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card shadow-lg border-success">
            <div class="card-header bg-success text-white text-center">
                <h4 class="mb-0"><i class="bi bi-check-circle"></i> Visit Request Submitted</h4>
            </div>
            <div class="card-body text-center p-4">
                <div class="mb-4">
                    <i class="bi bi-hourglass-split display-1 text-warning"></i>
                </div>

                <h5 class="mb-3">Visitor:
                    <?php echo htmlspecialchars($visit['visitor_name']); ?>
                </h5>
                <p class="mb-1"><strong>Mobile:</strong>
                    <?php echo htmlspecialchars($visit['mobile']); ?>
                </p>
                <p class="mb-1"><strong>Host:</strong>
                    <?php echo htmlspecialchars($visit['host_name']); ?>
                </p>
                <p class="mb-3"><strong>Visit Code:</strong> <span class="badge bg-primary fs-6">
                        <?php echo $visit['visit_code']; ?>
                    </span></p>

                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Pending Host Approval</strong><br>
                    <small>The visitor pass will be issued once the host approves this visit request.</small>
                </div>

                <div class="mt-4">
                </div>
            </div>
            <div class="card-footer text-center">
                <a href="<?php echo $home_url; ?>" class="btn btn-primary">Back to Dashboard</a>
                <a href="register.php" class="btn btn-outline-success">Register Another</a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>