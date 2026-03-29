<?php
require_once 'includes/db_api.php';
// Fetch hosts and purposes for the registration form inside the dashboard
try {
    $employees = $pdo->query("SELECT id, name, department FROM employees WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $purposes = $pdo->query("SELECT purpose_name FROM visit_purposes ORDER BY purpose_name")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch Company Settings for Logo
    $comp_rows = $pdo->query("SELECT setting_key, setting_value FROM company_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $company_logo = $comp_rows['logo'] ?? 'assets/img/logo.png';
    $company_name = $comp_rows['name'] ?? 'VMS';
}
catch (Exception $e) {
    $employees = [];
    $purposes = [];
    $company_logo = 'assets/img/logo.png';
    $company_name = 'VMS';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Dashboard - VMS API</title>
    <!-- CSS Dependencies -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <style>
        .navbar {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .live-indicator {
            width: 10px;
            height: 10px;
            background: #0d6efd;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
            animation: pulse-blue 2s infinite;
        }

        @keyframes pulse-blue {
            0% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.7);
            }

            70% {
                box-shadow: 0 0 0 10px rgba(13, 110, 253, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0);
            }
        }

        .nav-link.active {
            font-weight: bold;
            border-bottom: 2px solid white;
        }

        /* Modal and Overlay for API logic */
        #api-login-overlay {
            position: fixed;
            inset: 0;
            background: #f8f9fa;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .screen {
            display: none;
        }

        .screen.active {
            display: block;
        }

        /* Camera UI */
        #camera-modal-view {
            width: 100%;
            aspect-ratio: 4/3;
            background: #000;
            border-radius: 12px;
            overflow: hidden;
        }

        #v-stream,
        #v-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
    </style>
</head>

