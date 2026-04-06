<?php
require_once '../includes/db.php';
requireLogin();

// Ensure email/mobile columns exist (One-time check per request if needed, but better once)
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(150)");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS mobile VARCHAR(20)");
} catch (PDOException $e) {}

// Handle Add/Edit
$msg = '';
$error = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = sanitize($_POST['name']);
    $dept = sanitize($_POST['department']);
    $email = sanitize($_POST['email']);
    $mobile = sanitize($_POST['mobile']);

    // Store for form persistence on error
    $form_data = $_POST;

    // Validation
    if (!empty($mobile) && !preg_match("/^[0-9]{10}$/", $mobile)) {
        $error = "Please enter a valid 10-digit mobile number.";
    } else {
        if (isset($_POST['id']) && !empty($_POST['id'])) {
            // Edit
            try {
                // Change Detection for Audit Log
                $curr = $pdo->prepare("SELECT name, department, email, mobile FROM employees WHERE id = ?");
                $curr->execute([$_POST['id']]);
                $old = $curr->fetch();

                $changes = [];
                if ($old['name'] !== $name) $changes[] = "name";
                if ($old['department'] !== $dept) $changes[] = "department";
                if ($old['email'] !== $email) $changes[] = "email";
                if ($old['mobile'] !== $mobile) $changes[] = "mobile number";

                $stmt = $pdo->prepare("UPDATE employees SET name=?, department=?, email=?, mobile=? WHERE id=?");
                $stmt->execute([$name, $dept, $email, $mobile, $_POST['id']]);

                $uUpd = $pdo->prepare("UPDATE users SET full_name=?, department=?, email=?, mobile=? WHERE employee_id=?");
                $uUpd->execute([$name, $dept, $email, $mobile, $_POST['id']]);

                $msg_log = "Updated employee $name (ID: {$_POST['id']}): " . (empty($changes) ? "no profile changes" : implode(", ", $changes));
                logAction($pdo, $_SESSION['user_id'], $msg_log);

                header("Location: employees.php?edit_success=1");
                exit;
            } catch (Exception $e) {
                $error = "Error updating employee: " . $e->getMessage();
            }
        } else {
            // Add
            try {
                // Check if employee with same Name + (Email or Mobile) exists
                $checkEmp = $pdo->prepare("SELECT id FROM employees WHERE name = ? AND (email = ? OR mobile = ?)");
                $checkEmp->execute([$name, $email, $mobile]);
                $existingEmp = $checkEmp->fetch();

                if ($existingEmp) {
                    $error = "An employee with the name '$name' and the same email/mobile already exists.";
                } else {
                    $pdo->beginTransaction();

                    $stmt = $pdo->prepare("INSERT INTO employees (name, department, email, mobile) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $dept, $email, $mobile]);
                    $emp_id = $pdo->lastInsertId();

                    // Auto-create User Logic
                    // Generate Username Logic
                    $base_username = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '.', $name))); // john.doe
                    $base_username = trim($base_username, '.');
                    if (strlen($base_username) < 3)
                        $base_username = "user." . $base_username;

                    $username = $base_username;
                    $counter = 1;
                    while (true) {
                        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                        $check->execute([$username]);
                        if ($check->fetchColumn() == 0)
                            break;
                        $username = $base_username . "." . $counter;
                        $counter++;
                    }

                    $default_pass = "Welcome@123";
                    $hashed = password_hash($default_pass, PASSWORD_DEFAULT);


                    $uStmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, employee_id, department, email, mobile) VALUES (?, ?, ?, 'employee', ?, ?, ?, ?)");
                    $uStmt->execute([$username, $hashed, $name, $emp_id, $dept, $email, $mobile]);

                    logAction($pdo, $_SESSION['user_id'], "Added employee: $name and created user: $username");

                    $pdo->commit();

                    // Success redirect
                    header("Location: employees.php?new_user=" . urlencode($username) . "&new_pass=" . urlencode($default_pass));
                    exit;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Handle Disable User (Login Only)
if (isset($_GET['disable_user'])) {
    $id = (int)$_GET['disable_user'];
    try {
        // Fetch details for enriched logging before removal
        $uInfo = $pdo->prepare("SELECT full_name, username FROM users WHERE employee_id = ?");
        $uInfo->execute([$id]);
        $u = $uInfo->fetch();

        if ($u) {
            $performer = "{$u['full_name']} (@{$u['username']})";
            $stmt = $pdo->prepare("DELETE FROM users WHERE employee_id=?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                logAction($pdo, $_SESSION['user_id'], "Revoked user access for employee: $performer");
            }
        }

        header("Location: employees.php?revoke_success=1");
        exit;
    } catch (Exception $e) { $error = $e->getMessage(); }
}

// Handle Grant User (Create Login for Existing)
if (isset($_GET['grant_user'])) {
    $id = (int)$_GET['grant_user'];
    try {
        $emp = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $emp->execute([$id]);
        $emp_data = $emp->fetch();

        if ($emp_data) {
            // SAFE-CHECK: Only proceed if this employee doesn't already have an account 
            // (prevents duplicate accounts on page refresh)
            $existing = $pdo->prepare("SELECT id FROM users WHERE employee_id = ?");
            $existing->execute([$id]);
            if ($existing->fetch()) {
                header("Location: employees.php?msg=account_already_exists");
                exit;
            }

            // Generate Username Logic (Same as Add Flow)
            $base_username = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '.', $emp_data['name']))); 
            $base_username = trim($base_username, '.');
            if (strlen($base_username) < 3) $base_username = "user." . $base_username;

            $username = $base_username;
            $counter = 1;
            while (true) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $check->execute([$username]);
                if ($check->fetchColumn() == 0) break;
                $username = $base_username . "." . $counter;
                $counter++;
            }

            $default_pass = "Welcome@123";
            $hashed = password_hash($default_pass, PASSWORD_DEFAULT);

            $uStmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, employee_id, department, email, mobile) VALUES (?, ?, ?, 'employee', ?, ?, ?, ?)");
            $uStmt->execute([$username, $hashed, $emp_data['name'], $id, $emp_data['department'], $emp_data['email'], $emp_data['mobile']]);

            logAction($pdo, $_SESSION['user_id'], "Granted login access to employee: {$emp_data['name']} (@$username)");
            
            header("Location: employees.php?new_user=" . urlencode($username) . "&new_pass=" . urlencode($default_pass));
            exit;
        }
    } catch (Exception $e) {
        $error = "Error granting access: " . $e->getMessage();
    }
}

