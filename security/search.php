<?php
require_once 'header.php'; ?>
</div><!-- Close the default header container to allow full-width background -->
<?php

// Check if API key is configured
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ai_api_key' LIMIT 1");
$stmt->execute();
$hasApiKey = !empty(trim($stmt->fetchColumn() ?: ''));

$results = [];
$search_term = '';

if (isset($_GET['q'])) {
    $search_term = sanitize($_GET['q']);

    $sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.photo_path, emp.name as host_name, emp.department 
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            JOIN employees emp ON v.employee_id = emp.id 
            WHERE vis.name LIKE ? OR vis.mobile LIKE ? OR v.visit_code LIKE ?
            ORDER BY v.created_at DESC LIMIT 20";

    $search_pattern = "%$search_term%";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$search_pattern, $search_pattern, $search_pattern]);
    $results = $stmt->fetchAll();
}
?>

<div class="search-page-wrapper py-5">
    <div class="container h-100">
        <div class="row justify-content-center h-100 align-items-center">
            <div class="col-lg-11">
                <div class="card glass-card border-0 rounded-5 shadow-2xl overflow-hidden">
                    <div class="card-header bg-transparent border-0 pt-5 pb-4 text-center">
                        <span class="hero-badge bg-primary text-white mb-3">Intelligent Discovery</span>
                        <h1 class="fw-900 text-dark ls-tight display-5 mb-0">Search Records</h1>
                        <p class="text-muted fs-5 mt-2">Find visitors, analyze trends, or ask **VisitPilot AI** for
                            insights.</p>
                    </div>

                    <div class="card-body px-4 px-lg-5 pb-5">
                        <form method="GET" class="mb-5" id="searchForm">
                            <div
                                class="input-wrapper-lg shadow-2xl rounded-pill glass-input-group p-3 d-flex align-items-center flex-wrap flex-md-nowrap gap-2">
                                <div class="d-flex align-items-center flex-grow-1 w-100 ps-2">
                                    <button type="button" id="searchMicBtn" class="btn btn-icon-round mic-btn-xl me-2"
                                        title="Voice Search">
                                        <i class="bi bi-mic-fill"></i>
                                    </button>
                                    <input type="text" name="q" id="searchInput"
                                        class="form-control border-0 bg-transparent px-3 fs-4"
                                        placeholder="Name, Mobile, or Ask AI..."
                                        value="<?php echo htmlspecialchars($search_term); ?>" required autofocus>
                                </div>
                                <div class="d-flex gap-2 w-100 w-md-auto pe-md-2 mt-3 mt-md-0">
                                    <button type="submit"
                                        class="btn btn-primary-premium rounded-pill px-4 py-2 flex-grow-1 flex-md-grow-0">
                                        <i class="bi bi-search me-1"></i> Search
                                    </button>
                                    <?php if ($search_term): ?>
                                        <a href="search.php"
                                            class="btn btn-outline-secondary rounded-pill px-4 py-2 flex-grow-1 flex-md-grow-0 d-flex align-items-center justify-content-center">
                                            <i class="bi bi-x-lg me-1"></i> Clear
                                        </a>
                                        <?php
                                    endif; ?>
                                    <button type="button" id="aiSearchBtn"
                                        class="btn btn-ai-premium rounded-pill px-4 py-2 flex-grow-1 flex-md-grow-0">
                                        <i class="bi bi-robot me-1"></i> ASK AI
                                    </button>
                                </div>
                            </div>
                        </form>

                        <div id="ai-loading" class="text-center py-5 d-none animate__animated animate__fadeIn">
                            <div class="spinner-premium mx-auto mb-3"></div>
                            <h4 class="text-primary fw-bold">VisitPilot AI is deep-diving into your data...</h4>
                            <p class="text-muted">Analyzing visits, matching intents, and calculating results.</p>
                        </div>

                        <?php if ($search_term): ?>
                            <h5 class="mb-3">Search Results (
                                <?php echo count($results); ?>)
                            </h5>

                            <?php if (empty($results)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i> No visitors found matching your search.
                                </div>
                                <?php
                            else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Visitor</th>
                                                <th>Host</th>
                                                <th>Dept</th>
                                                <th>Purpose</th>
                                                <th>Visit Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($results as $visit): ?>
                                                <tr onclick="viewVisitDetails(<?php echo $visit['id']; ?>)"
                                                    style="cursor: pointer;">
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <?php
                                                            $display_photo = $visit['visit_photo'];
                                                            if ($display_photo): ?>
                                                                <img src="../<?php echo $display_photo; ?>" class="rounded-circle me-2"
                                                                    width="40" height="40" style="object-fit:cover">
                                                                <?php
                                                            else: ?>
                                                                <div class="rounded-circle bg-secondary me-2 d-flex align-items-center justify-content-center text-white"
                                                                    style="width:40px;height:40px">
                                                                    <i class="bi bi-person"></i>
                                                                </div>
                                                                <?php
                                                            endif; ?>
                                                            <div>
                                                                <div class="fw-bold">
                                                                    <?php echo htmlspecialchars($visit['visitor_name']); ?>
                                                                </div>
                                                                <div class="small text-muted">
                                                                    <?php echo htmlspecialchars($visit['mobile']); ?>
                                                                </div>
                                                                <div class="small"><span class="badge bg-secondary">
                                                                        <?php echo $visit['visit_code']; ?>
                                                                    </span></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php echo htmlspecialchars($visit['host_name']); ?>
                                                    </td>
                                                    <td>
                                                        <small
                                                            class="text-muted"><?php echo htmlspecialchars($visit['department'] ?? '-'); ?></small>
                                                    </td>
                                                    <td class="small">
                                                        <?php echo htmlspecialchars($visit['purpose']); ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('d M Y, h:i A', strtotime($visit['created_at'])); ?>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $badgeParams = [
                                                            'registered' => 'bg-info',
                                                            'checked_in' => 'bg-success',
                                                            'checked_out' => 'bg-secondary'
                                                        ];
                                                        $badgeClass = $badgeParams[$visit['status']] ?? 'bg-secondary';
                                                        ?>
                                                        <span class="badge <?php echo $badgeClass; ?>">
                                                            <?php echo strtoupper(str_replace('_', ' ', $visit['status'])); ?>
                                                        </span>
                                                    </td>

                                                    <td onclick="event.stopPropagation()">
                                                        <div class="btn-group btn-group-sm">
                                                            <?php if ($visit['approval_status'] !== 'rejected'): ?>
                                                                <a href="pass.php?id=<?php echo $visit['id']; ?>"
                                                                    class="btn btn-outline-primary" title="View Pass"
                                                                    onclick="viewPass(event, <?php echo $visit['id']; ?>, '<?php echo $visit['approval_status']; ?>')">
                                                                    <i class="bi bi-ticket-detailed"></i>
                                                                </a>
                                                                <?php
                                                            endif; ?>
                                                            <?php if ($visit['status'] == 'registered' && $visit['approval_status'] == 'approved'): ?>
                                                                <button class="btn btn-outline-success"
                                                                    onclick="confirmAction(event, 'checkin', <?php echo $visit['id']; ?>)">
                                                                    Check In
                                                                </button>
                                                                <?php
                                                            elseif ($visit['status'] == 'checked_in'): ?>
                                                                <button class="btn btn-outline-danger"
                                                                    onclick="confirmAction(event, 'checkout', <?php echo $visit['id']; ?>)">
                                                                    Check Out
                                                                </button>
                                                                <?php
                                                            endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php
                                            endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php
                            endif; ?>
                            <?php
                        endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="container py-4">
            <?php require_once 'footer.php'; ?>


            <!-- Visit Details Modal -->
            <div class="modal fade" id="visitDetailsModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                        <div class="modal-header bg-primary text-white border-0 py-3">
                            <h5 class="modal-title fw-bold"><i class="bi bi-info-circle me-2"></i> Visit Details</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            <div id="visit-details-content">
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                :root {
                    --primary-gradient: linear-gradient(135deg, #0d6efd 0%, #20c997 100%);
                    --ai-gradient: linear-gradient(135deg, #6610f2 0%, #0d6efd 100%);
                    --glass-bg: rgba(255, 255, 255, 0.85);
                    --glass-border: rgba(255, 255, 255, 0.4);
                }

                .search-page-wrapper {
                    min-height: 85vh;
                    background: url('../assets/img/website%20images/search_bg.png') center/cover no-repeat;
                    position: relative;
                    margin-top: -24px;
                    /* Offset the header container padding */
                }

                .glass-card {
                    background: var(--glass-bg);
                    backdrop-filter: blur(25px);
                    -webkit-backdrop-filter: blur(25px);
                    border: 1px solid var(--glass-border) !important;
                }

                .glass-input-group {
                    background: white;
                    border: 1px solid rgba(0, 0, 0, 0.05);
                }

                .btn-primary-premium {
                    background: var(--primary-gradient);
                    color: white;
                    border: none;
                    font-weight: 700;
                    box-shadow: 0 10px 20px rgba(13, 110, 253, 0.2);
                    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                }

                .btn-ai-premium {
                    background: var(--ai-gradient);
                    color: white;
                    border: none;
                    font-weight: 700;
                    box-shadow: 0 10px 20px rgba(102, 16, 242, 0.2);
                    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                }

                .btn-primary-premium:hover,
                .btn-ai-premium:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
                    color: white;
                }

                .spinner-premium {
                    width: 60px;
                    height: 60px;
                    border-radius: 50%;
                    border: 4px solid rgba(13, 110, 253, 0.1);
                    border-top-color: #0d6efd;
                    animation: spin 1s linear infinite;
                }

                @keyframes spin {
                    to {
                        transform: rotate(360deg);
                    }
                }

                /* Voice / Mic */
                .mic-btn-xl {
                    width: 55px;
                    height: 55px;
                    border-radius: 50%;
                    background: #f8f9fa;
                    color: #6c757d;
                    border: none;
                    transition: all 0.3s;
                }

                .mic-active {
                    background: #dc3545 !important;
                    color: white !important;
                    animation: pulse-red-premium 1.5s infinite;
                }

                @keyframes pulse-red-premium {
                    0% {
                        box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4);
                    }

                    70% {
                        box-shadow: 0 0 0 20px rgba(220, 53, 69, 0);
                    }

                    100% {
                        box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
                    }
                }

                /* Table Styling */
                .table thead th {
                    font-weight: 800;
                    text-transform: uppercase;
                    font-size: 0.8rem;
                    letter-spacing: 1px;
                    color: #6c757d;
                    border-bottom: 2px solid #f8f9fa;
                }

                .table tr {
                    transition: all 0.2s;
                }

                .table tr:hover {
                    background-color: rgba(13, 110, 253, 0.05) !important;
                    transform: scale(1.005);
                }

                .hero-badge {
                    padding: 8px 16px;
                    border-radius: 50px;
                    font-weight: 800;
                    font-size: 0.75rem;
                    text-transform: uppercase;
                    letter-spacing: 1.5px;
                    display: inline-block;
                }

                .timeline {
                    position: relative;
                    padding: 20px 0;
                }

                .timeline::before {
                    content: '';
                    position: absolute;
                    left: 20px;
                    top: 0;
                    bottom: 0;
                    width: 2px;
                    background: #e9ecef;
                }

                .timeline-item {
                    position: relative;
                    margin-bottom: 25px;
                    padding-left: 50px;
                }

                .timeline-marker {
                    position: absolute;
                    left: 12px;
                    top: 0;
                    width: 18px;
                    height: 18px;
                    border-radius: 50%;
                    background: #fff;
                    border: 3px solid #0d6efd;
                    z-index: 1;
                }

                .timeline-item.success .timeline-marker {
                    border-color: #198754;
                }

                .timeline-item.warning .timeline-marker {
                    border-color: #ffc107;
                }

                .timeline-item.danger .timeline-marker {
                    border-color: #dc3545;
                }

                .timeline-content {
                    padding-top: 0;
                }

                .timeline-date {
                    font-size: 0.75rem;
                    color: #6c757d;
                    font-weight: 600;
                }

                .ls-tight {
                    letter-spacing: -1.5px;
                }

                .fw-900 {
                    font-weight: 900;
                }
            </style>

            <script src="../assets/js/datetime-format.js"></script>
            <script>
                function viewPass(event, visitId, status) {
                    event.preventDefault();
                    event.stopPropagation(); // Stop row click
                    if (status === 'pending') {
                        Swal.fire({
                            icon: 'info',
                            title: 'Approval Pending',
                            text: 'This pass cannot be viewed yet because the host has not approved the visit.'
                        });
                    } else {
                        window.location.href = `pass.php?id=${visitId}`;
                    }
                }
                function confirmAction(event, action, visitId) {
                    event.preventDefault();
                    event.stopPropagation(); // Stop row click

                    const actionText = action === 'checkin' ? 'Check In' : 'Check Out';
                    const confirmBtnColor = action === 'checkin' ? '#198754' : '#dc3545';

                    Swal.fire({
                        title: `Confirm ${actionText}`,
                        text: `Are you sure you want to ${actionText.toLowerCase()} this visitor?`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonColor: confirmBtnColor,
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: `Yes, ${actionText}`
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Perform Action
                            fetch(`process_visit.php?action=${action}&id=${visitId}`)
                                .then(response => {
                                    if (response.ok) {
                                        Swal.fire(
                                            'Success!',
                                            `Visitor has been ${actionText.toLowerCase()}ed.`,
                                            'success'
                                        ).then(() => {
                                            location.reload();
                                        });
                                    } else {
                                        Swal.fire('Error', 'Action failed. Please try again.', 'error');
                                    }
                                })
                                .catch(() => {
                                    Swal.fire('Error', 'Network error. Please try again.', 'error');
                                });
                        }
                    });
                }

                async function viewVisitDetails(visitId) {
                    const modal = new bootstrap.Modal(document.getElementById('visitDetailsModal'));
                    const content = document.getElementById('visit-details-content');

                    content.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>`;

                    modal.show();

                    try {
                        const response = await fetch(`../api/visit/details.php?id=${visitId}`);
                        const data = await response.json();

                        if (data.status === 'success') {
                            const v = data.data;
                            let statusBadge = v.status.toUpperCase();
                            let badgeClass = 'bg-secondary';
                            if (v.status === 'approved') badgeClass = 'bg-success';
                            if (v.status === 'pending') badgeClass = 'bg-warning text-dark';
                            if (v.status === 'rejected') badgeClass = 'bg-danger';
                            if (v.status === 'checked_in') badgeClass = 'bg-primary';

                            // Construct Timeline
                            let timelineHtml = `
                    <div class="timeline">
                        <div class="timeline-item success">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <div class="timeline-date">${formatDateTime(v.created_at)}</div>
                                <div class="timeline-title">Visit Registered</div>
                                <small class="text-muted">Visitor: ${v.visitor_name}</small>
                            </div>
                        </div>`;

                            if (v.approved_at) {
                                const decision = v.approval_status === 'approved' ? 'Approved' : 'Rejected';
                                const decisionClass = v.approval_status === 'approved' ? 'success' : 'danger';
                                timelineHtml += `
                        <div class="timeline-item ${decisionClass}">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <div class="timeline-date">${formatDateTime(v.approved_at)}</div>
                                <div class="timeline-title">Host Decision: ${decision}</div>
                                ${v.rejection_reason ? `<small class="text-danger">Reason: ${v.rejection_reason}</small>` : ''}
                            </div>
                        </div>`;
                            }

                            if (v.check_in_time) {
                                timelineHtml += `
                        <div class="timeline-item success">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <div class="timeline-date">${formatDateTime(v.check_in_time)}</div>
                                <div class="timeline-title">Checked In</div>
                                <small class="text-muted">Entered through security gate</small>
                            </div>
                        </div>`;
                            }

                            if (v.check_out_time) {
                                timelineHtml += `
                        <div class="timeline-item secondary">
                            <div class="timeline-marker"></div>
                            <div class="timeline-content">
                                <div class="timeline-date">${formatDateTime(v.check_out_time)}</div>
                                <div class="timeline-title">Checked Out</div>
                                <small class="text-muted">Exit recorded</small>
                            </div>
                        </div>`;
                            }

                            timelineHtml += '</div>';

                            content.innerHTML = `
                    <div class="row">
                        <div class="col-md-4 text-center border-end">
                            <img src="${v.photo_url}" class="img-fluid rounded-4 shadow-sm mb-3" style="width: 150px; height: 150px; object-fit: cover;">
                            <h5 class="fw-bold mb-0">${v.visitor_name}</h5>
                            <p class="text-muted small">${v.mobile}</p>
                            <span class="badge ${badgeClass} rounded-pill px-3 py-2 mb-3">${statusBadge}</span>
                            <div class="mt-2 text-start small bg-light p-2 rounded">
                               <strong>ID Type:</strong> ${v.id_proof_type || 'N/A'}<br>
                               <strong>ID Number:</strong> ${v.id_proof_number || 'N/A'}
                            </div>
                        </div>
                        <div class="col-md-8 px-4">
                            <h6 class="fw-bold text-uppercase small text-muted mb-3 ls-1">Visit Information</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-6">
                                    <label class="small text-muted d-block">Host Name</label>
                                    <span class="fw-bold text-dark">${v.host_name}</span>
                                </div>
                                <div class="col-6">
                                    <label class="small text-muted d-block">Department</label>
                                    <span class="fw-bold text-dark">${v.department}</span>
                                </div>
                                <div class="col-12">
                                    <label class="small text-muted d-block">Purpose of Visit</label>
                                    <span class="fw-bold text-dark">${v.purpose}</span>
                                </div>
                                <div class="col-6">
                                    <label class="small text-muted d-block">Visit Code</label>
                                    <span class="badge bg-light text-dark border fw-bold">#${v.visit_code}</span>
                                </div>
                            </div>
                            
                            <h6 class="fw-bold text-uppercase small text-muted mb-3 ls-1">Timeline of Events</h6>
                            ${timelineHtml}
                        </div>
                    </div>`;
                        } else {
                            content.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
                        }
                    } catch (error) {
                        content.innerHTML = `<div class="alert alert-danger">Failed to load details.</div>`;
                    }
                }

                const hasApiKey = <?php echo json_encode($hasApiKey); ?>;

                document.getElementById('aiSearchBtn').addEventListener('click', async () => {
                    if (!hasApiKey) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'AI API Key not configured.',
                            html: 'To use ASK AI, you need to link your API key in **AI Integration Settings**.<br><br>' +
                                'Don\'t have an API key? <a href="https://aistudio.google.com/app/apikey" target="_blank" class="fw-bold text-primary">Get your Gemini API Key here (Free)</a>',
                            confirmButtonText: 'Got it'
                        });
                        return;
                    }

                    const query = document.getElementById('searchInput').value;
                    if (!query) {
                        Swal.fire('Input Required', 'Please type what you are looking for.', 'info');
                        return;
                    }

                    const loader = document.getElementById('ai-loading');
                    const resultsTable = document.querySelector('tbody');
                    const resultsHeader = document.querySelector('h5');

                    loader.classList.remove('d-none');

                    try {
                        const response = await fetch('../api/ai/process.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ query: query, mode: 'search' })
                        });
                        const result = await response.json();

                        if (result.status === 'success') {
                            const data = result.data;
                            resultsHeader.innerHTML = `AI Search Results (${data.length})`;

                            if (data.length === 0) {
                                resultsTable.innerHTML = `<tr><td colspan="7" class="text-center py-4">No results found by AI. Try a different query.</td></tr>`;
                            } else {
                                let html = '';
                                data.forEach(v => {
                                    const date = new Date(v.created_at).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                                    const badgeClass = {
                                        'registered': 'bg-info',
                                        'checked_in': 'bg-success',
                                        'checked_out': 'bg-secondary'
                                    }[v.status] || 'bg-secondary';

                                    html += `
                            <tr onclick="viewVisitDetails(${v.id})" style="cursor: pointer;">
                                <td>
                                    <div class="d-flex align-items-center">
                                        ${v.visit_photo ? `<img src="../${v.visit_photo}" class="rounded-circle me-2" width="40" height="40" style="object-fit:cover">` : `<div class="rounded-circle bg-secondary me-2 d-flex align-items-center justify-content-center text-white" style="width:40px;height:40px"><i class="bi bi-person"></i></div>`}
                                        <div>
                                            <div class="fw-bold">${v.visitor_name}</div>
                                            <div class="small text-muted">${v.mobile}</div>
                                            <div class="small"><span class="badge bg-secondary">${v.visit_code}</span></div>
                                        </div>
                                    </div>
                                </td>
                                <td>${v.host_name}</td>
                                <td><small class="text-muted">${v.department || '-'}</small></td>
                                <td class="small">${v.purpose}</td>
                                <td>${date}</td>
                                <td><span class="badge ${badgeClass}">${v.status.toUpperCase().replace('_', ' ')}</span></td>
                                <td onclick="event.stopPropagation()">
                                    <div class="btn-group btn-group-sm">
                                        ${v.approval_status !== 'rejected' ? `<a href="pass.php?id=${v.id}" class="btn btn-outline-primary"><i class="bi bi-ticket-detailed"></i></a>` : ''}
                                    </div>
                                </td>
                            </tr>
                        `;
                                });
                                resultsTable.innerHTML = html;
                            }
                        } else {
                            Swal.fire('AI Error', result.message, 'error');
                        }
                    } catch (error) {
                        Swal.fire('Error', 'Connection failed.', 'error');
                    } finally {
                        loader.classList.add('d-none');
                    }
                });

                // Voice Search
                const searchMicBtn = document.getElementById('searchMicBtn');
                const searchInput = document.getElementById('searchInput');
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

                if (SpeechRecognition) {
                    const recognition = new SpeechRecognition();
                    recognition.continuous = true;
                    recognition.interimResults = true;
                    recognition.lang = 'en-IN'; // Optimized for local accent if English is spoken

                    let silenceTimer;
                    let isStartedManually = false;

                    searchMicBtn.onclick = () => {
                        if (searchMicBtn.classList.contains('mic-active')) {
                            isStartedManually = false;
                            recognition.stop();
                        } else {
                            searchInput.value = '';
                            isStartedManually = true;
                            recognition.start();
                        }
                    };

                    recognition.onstart = () => {
                        searchMicBtn.classList.add('mic-active');
                        searchInput.placeholder = "Listening... (Talk freely)";
                    };

                    recognition.onend = () => {
                        // Automatic restart if manually started and not finished by silence
                        if (isStartedManually) {
                            try { recognition.start(); return; } catch (e) { }
                        }
                        searchMicBtn.classList.remove('mic-active');
                        searchInput.placeholder = "Search by Name, Mobile, or Ask AI...";
                        clearTimeout(silenceTimer);
                    };

                    recognition.onresult = (event) => {
                        clearTimeout(silenceTimer);
                        let transcript = '';
                        for (let i = 0; i < event.results.length; i++) {
                            let segment = event.results[i][0].transcript;
                            // Ensure proper spacing between result segments
                            if (i > 0 && !transcript.endsWith(' ') && !segment.startsWith(' ')) {
                                transcript += ' ';
                            }
                            transcript += segment;
                        }
                        searchInput.value = transcript;

                        // Increased silence timer to 4 seconds for better natural sentence pauses
                        silenceTimer = setTimeout(() => {
                            if (transcript.trim()) {
                                isStartedManually = false; // Prevents auto-restart
                                handleVoiceComplete(transcript);
                                recognition.stop();
                            }
                        }, 4000);
                    };

                    function handleVoiceComplete(transcript) {
                        if (!transcript.trim()) return;
                        Swal.fire({
                            title: 'Analyze with AI?',
                            text: `You said: "${transcript}". How should I proceed?`,
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: '<i class="bi bi-robot"></i> ASK AI',
                            cancelButtonText: '<i class="bi bi-search"></i> Normal Search',
                            confirmButtonColor: '#6610f2',
                            cancelButtonColor: '#0d6efd'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                document.getElementById('aiSearchBtn').click();
                            } else if (result.dismiss === Swal.DismissReason.cancel) {
                                document.getElementById('searchForm').submit();
                            }
                        });
                    }
                } else {
                    searchMicBtn.style.display = 'none';
                }
            </script>