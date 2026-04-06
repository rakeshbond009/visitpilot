<?php
require_once '../includes/db.php';
requireLogin();

$msg = '';

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = sanitize($_POST['name']);
    $status = sanitize($_POST['status']);

    if (isset($_POST['id']) && !empty($_POST['id'])) {
        // Edit
        try {
            $old = $pdo->prepare("SELECT name, status FROM departments WHERE id = ?");
            $old->execute([$_POST['id']]);
            $oldData = $old->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE departments SET name=?, status=? WHERE id=?");
            $stmt->execute([$name, $status, $_POST['id']]);
            
            $msg_log = "Updated department: $name";
            logAction($pdo, $_SESSION['user_id'], $msg_log, $oldData, ['name' => $name, 'status' => $status]);

            $msg = "Department updated successfully!";
        } catch (PDOException $e) {
            $msg = "Error: " . $e->getMessage();
        }
    } else {
        // Add
        try {
            $stmt = $pdo->prepare("INSERT INTO departments (name, status) VALUES (?, ?)");
            $stmt->execute([$name, $status]);
            logAction($pdo, $_SESSION['user_id'], "Added new department: $name", null, ['name' => $name, 'status' => $status]);
            $msg = "Department added successfully!";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $msg = "Error: Department name already exists.";
            } else {
                $msg = "Error: " . $e->getMessage();
            }
        }
    }
    redirect("departments.php?msg=" . urlencode($msg));
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->execute([$id]);
        logAction($pdo, $_SESSION['user_id'], "Deleted department ID: $id");
        $msg = "Department deleted.";
    } catch (PDOException $e) {
        $msg = "Error: Could not delete department. It may be in use.";
    }
    redirect("departments.php?msg=" . urlencode($msg));
}

require_once 'header.php';

$departments = $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
$msg = $_GET['msg'] ?? '';
?>

<?php // AppDialog will handle the message via JS below ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Department Master</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="clearForm()">
        <i class="bi bi-plus-lg"></i> Add Department
    </button>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Department Name</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $d): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($d['name']); ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $d['status'] == 'active' ? 'success' : 'secondary'; ?>">
                                <?php echo ucfirst($d['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php echo date('d-M-Y', strtotime($d['created_at'])); ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info text-white"
                                onclick="editDept(<?php echo htmlspecialchars(json_encode($d)); ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <a href="?delete=<?php echo $d['id']; ?>" class="btn btn-sm btn-danger"
                                onclick="return confirmAction(event, 'Are you sure you want to delete this department?')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($departments) == 0): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">No departments found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="deptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Add Department</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="dept_id">
                    <div class="mb-3">
                        <label>Department Name</label>
                        <input type="text" name="name" id="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label>Status</label>
                        <select name="status" id="status" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function editDept(data) {
        document.getElementById('modalTitle').innerText = 'Edit Department';
        document.getElementById('dept_id').value = data.id;
        document.getElementById('name').value = data.name;
        document.getElementById('status').value = data.status;
        new bootstrap.Modal(document.getElementById('deptModal')).show();
    }

    function clearForm() {
        document.getElementById('modalTitle').innerText = 'Add Department';
        document.getElementById('dept_id').value = '';
        document.getElementById('name').value = '';
        document.getElementById('status').value = 'active';
    }

    document.addEventListener('DOMContentLoaded', function () {
        <?php if ($msg): ?>
            AppDialog.show({
                text: '<?php echo addslashes($msg); ?>',
                icon: '<?php echo (strpos($msg, 'Error') !== false) ? 'error' : 'success'; ?>'
            });
        <?php endif; ?>
    });
</script>

<?php require_once 'footer.php'; ?>