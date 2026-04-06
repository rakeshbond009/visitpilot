<?php
require_once 'header.php';

// Determine if user is Super Admin
$is_super = (bool)($_SESSION['is_super'] ?? false);
$current_tenant = $_SESSION['tenant_key'] ?? 'master';

$search = sanitize($_GET['search'] ?? '');
$user_filter = (int)($_GET['user_id'] ?? 0);
// If not super-admin, always force the current tenant's key
$tenant_filter = $is_super ? sanitize($_GET['tenant_key'] ?? '') : $current_tenant;

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$params = [];
$where = " WHERE 1=1 ";
if ($search) {
    $where .= " AND (al.action LIKE ? OR al.ip_address LIKE ?) ";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($user_filter) {
    $where .= " AND al.user_id = ? ";
    $params[] = $user_filter;
}
if ($tenant_filter) {
    $where .= " AND al.tenant_key = ? ";
    $params[] = $tenant_filter;
}

$count_sql = "SELECT COUNT(*) FROM audit_logs al $where";
$stmt = $master_pdo->prepare($count_sql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Get logs from Master DB to see all tenants
$sql = "SELECT al.*, u.username, u.full_name, t.company_name as tenant_name
        FROM audit_logs al 
        LEFT JOIN users u ON al.user_id = u.id 
        LEFT JOIN tenants t ON al.tenant_key = t.tenant_key
        $where
        ORDER BY al.created_at DESC 
        LIMIT $per_page OFFSET $offset";

$stmt = $master_pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get users for filter
$all_users = $master_pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name")->fetchAll();
$all_tenants = $master_pdo->query("SELECT tenant_key, company_name FROM tenants ORDER BY company_name")->fetchAll();
?>

<div class="row align-items-center mb-4">
    <div class="col-md-6">
        <h3 class="fw-bold text-dark"><i class="bi bi-journal-check text-primary me-2"></i>System Audit Trail</h3>
        <p class="text-muted small mb-0">Security log of all administrative and system actions.</p>
    </div>
    <div class="col-md-6 text-md-end mt-3 mt-md-0">
        <span class="badge bg-primary-subtle text-primary border border-primary px-3 py-2 fs-6">
            <i class="bi bi-list-ol me-1"></i> <?php echo number_format($total); ?> Total Entries
        </span>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-4">
        <form method="GET" class="row g-3">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" 
                           placeholder="Search action or IP..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="user_id" class="form-select">
                    <option value="">All Users</option>
                    <?php foreach ($all_users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo($user_filter == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name'] . " (@" . $u['username'] . ")"); ?>
                        </option>
                    <?php
endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <?php if ($is_super): ?>
                    <select name="tenant_key" class="form-select">
                        <option value="">All Tenants</option>
                        <?php foreach ($all_tenants as $t): ?>
                            <option value="<?php echo $t['tenant_key']; ?>" <?php echo ($tenant_filter == $t['tenant_key']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['company_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <div class="form-control bg-light text-muted">
                        <i class="bi bi-building me-1"></i> My Tenant Logs
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-1">
                <div class="btn-group w-100">
                    <button type="submit" class="btn btn-primary">Go</button>
                    <a href="audit_logs.php" class="btn btn-outline-secondary">X</a>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light border-bottom">
                <tr class="text-uppercase small fw-bold text-muted">
                    <th style="width: 200px;">When (IST)</th>
                    <th style="width: 200px;">Performed By</th>
                    <th style="width: 150px;">Tenant Name</th>
                    <th>Action Description</th>
                    <th class="pe-4" style="width: 150px;">IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td class="ps-4 text-muted small">#<?php echo $log['id']; ?></td>
                    <td>
                        <div class="fw-bold"><?php echo date('d-M-Y', strtotime($log['created_at'])); ?></div>
                        <div class="small text-muted"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></div>
                    </td>
                    <td>
                        <?php if ($log['username']): ?>
                            <div class="d-flex align-items-center">
                                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                    <?php echo strtoupper(substr($log['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($log['full_name']); ?></div>
                                    <div class="small text-muted">@<?php echo htmlspecialchars($log['username']); ?></div>
                                </div>
                            </div>
                        <?php
    else: ?>
                            <span class="badge bg-light text-secondary border">System</span>
                        <?php
    endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-info-subtle text-info border border-info small"><?php echo htmlspecialchars($log['tenant_name'] ?? 'Super User'); ?></span>
                    </td>
                    <td>
                        <div class="p-2 rounded bg-light border-start border-3 border-primary small text-dark">
                            <?php echo htmlspecialchars($log['action']); ?>
                        </div>
                    </td>
                    <td class="pe-4">
                        <span class="badge bg-secondary-subtle text-secondary small"><?php echo htmlspecialchars($log['ip_address']); ?></span>
                    </td>
                </tr>
                <?php
endforeach; ?>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-5">
                            <i class="bi bi-inbox display-4 text-muted d-block mb-3"></i>
                            <p class="text-muted">No activities found matching your criteria.</p>
                        </td>
                    </tr>
                <?php
endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white p-3 border-top-0">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php
    $start = max(1, $page - 2);
    $end = min($total_pages, $page + 2);

    if ($page > 1): ?>
                    <li class="page-item"><a class="page-link" href="?page=1&search=<?php echo urlencode($search); ?>&user_id=<?php echo $user_filter; ?>">First</a></li>
                <?php
    endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&user_id=<?php echo $user_filter; ?>"><?php echo $i; ?></a>
                    </li>
                <?php
    endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search); ?>&user_id=<?php echo $user_filter; ?>">Last</a></li>
                <?php
    endif; ?>
            </ul>
        </nav>
    </div>
    <?php
endif; ?>
</div>

<style>
    .page-link { border-radius: 8px !important; margin: 0 2px; }
    .table-hover tbody tr:hover { background-color: rgba(67, 97, 238, 0.02); }
</style>

<?php require_once 'footer.php'; ?>
