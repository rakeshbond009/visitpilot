<?php
require_once 'header.php';

// Search Logic
$search = $_GET['search'] ?? '';
$search_sql = '';
$search_params = [];

if ($search) {
    $search_sql = " AND (vis.name LIKE ? OR vis.mobile LIKE ? OR v.id LIKE ? OR v.visit_code LIKE ?)";
    $search_params = ["%$search%", "%$search%", "%$search%", "%$search%"];
}

// Date Filter
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status = $_GET['status'] ?? '';

if ($start_date && $end_date) {
    $search_sql .= " AND DATE(v.created_at) BETWEEN ? AND ?";
    $search_params[] = $start_date;
    $search_params[] = $end_date;
}
elseif ($start_date) {
    $search_sql .= " AND DATE(v.created_at) >= ?";
    $search_params[] = $start_date;
}
elseif ($end_date) {
    $search_sql .= " AND DATE(v.created_at) <= ?";
    $search_params[] = $end_date;
}

if ($status) {
    if ($status === 'invited') {
        // Show all records that were invited, including those that were subsequently REJECTED
        $search_sql .= " AND (v.is_invited = 1 OR v.status = 'rejected' OR v.status = 'canceled' OR v.status = 'cancelled')";
    }
    else {
        if ($status === 'rejected') {
            // Group all rejected and canceled statuses under a single 'rejected' filter
            $search_sql .= " AND (v.status IN ('rejected', 'canceled', 'cancelled') OR v.approval_status IN ('rejected', 'canceled', 'cancelled'))";
        } else {
            $search_sql .= " AND (v.status = ? OR v.approval_status = ?)";
            $search_params[] = $status;
            $search_params[] = $status;
        }
    }
}

// Get visitors - if user is host/employee, show only theirs; otherwise show all
$is_unrestricted = ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'security');

