<?php
require_once 'header.php';

// Get currently checked-in visitors
$sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, emp.name as host_name,
        TIMESTAMPDIFF(MINUTE, v.check_in_time, NOW()) as duration_minutes
        FROM visits v 
        JOIN visitors vis ON v.visitor_id = vis.id 
        JOIN employees emp ON v.employee_id = emp.id 
        WHERE v.status = 'checked_in'
        ORDER BY v.check_in_time ASC";

$stmt = $pdo->query($sql);
$active_visitors = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3><i class="bi bi-people-fill"></i> Active Visitors Inside Premises</h3>
    <div>
        <span class="badge bg-success fs-5">
            <?php echo count($active_visitors); ?> Inside
        </span>
    </div>
</div>

<?php if (empty($active_visitors)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle"></i> No visitors currently inside the premises.
    </div>
<?php
else: ?>
    <div class="row">
        <?php foreach ($active_visitors as $visitor): ?>
            <div class="col-md-6 col-lg-4 mb-4">
                <div class="card h-100 shadow-sm border-success" onclick="viewVisitDetails(<?php echo $visitor['id']; ?>)"
                    style="cursor: pointer;">
                    <div class="card-body">
                        <div class="d-flex align-items-start mb-3">
                            <?php
        $display_photo = $visitor['visit_photo'];
        if ($display_photo): ?>
                                <img src="../<?php echo $display_photo; ?>" class="rounded-circle me-3" width="60" height="60"
                                    style="object-fit:cover;">
                            <?php
        else: ?>
                                <div class="rounded-circle bg-light me-3 d-flex align-items-center justify-content-center"
                                    style="width:60px;height:60px;">
                                    <i class="bi bi-person display-6 text-secondary"></i>
                                </div>
                            <?php
        endif; ?>
                            <div class="flex-grow-1">
                                <h5 class="mb-1">
                                    <?php echo htmlspecialchars($visitor['visitor_name']); ?>
                                </h5>
                                <p class="text-muted small mb-0">
                                    <i class="bi bi-phone"></i>
                                    <?php echo htmlspecialchars($visitor['mobile']); ?>
                                </p>
                            </div>
                        </div>

                        <div class="mb-2">
                            <small class="text-muted">Meeting With:</small>
                            <div class="fw-bold">
                                <?php echo htmlspecialchars($visitor['host_name']); ?>
                            </div>
                        </div>

                        <div class="mb-2">
                            <small class="text-muted">Purpose:</small>
                            <div>
                                <?php echo htmlspecialchars($visitor['purpose']); ?>
                            </div>
                        </div>

                        <div class="mb-3">
                            <small class="text-muted">Check-in Time:</small>
                            <div>
                                <?php echo formatTime($visitor['check_in_time']); ?>
                            </div>
                            <small class="text-success">
                                <i class="bi bi-clock"></i>
                                <?php
        $hours = floor($visitor['duration_minutes'] / 60);
        $mins = $visitor['duration_minutes'] % 60;
        echo $hours > 0 ? "{$hours}h " : "";
        echo "{$mins}m inside";
?>
                            </small>
                        </div>

                        <div class="d-grid gap-2" onclick="event.stopPropagation()">
                            <a href="process_visit.php?action=checkout&id=<?php echo $visitor['id']; ?>"
                                class="btn btn-danger btn-sm"
                                onclick="return confirmAction(event, 'Are you sure you want to check out <?php echo htmlspecialchars($visitor['visitor_name']); ?>?')">
                                <i class="bi bi-box-arrow-right"></i> Check Out
                            </a>
                            <a href="pass.php?id=<?php echo $visitor['id']; ?>" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-ticket-detailed"></i> View Pass
                            </a>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-success">
                        <small class="text-muted">
                            <i class="bi bi-hash"></i>
                            <?php echo $visitor['visit_code']; ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php
    endforeach; ?>
    </div>
<?php
endif; ?>


<?php require_once '../includes/visit_details_modal.php'; ?>
<?php require_once 'footer.php'; ?>