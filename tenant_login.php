<?php
/**
 * Tenant Login Page
 * Simplified login for specific tenant access
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Use default session
session_start();

// Sanitize function
function sanitize($data)
{
    return htmlspecialchars(strip_tags(trim($data)));
}

// Handle tenant parameter from URL
$target_tenant_key = $_GET['tenant'] ?? null;

if ($target_tenant_key) {
    // Store in a separate variable to avoid affecting main session
    $isolated_tenant_key = sanitize($target_tenant_key);
} else {
    // Fall back to session tenant
    require_once 'includes/db.php';
    $isolated_tenant_key = $tenant_key;
}

// Connect to the specific tenant database
$tenant_pdo = null;
$tenant_info = null;

// Get master connection first - Use proper environment detection
$is_local = in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1']) ||
    (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

if ($is_local) {
    $m_host = 'localhost';
    $m_user = 'root';
    $m_pass = '';
    $m_db = 'vms_master';
} else {
    // Hosted environment
    $m_host = 'localhost';
    $m_user = 'u875321134_vms_master';
    $m_pass = 'Eu8~ieQH?Wzc';
    $m_db = 'u875321134_vms_master';
}

try {
    $master_pdo = new PDO("mysql:host=$m_host;dbname=$m_db;charset=utf8mb4", $m_user, $m_pass);
    $master_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get tenant info
    $stmt = $master_pdo->prepare("SELECT * FROM tenants WHERE tenant_key = ? AND status = 'active'");
    $stmt->execute([$isolated_tenant_key]);
    $tenant_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tenant_info) {
        // Connect to tenant database
        $tenant_pdo = new PDO(
            "mysql:host={$tenant_info['db_host']};dbname={$tenant_info['db_name']};charset=utf8mb4",
            $tenant_info['db_user'],
            $tenant_info['db_pass']
        );
        $tenant_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tenant_pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Use this connection for login
        $pdo = $tenant_pdo;
    } else {
        die("Error: Tenant not found or inactive");
    }
} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}

$error = '';
$success_msg = "Login to " . ucfirst($isolated_tenant_key);

// Fetch Logo (Priority: Tenant Settings > Master Identity > Default Fallback)
$company_logo = 'assets/img/CodePilotx Logo.webp';
$company_name = 'CodePilotx VMS';

try {
    // 1. First Get Master Identity (as baseline)
    $stmt_master = $master_pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_logo')");
    if ($stmt_master) {
        $master_settings = $stmt_master->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($master_settings['company_name'])) $company_name = $master_settings['company_name'];
        if (!empty($master_settings['company_logo'])) $company_logo = $master_settings['company_logo'];
    }

    // 2. Then try to override with Tenant Specific Branding
    $stmt_tenant = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'company_logo')");
    if ($stmt_tenant) {
        $tenant_settings = $stmt_tenant->fetchAll(PDO::FETCH_KEY_PAIR);
        if (!empty($tenant_settings['company_name'])) $company_name = $tenant_settings['company_name'];
        if (!empty($tenant_settings['company_logo'])) $company_logo = $tenant_settings['company_logo'];
    }
} catch (Exception $e) {
    // Fail silently, use defaults
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_action'])) {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['bg_mode'] = $user['bg_mode'];
            $_SESSION['is_super'] = (bool) (($user['is_superadmin'] ?? $user['is_super']) ?? false);
            $_SESSION['tenant_key'] = $isolated_tenant_key; // Set the tenant context

            // Log action (simple version without logAction function)
            try {
                $log_stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
                $log_stmt->execute([$user['id'], "User Login: $username (Tenant: $isolated_tenant_key)"]);
            } catch (Exception $e) {
                // Ignore logging errors
            }

            if ($user['role'] == 'admin') {
                header("Location: admin/dashboard.php");
                exit;
            } elseif ($user['role'] == 'host' || $user['role'] == 'employee') {
                header("Location: host/dashboard.php");
                exit;
            } else {
                header("Location: security/dashboard.php");
                exit;
            }
        } else {
            $error = "Invalid password";
        }
    } else {
        $error = "User not found in this tenant's database";
    }
}

$current_tenant = ucfirst($isolated_tenant_key);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login -
        <?php echo htmlspecialchars($current_tenant); ?> | VMS
    </title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-container {
            max-width: 450px;
            width: 100%;
            padding: 20px;
        }

        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }

        .tenant-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 20px;
        }

        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 600;
            color: white;
            transition: transform 0.2s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
            color: white;
        }

        .back-link {
            color: #667eea;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-4">
                <?php if ($company_logo): ?>
                    <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" class="mb-3 rounded-4 shadow-sm"
                        style="max-height: 80px; max-width: 150px; object-fit: contain;">
                <?php else: ?>
                    <i class="bi bi-building text-primary" style="font-size: 3rem;"></i>
                <?php endif; ?>
                <h3 class="mt-2 fw-bold"><?php echo htmlspecialchars($company_name); ?></h3>
                <span class="tenant-badge">
                    <i class="bi bi-shield-check me-1"></i>
                    <?php echo htmlspecialchars($current_tenant); ?>
                </span>
            </div>

            <?php if ($success_msg): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?php echo htmlspecialchars($success_msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="login_action" value="1">

                <div class="mb-3">
                    <label class="form-label fw-bold">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-person-fill text-primary"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" name="username"
                            placeholder="Enter username" required autofocus>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-bold">Password</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0">
                            <i class="bi bi-lock-fill text-primary"></i>
                        </span>
                        <input type="password" class="form-control border-start-0" name="password"
                            placeholder="Enter password" required>
                    </div>
                </div>

                <div class="d-grid mb-3">
                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                    </button>
                </div>

                <div class="text-center">
                    <a href="admin/tenants.php" class="back-link">
                        <i class="bi bi-arrow-left me-1"></i>Back to Tenant Management
                    </a>
                </div>
            </form>

            <div class="text-center mt-4 pt-3 border-top">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Default credentials: admin / admin
                </small>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>