// Fetch Employees with User Status
$employees = $pdo->query("SELECT e.*, u.id as user_id FROM employees e LEFT JOIN users u ON e.id = u.employee_id WHERE e.status='active' ORDER BY e.name")->fetchAll();

require_once 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Employee Management</h3>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#empModal" onclick="clearForm()">
        <i class="bi bi-plus-lg"></i> Add New Employee
    </button>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Department</th>
                    <th>Contact</th>
                    <th>User Access</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td>
                            <?php echo htmlspecialchars($emp['name']); ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($emp['department']); ?>
                        </td>
                        <td>
                            <div><i class="bi bi-envelope"></i>
                                <?php echo htmlspecialchars($emp['email']); ?>
                            </div>
                            <div><i class="bi bi-phone"></i>
                                <?php echo htmlspecialchars($emp['mobile']); ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($emp['user_id']): ?>
                                <span class="badge bg-success">Active</span>
                                <button type="button" class="btn btn-sm btn-outline-danger ms-1 border-0" title="Revoke Login"
                                    onclick="confirmRevoke(<?php echo $emp['id']; ?>)">
                                    <i class="bi bi-slash-circle-fill"></i>
                                </button>
                            <?php else: ?>
                                <span class="badge bg-secondary mb-1">No Login</span>
                                <a href="?grant_user=<?php echo $emp['id']; ?>" class="btn btn-sm btn-outline-primary py-0 px-2 d-block" 
                                   title="Grant Access" 
                                   onclick="return confirm('Do you want to create a login account for this employee?')">
                                   <i class="bi bi-person-plus-fill"></i> Enable Access
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-info text-white"
                                onclick="editEmp(<?php echo htmlspecialchars(json_encode($emp)); ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>

                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modals replaced with AppDialog -->



