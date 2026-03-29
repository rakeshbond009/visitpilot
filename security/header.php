<?php
require_once '../includes/db.php';
requireLogin();

// 1. Enforce Page-Level Security
enforcePageSecurity();

$current_page = basename($_SERVER['PHP_SELF']);
$home_url = getHomeUrl($_SESSION['role']);

// Dynamic Header Appearance based on Role
$navbar_class = 'bg-dark';
$pulse_text = 'Admin Center';

if ($_SESSION['role'] === 'security') {
    $navbar_class = 'bg-security';
    $pulse_text = 'Reception Desk';
}
elseif ($_SESSION['role'] === 'host' || $_SESSION['role'] === 'employee') {
    $navbar_class = 'bg-success';
    $pulse_text = 'Host Portal';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Management</title>
    <script>
        var BASE_URL = '<?php echo BASE_URL; ?>';
        var HOME_URL = '<?php echo $home_url; ?>';
    </script>
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/style.css?v=1.2">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/datetime-format.js"></script>
    <style>
        .navbar {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .live-indicator {
            width: 10px;
            height: 10px;
            background: #6610f2;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            animation: pulse-purple 2s infinite;
        }

        @keyframes pulse-purple {
            0% {
                box-shadow: 0 0 0 0 rgba(102, 16, 242, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(102, 16, 242, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(102, 16, 242, 0);
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
                background: #008b8b !important; /* Solid Cyan Background */
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

        .bg-security {
            background-color: #008b8b !important;
            /* Dark Cyan */
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
                        <a class="nav-link <?php echo($current_page == 'dashboard.php' && strpos($_SERVER['PHP_SELF'], '/security/') !== false) ? 'active' : ''; ?>"
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