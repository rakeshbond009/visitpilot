<?php
require_once 'includes/db.php';

$error = '';

// Check for Login Errors from Session (Flash Message)
if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// 1. Handle Persistent Sessions (Auto-login via Cookie)
handlePersistentLogin();

// 2. Auto-redirect if already logged in (via Session or Cookie)
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] == 'admin') {
        redirect(BASE_URL . 'admin/dashboard.php');
    }
    elseif ($_SESSION['role'] == 'host' || $_SESSION['role'] == 'employee') {
        redirect(BASE_URL . 'host/dashboard.php');
    }
    else {
        redirect(BASE_URL . 'security/dashboard.php');
    }
}

$login_username_value = '';
if (isset($_SESSION['login_attempt_username'])) {
    $login_username_value = $_SESSION['login_attempt_username'];
    unset($_SESSION['login_attempt_username']);
}

// Use Global Company Settings
$company_logo = !empty($company_settings['logo']) ? $company_settings['logo'] : 'assets/img/logo.png';
$company_name = $company_settings['name'] ?? 'CodePilotx VMS';

// Fetch Other UI Settings
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_support_link', 'company_address', 'company_email', 'company_phone')");
$settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$support_link = $settings['company_support_link'] ?? 'https://www.codepilotx.com/pages/contact.html';
$company_address = $settings['company_address'] ?? 'Tech Hub, Silicon Valley, CA';
$company_email = $settings['company_email'] ?? 'hello@codepilotx.com';
$company_phone = $settings['company_phone'] ?? '+1 (555) 000-0000';

