<?php
require_once '../includes/db.php';
requireLogin();

// 1. Enforce Page-Level Security
enforcePageSecurity();

$current_page = basename($_SERVER['PHP_SELF']);
$home_url = getHomeUrl($_SESSION['role']);

// Dynamic Header Appearance based on Role
$navbar_class = 'bg-success';
$pulse_text = 'Host Portal';

if ($_SESSION['role'] === 'admin') {
    $navbar_class = 'bg-dark';
    $pulse_text = 'Admin Center';
}
elseif ($_SESSION['role'] === 'security') {
    $navbar_class = 'bg-primary';
    $pulse_text = 'Reception Desk';
}

// Get host's details (if they have an employee_id)
$stmt = $pdo->prepare("SELECT u.employee_id, e.mobile FROM users u LEFT JOIN employees e ON u.employee_id = e.id WHERE u.id = ?");
$stmt->execute([$_SESSION['user_id']]);
$host_data = $stmt->fetch();
$host_employee_id = $host_data['employee_id'] ?? null;
$host_mobile = $host_data['mobile'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Dashboard - VisitPilot</title>
    <script>
        var BASE_URL = '<?php echo BASE_URL; ?>';
        var HOME_URL = '<?php echo $home_url; ?>';
        var USER_FULL_NAME = '<?php echo addslashes($_SESSION['full_name']); ?>';
    </script>
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=1.1">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .navbar {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .live-indicator {
            width: 10px;
            height: 10px;
            background: #28a745;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            animation: pulse-green 2s infinite;
        }

        @keyframes pulse-green {
            0% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(40, 167, 69, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0);
            }
        }

        .nav-link {
            white-space: nowrap;
        }

        .nav-link.active {
            font-weight: bold;
            border-bottom: 2px solid white;
        }

        /* Mobile Menu Enhancement */
        @media (max-width: 991.98px) {
            .navbar-collapse {
                background: #198754 !important; /* Solid Success Background */
                padding: 20px;
                border-radius: 0 0 15px 15px;
                box-shadow: 0 15px 30px rgba(0,0,0,0.4);
                margin-top: 0;
                z-index: 1000;
            }
            .nav-link.active {
                border-bottom: none;
                border-left: 3px solid white;
                padding-left: 15px;
            }
        }
    </style>
</head>

<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark <?php echo $navbar_class; ?> sticky-top p-0 shadow-sm" style="height: 85px; overflow: visible;">
        <div class="container-fluid p-0 h-100">
            <a class="navbar-brand fw-bold p-0 m-0 h-100 d-flex align-items-center" href="<?php echo $home_url; ?>"
                style="padding-left: 125px !important; margin-left: -30px !important; margin-right: 40px !important; position: relative; z-index: 101;">
                <img src="<?php echo BASE_URL . $company_settings['logo']; ?>" alt="Logo"
                    style="height: 175%; width: auto; object-fit: contain; position: absolute; left: 0; top: 62%; transform: translateY(-50%); filter: drop-shadow(0 10px 20px rgba(0,0,0,0.3)); z-index: 100;">
                <span style="font-weight: 800; line-height: 1; font-size: 1.6rem; letter-spacing: -1px; background: linear-gradient(45deg, #ffffff, #a5c7fb); -webkit-background-clip: text; -webkit-text-fill-color: transparent; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));"><?php echo htmlspecialchars($company_settings['name']); ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#vmsNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="vmsNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?php echo(basename($_SERVER['PHP_SELF']) == 'dashboard.php') ? 'active' : ''; ?>"
                            href="<?php echo $home_url; ?>">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>

                    <!-- Include Unified Menu Items -->
                    <?php include '../includes/menu_items.php'; ?>
                </ul>
                <div class="d-flex align-items-center">
                    <div class="text-white me-3 d-none d-md-block">
                        <span class="live-indicator"></span><small><?php echo $pulse_text; ?></small>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-outline-light dropdown-toggle btn-sm" type="button"
                            data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="../admin/change_password.php"><i class="bi bi-key me-2"></i>Change Password</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger fw-bold" href="../logout.php"><i
                                        class="bi bi-box-arrow-right"></i> Logout Now</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    <div class="container py-4">