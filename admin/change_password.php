<?php
require_once 'header.php';

$msg = '';
$msg_type = 'success';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $msg = "All fields are required!";
        $msg_type = 'danger';
    } else {
        // First, verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!password_verify($current_password, $user['password'])) {
            $msg = "Current password is incorrect!";
            $msg_type = 'danger';
        } elseif (strlen($new_password) < 6) {
            $msg = "New password must be at least 6 characters long!";
            $msg_type = 'danger';
        } elseif ($new_password !== $confirm_password) {
            $msg = "New password and confirm password do not match!";
            $msg_type = 'danger';
        } else {
            // Update password
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $_SESSION['user_id']]);

            $msg = "Password changed successfully!";
            $msg_type = 'success';

            // Log the action
            logAction($pdo, $_SESSION['user_id'], "Password changed");
        }
    }
}

// Get user info
$stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_info = $stmt->fetch();
?>

<div class="container-fluid px-4 py-4">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-header bg-primary text-white rounded-top-4 py-3">
                    <h5 class="mb-0"><i class="bi bi-key me-2"></i>Change Password</h5>
                </div>
                <div class="card-body p-4">

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password"
                                required>
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required
                                minlength="6">
                            <div class="form-text">Minimum 6 characters</div>
                        </div>

                        <div class="mb-4">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                required minlength="6">
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                <i class="bi bi-check-circle me-2"></i>Change Password
                            </button>
                            <a href="<?php echo $home_url; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 mt-3">
                <div class="card-body p-3">
                    <h6 class="text-muted mb-2"><i class="bi bi-info-circle me-2"></i>Password Tips</h6>
                    <ul class="small text-muted mb-0">
                        <li>Use a mix of letters, numbers, and symbols</li>
                        <li>Avoid using personal information</li>
                        <li>Don't reuse passwords from other accounts</li>
                        <li>Change your password regularly</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($msg): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            <?php if ($msg_type === 'success'): ?>
                AppDialog.show({
                    icon: 'success',
                    title: 'Password Changed Successfully!',
                    text: 'Your password has been updated. You can now use your new password to log in.'
                });
            <?php else: ?>
                AppDialog.show({
                    icon: 'error',
                    title: 'Password Change Failed',
                    text: '<?php echo addslashes($msg); ?>'
                });
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<?php require_once 'footer.php'; ?>