<?php
require_once '../includes/db.php';
requireLogin();

// Permission Check
if (!canView('view_hardware_logs')) {
    $_SESSION['app_msg'] = "Access Denied: You do not have permission to view hardware logs.";
    header("Location: dashboard.php");
    exit;
}

$page_title = "Hardware Access Logs";
include 'header.php';

// Pagination
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Filters
$machine_id = $_GET['machine_id'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

$where = ["1=1"];
$params = [];

if ($machine_id) {
    $where[] = "machine_id = ?";
    $params[] = $machine_id;
}
if ($date_from) {
    $where[] = "event_time >= ?";
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to) {
    $where[] = "event_time <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$whereClause = implode(" AND ", $where);

// Count total
$countQuery = $pdo->prepare("SELECT COUNT(*) FROM machine_logs WHERE $whereClause");
$countQuery->execute($params);
$total_records = $countQuery->fetchColumn();
$total_pages = ceil($total_records / $limit);

// Fetch logs
$sql = "SELECT * FROM machine_logs WHERE $whereClause ORDER BY event_time DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique machine IDs for filter
$machines = $pdo->query("SELECT DISTINCT machine_id FROM machine_logs WHERE machine_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-microchip me-2 text-primary"></i>Hardware Access Logs</h5>
                    <div class="text-muted small">Total Records: <?php echo number_format($total_records); ?></div>
                </div>
                <div class="card-body bg-light border-bottom">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">Machine ID</label>
                            <select name="machine_id" class="form-select">
                                <option value="">All Machines</option>
                                <?php foreach ($machines as $m): ?>
                                    <option value="<?php echo htmlspecialchars($m); ?>" <?php echo $machine_id == $m ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($m); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-1"></i>Filter Logs
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Timestamp</th>
                                    <th>Machine</th>
                                    <th>Person Details</th>
                                    <th>Event Type</th>
                                    <th>Image</th>
                                    <th class="text-end pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($logs)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-5 text-muted">
                                            <i class="fas fa-search me-2"></i>No logs found matching your criteria.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold"><?php echo date('d-m-Y', strtotime($log['event_time'])); ?>
                                            </div>
                                            <div class="small text-muted">
                                                <?php echo date('H:i:s', strtotime($log['event_time'])); ?></div>
                                        </td>
                                        <td>
                                            <span
                                                class="badge bg-secondary opacity-75"><?php echo htmlspecialchars($log['machine_id']); ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-primary">
                                                <?php echo htmlspecialchars($log['person_name'] ?: 'Unknown'); ?></div>
                                            <div class="small text-muted">ID:
                                                <?php echo htmlspecialchars($log['person_id'] ?: 'N/A'); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info text-dark">
                                                <?php echo htmlspecialchars($log['event_type']); ?>
                                            </span>
                                            <?php if ($log['person_type']): ?>
                                                <div class="mt-1 small fw-bold text-uppercase" style="font-size: 10px;">
                                                    <?php echo $log['person_type']; ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($log['image_path']): ?>
                                                <img src="<?php
                                                if (strpos($log['image_path'], 'http') === 0) {
                                                    echo htmlspecialchars($log['image_path']);
                                                } else {
                                                    // Assume base64 or locally stored path
                                                    echo 'data:image/jpeg;base64,' . $log['image_path'];
                                                }
                                                ?>" class="rounded cursor-pointer hover-zoom"
                                                    style="width: 50px; height: 50px; object-fit: cover;"
                                                    onclick="viewLogImage('<?php echo htmlspecialchars($log['image_path']); ?>')">
                                            <?php else: ?>
                                                <div class="text-muted small">No Image</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button class="btn btn-sm btn-outline-secondary"
                                                onclick='viewRawPayload(<?php echo json_encode($log['raw_payload']); ?>)'>
                                                <i class="fas fa-code me-1"></i>JSON
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if ($total_pages > 1): ?>
                    <div class="card-footer bg-white py-3">
                        <nav aria-label="Page navigation">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                        href="?page=<?php echo $page - 1; ?>&machine_id=<?php echo $machine_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                        <a class="page-link"
                                            href="?page=<?php echo $i; ?>&machine_id=<?php echo $machine_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link"
                                        href="?page=<?php echo $page + 1; ?>&machine_id=<?php echo $machine_id; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Raw Payload Modal -->
<div class="modal fade" id="payloadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Raw Machine Event (JSON)</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-dark text-light">
                <pre id="payloadContent" class="mb-0 p-3" style="max-height: 500px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>
</div>

<!-- Image View Modal -->
<div class="modal fade" id="imageModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-body p-0 text-center bg-black rounded">
                <img id="modalImage" src="" class="img-fluid rounded">
            </div>
        </div>
    </div>
</div>

<script>
    function viewRawPayload(payload) {
        try {
            const json = typeof payload === 'string' ? JSON.parse(payload) : payload;
            document.getElementById('payloadContent').textContent = JSON.stringify(json, null, 4);
        } catch (e) {
            document.getElementById('payloadContent').textContent = payload;
        }
        new bootstrap.Modal(document.getElementById('payloadModal')).show();
    }

    function viewLogImage(src) {
        if (!src) return;
        const modalImg = document.getElementById('modalImage');
        if (src.startsWith('http')) {
            modalImg.src = src;
        } else {
            modalImg.src = 'data:image/jpeg;base64,' + src;
        }
        new bootstrap.Modal(document.getElementById('imageModal')).show();
    }
</script>

<style>
    .cursor-pointer {
        cursor: pointer;
    }

    .hover-zoom:hover {
        transform: scale(1.1);
        transition: transform 0.2s;
        z-index: 100;
    }
</style>

<?php include 'footer.php'; ?>