/**
 * Handle Login POST
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_action'])) {
    // Handle tenant selection if provided
    if (isset($_POST['tenant_key']) && !empty($_POST['tenant_key'])) {
        $_SESSION['tenant_key'] = sanitize($_POST['tenant_key']);
        // Reload db.php to reconnect with the new tenant
        $tenant_key = $_SESSION['tenant_key'];

        // Reconnect to the selected tenant's database
        if (isset($master_pdo)) {
            try {
                $stmt = $master_pdo->prepare("SELECT * FROM tenants WHERE tenant_key = ? AND status = 'active'");
                $stmt->execute([$tenant_key]);
                $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($tenant) {
                    $pdo = new PDO(
                        "mysql:host={$tenant['db_host']};dbname={$tenant['db_name']};charset=utf8mb4",
                        $tenant['db_user'],
                        $tenant['db_pass']
                        );
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                }
            }
            catch (Exception $e) {
            // Continue with default tenant
            }
        }
    }

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
            $_SESSION['is_super'] = (bool)(($user['is_superadmin'] ?? $user['is_super'] ?? false));

            // Create Persistent Session (Cookie + DB Token)
            createPersistentSession($user['id']);

            logAction($pdo, $user['id'], "User Login: $username");

            if ($user['role'] == 'admin') {
                redirect('admin/dashboard.php');
            }
            elseif ($user['role'] == 'host' || $user['role'] == 'employee') {
                redirect('host/dashboard.php');
            }
            else {
                redirect('security/dashboard.php');
            }
        }
        else {
            // Redirect with Error
            $_SESSION['login_error'] = "Invalid password associated with this account.";
            $_SESSION['login_attempt_username'] = $username;
            redirect('index.php');
        }
    }
    else {
        // Redirect with Error
        $_SESSION['login_error'] = "Account does not exist or has been deactivated.";
        $_SESSION['login_attempt_username'] = $username;
        redirect('index.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodePilotx VMS - Smart Visitor Management</title>
    <meta name="description" content="CodePilotx VMS is an advanced Smart Visitor Management System offering AI-powered intelligence, hardware integration, and robust security for modern workspaces.">
    <meta name="keywords" content="Visitor Management System, VMS, Smart Visitor System, Access Control, CodePilotx VMS, Workplace Security">
    <meta name="author" content="CodePilotX">
    <meta property="og:title" content="CodePilotx VMS - Smart Visitor Management">
    <meta property="og:description" content="Streamline visitor experience with AI-powered smart visitor management and robust security.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://visitpilot.codepilotx.com/">
    <link rel="canonical" href="https://visitpilot.codepilotx.com/">
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/landing.css">
    <style>
        :root {
            --primary-bg: #0F172A;
        }
        .hero-section-new {
            background-color: var(--primary-bg);
            min-h: 90vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
            padding: 100px 0;
            color: white;
        }
        .hero-title-new {
            font-size: 3.5rem;
            font-weight: 900;
            line-height: 1.1;
            margin-bottom: 2rem;
            letter-spacing: -1px;
        }
        @media (min-width: 992px) {
            .hero-title-new { font-size: 4.5rem; }
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 40px;
            padding: 30px;
        }
        .video-container {
            position: relative;
            border-radius: 48px;
            overflow: hidden;
            border: 12px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 40px 100px -20px rgba(0,0,0,0.5);
            transition: transform 0.3s ease;
        }
        .video-container:hover {
            transform: scale(1.02);
        }
        .nav-social-icon {
            color: rgba(0,0,0,0.5);
            transition: all 0.3s;
            margin-right: 15px;
        }
        .nav-social-icon:hover {
            color: #0d6efd;
            transform: scale(1.1);
        }
        .feature-bubble {
            position: absolute;
            bottom: -30px;
            right: -30px;
            z-index: 10;
        }
        @media (max-width: 991.98px) {
            .navbar-collapse {
                background: white;
                padding: 20px;
                border-radius: 0 0 15px 15px;
                box-shadow: 0 15px 30px rgba(0,0,0,0.1);
                margin-top: 10px;
            }
            .hero-section-new { padding: 80px 0; }
        }
    </style>
</head>

<body>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg fixed-top p-0 m-0 shadow-sm" id="mainNav" style="height: 85px; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); border-bottom: 1px solid rgba(0,0,0,0.05);">
        <div class="container-fluid p-0 h-100" style="overflow: visible;">
            <a class="navbar-brand d-flex align-items-center p-0 m-0 h-100" href="#" 
                style="padding-left: 100px !important; margin-left: 0 !important; margin-right: 15px !important; position: relative; z-index: 101;">
                <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" class="rounded-circle"
                    style="height: 140%; width: auto; object-fit: contain; position: absolute; left: 0; top: 60%; transform: translateY(-50%); filter: drop-shadow(0 10px 20px rgba(0,0,0,0.15)); z-index: 10;">
                <span class="fw-bold mb-0" 
                    style="font-size: 1.6rem; letter-spacing: -1.2px; line-height: 1; background: linear-gradient(45deg, var(--primary-color, #0d6efd), #052c65); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                    <?php echo htmlspecialchars($company_name); ?>
                </span>
            </a>
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <i class="bi bi-list fs-1 text-dark"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto me-4">
                    <li class="nav-item"><a class="nav-link px-2" href="#features">Features</a></li>
                    <li class="nav-item"><a class="nav-link px-2" href="#ai-intelligence">AI Power</a></li>
                    <li class="nav-item"><a class="nav-link px-2" href="#hardware">Hardware</a></li>
                    <li class="nav-item"><a class="nav-link px-2" href="#video">Video</a></li>
                    <li class="nav-item"><a class="nav-link px-2" href="#how">Process</a></li>
                </ul>
                <div class="d-flex align-items-center">
                    <div class="d-none d-md-flex me-4">
                        <a href="https://www.linkedin.com/in/rakesh-verma-22a8b996/" target="_blank" class="nav-social-icon"><i data-lucide="linkedin"></i></a>
                        <a href="https://x.com/rakesh_bond009" target="_blank" class="nav-social-icon"><i data-lucide="twitter"></i></a>
                    </div>
                    <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal"
                        class="btn btn-primary-cta btn-cta py-2 px-4 shadow-sm" style="font-size: 0.9rem; border-radius: 25px;">Portal Login <i
                            class="bi bi-box-arrow-in-right ms-1"></i></a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section-new">
        <div class="container-fluid px-lg-5">
            <div class="row align-items-center">
                <div class="col-lg-5 mb-5 mb-lg-0 px-lg-5">
                    <div class="animate__animated animate__fadeInLeft">
                        <span class="hero-badge bg-white bg-opacity-10 border-white border-opacity-10 text-white mb-4">
                            <i data-lucide="star" class="me-2 text-warning" style="width: 14px;"></i> Next-Gen Visitor Management
                        </span>
                        <h1 class="hero-title-new text-white">
                            Elevate Your <br>
                            <span class="text-info">Reception</span> <br>
                            Experience.
                        </h1>
                        <p class="text-white text-opacity-70 fs-5 mb-5 max-w-lg">
                            Transform your front desk into a high-tech entrance. Secure, seamless, and fully digital visitor management for the modern workplace.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <a href="#" data-bs-toggle="modal" data-bs-target="#loginModal"
                                class="btn btn-light py-3 px-5 fw-black rounded-4 d-flex align-items-center gap-2">
                                Get Started <i data-lucide="arrow-right" class="w-5 h-5"></i>
                            </a>
                            <a href="#video" class="btn btn-outline-light py-3 px-4 rounded-4 d-flex align-items-center gap-2">
                                <i data-lucide="play" class="w-5 h-5"></i> Watch Preview
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7 position-relative">
                    <div class="animate__animated animate__zoomIn animate__delay-1s px-lg-4">
                        <div class="video-container">
                            <video 
                                src="<?php echo BASE_URL; ?>assets/img/Videos/Video_VisitPilot.mp4" 
                                autoPlay muted loop playsInline 
                                class="w-100 h-auto d-block">
                            </video>
                        </div>
                        
                        <!-- Dynamic Feature Bubble -->
                        <div class="feature-bubble glass-card d-none d-xl-block animate__animated animate__fadeInUp animate__delay-2s" style="min-width: 320px;">
                            <div class="d-flex align-items-center gap-4" id="feature-looper">
                                <div class="bg-primary rounded-4 p-3 text-white shadow-lg" id="looper-icon">
                                    <i data-lucide="shield" class="w-8 h-8"></i>
                                </div>
                                <div>
                                    <h4 class="fw-black text-white m-0 h2" id="looper-value">99.9%</h4>
                                    <p class="text-white text-opacity-50 m-0 fw-bold small text-uppercase ls-wide" id="looper-label">Uptime Accuracy</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        // Initialize Lucid Icons
        lucide.createIcons();

        // Feature Looper Script
        const heroFeatures = [
            { label: "Uptime Accuracy", value: "99.9%", icon: "shield", color: "bg-primary" },
            { label: "Check-in Speed", value: "Instant", icon: "zap", color: "bg-success" },
            { label: "Security Level", value: "Bank-Grade", icon: "lock", color: "bg-danger" },
            { label: "AI Recognition", value: "Smart", icon: "cpu", color: "bg-info" }
        ];

        let currentIndex = 0;
        const looperIcon = document.getElementById('looper-icon');
        const looperValue = document.getElementById('looper-value');
        const looperLabel = document.getElementById('looper-label');

        setInterval(() => {
            currentIndex = (currentIndex + 1) % heroFeatures.length;
            const feature = heroFeatures[currentIndex];
            
            // Fade out
            document.getElementById('feature-looper').style.opacity = '0';
            
            setTimeout(() => {
                looperValue.innerText = feature.value;
                looperLabel.innerText = feature.label;
                looperIcon.className = `rounded-4 p-3 text-white shadow-lg ${feature.color}`;
                looperIcon.innerHTML = `<i data-lucide="${feature.icon}" class="w-8 h-8"></i>`;
                lucide.createIcons();
                
                // Fade in
                document.getElementById('feature-looper').style.opacity = '1';
                document.getElementById('feature-looper').style.transition = 'opacity 0.5s ease-in-out';
            }, 500);
        }, 4000);
    </script>

    <!-- AI Intelligence - Moved Up -->
    <section id="ai-intelligence" class="pt-5 pb-100 bg-light">
        <div class="container">
            <div class="text-center mb-80 reveal">
                <span class="hero-badge bg-primary text-white">Next-Gen Intelligence</span>
                <h2 class="display-5 fw-800">VisitPilot AI is here.</h2>
                <p class="text-muted">High-tech automation meets natural conversation.</p>
            </div>

            <div class="row align-items-center flex-row-reverse mb-5 pb-5 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">AI Assistant & Natural Chat</h3>
                    <p class="text-muted fs-5 mb-4">Meet **VisitPilot AI**, your 24/7 intelligent concierge. Ask questions about traffic, search for visitors by description, or get real-time security summaries using natural language.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-chat-dots-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Zero-Quota Local Intelligence</span></li>
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-mic-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Voice Input & Audio Responses</span></li>
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-robot text-primary me-3 fs-5"></i> <span class="fw-bold">Automated Security Insights</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-white rounded-5 shadow-2xl overflow-hidden border">
                        <img src="assets/img/website%20images/ai_chat.png" alt="AI Chatbot Assistant" class="img-fluid rounded-4 shadow-lg">
                    </div>
                </div>
            </div>

            <div class="row align-items-center mb-5 pb-5 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">Intelligent AI Search</h3>
                    <p class="text-muted fs-5 mb-4">Forget complex filters. Our AI-powered search understands intent. Simply type *"who visited Siddhitech yesterday"* or *"show me busy hours"* and get instant data-driven answers.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-search-heart text-primary me-3 fs-5"></i> <span class="fw-bold">Intent-Based Search Engine</span></li>
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-graph-up text-primary me-3 fs-5"></i> <span class="fw-bold">Real-time Data Visualization</span></li>
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-magic text-primary me-3 fs-5"></i> <span class="fw-bold">Smart Typo Tolerance</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-white rounded-5 shadow-2xl overflow-hidden border">
                        <img src="assets/img/website%20images/ai_search.png" alt="AI Search Interface" class="img-fluid rounded-4 shadow-lg">
                    </div>
                </div>
            </div>
        </div>
    </section>


    <!-- Core Features Showcase -->
    <div class="py-100 bg-white">
        <div class="container">

            <!-- 1. Centralized Admin Control -->
            <div class="row align-items-center mb-100 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">Centralized Admin Control</h3>
                    <p class="text-muted fs-5 mb-4">Complete oversight of your organization's security. Monitor
                        real-time stats, AI-predicted traffic surges, and detailed visitor logs from a single powerful
                        dashboard.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-bar-chart-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Real-time
                                Stats & 7-Day Trends</span></li>
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-cpu-fill text-primary me-3 fs-5"></i>
                            <span class="fw-bold">AI Crowd Density & Predictions</span>
                        </li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-shield-exclamation text-primary me-3 fs-5"></i> <span
                                class="fw-bold">Security Anomalies & Overstay Alerts</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-people-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Top Hosts &
                                Zone Monitoring</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-dark rounded-5 shadow-2xl overflow-hidden ring-offset-4 ring-primary ring-2">
                        <div id="carouselAdmin" class="carousel slide carousel-fade" data-bs-ride="carousel">
                            <div class="carousel-inner rounded-4">
                                <div class="carousel-item active">
                                    <img src="assets/img/website%20images/Admin_Dashboard.jpg" class="d-block w-100"
                                        alt="Global Admin Dashboard">
                                </div>
                                <div class="carousel-item">
                                    <img src="assets/img/website%20images/Admin_Dashboard2.jpg" class="d-block w-100"
                                        alt="Detailed Admin Analytics">
                                </div>
                            </div>
                            <button class="carousel-control-prev" type="button" data-bs-target="#carouselAdmin"
                                data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"
                                    style="filter: invert(0.5);"></span>
                                <span class="visually-hidden">Previous</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#carouselAdmin"
                                data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"
                                    style="filter: invert(0.5);"></span>
                                <span class="visually-hidden">Next</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Unified Host Dashboard -->
            <div class="row align-items-center flex-row-reverse mb-100 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">Unified Host Dashboard</h3>
                    <p class="text-muted fs-5 mb-4">Empower your employees with a command center to manage their guests.
                        Approve visits, track arrivals, and view history in a stunning, intuitive interface.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-check-circle-fill text-primary me-3 fs-5"></i> <span
                                class="fw-bold">One-click Approvals</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-calendar-check text-primary me-3 fs-5"></i> <span
                                class="fw-bold">Pre-schedule Meetings</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-clock-history text-primary me-3 fs-5"></i> <span class="fw-bold">Visitor
                                History Log</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-white rounded-5 shadow-2xl overflow-hidden border">
                        <img src="assets/img/website%20images/Host_Dashboard.jpg" alt="Host Dashboard"
                            class="img-fluid rounded-4 shadow-lg">
                    </div>
                </div>
            </div>

            <!-- 2.5. Express Invites & Fast Track -->
            <div class="row align-items-center mb-100 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">Express Invites & Fast Track</h3>
                    <p class="text-muted fs-5 mb-4">Streamline the process before your guest even arrives. Send express
                        invitation links and let visitors pre-fill their details for a zero-wait experience.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-send-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Instant Invite
                                Links</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-person-check-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Guest
                                Self-Registration</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-lightning-charge-fill text-primary me-3 fs-5"></i> <span
                                class="fw-bold">Priority Check-in Lane</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-white rounded-5 shadow-2xl overflow-hidden border">
                        <img src="assets/img/website%20images/Invite_Fastrack.jpg" alt="Express Invite"
                            class="img-fluid rounded-4 shadow-lg">
                    </div>
                </div>
            </div>

            <!-- 3. Reception & Security Desk -->
            <div class="row align-items-center mb-100 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">Smart Reception & Security</h3>
                    <p class="text-muted fs-5 mb-4">Equip your front desk with tools for rapid processing. Handle
                        walk-ins, verify pre-registrations, and issue digital passes in seconds.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-display text-primary me-3 fs-5"></i>
                            <span class="fw-bold">Live Visitor Queue</span>
                        </li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-camera-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Instant
                                Photo Capture</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-printer-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Badge
                                Printing Integration</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-dark rounded-5 shadow-2xl overflow-hidden ring-offset-4 ring-primary ring-2">
                        <img src="assets/img/website%20images/Reception_Dashboard.jpg" alt="Reception Dashboard"
                            class="img-fluid rounded-4 shadow-lg">
                    </div>
                </div>
            </div>

            <!-- 4. Advanced Analytics -->
            <div class="row align-items-center flex-row-reverse mb-100 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">Advanced Analytics & Reports</h3>
                    <p class="text-muted fs-5 mb-4">Gain actionable insights into your facility's traffic. Identify peak
                        hours, overstay anomalies, and generate compliant PDF reports for audits instantly.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-graph-up-arrow text-primary me-3 fs-5"></i> <span class="fw-bold">Traffic
                                Trend Analysis</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-pie-chart-fill text-primary me-3 fs-5"></i> <span
                                class="fw-bold">Departmental Usage Stats</span></li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-file-earmark-pdf-fill text-primary me-3 fs-5"></i> <span
                                class="fw-bold">Automated Audit Trails</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-light rounded-5 shadow-2xl overflow-hidden border">
                        <div id="carouselReports" class="carousel slide carousel-fade" data-bs-ride="carousel">
                            <div class="carousel-inner rounded-4">
                                <div class="carousel-item active">
                                    <img src="assets/img/website%20images/Advanced_Analytics_Reports.png"
                                        class="d-block w-100" alt="Advanced Analytics">
                                </div>
                                <div class="carousel-item">
                                    <img src="assets/img/website%20images/Reports.jpg" class="d-block w-100"
                                        alt="General Reports">
                                </div>
                                <div class="carousel-item">
                                    <img src="assets/img/website%20images/Reports1.jpg" class="d-block w-100"
                                        alt="Usage Reports">
                                </div>
                                <div class="carousel-item">
                                    <img src="assets/img/website%20images/Reports2.jpg" class="d-block w-100"
                                        alt="Audit Logs">
                                </div>
                            </div>
                            <button class="carousel-control-prev" type="button" data-bs-target="#carouselReports"
                                data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"
                                    style="filter: invert(1);"></span>
                                <span class="visually-hidden">Previous</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#carouselReports"
                                data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"
                                    style="filter: invert(1);"></span>
                                <span class="visually-hidden">Next</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. Digital Pass & Entry -->
            <div class="row align-items-center mb-100 g-5 reveal">
                <div class="col-lg-6">
                    <h3 class="fw-800 mb-4 h1">Touchless Digital Entry</h3>
                    <p class="text-muted fs-5 mb-4">Modernize the arrival experience with completely paperless digital
                        passes. Scan via QR code for instant, secure access.</p>
                    <ul class="list-unstyled">
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-qr-code text-primary me-3 fs-5"></i>
                            <span class="fw-bold">Dynamic QR Passes</span>
                        </li>
                        <li class="mb-3 d-flex align-items-center"><i class="bi bi-phone text-primary me-3 fs-5"></i>
                            <span class="fw-bold">Smartphone Ready Pass</span>
                        </li>
                        <li class="mb-3 d-flex align-items-center"><i
                                class="bi bi-shield-lock-fill text-primary me-3 fs-5"></i> <span
                                class="fw-bold">Fraud-proof Verification</span></li>
                    </ul>
                </div>
                <div class="col-lg-6">
                    <div class="p-2 bg-white rounded-5 shadow-2xl overflow-hidden border">
                        <img src="assets/img/website%20images/Pass-Visitor-Enrty.jpg" alt="Digital Pass"
                            class="img-fluid rounded-4 shadow-lg">
                    </div>
                </div>
            </div>            <!-- Hardware Integration Section -->
            <div id="hardware" class="py-100 reveal">
                <div class="text-center mb-80">
                    <span class="hero-badge">Next-Gen Integration</span>
                    <h2 class="display-5 fw-800">Unified Hardware Ecosystem</h2>
                    <p class="text-muted fs-5">Visitors registered in our app gain seamless, secure access via integrated hardware devices.</p>
                </div>
                
                <div class="row g-4">
                    <!-- Face Recognition -->
                    <div class="col-lg-4">
                        <div class="feature-card">
                            <div class="mb-4 overflow-hidden rounded-4 shadow-sm border">
                                <img src="assets/img/website%20images/face_recognition.png" alt="Face Recognition" class="img-fluid" style="width: 100%; height: 250px; object-fit: cover;">
                            </div>
                            <h4 class="fw-bold mb-3">Face Recognition</h4>
                            <p class="text-muted mb-0">Registered visitors get immediate access via high-precision facial recognition machines, ensuring a touchless and rapid entry experience.</p>
                        </div>
                    </div>
                    
                    <!-- Biometric -->
                    <div class="col-lg-4">
                        <div class="feature-card">
                            <div class="mb-4 overflow-hidden rounded-4 shadow-sm border">
                                <img src="assets/img/website%20images/biometric.png" alt="Biometric Access" class="img-fluid" style="width: 100%; height: 250px; object-fit: cover;">
                            </div>
                            <h4 class="fw-bold mb-3">Biometric Access</h4>
                            <p class="text-muted mb-0">Seamlessly integrate with fingerprint and palm scanners. Elevate your security by allowing verified visitors access to restricted zones.</p>
                        </div>
                    </div>
                    
                    <!-- QR Scan -->
                    <div class="col-lg-4">
                        <div class="feature-card">
                            <div class="mb-4 overflow-hidden rounded-4 shadow-sm border">
                                <img src="assets/img/website%20images/qr_scan.png" alt="QR Code Access" class="img-fluid" style="width: 100%; height: 250px; object-fit: cover;">
                            </div>
                            <h4 class="fw-bold mb-3">Speedy QR Scanning</h4>
                            <p class="text-muted mb-0">Visitors scan their digital QR pass at turnstiles or gates. Our app syncs instantly with scanning hardware for zero-delay entry.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 6. Notifications (OTP & WhatsApp) -->
            <div class="row align-items-center flex-row-reverse g-5 reveal">
                <div class="col-lg-6">
                        <h3 class="fw-800 mb-4 h1">Secure & Instant Communication</h3>
                        <p class="text-muted fs-5 mb-4">Keep everyone in the loop with real-time notifications. Validate
                            identities centrally and keep visitors informed on their preferred channels.</p>
                        <ul class="list-unstyled">
                            <li class="mb-3 d-flex align-items-center"><i
                                    class="bi bi-whatsapp text-success me-3 fs-5"></i>
                                <span class="fw-bold">WhatsApp Integration</span> <span
                                    class="badge bg-success ms-2">New</span><br><small class="text-muted ms-5">Send
                                    passes &
                                    maps directly to WhatsApp.</small>
                            </li>
                            <li class="mb-3 d-flex align-items-center"><i
                                    class="bi bi-shield-check text-primary me-3 fs-5"></i> <span class="fw-bold">Mobile
                                    OTP
                                    Verification</span><br><small class="text-muted ms-5">Authenticate visitors via SMS
                                    OTP.</small></li>
                            <li class="mb-3 d-flex align-items-center"><i
                                    class="bi bi-bell-fill text-primary me-3 fs-5"></i> <span class="fw-bold">Real-time
                                    Host
                                    Alerts</span><br><small class="text-muted ms-5">Instant email & SMS arrival
                                    notifications.</small></li>
                        </ul>
                    </div>
                    <div class="col-lg-6">
                        <div class="p-2 bg-white rounded-5 shadow-2xl overflow-hidden border">
                            <img src="assets/img/website%20images/Visit_Details.jpg" alt="Communication & Details"
                                class="img-fluid rounded-4 shadow-lg">
                        </div>
                    </div>
                </div>

                <!-- Video Section -->
                <div id="video" class="row pt-5 mt-5 reveal justify-content-center">
                    <style>
                        #videoCarousel .carousel-control-prev,
                        #videoCarousel .carousel-control-next {
                            width: 50px;
                            height: 50px;
                            background: rgba(0, 0, 0, 0.5);
                            border-radius: 50%;
                            top: 50%;
                            transform: translateY(-50%);
                            opacity: 0.8;
                            margin: 0 20px;
                        }

                        #videoCarousel .carousel-control-prev:hover,
                        #videoCarousel .carousel-control-next:hover {
                            background: rgba(0, 0, 0, 0.8);
                        }
                    </style>
                    <div class="col-12 text-center mb-5">
                        <span class="hero-badge">See it in Action</span>
                        <h2 class="display-5 fw-800">Feature Highlights</h2>
                        <p class="text-muted fs-5">Take a closer look at the CodePilotx VMS experience.</p>
                    </div>
                    <div class="col-lg-10">
                        <?php
$videoDir = 'assets/img/Videos/';
$videos = [];
if (is_dir($videoDir)) {
    $files = scandir($videoDir);
    foreach ($files as $file) {
        if (in_array(pathinfo($file, PATHINFO_EXTENSION), ['mp4', 'webm', 'ogg'])) {
            $videos[] = $file;
        }
    }
}

if (count($videos) > 0):
?>
                            <div id="videoCarousel" class="carousel slide shadow-2xl rounded-5 overflow-hidden border"
                                data-bs-interval="false">
                                <div class="carousel-inner bg-black">
                                    <?php foreach ($videos as $index => $video): ?>
                                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                            <div class="ratio ratio-16x9">
                                                <video controls class="d-block w-100" preload="metadata">
                                                    <source src="<?php echo $videoDir . $video; ?>"
                                                        type="video/<?php echo pathinfo($video, PATHINFO_EXTENSION); ?>">
                                                    Your browser does not support the video tag.
                                                </video>
                                            </div>
                                        </div>
                                    <?php
    endforeach; ?>
                                </div>
                                <?php if (count($videos) > 1): ?>
                                    <button class="carousel-control-prev" type="button" data-bs-target="#videoCarousel"
                                        data-bs-slide="prev">
                                        <span class="carousel-control-prev-icon" aria-hidden="true"
                                            style="filter: invert(1);"></span>
                                        <span class="visually-hidden">Previous</span>
                                    </button>
                                    <button class="carousel-control-next" type="button" data-bs-target="#videoCarousel"
                                        data-bs-slide="next">
                                        <span class="carousel-control-next-icon" aria-hidden="true"
                                            style="filter: invert(1);"></span>
                                        <span class="visually-hidden">Next</span>
                                    </button>
                                <?php
    endif; ?>
                            </div>
                        <?php
else: ?>
                            <div class="alert alert-light text-center">No videos available at the moment.</div>
                        <?php
endif; ?>
                    </div>
                </div>
            </div>
    </div>

    <!-- Features Grid -->
    <section id="features" class="py-100 bg-light">
        <div class="container">
            <div class="text-center mb-80 reveal">
                <span class="hero-badge">Core Capabilities</span>
                <h2 class="display-5 fw-800">Designed for modern security.</h2>
            </div>
            <div class="row g-4">
                <!-- Row 1 -->
                <div class="col-md-3 reveal">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-blue"><i class="bi bi-qr-code-scan"></i></div>
                        <h4 class="fw-bold mb-3">Instant QR Check-in</h4>
                        <p class="text-muted mb-0">Contactless QR scanning for rapid, hygienic, and professional visitor
                            registration.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.1s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-teal"><i class="bi bi-whatsapp"></i></div>
                        <h4 class="fw-bold mb-3">WhatsApp Integration</h4>
                        <p class="text-muted mb-0">Send digital passes, location maps, and entry notifications directly
                            to visitor's WhatsApp.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.2s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-purple"><i class="bi bi-printer-fill"></i></div>
                        <h4 class="fw-bold mb-3">Badge Printing</h4>
                        <p class="text-muted mb-0">Generate and print professional visitor badges instantly for visible
                            identification.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.3s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-orange"><i class="bi bi-shield-lock"></i></div>
                        <h4 class="fw-bold mb-3">Mobile OTP Verify</h4>
                        <p class="text-muted mb-0">Authenticate visitor identities with instant One-Time Password
                            verification sent to their phone.</p>
                    </div>
                </div>

                <!-- Row 2 -->
                <div class="col-md-3 reveal">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-blue"><i class="bi bi-cpu"></i></div>
                        <h4 class="fw-bold mb-3">AI Intelligence</h4>
                        <p class="text-muted mb-0">Predict peak traffic hours and monitor crowd density with advanced
                            algorithmic insights.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.1s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-teal"><i class="bi bi-bell-fill"></i></div>
                        <h4 class="fw-bold mb-3">Real-time Host Alerts</h4>
                        <p class="text-muted mb-0">Automatically notify employees via Email or SMS the moment their
                            guest arrives.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.2s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-purple"><i class="bi bi-file-earmark-pdf"></i></div>
                        <h4 class="fw-bold mb-3">Audit-Ready Reports</h4>
                        <p class="text-muted mb-0">Download detailed PDF/Excel logs of all visitor activity for
                            compliance and security audits.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.3s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-orange"><i class="bi bi-laptop"></i></div>
                        <h4 class="fw-bold mb-3">Self-Service Kiosk</h4>
                        <p class="text-muted mb-0">Allow visitors to check themselves in using a tablet or touchscreen,
                            reducing front-desk load.</p>
                    </div>
                </div>

                <!-- Row 3 -->
                <div class="col-md-3 reveal">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-blue"><i class="bi bi-clock-history"></i></div>
                        <h4 class="fw-bold mb-3">Overstay Tracking</h4>
                        <p class="text-muted mb-0">Receive automatic alerts when a visitor remains on premises beyond
                            their approved duration.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.1s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-teal"><i class="bi bi-geo-alt-fill"></i></div>
                        <h4 class="fw-bold mb-3">Multi-Location</h4>
                        <p class="text-muted mb-0">Scalable architecture that supports managing multiple gates,
                            buildings, or zones.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.2s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-purple"><i class="bi bi-phone"></i></div>
                        <h4 class="fw-bold mb-3">Digital Passes</h4>
                        <p class="text-muted mb-0">Eco-friendly paperless passes stored directly on the visitor's
                            smartphone.</p>
                    </div>
                </div>
                <div class="col-md-3 reveal" style="transition-delay: 0.3s;">
                    <div class="feature-card h-100">
                        <div class="feature-icon icon-orange"><i class="bi bi-people-fill"></i></div>
                        <h4 class="fw-bold mb-3">Role Management</h4>
                        <p class="text-muted mb-0">Granular access controls for Admins, Security, Hosts, and Front Desk
                            staff.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- How it Works -->
    <section id="how" class="how-section py-100 position-relative">
        <div class="container position-relative z-2">
            <div class="text-center mb-80 reveal">
                <span class="badge rounded-pill bg-primary bg-opacity-10 text-primary mb-3 px-3 py-2 border border-primary border-opacity-10">Simple Process</span>
                <h2 class="display-5 fw-800 mb-4 text-dark">Three Steps to Perfection</h2>
                <p class="text-muted fs-5">Simple for visitors, powerful for administrators.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4 reveal">
                    <div class="step-card p-5 h-100 text-center">
                        <div class="icon-box mb-4 mx-auto">
                            <i class="bi bi-envelope-paper-heart-fill"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-3">1. Digital Invitation</h3>
                        <p class="text-muted mb-0">Hosts send a secure digital link via email. Visitors pre-register to skip
                        the queue.</p>
                    </div>
                </div>
                <div class="col-md-4 reveal" style="transition-delay: 0.1s;">
                    <div class="step-card p-5 h-100 text-center">
                        <div class="icon-box mb-4 mx-auto icon-box-2">
                             <i class="bi bi-qr-code-scan"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-3">2. Arrival Scan</h3>
                        <p class="text-muted mb-0">Visitor scans their QR code at the reception kiosk. Photos and details are
                        logged instantly.</p>
                    </div>
                </div>
                <div class="col-md-4 reveal" style="transition-delay: 0.2s;">
                    <div class="step-card p-5 h-100 text-center">
                        <div class="icon-box mb-4 mx-auto icon-box-3">
                            <i class="bi bi-patch-check-fill"></i>
                        </div>
                        <h3 class="fw-bold text-dark mb-3">3. Host Approval</h3>
                        <p class="text-muted mb-0">Host approves entry with one click. Visitor receives a digital pass to
                        proceed.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Support Section -->
    <!-- Login Portal -->
    <!-- Login Modal (New) -->
    <div class="modal fade" id="loginModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static"
        style="z-index: 10000;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-5 border-0 shadow-lg overflow-hidden">
                <!-- Colorful Header Gradient with Branding -->
                <div class="modal-header border-0 p-0 position-relative"
                    style="height: 140px; background: var(--primary-gradient);">
                    <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3 z-3"
                        data-bs-dismiss="modal" aria-label="Close"></button>
                    <!-- Background Pattern Overlay -->
                    <div class="position-absolute top-0 start-0 w-100 h-100"
                        style="background-image: url('assets/img/pattern_overlay.png'); opacity: 0.1;"></div>
                    
                    <div class="position-absolute bottom-0 start-0 w-100 p-4 text-white">
                        <div class="d-flex align-items-center mb-2">
                            <div class="bg-white rounded-circle p-2 me-3 shadow-sm"
                                style="width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="Logo" class="img-fluid"
                                    style="max-width: 100%; max-height: 100%; object-fit: contain;">
                            </div>
                            <div>
                                <h3 class="fw-800 mb-0 text-shadow"><?php echo htmlspecialchars($company_name); ?></h3>
                                <p class="mb-0 opacity-75 small">Secure Portal Access</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-body p-4 pt-4">
                    <!-- Error Message Place -->
                    <?php if ($error): ?>
                        <div
                            class="alert alert-danger d-flex align-items-center rounded-4 shadow-sm mb-4 animate__animated animate__shakeX">
                            <i class="bi bi-exclamation-octagon-fill me-2 fs-4 text-danger"></i>
                            <div class="small fw-semibold text-danger"><?php echo $error; ?></div>
                        </div>
                    <?php
endif; ?>

                    <form method="POST">
                        <input type="hidden" name="login_action" value="1">

                        <?php
// Fetch all active tenants for the dropdown
$tenants_list = [];
try {
    $tenants_stmt = $master_pdo->query("SELECT tenant_key, db_name FROM tenants WHERE status = 'active' ORDER BY tenant_key");
    $tenants_list = $tenants_stmt->fetchAll(PDO::FETCH_ASSOC);
}
catch (Exception $e) {
// Ignore if tenants table doesn't exist
}
?>

                        <?php if (count($tenants_list) > 1): ?>
                        <div class="mb-4">
                            <label class="form-label fw-bold text-muted small text-uppercase">Organization / Client</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-primary fs-5 ps-3"><i
                                        class="bi bi-building"></i></span>
                                <select class="form-select border-start-0 ps-0" name="tenant_key" 
                                    id="tenantSelector"
                                    style="height: 50px;">
                                    <?php foreach ($tenants_list as $t): ?>
                                        <option value="<?php echo htmlspecialchars($t['tenant_key']); ?>" 
                                            <?php echo($tenant_key === $t['tenant_key']) ? 'selected' : ''; ?>>
                                            <?php echo ucfirst(htmlspecialchars($t['tenant_key'])); ?>
                                        </option>
                                    <?php
    endforeach; ?>
                                </select>
                            </div>
                            <div class="form-text small">
                                <i class="bi bi-info-circle me-1"></i>
                                Select your organization before logging in
                            </div>
                        </div>
                        <?php
else: ?>
                            <input type="hidden" name="tenant_key" value="<?php echo htmlspecialchars($tenant_key); ?>">
                        <?php
endif; ?>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-muted small text-uppercase">Username</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-primary fs-5 ps-3"><i
                                        class="bi bi-person-badge-fill"></i></span>
                                <input type="text" class="form-control border-start-0 ps-0" name="username"
                                    value="<?php echo htmlspecialchars($login_username_value); ?>"
                                    placeholder="Enter your ID" required autofocus style="height: 50px;">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold text-muted small text-uppercase">Password</label>
                            <div class="input-group">
                                <span class="input-group-text bg-white border-end-0 text-primary fs-5 ps-3"><i
                                        class="bi bi-shield-lock-fill"></i></span>
                                <input type="password" class="form-control border-start-0 ps-0" name="password"
                                    placeholder="••••••••" required style="height: 50px;">
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="rememberMeModal">
                                <label class="form-check-label text-muted small" for="rememberMeModal">Remember
                                    me</label>
                            </div>
                            <a href="#" data-bs-target="#forgotModal" data-bs-toggle="modal"
                                class="text-primary text-decoration-none small fw-bold">Forgot Password?</a>
                        </div>

                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-primary-cta btn-cta py-3 rounded-pill shadow-lg">
                                <span class="h6 mb-0 fw-bold">Sign In to Dashboard</span> <i
                                    class="bi bi-arrow-right-circle-fill ms-2"></i>
                            </button>
                        </div>
                    </form>

                    <div class="text-center mt-4 pt-3 border-top border-light">
                        <p class="small text-muted mb-0">Don't have an account? <a href="#footer"
                                onclick="bootstrap.Modal.getInstance(document.getElementById('loginModal')).hide();"
                                class="text-decoration-none fw-bold text-secondary">Contact Admin</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Brands/Trust -->
    <div class="container text-center py-5 reveal">
        <p class="text-muted small fw-bold text-uppercase ls-1 mb-4">Trusted by innovative companies</p>
        <div class="row g-4 justify-content-center opacity-50">
            <div class="col-4 col-md-2 h3 fw-light">Empire</div>
            <div class="col-4 col-md-2 h3 fw-light">SiddhiTech</div>
        </div>
    </div>

    <!-- Footer -->
    <footer id="footer" class="py-5 bg-white border-top">
        <div class="container py-4">
            <div class="row g-5">
                <div class="col-lg-4">
                    <h4 class="fw-bold mb-4 text-gradient"><?php echo htmlspecialchars($company_name); ?></h4>
                    <p class="text-muted mb-4">The ultimate Visitor Management System designed for modern security and seamless experiences. Secure. Intelligent. Fully Digital.</p>
                    <div class="d-flex gap-2">
                        <a href="#" class="btn btn-light btn-sm rounded-circle p-2 shadow-sm"><i class="bi bi-linkedin"></i></a>
                        <a href="#" class="btn btn-light btn-sm rounded-circle p-2 shadow-sm"><i class="bi bi-twitter"></i></a>
                        <a href="#" class="btn btn-light btn-sm rounded-circle p-2 shadow-sm"><i class="bi bi-envelope"></i></a>
                    </div>
                </div>
                <div class="col-lg-3 col-md-4">
                    <h6 class="fw-bold text-dark mb-4 text-uppercase ls-1">Product</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="#features" class="text-muted text-decoration-none small">Features</a></li>
                        <li class="mb-2"><a href="#ai-intelligence" class="text-muted text-decoration-none small">AI Power</a></li>
                        <li class="mb-2"><a href="#hardware" class="text-muted text-decoration-none small">Hardware</a></li>
                        <li class="mb-2"><a href="#how" class="text-muted text-decoration-none small">How it Works</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6 class="fw-bold text-dark mb-4 text-uppercase ls-1">Company</h6>
                    <ul class="list-unstyled">
                        <li class="mb-2"><a href="terms.php" class="text-muted text-decoration-none small">Terms of Service</a></li>
                        <li class="mb-2"><a href="privacy.php" class="text-muted text-decoration-none small">Privacy Policy</a></li>
                    </ul>
                </div>
                <div class="col-lg-3">
                    <h6 class="fw-bold text-dark mb-4 text-uppercase ls-1">Connect</h6>
                    <ul class="list-unstyled mb-3">
                        <li class="mb-2"><a href="https://www.codepilotx.com/pages/contact.html" target="_blank" class="text-muted text-decoration-none small"><i class="bi bi-chat-dots me-2 text-primary"></i>Online Support</a></li>
                        <li class="mb-2"><a href="https://wa.me/919873028739" target="_blank" class="text-muted text-decoration-none small"><i class="bi bi-whatsapp me-2 text-primary"></i>WhatsApp</a></li>
                    </ul>
                    <p class="text-muted small mb-1"><i class="bi bi-geo-alt me-2 text-primary"></i> Dombivli, Maharashtra</p>
                    <p class="text-muted small mb-1"><i class="bi bi-envelope me-2 text-primary"></i> <?php echo htmlspecialchars($company_email); ?></p>
                    <p class="text-muted small mb-1"><i class="bi bi-telephone me-2 text-primary"></i> <?php echo htmlspecialchars($company_phone); ?></p>
                </div>
            </div>
            
            <div class="border-top mt-5 pt-4 text-center">
                <p class="text-muted small mb-0">&copy; <?php echo date('Y'); ?> <span class="fw-bold text-dark">VisitPilot</span>. A CodePilotx Architecture. All Rights Reserved.</p>
            </div>
        </div>
    </footer>

    <!-- Forgot Password Modal (Existing) -->
    <div class="modal fade" id="forgotModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold">Reset Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-2 pb-4">
                    <p class="text-muted small mb-3">Enter your email address to receive reset instructions.</p>
                    <form id="forgotForm">
                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text bg-light border-end-0"><i
                                        class="bi bi-envelope text-muted"></i></span>
                                <input type="email" class="form-control border-start-0 ps-0" required
                                    placeholder="name@company.com">
                            </div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary-cta btn-cta">Send Reset Link</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Link Sent Modal -->
    <div class="modal fade" id="linkSentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-body py-5 text-center">
                    <div class="mb-3 text-success">
                        <i class="bi bi-envelope-check-fill display-1"></i>
                    </div>
                    <h4 class="fw-bold text-success">Link Sent!</h4>
                    <p class="text-muted px-4"> we've sent instructions to reset your password.</p>
                    <button type="button" class="btn btn-outline-success rounded-pill px-4 mt-3"
                        data-bs-dismiss="modal">Okay, got it</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Link Failed Modal -->
    <div class="modal fade" id="linkFailedModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-4 border-0 shadow-lg">
                <div class="modal-body py-5 text-center">
                    <div class="mb-3 text-danger">
                        <i class="bi bi-x-circle-fill display-1"></i>
                    </div>
                    <h4 class="fw-bold text-danger">Account Not Found</h4>
                    <p class="text-muted px-4" id="failMessage">We couldn't find an account associated with that email.
                    </p>
                    <button type="button" class="btn btn-outline-danger rounded-pill px-4 mt-3" data-bs-dismiss="modal"
                        onclick="new bootstrap.Modal(document.getElementById('forgotModal')).show()">Try Again</button>
                </div>
            </div>
        </div>
    </div>



    <!-- Reusing Notification Modal from app_dialogs.php implies it is already in the DOM or included. 
         If not, I should ensure app_dialogs.php is included. 
         Based on the context, I will include it if not present, but for now I'll assume the notificationModal logic needs to be handled in JS.
    -->
    <?php include_once 'includes/app_dialogs.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>


        // Forgot Password Logic
        document.getElementById('forgotForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const emailInput = this.querySelector('input[type="email"]');
            const email = emailInput.value;
            const btn = this.querySelector('button[type="submit"]');
            const originalBtnText = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';

            fetch('api/check_users.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email })
            })
                .then(response => response.json())
                .then(data => {
                    const forgotModalEl = document.getElementById('forgotModal');
                    const forgotModal = bootstrap.Modal.getInstance(forgotModalEl);
                    if (forgotModal) forgotModal.hide();

                    this.reset();
                    btn.disabled = false;
                    btn.innerHTML = originalBtnText;

                    if (data.success) {
                        new bootstrap.Modal(document.getElementById('linkSentModal')).show();
                    } else {
                        document.getElementById('failMessage').innerText = data.message || "Account not found.";
                        new bootstrap.Modal(document.getElementById('linkFailedModal')).show();
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerHTML = originalBtnText;
                    alert('Something went wrong. Please try again.');
                });
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function () {
            const nav = document.getElementById('mainNav');
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });

        // Simple Reveal on Scroll
        function reveal() {
            var reveals = document.querySelectorAll(".reveal");
            for (var i = 0; i < reveals.length; i++) {
                var windowHeight = window.innerHeight;
                var elementTop = reveals[i].getBoundingClientRect().top;
                var elementVisible = 150;
                if (elementTop < windowHeight - elementVisible) {
                    reveals[i].classList.add("active");
                }
            }
        }
        window.addEventListener("scroll", reveal);
        // Initial check
        reveal();

        <?php if (!empty($error)): ?>
            // Auto-open Login Modal if Error
            var loginModal = new bootstrap.Modal(document.getElementById('loginModal'));
            loginModal.show();
        <?php
endif; ?>
    </script>
</body>

</html>