if ($host_employee_id && !$is_unrestricted) {
    $sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN employees e ON v.employee_id = e.id
            WHERE v.employee_id = ? $search_sql
            ORDER BY v.created_at DESC";
    $params = array_merge([$host_employee_id], $search_params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
else {
    // Security/Admin user - show all visitors
    $sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name, e.department
            FROM visits v  
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN employees e ON v.employee_id = e.id
            WHERE 1=1 $search_sql
            ORDER BY v.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($search_params);
}
$visitors = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">My Visitors History</h3>
    <form method="GET" class="d-flex align-items-center gap-2">
        <select name="status" class="form-select form-select-sm" style="max-width: 130px;">
            <option value="">All Statuses</option>
            <option value="pending" <?php if ($status == 'pending')
    echo 'selected'; ?>>Pending</option>
            <option value="approved" <?php if ($status == 'approved')
    echo 'selected'; ?>>Approved</option>
            <option value="checked_in" <?php if ($status == 'checked_in')
    echo 'selected'; ?>>Checked In</option>
            <option value="checked_out" <?php if ($status == 'checked_out')
    echo 'selected'; ?>>Checked Out</option>
            <option value="rejected" <?php if ($status == 'rejected')
    echo 'selected'; ?>>Rejected</option>
        </select>
        <input type="date" name="start_date" class="form-control form-control-sm"
            value="<?php echo htmlspecialchars($start_date); ?>" title="Start Date">
        <span class="text-muted">-</span>
        <input type="date" name="end_date" class="form-control form-control-sm"
            value="<?php echo htmlspecialchars($end_date); ?>" title="End Date">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search ID, Name, Mob..."
            value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($search || $start_date || $end_date || $status): ?>
            <a href="my_visitors.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
        <?php
endif; ?>
    </form>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Date/Time</th>
                        <th>Visitor</th>
                        <th>Host</th>
                        <th>Department</th>
                        <th>Purpose</th>
                        <th>Check In</th>
                        <th>Check Out</th>
                        <th>Status</th>
                        <th>Approval</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($visitors)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-5">
                                <i class="bi bi-inbox display-4 d-block mb-3 opacity-25"></i>
                                No visitor history found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($visitors as $v): ?>
                        <tr onclick="viewVisitDetails(<?php echo $v['id']; ?>)" style="cursor: pointer;">
                            <td class="ps-4">
                                <span class="fw-bold text-dark">#<?php echo $v['id']; ?></span>
                            </td>
                            <td>
                                <div class="text-dark small">
                                    <i class="bi bi-calendar3 me-1"></i><?php echo date('d M Y', strtotime($v['created_at'])); ?>
                                </div>
                                <div class="text-muted" style="font-size: 0.7rem;">
                                    <i class="bi bi-clock me-1"></i><?php echo date('h:i A', strtotime($v['created_at'])); ?>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex align-items-center">
                                    <img src="../<?php echo !empty($v['visit_photo']) ? $v['visit_photo'] : 'assets/img/visitor-icon.png'; ?>" 
                                         class="rounded-circle me-3 border shadow-sm" width="40" height="40" style="object-fit:cover"
                                         onerror="this.src='../assets/img/visitor-icon.png';">
                                    <div>
                                        <div class="fw-bold text-dark small"><?php echo htmlspecialchars($v['visitor_name']); ?></div>
                                        <div class="text-muted" style="font-size: 0.7rem;"><?php echo htmlspecialchars($v['mobile']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td><div class="small fw-bold text-dark"><?php echo htmlspecialchars($v['host_name'] ?? '-'); ?></div></td>
                            <td><div class="small text-muted"><?php echo htmlspecialchars($v['department'] ?? '-'); ?></div></td>
                            <td><div class="badge bg-light text-dark border fw-normal"><?php echo htmlspecialchars($v['purpose']); ?></div></td>
                            <td class="small fw-bold text-success"><?php echo formatDateTime($v['check_in_time']); ?></td>
                            <td class="small fw-bold text-danger"><?php echo formatDateTime($v['check_out_time']); ?></td>
                            <td>
                                <?php
                                $status = $v['status'];
                                $badge = 'bg-secondary';
                                $statusText = !empty($status) ? strtoupper(str_replace('_', ' ', $status)) : '';
                                
                                if ($v['is_invited'] && (empty($status) || $status === 'pending' || $status === 'approved')) {
                                    $badge = 'bg-info bg-gradient';
                                    $statusText = 'INVITED';
                                } else {
                                    $badge = match ($status) {
                                        'pending' => 'bg-warning text-dark',
                                        'approved' => 'bg-success',
                                        'rejected', 'canceled', 'cancelled' => 'bg-danger',
                                        'checked_in' => 'bg-primary',
                                        'checked_out' => 'bg-dark',
                                        default => 'bg-secondary'
                                    };
                                    if (in_array($status, ['rejected', 'canceled', 'cancelled'])) {
                                        $statusText = 'REJECTED';
                                    } elseif (empty($statusText)) {
                                        $statusText = 'PENDING';
                                    }
                                }
                                ?>
                                <span class="badge <?php echo $badge; ?> px-2 py-1" style="font-size: 0.7rem;"><?php echo $statusText; ?></span>
                            </td>
                            <td>
                                <?php
                                $appStatusValue = $v['approval_status'];
                                if (empty($appStatusValue)) {
                                    // Robust fallback: if its an invitation, it should probably be 'pending' or 'approved'
                                    // For record FA86FE23 specifically, both were empty.
                                    $appStatusValue = $v['is_invited'] ? 'pending' : ($v['status'] ?: 'pending');
                                }
                                $appBadge = 'bg-secondary';
                                if ($appStatusValue === 'approved') $appBadge = 'bg-success';
                                elseif (in_array($appStatusValue, ['rejected', 'canceled', 'cancelled'])) $appBadge = 'bg-danger';
                                elseif ($appStatusValue === 'pending' || empty($appStatusValue)) $appBadge = 'bg-warning text-dark';
                                ?>
                                <span class="badge <?php echo $appBadge; ?> border bg-opacity-10 text-dark px-2" style="font-size: 0.65rem;">
                                    <?php 
                                    if (in_array($appStatusValue, ['rejected', 'canceled', 'cancelled'])) {
                                        echo 'REJECTED';
                                    } elseif (empty($appStatusValue)) {
                                        echo 'PENDING';
                                    } else {
                                        echo strtoupper(str_replace('_', ' ', $appStatusValue));
                                    }
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/visit_details_modal.php'; ?>
<?php require_once 'footer.php'; ?>