<!-- Modal -->
<div class="modal fade" id="empModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST"
                onsubmit="this.querySelector('button[type=submit]').disabled=true; this.querySelector('button[type=submit]').innerHTML='<span class=\'spinner-border spinner-border-sm me-2\'></span>Processing...';">
                <div class="modal-header bg-gradient-primary text-white"
                    style="background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);">
                    <h5 class="modal-title fw-bold" id="modalTitle">
                        <i class="bi bi-person-plus-fill me-2"></i>
                        <?php echo (isset($form_data['id']) && !empty($form_data['id'])) ? 'Edit Employee' : 'Add Employee'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="id" id="emp_id" value="<?php echo htmlspecialchars($form_data['id'] ?? ''); ?>">

                    <div class="form-floating mb-3">
                        <input type="text" name="name" id="name" class="form-control rounded-3" placeholder="Full Name"
                            value="<?php echo htmlspecialchars($form_data['name'] ?? ''); ?>" required>
                        <label for="name"><i class="bi bi-person me-1"></i> Full Name</label>
                    </div>

                    <div class="form-floating mb-3">
                        <select name="department" id="department" class="form-select rounded-3" required>
                            <option value="">Select Department</option>
                            <?php
                            $dept_stmt = $pdo->query("SELECT name FROM departments WHERE status='active' ORDER BY name");
                            while ($d = $dept_stmt->fetchColumn()):
                                ?>
                                <option value="<?php echo htmlspecialchars($d); ?>" 
                                    <?php echo (isset($form_data['department']) && $form_data['department'] == $d) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <label for="department"><i class="bi bi-building me-1"></i> Department</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="email" name="email" id="email" class="form-control rounded-3"
                            placeholder="Email Address" 
                            value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
                        <label for="email"><i class="bi bi-envelope me-1"></i> Email Address</label>
                    </div>

                    <div class="form-floating mb-3">
                        <input type="tel" name="mobile" id="mobile" class="form-control rounded-3"
                            placeholder="Mobile Number" required maxlength="10" pattern="[0-9]{10}"
                            value="<?php echo htmlspecialchars($form_data['mobile'] ?? ''); ?>"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                        <label for="mobile"><i class="bi bi-phone me-1"></i> Mobile Number</label>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light">
                    <button type="button" class="btn btn-secondary rounded-pill px-4"
                        data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function confirmRevoke(id) {
        AppDialog.confirm({
            title: 'Revoke Access?',
            text: 'Are you sure you want to disable login for this employee? The employee record will remain, but the user account will be deleted.',
            confirmText: 'Yes, Revoke',
            icon: 'warning'
        }).then(function (confirmed) {
            if (confirmed) {
                window.location.href = 'employees.php?disable_user=' + id;
            }
        });
    }



    function editEmp(data) {
        document.getElementById('modalTitle').innerText = 'Edit Employee';
        document.getElementById('emp_id').value = data.id;
        document.getElementById('name').value = data.name;
        document.getElementById('department').value = data.department;
        document.getElementById('email').value = data.email;
        document.getElementById('mobile').value = data.mobile;

        var myModal = new bootstrap.Modal(document.getElementById('empModal'));
        myModal.show();
    }

    function clearForm() {
        document.getElementById('modalTitle').innerText = 'Add Employee';
        document.getElementById('emp_id').value = '';
        document.getElementById('name').value = '';
        document.getElementById('department').value = '';
        document.getElementById('email').value = '';
        document.getElementById('mobile').value = '';
    }

    // Auto-show success modal based on URL
    document.addEventListener('DOMContentLoaded', function () {
        <?php if (isset($_GET['new_user'])): ?>
            AppDialog.show({
                icon: 'success',
                title: 'Employee Added!',
                text: 'User Account Created:\nUsername: <?php echo htmlspecialchars($_GET['new_user']); ?>\nPassword: <?php echo htmlspecialchars($_GET['new_pass']); ?>'
            });
        <?php elseif (isset($_GET['edit_success'])): ?>
            AppDialog.show({
                icon: 'success',
                title: 'Updated!',
                text: 'Employee details and linked user account updated successfully.'
            });
        <?php elseif (isset($_GET['revoke_success'])): ?>
            AppDialog.show({
                icon: 'success',
                title: 'Access Revoked!',
                text: 'The user account for this employee has been disabled.'
            });
        <?php elseif (isset($_GET['msg'])): ?>
            AppDialog.show({
                icon: 'info',
                title: 'Notice',
                text: '<?php echo htmlspecialchars($_GET['msg']); ?>'
            });
        <?php elseif ($error): ?>
            // Re-open modal if there was an error
            var myModal = new bootstrap.Modal(document.getElementById('empModal'));
            myModal.show();

            AppDialog.show({
                icon: 'error',
                title: 'Error',
                text: '<?php echo htmlspecialchars($error); ?>'
            });
        <?php endif; ?>

        // CLEAN URL: Remove success flags from address bar after displaying (prevents repetitive alerts on refresh)
        if (typeof window.history.replaceState === 'function') {
            const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
            window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
        }
    });
</script>

<?php require_once 'footer.php'; ?>