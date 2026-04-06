<?php
require_once '../includes/db.php';
requireLogin();

// --- SUPER ADMIN SECURITY ---
if (!isset($_SESSION['is_super']) || !$_SESSION['is_super']) {
    header("Location: dashboard.php");
    exit;
}

$target_tenant = $_GET['tenant'] ?? '';

// Handle Toggle Action (CRITICAL: Must be BEFORE any HTML output to avoid white page)
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $new_status = $_GET['toggle'] === 'block' ? 'blocked' : 'active';
    
    $stmt = $master_pdo->prepare("UPDATE tenant_devices SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $id]);
    
    // Redirect back to avoid white page and clarify state
    header("Location: tenant_devices.php?tenant=" . urlencode($target_tenant) . "&msg=Status Updated");
    exit;
}

// Now include UI
require_once 'header.php';

// Fetch Devices
if ($target_tenant) {
    $stmt = $master_pdo->prepare("SELECT * FROM tenant_devices WHERE tenant_key = ? ORDER BY last_login DESC");
    $stmt->execute([$target_tenant]);
} else {
    $stmt = $master_pdo->query("SELECT * FROM tenant_devices ORDER BY last_login DESC LIMIT 100");
}
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Tenant Info for Header
$tenant_name = "All Clients";
if ($target_tenant) {
    $t_stmt = $master_pdo->prepare("SELECT tenant_key, max_devices FROM tenants WHERE tenant_key = ?");
    $t_stmt->execute([$target_tenant]);
    $t_info = $t_stmt->fetch();
    $tenant_name = $t_info['tenant_key'] ?? $target_tenant;
    
    // Count active for quota display
    $c_stmt = $master_pdo->prepare("SELECT COUNT(*) FROM tenant_devices WHERE tenant_key = ? AND status = 'active'");
    $c_stmt->execute([$target_tenant]);
    $active_count = $c_stmt->fetchColumn();
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-0 text-dark">Mobile Hardware Manager</h2>
            <p class="text-muted small">Managing authorized android devices for: <span class="badge bg-primary px-3 rounded-pill"><?php echo strtoupper($tenant_name); ?></span></p>
        </div>
        <div>
            <a href="tenants.php" class="btn btn-light rounded-pill px-4 border shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Clients
            </a>
        </div>
    </div>

    <?php if ($target_tenant): ?>
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white p-3">
                <div class="small opacity-75">Current Quota Usage</div>
                <div class="display-6 fw-bold"><?php echo $active_count; ?> / <?php echo $t_info['max_devices'] ?? 5; ?></div>
                <div class="small opacity-75">Only 'Active' devices count towards quota.</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 small text-uppercase">Client</th>
                        <th class="small text-uppercase">Device Name / ID</th>
                        <th class="small text-uppercase text-center">Status</th>
                        <th class="small text-uppercase">Last Login</th>
                        <th class="text-end pe-4 small text-uppercase">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($devices)): ?>
                        <tr><td colspan="5" class="p-5 text-center text-muted">No mobile devices registered yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="fw-bold"><?php echo strtoupper($d['tenant_key']); ?></span>
                            </td>
                            <td>
                                <div class="fw-bold"><?php echo htmlspecialchars($d['device_name'] ?: 'Unknown Android'); ?></div>
                                <div class="small text-muted font-monospace text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($d['device_id']); ?></div>
                            </td>
                            <td class="text-center">
                                <div class="badge bg-<?php echo ($d['status'] === 'active' ? 'success' : 'danger'); ?>-opacity text-<?php echo ($d['status'] === 'active' ? 'success' : 'danger'); ?> px-3 rounded-pill">
                                    <i class="bi bi-<?php echo ($d['status'] === 'active' ? 'check-circle-fill' : 'slash-circle-fill'); ?> me-1"></i>
                                    <?php echo strtoupper($d['status']); ?>
                                </div>
                            </td>
                            <td class="small text-muted">
                                <?php echo date('M d, Y H:i', strtotime($d['last_login'])); ?>
                            </td>
                            <td class="text-end pe-4">
                                <?php if ($d['status'] === 'active'): ?>
                                    <a href="tenant_devices.php?tenant=<?php echo urlencode($target_tenant); ?>&id=<?php echo $d['id']; ?>&toggle=block" 
                                       class="btn btn-sm btn-danger rounded-pill px-3 shadow-xs" onclick="return confirm('Immediately block all access for this hardware?')">
                                        <i class="bi bi-slash-circle me-1"></i> Block
                                    </a>
                                <?php else: ?>
                                    <a href="tenant_devices.php?tenant=<?php echo urlencode($target_tenant); ?>&id=<?php echo $d['id']; ?>&toggle=unblock" 
                                       class="btn btn-sm btn-success rounded-pill px-3 shadow-xs">
                                        <i class="bi bi-check-circle me-1"></i> Authorize
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