<body class="bg-light">

    <!-- LOGIN PANEL -->
    <div id="api-login-overlay">
        <div class="card shadow-lg border-0" style="width: 400px; border-radius: 15px;">
            <div class="card-body p-5">
                <div class="text-center mb-4">
                    <i class="bi bi-shield-lock-fill text-primary" style="font-size: 3rem;"></i>
                    <h3 class="fw-bold mt-3">VMS SECURITY</h3>
                    <p class="text-muted">API Login Terminal</p>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Username</label>
                    <input type="text" id="u" class="form-control" value="admin">
                </div>
                <div class="mb-4">
                    <label class="form-label fw-bold">Password</label>
                    <input type="password" id="p" class="form-control" value="admin123">
                </div>
                <button class="btn btn-primary btn-lg w-100 fw-bold" onclick="doAuth()">LOGIN NOW</button>
            </div>
        </div>
    </div>

    <!-- MAIN APP BAR (Matching your PHP Header) -->
    <div id="main-app" style="display:none;">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top p-0 shadow-sm" style="height: 85px; overflow: visible;">
        <div class="container-fluid p-0 h-100">
            <a class="navbar-brand fw-bold p-0 m-0 h-100 d-flex align-items-center" href="#" onclick="showPage('dash')"
                style="padding-left: 125px !important; margin-left: -30px !important; margin-right: 40px !important; position: relative; z-index: 101;">
                <img src="<?php echo BASE_URL . $company_logo; ?>" alt="Logo"
                    style="height: 175%; width: auto; object-fit: contain; position: absolute; left: 0; top: 62%; transform: translateY(-50%); filter: drop-shadow(0 10px 20px rgba(0,0,0,0.3)); z-index: 100;">
                <span style="font-weight: 800; line-height: 1; font-size: 1.6rem; letter-spacing: -1px; background: linear-gradient(45deg, #ffffff, #a5c7fb); -webkit-background-clip: text; -webkit-text-fill-color: transparent; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));"><?php echo htmlspecialchars($company_name); ?></span>
            </a>
            <button class="navbar-toggler me-3" type="button" data-bs-toggle="collapse" data-bs-target="#vmsNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="vmsNav">
                    <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                        <li class="nav-item"><a class="nav-link" id="link-dash" href="#" onclick="showPage('dash')"><i
                                    class="bi bi-speedometer2 me-1"></i> Dashboard</a></li>
                        <li class="nav-item security-only"><a class="nav-link" id="link-reg" href="#" onclick="showPage('reg')"><i
                                    class="bi bi-person-plus me-1"></i> New Visitor</a></li>
                        <li class="nav-item security-only"><a class="nav-link" id="link-scan" href="#" onclick="showPage('scan')"><i
                                    class="bi bi-qr-code-scan me-1"></i> Scan QR</a></li>
                        <li class="nav-item security-only"><a class="nav-link" id="link-search" href="#"
                                onclick="showPage('search')"><i class="bi bi-search me-1"></i> Search</a></li>
                        
                        <!-- Admin Features -->
                        <li class="nav-item admin-only"><a class="nav-link" id="link-employees" href="#" onclick="showPage('employees')"><i
                                    class="bi bi-people me-1"></i> Employees</a></li>
                        <li class="nav-item admin-only"><a class="nav-link" id="link-reports" href="#" onclick="showPage('reports')"><i
                                    class="bi bi-file-earmark-bar-graph me-1"></i> Reports</a></li>
                        <li class="nav-item admin-only"><a class="nav-link" id="link-logs" href="#" onclick="showPage('logs')"><i
                                    class="bi bi-journal-text me-1"></i> System Logs</a></li>
                        <li class="nav-item admin-only"><a class="nav-link" id="link-settings" href="#" onclick="showPage('settings')"><i
                                    class="bi bi-gear me-1"></i> Settings</a></li>
                        
                        <!-- Host Features -->
                        <li class="nav-item host-only"><a class="nav-link" id="link-pending" href="#" onclick="showPage('pending')"><i
                                    class="bi bi-clock-history me-1"></i> Pending</a></li>
                        <li class="nav-item host-only"><a class="nav-link" id="link-history" href="#" onclick="showPage('history')"><i
                                    class="bi bi-calendar-check me-1"></i> My History</a></li>
                    </ul>
                    <div class="d-flex align-items-center">
                        <div class="text-white me-3 d-none d-md-block">
                            <span class="live-indicator"></span><small>Live Terminal (API)</small>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-outline-light dropdown-toggle btn-sm" type="button"
                                data-bs-toggle="dropdown">
                                <i class="bi bi-person-circle"></i> <span id="user-name">Admin</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow">
                                <li><a class="dropdown-item text-danger fw-bold" href="#" onclick="logout()"><i
                                            class="bi bi-box-arrow-right"></i> Logout Now</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <div class="container py-4">

            <!-- DASHBOARD SCREEN -->
            <div id="page-dash" class="screen active">
                <div class="row align-items-center mb-4 g-3">
                    <div class="col-8">
                        <h3 class="mb-0 fw-bold" id="dash-title"><i class="bi bi-shield-lock-fill text-primary"></i> Security Dashboard</h3>
                    </div>
                    <div class="col-4 text-end">
                        <div class="bg-white p-2 px-3 rounded-pill shadow-sm border d-inline-block">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="backgroundToggle" onchange="togglePolling(this)">
                                <label class="form-check-label fw-bold small text-muted" for="backgroundToggle">
                                    <i class="bi bi-cpu me-1"></i> BG Mode
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="stat-card blue">
                            <h3 id="stat-total">0</h3>
                            <p id="lbl-total">Total Visitors Today</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card green">
                            <h3 id="stat-active">0</h3>
                            <p id="lbl-active">Currently Inside</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card orange">
                            <h3 id="stat-pending">0</h3>
                            <p id="lbl-pending">Pending Approvals</p>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="mb-0 fw-bold" id="log-title">Live Visitor Log</h4>
                    <div class="security-only">
                        <button onclick="alert('Viewing Active Visitors Inside')" class="btn btn-sm btn-success rounded-pill px-3"><i class="bi bi-people-fill"></i> Inside</button>
                        <button onclick="showPage('reg')" class="btn btn-sm btn-primary rounded-pill px-3"><i class="bi bi-person-plus"></i> New</button>
                        <button onclick="showPage('scan')" class="btn btn-sm btn-warning rounded-pill px-3"><i class="bi bi-qr-code-scan"></i> Scan</button>
                    </div>
                </div>

                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-4">Code</th>
                                        <th>Visitor</th>
                                        <th>Host</th>
                                        <th>Status</th>
                                        <th class="text-end pe-4">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="visitor-log"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- REGISTRATION SCREEN -->
            <div id="page-reg" class="screen">
                <div class="row justify-content-center">
                    <div class="col-md-8">
                        <div class="card shadow-sm border-0 rounded-4">
                            <div class="card-header bg-primary text-white p-3">
                                <h4 class="mb-0"><i class="bi bi-person-vcard me-2"></i>Visitor Registration</h4>
                            </div>
                            <div class="card-body p-4">
                                <div class="row g-3 mb-4">
                                    <div class="col-md-8">
                                        <input type="text" id="reg-search-mobile" class="form-control"
                                            placeholder="Search by Mobile Number">
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-secondary w-100"
                                            onclick="searchVisitor()">Search</button>
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" id="reg-name" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Mobile Number</label>
                                        <input type="text" id="reg-mobile" class="form-control">
                                    </div>

                                    <div class="col-12">
                                        <label class="form-label">Visitor Photo</label>
                                        <div class="d-flex gap-3">
                                            <div id="camera-modal-view">
                                                <video id="v-stream" autoplay playsinline></video>
                                                <img id="v-preview" style="display:none;">
                                            </div>
                                            <div class="d-flex flex-column justify-content-center gap-2"
                                                style="min-width:120px;">
                                                <button class="btn btn-sm btn-outline-primary"
                                                    onclick="startCamera()">Start Camera</button>
                                                <button class="btn btn-sm btn-success"
                                                    onclick="takeSnapshot()">Capture</button>
                                            </div>
                                        </div>
                                        <input type="hidden" id="reg-photo">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">ID Proof Type</label>
                                        <select id="reg-id-type" class="form-select">
                                            <option>Aadhaar</option>
                                            <option>Driving License</option>
                                            <option>Voter ID</option>
                                            <option>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">ID Proof Number</label>
                                        <input type="text" id="reg-id-num" class="form-control">
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Host (Employee)</label>
                                        <select id="reg-host" class="form-select">
                                            <option value="">Select Host</option>
                                            <?php foreach ($employees as $e): ?>
                                                <option value="<?php echo $e['id']; ?>">
                                                    <?php echo htmlspecialchars($e['name']); ?>
                                                </option>
                                            <?php
endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Purpose</label>
                                        <select id="reg-purpose" class="form-select">
                                            <?php foreach ($purposes as $p): ?>
                                                <option value="<?php echo htmlspecialchars($p['purpose_name']); ?>">
                                                    <?php echo htmlspecialchars($p['purpose_name']); ?>
                                                </option>
                                            <?php
endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mt-4 text-end">
                                    <button class="btn btn-primary btn-lg px-5" onclick="submitRegistration()">Register
                                        & Check In</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SCAN QR SCREEN -->
            <div id="page-scan" class="screen">
                <div class="row justify-content-center">
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 rounded-4 text-center p-4">
                            <h4 class="mb-4">Scan Visitor Pass</h4>
                            <div id="qr-reader" style="width: 100%; border-radius: 15px; overflow: hidden;"></div>
                            <p class="text-muted mt-3">Align the QR code within the frame to process visit.</p>
                            <button class="btn btn-outline-primary mt-2" onclick="initScanner()">Start Scanner</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SEARCH SCREEN -->
            <div id="page-search" class="screen">
                <div class="card shadow-sm border-0 rounded-4 p-4">
                    <h4 class="fw-bold mb-4">Visitor Search</h4>
                    <div class="row g-3">
                        <div class="col-md-9">
                            <input type="text" id="search-query" class="form-control"
                                placeholder="Search by name, mobile or visit code...">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100" onclick="searchVisitorGlobal()">Search
                                Records</button>
                        </div>
                    </div>
                    <div id="search-results" class="mt-4"></div>
                </div>
            </div>

            <!-- PLACEHOLDER SCREENS FOR ADMIN/HOST -->
            <div id="page-employees" class="screen">
                <div class="card shadow-sm border-0 rounded-4 p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4 class="fw-bold mb-0">Employee Management</h4>
                        <button class="btn btn-primary btn-sm" onclick="showAddEmpModal()">
                            <i class="bi bi-person-plus me-1"></i> Add Employee
                        </button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Employee</th>
                                    <th>Department</th>
                                    <th>Contact</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="employee-list-body">
                                <tr><td colspan="4" class="text-center py-4 text-muted">Loading employees...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="page-reports" class="screen">
                <div class="card shadow-sm border-0 rounded-4 p-4">
                    <h4 class="fw-bold mb-4">Reports & Analytics</h4>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label small">From Date</label>
                            <input type="date" id="report-from" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">To Date</label>
                            <input type="date" id="report-to" class="form-control">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button class="btn btn-primary w-100" onclick="generateReport()">
                                <i class="bi bi-filter me-1"></i> Generate Report
                            </button>
                        </div>
                    </div>
                    <div id="report-results" class="mt-4">
                        <div class="alert alert-info">Select date range to view visitor analytics.</div>
                    </div>
                </div>
            </div>

            <div id="page-logs" class="screen">
                <div class="card shadow-sm border-0 rounded-4 p-4">
                    <h4 class="fw-bold mb-4">System Audit Logs</h4>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="audit-log-body">
                                <tr><td colspan="3" class="text-center py-4 text-muted">Loading logs...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div id="page-settings" class="screen">
                <div class="card shadow-sm border-0 rounded-4 p-4">
                    <h4 class="fw-bold mb-4">System Settings</h4>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1 fw-bold">Visitor Approval Workflow</h6>
                                    <p class="text-muted small mb-0">Require hosts to approve all visitors</p>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" checked disabled>
                                </div>
                            </div>
                        </div>
                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1 fw-bold">Auto Check-out</h6>
                                    <p class="text-muted small mb-0">Automatically check out visitors at end of day</p>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" disabled>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="page-pending" class="screen"><div class="alert alert-warning">Host: Pending Approvals - Use Mobile App for best experience</div></div>
            <div id="page-history" class="screen"><div class="alert alert-info">Host: My Visit History - Use Mobile App for best experience</div></div>

        </div>
    </div>

    <!-- Employee Modal -->
    <div class="modal fade" id="empModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold" id="empModalLabel">Add Employee</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="empForm">
                        <input type="hidden" id="emp-id">
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Full Name</label>
                            <input type="text" id="emp-name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Department</label>
                            <input type="text" id="emp-dept" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Email Address</label>
                            <input type="email" id="emp-email" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Mobile Number</label>
                            <input type="text" id="emp-mobile" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2 mt-3">Save Employee</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const API = './';
        let stream = null;
        let qrScanner = null;
        let currentUser = null;

        async function doAuth() {
            const u = document.getElementById('u').value;
            const p = document.getElementById('p').value;
            try {
                const res = await fetch(API + 'auth/login.php', { method: 'POST', body: JSON.stringify({ username: u, password: p }) });
                const data = await res.json();
                if (data.status === 'success') {
                    localStorage.setItem('vms_auth', JSON.stringify(data.data));
                    setupApp(data.data);
                } else alert(data.message);
            } catch (e) { alert('API Error'); }
        }

        async function loadStats() {
            let url = API + 'dashboard/stats.php';
            if (currentUser && currentUser.role === 'host' && currentUser.employee_id) {
                url += '?employee_id=' + currentUser.employee_id;
            }
            const res = await fetch(url);
            const data = await res.json();
            if (data.status === 'success') {
                document.getElementById('stat-total').innerText = data.data.today_visitors;
                document.getElementById('stat-active').innerText = data.data.inside_now;
                document.getElementById('stat-pending').innerText = data.data.pending_approvals;
            }
        }

        async function loadLog() {
            const tbody = document.getElementById('visitor-log');
            let url = API + 'visit/log.php';
            if (currentUser && currentUser.role === 'host' && currentUser.employee_id) {
                url += '?employee_id=' + currentUser.employee_id;
            }
            const res = await fetch(url);
            const data = await res.json();
            if (data.status === 'success') {
                tbody.innerHTML = data.data.map(v => {
                    let actions = '';
                    if (currentUser && currentUser.role === 'host') {
                        if (v.approval_status === 'pending') {
                            actions = `
                                <button class="btn btn-success" onclick="visitAction(${v.visit_id}, 'approve')">Approve</button>
                                <button class="btn btn-danger" onclick="visitAction(${v.visit_id}, 'reject')">Reject</button>
                            `;
                        } else {
                            actions = `<span class="badge bg-secondary">${v.approval_status.toUpperCase()}</span>`;
                        }
                    } else {
                        // Security Actions
                        actions = `
                            <button class="btn btn-outline-primary" onclick="alert('Viewing Pass: ' + ${v.visit_id})"><i class="bi bi-ticket-detailed"></i></button>
                            ${v.visit_status === 'registered' ? `<button class="btn btn-success" onclick="visitAction(${v.visit_id}, 'checkin')">Check In</button>` : ''}
                            ${v.visit_status === 'checked_in' ? `<button class="btn btn-danger" onclick="visitAction(${v.visit_id}, 'checkout')">Check Out</button>` : ''}
                        `;
                    }
                    
                    return `
                    <tr>
                        <td class="ps-4"><strong>${v.visit_code}</strong></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <img src="${v.photo_url}" class="rounded-circle me-2 shadow-sm" width="40" height="40" style="object-fit:cover">
                                <div>
                                    <div class="fw-bold small">${v.visitor_name}</div>
                                    <div class="text-muted" style="font-size:0.7rem">${v.mobile}</div>
                                </div>
                            </div>
                        </td>
                        <td class="small">${v.host_name}</td>
                        <td>
                            <span class="badge rounded-pill ${getStatusBadge(v.visit_status)}" style="font-size:0.65rem">
                                ${v.visit_status.toUpperCase().replace('_', ' ')}
                            </span>
                        </td>
                        <td class="text-end pe-4">
                            <div class="btn-group btn-group-sm">
                                ${actions}
                            </div>
                        </td>
                    </tr>
                `}).join('') || '<tr><td colspan="5" class="text-center py-5 text-muted">No visitors today</td></tr>';
            }
        }

        async function visitAction(id, act) {
            const res = await fetch(API + 'visit/status_action.php', { method: 'POST', body: JSON.stringify({ visit_id: id, action: act }) });
            const data = await res.json();
            if (data.status === 'success') loadLog();
            else alert(data.message);
        }

        function getStatusBadge(s) {
            return { 'registered': 'bg-info', 'checked_in': 'bg-success', 'checked_out': 'bg-secondary', 'pending': 'bg-warning text-dark' }[s] || 'bg-secondary';
        }

        async function searchVisitorGlobal() {
            const q = document.getElementById('search-query').value;
            if (!q) return;
            const res = await fetch(API + 'visitor/search_global.php?q=' + encodeURIComponent(q));
            const data = await res.json();
            const resultsDiv = document.getElementById('search-results');
            if (data.status === 'success') {
                if (data.data.length === 0) {
                    resultsDiv.innerHTML = '<div class="alert alert-warning">No records found matching your search.</div>';
                    return;
                }
                resultsDiv.innerHTML = `
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Visitor</th>
                                    <th>Host</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.data.map(v => `
                                    <tr>
                                        <td>
                                            <div class="fw-bold">${v.visitor_name}</div>
                                            <div class="small text-muted">${v.mobile}</div>
                                        </td>
                                        <td>${v.host_name}</td>
                                        <td><span class="badge rounded-pill ${getStatusBadge(v.visit_status)}">${v.visit_status}</span></td>
                                        <td class="small text-muted">${new Date(v.created_at).toLocaleDateString()}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            } else {
                resultsDiv.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
            }
        }

        async function loadEmployees() {
            try {
                const res = await fetch(API + 'employee/list.php');
                const data = await res.json();
                const body = document.getElementById('employee-list-body');
                if (data.status === 'success') {
                    const emps = data.data.employees || [];
                    window.currentEmployees = emps;
                    body.innerHTML = emps.map(e => `
                        <tr>
                            <td><div class="fw-bold">${e.name}</div></td>
                            <td>${e.department}</td>
                            <td>
                                <div class="small"><i class="bi bi-envelope me-1"></i>${e.email || 'N/A'}</div>
                                <div class="small"><i class="bi bi-phone me-1"></i>${e.mobile || 'N/A'}</div>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-primary me-1" onclick="editEmployee(${e.id})"><i class="bi bi-pencil"></i></button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteEmployee(${e.id})"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    `).join('');
                } else {
                    body.innerHTML = `<tr><td colspan="4" class="text-center py-4 text-danger">${data.message}</td></tr>`;
                }
            } catch (e) {
                document.getElementById('employee-list-body').innerHTML = `<tr><td colspan="4" class="text-center py-4 text-danger">Failed to load employees</td></tr>`;
            }
        }

        function showAddEmpModal() {
            document.getElementById('emp-id').value = '';
            document.getElementById('emp-name').value = '';
            document.getElementById('emp-dept').value = '';
            document.getElementById('emp-email').value = '';
            document.getElementById('emp-mobile').value = '';
            document.getElementById('empModalLabel').innerText = 'Add Employee';
            new bootstrap.Modal(document.getElementById('empModal')).show();
        }

        function editEmployee(id) {
            const emp = window.currentEmployees.find(e => e.id == id);
            if (!emp) return;
            document.getElementById('emp-id').value = emp.id;
            document.getElementById('emp-name').value = emp.name;
            document.getElementById('emp-dept').value = emp.department;
            document.getElementById('emp-email').value = emp.email;
            document.getElementById('emp-mobile').value = emp.mobile;
            document.getElementById('empModalLabel').innerText = 'Edit Employee';
            new bootstrap.Modal(document.getElementById('empModal')).show();
        }

        async function deleteEmployee(id) {
            if (!confirm('Are you sure you want to deactivate this employee?')) return;
            const formData = new FormData();
            formData.append('id', id);
            const res = await fetch(API + 'employee/delete.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                loadEmployees();
            } else {
                alert(data.message);
            }
        }

        document.getElementById('empForm').onsubmit = async (e) => {
            e.preventDefault();
            const formData = new FormData();
            formData.append('id', document.getElementById('emp-id').value);
            formData.append('name', document.getElementById('emp-name').value);
            formData.append('department', document.getElementById('emp-dept').value);
            formData.append('email', document.getElementById('emp-email').value);
            formData.append('mobile', document.getElementById('emp-mobile').value);

            const res = await fetch(API + 'employee/save.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                bootstrap.Modal.getInstance(document.getElementById('empModal')).hide();
                loadEmployees();
            } else {
                alert(data.message);
            }
        };

        async function generateReport() {
            const from = document.getElementById('report-from').value;
            const to = document.getElementById('report-to').value;
            if (!from || !to) {
                alert('Please select both dates');
                return;
            }

            const resultsDiv = document.getElementById('report-results');
            resultsDiv.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div></div>';

            try {
                const res = await fetch(`${API}dashboard/reports.php?from=${from}&to=${to}`);
                const data = await res.json();
                if (data.status === 'success') {
                    if (data.data.length === 0) {
                        resultsDiv.innerHTML = '<div class="alert alert-warning">No records found for this period.</div>';
                        return;
                    }
                    resultsDiv.innerHTML = `
                        <div class="table-responsive">
                            <table class="table table-hover table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Visitor</th>
                                        <th>Host</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.data.map(v => `
                                        <tr>
                                            <td class="small">${new Date(v.created_at).toLocaleDateString()}</td>
                                            <td class="fw-bold">${v.visitor_name}</td>
                                            <td>${v.host_name}</td>
                                            <td class="small">${v.purpose}</td>
                                            <td><span class="badge ${getStatusBadge(v.visit_status)}">${v.visit_status}</span></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;
                } else {
                    resultsDiv.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                }
            } catch (e) {
                resultsDiv.innerHTML = '<div class="alert alert-danger">Failed to generate report</div>';
            }
        }

        async function loadAuditLogs() {
            // Since we don't have a dedicated API for audit logs yet, we'll show a friendly message
            const body = document.getElementById('audit-log-body');
            body.innerHTML = `<tr><td colspan="3" class="text-center py-5 text-muted">
                <i class="bi bi-shield-check display-4 mb-3 d-block"></i>
                System is secure. Audit logging is active in the background.
            </td></tr>`;
        }

        function showPage(id) {
            // Get current role from multiple sources for reliability
            let role = '';
            
            // Priority 1: currentUser global variable
            if (currentUser && currentUser.role) {
                role = currentUser.role;
            } 
            
            // Priority 2: localStorage fallback
            if (!role) {
                try {
                    const auth = JSON.parse(localStorage.getItem('vms_auth') || '{}');
                    role = auth.role || '';
                } catch (e) {
                    console.error("Error parsing auth from localStorage", e);
                }
            }

            // Normalize role
            role = String(role || '').toLowerCase().trim();

            // Role based access control
            const adminPages = ['employees', 'reports', 'logs', 'settings'];
            const securityPages = ['reg', 'scan', 'search'];
            const hostPages = ['pending', 'history'];
            
            // 1. Admin-only pages
            if (adminPages.includes(id)) {
                if (role !== 'admin') {
                    alert('Access Denied: Only administrators can access this page. Current role: ' + (role || 'none'));
                    return;
                }
            }

            // 2. Security & Admin pages
            if (securityPages.includes(id)) {
                if (role !== 'admin' && role !== 'security') {
                    alert('Access Denied: Only admin or security personnel can access this page. Current role: ' + (role || 'none'));
                    return;
                }
            }

            // 3. Host & Admin pages
            if (hostPages.includes(id)) {
                if (role !== 'admin' && role !== 'host') {
                    alert('Access Denied: Only hosts or admins can access this page. Current role: ' + (role || 'none'));
                    return;
                }
            }

            // If we reached here, access is granted
            document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
            
            const targetPage = document.getElementById('page-' + id);
            const targetLink = document.getElementById('link-' + id);
            
            if (targetPage) targetPage.classList.add('active');
            if (targetLink) targetLink.classList.add('active');
            
            // Load content based on page
            if (id === 'dash') { loadStats(); loadLog(); }
            if (id === 'employees') loadEmployees();
            if (id === 'logs') loadAuditLogs();
            
            // Cleanup resources
            if (qrScanner) { try { qrScanner.stop(); } catch (e) { } }
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
        }

        // CAMERA
        async function startCamera() {
            stream = await navigator.mediaDevices.getUserMedia({ video: true });
            const v = document.getElementById('v-stream');
            v.srcObject = stream; v.style.display = 'block';
            document.getElementById('v-preview').style.display = 'none';
        }
        function takeSnapshot() {
            const v = document.getElementById('v-stream');
            const c = document.createElement('canvas');
            c.width = v.videoWidth; c.height = v.videoHeight;
            c.getContext('2d').drawImage(v, 0, 0);
            const data = c.toDataURL('image/jpeg');
            document.getElementById('reg-photo').value = data;
            document.getElementById('v-preview').src = data;
            document.getElementById('v-preview').style.display = 'block';
            v.style.display = 'none';
        }

        async function submitRegistration() {
            const btn = document.querySelector('button[onclick="submitRegistration()"]');
            const orgText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

            const payload = {
                name: document.getElementById('reg-name').value,
                mobile: document.getElementById('reg-mobile').value,
                id_proof_number: document.getElementById('reg-id-num').value,
                id_proof_type: document.getElementById('reg-id-type').value,
                employee_id: document.getElementById('reg-host').value,
                purpose: document.getElementById('reg-purpose').value,
                photo_data: document.getElementById('reg-photo').value
            };
            try {
                const r = await fetch(API + 'visitor/register.php', { method: 'POST', body: JSON.stringify(payload) });
                const d = await r.json();
                if (d.status === 'success') {
                    Swal.fire({
                        title: '<h2 class="fw-bold text-success mb-0">Check-in Success!</h2>',
                        html: `
                            <div class="text-center p-3">
                                <i class="bi bi-check-circle-fill text-success display-1 mb-4 d-block"></i>
                                <h4 class="fw-bold mb-1">${payload.name}</h4>
                                <p class="text-muted mb-4">${payload.mobile}</p>
                                <div class="bg-light p-3 rounded-4 border">
                                    <div class="row text-start g-3">
                                        <div class="col-6">
                                            <small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.6rem">Purpose</small>
                                            <span class="fw-bold">${payload.purpose}</span>
                                        </div>
                                        <div class="col-6 text-end">
                                            <small class="text-muted d-block text-uppercase fw-bold" style="font-size:0.6rem">Visit Code</small>
                                            <span class="text-primary fw-bold">#${d.data.visit_code}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'Great!',
                        confirmButtonColor: '#0d6efd',
                        customClass: { popup: 'rounded-5 border-0 shadow-lg' }
                    }).then(() => {
                        showPage('dash');
                    });
                } else {
                    Swal.fire('Registration Failed', d.message, 'error');
                }
            } catch (e) {
                Swal.fire('API Error', 'Could not connect to registration service', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orgText;
            }
        }

        // SCANNER
        function initScanner() {
            qrScanner = new Html5Qrcode("qr-reader");
            qrScanner.start({ facingMode: "environment" }, { fps: 10, qrbox: 250 }, async (txt) => {
                const res = await fetch(API + 'visit/status_action.php', { method: 'POST', body: JSON.stringify({ action: 'qr_process', code: txt }) });
                const d = await res.json();
                
                if (d.status === 'success') {
                    // Stop scanner temporarily to show success
                    if (qrScanner) qrScanner.pause();
                    
                    Swal.fire({
                        title: `<h3 class="fw-bold text-success mb-2">${d.message}</h3>`,
                        html: `
                            <div class="text-center p-2">
                                <i class="bi bi-patch-check-fill text-success display-1 mb-3 d-block"></i>
                                <div class="bg-light p-3 rounded-4 border">
                                    <p class="mb-0 fw-bold text-dark">Visit Code: <span class="text-primary">#${txt}</span></p>
                                    <p class="text-muted small mb-0">Processed at ${new Date().toLocaleTimeString()}</p>
                                </div>
                            </div>
                        `,
                        confirmButtonText: 'Continue',
                        confirmButtonColor: '#198754',
                        customClass: { popup: 'rounded-5 border-0 shadow-lg' }
                    }).then(() => {
                        showPage('dash');
                        if (qrScanner) qrScanner.resume();
                    });
                } else {
                    Swal.fire({
                        title: 'Scan Error',
                        text: d.message,
                        icon: 'error',
                        confirmButtonText: 'Try Again'
                    });
                }
            });
        }

        async function searchVisitor() {
            const m = document.getElementById('reg-search-mobile').value;
            const r = await fetch(API + 'visitor/search.php?mobile=' + m);
            const d = await r.json();
            if (d.status === 'success') {
                const v = d.data;
                document.getElementById('reg-name').value = v.name;
                document.getElementById('reg-mobile').value = v.mobile;
                document.getElementById('reg-id-num').value = v.id_proof_number;
                document.getElementById('reg-id-type').value = v.id_proof_type;
                if (v.photo_url) {
                    document.getElementById('v-preview').src = v.photo_url;
                    document.getElementById('v-preview').style.display = 'block';
                    document.getElementById('v-stream').style.display = 'none';
                }
            }
        }

        let pollInterval = null;
        function togglePolling(checkbox) {
            if (checkbox.checked) {
                console.log("BG Mode Active: Syncing every 5s");
                pollInterval = setInterval(() => {
                    const dash = document.getElementById('page-dash');
                    if (dash && dash.classList.contains('active')) {
                        loadStats();
                        loadLog();
                    }
                }, 5000);
            } else {
                if (pollInterval) clearInterval(pollInterval);
                console.log("BG Mode Off");
            }
        }

        function setupApp(user) {
            // Normalize role immediately
            if (user && user.role) {
                user.role = user.role.toLowerCase().trim();
            }
            currentUser = user;
            
            document.getElementById('api-login-overlay').style.display = 'none';
            document.getElementById('main-app').style.display = 'block';
            
            // Fix "Welcome Null" - ensure we use the correct field from API response
            const displayName = user.full_name || user.username || 'User';
            document.getElementById('user-name').innerText = displayName;
            
            const welcomeText = document.querySelector('#page-dash h3');
            if (welcomeText) {
                welcomeText.innerHTML = `<i class="bi bi-person-circle text-primary"></i> Welcome, ${displayName}`;
            }

            // Role based UI visibility
            document.querySelectorAll('.admin-only').forEach(el => el.style.display = user.role === 'admin' ? '' : 'none');
            document.querySelectorAll('.host-only').forEach(el => el.style.display = user.role === 'host' ? '' : 'none');
            document.querySelectorAll('.security-only').forEach(el => el.style.display = (user.role === 'security' || user.role === 'admin') ? '' : 'none');

            if (user.role === 'admin') {
                const navbar = document.querySelector('.navbar');
                navbar.classList.remove('bg-primary');
                navbar.classList.add('bg-dark');
                document.querySelector('.navbar-brand').innerHTML = '<i class="bi bi-shield-shaded me-2"></i>VMS ADMIN';
                
                if (document.getElementById('dash-title')) document.getElementById('dash-title').innerHTML = '<i class="bi bi-speedometer2 text-primary"></i> Admin Dashboard';
                
                // Update Stats Labels for Admin
                const labels = document.querySelectorAll('.stat-card p');
                if (labels.length >= 3) {
                    labels[0].innerText = 'Total Employees';
                    labels[1].innerText = 'Total Visits';
                    labels[2].innerText = 'Today\'s Visits';
                }

                // Update Stats Values for Admin
                fetch(`dashboard/stats.php?role=admin`)
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') {
                            const values = document.querySelectorAll('.stat-card h3');
                            if (values.length >= 3) {
                                values[0].innerText = res.data.total_employees;
                                values[1].innerText = res.data.total_visits;
                                values[2].innerText = res.data.today_visitors;
                            }
                        }
                    });

                if (document.getElementById('log-title')) document.getElementById('log-title').innerText = 'System Visitor Log';
            } else if (user.role === 'host') {
                document.querySelector('.navbar-brand').innerHTML = '<i class="bi bi-building-check me-2"></i>VMS HOST';
                if (document.getElementById('dash-title')) document.getElementById('dash-title').innerHTML = '<i class="bi bi-building-check text-primary"></i> Host Dashboard';
                if (document.getElementById('log-title')) document.getElementById('log-title').innerText = 'My Visits & Approvals';

                // Update Stat Labels
                document.getElementById('lbl-total').innerHTML = '<i class="bi bi-people-fill me-2"></i>My Visitors';
                document.getElementById('lbl-active').innerHTML = '<i class="bi bi-person-check-fill me-2"></i>My Guests Inside';
                document.getElementById('lbl-pending').innerHTML = '<i class="bi bi-hourglass-split me-2"></i>My Approvals';
            } else {
                document.querySelector('.navbar-brand').innerHTML = '<i class="bi bi-shield-lock me-2"></i>VMS SECURITY';
                if (document.getElementById('dash-title')) document.getElementById('dash-title').innerHTML = '<i class="bi bi-shield-lock-fill text-primary"></i> Security Dashboard';
                if (document.getElementById('log-title')) document.getElementById('log-title').innerText = 'Live Visitor Log';

                // Reset Stat Labels
                document.getElementById('lbl-total').innerHTML = '<i class="bi bi-people-fill me-2"></i>Today\'s Visitors';
                document.getElementById('lbl-active').innerHTML = '<i class="bi bi-person-check-fill me-2"></i>Inside Now';
                document.getElementById('lbl-pending').innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Pending Approvals';
            }

            showPage('dash');
        }

        function logout() {
            localStorage.removeItem('vms_auth');
            location.reload();
        }

        function init() {
            const auth = localStorage.getItem('vms_auth');
            if (auth) {
                setupApp(JSON.parse(auth));
            }
        }

        window.onload = init;
    </script>
</body>

</html>