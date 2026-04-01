<!-- Visit Details Modal -->
<!-- Included via PHP in Dashboard/Reports pages -->
<div class="modal fade" id="visitDetailsModal" tabindex="-1" aria-hidden="true" style="z-index: 10000;">
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

    .timeline-title {
        font-weight: 700;
        margin-bottom: 2px;
    }
</style>

<script>
    if (typeof BASE_URL === 'undefined') {
        var BASE_URL = '<?php echo BASE_URL; ?>';
    }

    // Format date as DD/MM/YYYY, HH:MM:SS AM/PM
    function formatDateTime(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);

        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();

        let hours = date.getHours();
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        const ampm = hours >= 12 ? 'PM' : 'AM';

        hours = hours % 12;
        hours = hours ? hours : 12; // 0 should be 12
        hours = String(hours).padStart(2, '0');

        return `${day}/${month}/${year}, ${hours}:${minutes}:${seconds} ${ampm}`;
    }

    async function viewVisitDetails(visitId) {
        if (!visitId) return;

        // Hide other modals if open (e.g. summary list, notifications) to prevent overlapping issues
        const validModals = ['detailsModal', 'detailsListModal', 'notificationModal', 'deleteConfirmModal', 'inputModal'];
        validModals.forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                const instance = bootstrap.Modal.getInstance(el);
                if (instance) instance.hide();
            }
        });

        const modalEl = document.getElementById('visitDetailsModal');
        if (!modalEl) {
            console.error("Visit Details Modal element not found!");
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const content = document.getElementById('visit-details-content');

        content.innerHTML = `
        <div class="text-center py-5">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>`;

        // Robust backdrop cleanup before showing
        document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
        document.body.classList.remove('modal-open');
        document.body.style.paddingRight = '';

        modal.show();

        try {
            // Use BASE_URL if available for absolute mapping, otherwise fallback to relative
            const apiBase = (typeof BASE_URL !== 'undefined') ? BASE_URL : '../';
            const response = await fetch(`${apiBase}api/visit/details.php?id=${visitId}`);
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
                    let decision = 'Decision Made';
                    let decisionClass = 'secondary';

                    if (v.approval_status === 'approved') {
                        decision = 'Approved';
                        decisionClass = 'success';
                    } else if (v.approval_status === 'canceled' || v.approval_status === 'cancelled' || v.approval_status === 'rejected') {
                        decision = 'Rejected';
                        decisionClass = 'danger';
                    }

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

                // Construct Action Bar for Security
                let actionBarHtml = '';
                const isSecurityPath = window.location.pathname.includes('/security/');

                if (isSecurityPath) {
                    const canCheckIn = v.status === 'approved' && v.approval_status === 'approved';
                    const canCheckOut = v.status === 'checked_in';
                    const canPrintPass = v.approval_status !== 'rejected';

                    actionBarHtml = `
                    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-primary bg-opacity-10">
                        <div class="card-body p-3 d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold text-primary"><i class="bi bi-lightning-charge-fill me-2"></i>Quick Actions</h6>
                            <div class="btn-group shadow-sm">
                                ${canPrintPass ? `<a href="javascript:void(0)" onclick="viewPass(${v.id}, '${v.approval_status}')" class="btn btn-outline-primary px-3 fw-bold bg-white"><i class="bi bi-printer me-2"></i>Pass</a>` : ''}
                                ${canCheckIn ? `<a href="process_visit.php?action=checkin&id=${v.id}" class="btn btn-success px-4 fw-bold"><i class="bi bi-door-open me-2"></i>Check In</a>` : ''}
                                ${canCheckOut ? `<a href="process_visit.php?action=checkout&id=${v.id}" class="btn btn-danger px-4 fw-bold"><i class="bi bi-door-closed me-2"></i>Check Out</a>` : ''}
                            </div>
                        </div>
                    </div>`;
                }

                content.innerHTML = `
                <div class="row g-0">
                    <!-- Left Panel - Visitor Info -->
                    <div class="col-md-4">
                        <div class="text-center p-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <div class="position-relative d-inline-block mb-3">
                                <img src="${v.photo_url}" class="rounded-circle shadow-lg border border-4 border-white" 
                                     style="width: 140px; height: 140px; object-fit: cover;"
                                     onerror="this.src='../assets/img/visitor-icon.png'">
                                <span class="position-absolute bottom-0 end-0 badge ${badgeClass} rounded-pill px-2 py-1 shadow" 
                                      style="font-size: 0.65rem;">${statusBadge}</span>
                            </div>
                            <h5 class="fw-bold mb-1 text-white">${v.visitor_name}</h5>
                            <p class="text-white-50 mb-3"><i class="bi bi-telephone-fill me-1"></i>${v.mobile}</p>
                            <div class="bg-white bg-opacity-10 backdrop-blur rounded-3 p-3 text-start text-white mb-3">
                                <div class="d-flex align-items-center mb-2">
                                    <i class="bi bi-credit-card-2-front fs-5 me-2 text-warning"></i>
                                    <div class="flex-grow-1">
                                        <small class="d-block opacity-75" style="font-size: 0.7rem;">ID Type</small>
                                        <strong style="font-size: 0.85rem;">${v.id_proof_type || 'N/A'}</strong>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-hash fs-5 me-2 text-warning"></i>
                                    <div class="flex-grow-1">
                                        <small class="d-block opacity-75" style="font-size: 0.7rem;">ID Number</small>
                                        <strong style="font-size: 0.85rem;">${v.id_proof_number || 'N/A'}</strong>
                                    </div>
                                </div>
                            </div>

                            <!-- QR Code at Left Bottom -->
                            <div class="mt-4 p-3 bg-white rounded-4 shadow-sm d-inline-block">
                                <img src="${v.qr_url}" class="img-fluid" style="width: 100px; height: 100px;" 
                                     onerror="this.src='../assets/img/qr-placeholder.png'">
                                <div class="mt-2 fw-bold text-dark small" style="letter-spacing: 1px;">#${v.visit_code}</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Panel - Visit Details -->
                    <div class="col-md-8 p-4" style="background: #f8f9fa;">
                        ${actionBarHtml}
                        
                        <!-- Visit Information Card -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-body p-4">
                                <h6 class="fw-bold text-uppercase small text-primary mb-3 d-flex align-items-center">
                                    <i class="bi bi-info-circle-fill me-2"></i>Visit Information
                                </h6>
                                <div class="row g-3">
                                    <div class="col-6">
                                        <div class="d-flex align-items-start">
                                            <i class="bi bi-person-badge text-primary fs-5 me-2 mt-1"></i>
                                            <div>
                                                <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Host Name</small>
                                                <span class="fw-bold text-dark">${v.host_name}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="d-flex align-items-start">
                                            <i class="bi bi-building text-success fs-5 me-2 mt-1"></i>
                                            <div>
                                                <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Department</small>
                                                <span class="fw-bold text-dark">${v.department}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12">
                                        <div class="d-flex align-items-start">
                                            <i class="bi bi-chat-left-text text-info fs-5 me-2 mt-1"></i>
                                            <div class="flex-grow-1">
                                                <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Purpose of Visit</small>
                                                <span class="fw-bold text-dark">${v.purpose}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="d-flex align-items-start">
                                            <i class="bi bi-qr-code text-warning fs-5 me-2 mt-1"></i>
                                            <div>
                                                <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Visit Code</small>
                                                <span class="badge bg-gradient bg-primary text-white fw-bold px-3 py-2">#${v.visit_code}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="d-flex align-items-start">
                                            <i class="bi bi-geo-alt text-danger fs-5 me-2 mt-1"></i>
                                            <div>
                                                <small class="text-muted d-block mb-1" style="font-size: 0.7rem;">Acces Area</small>
                                                <span class="fw-bold ${v.access_area ? 'text-danger' : 'text-muted'}">${v.access_area || 'Not Assigned'}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12 mt-3 p-3 bg-light rounded-3 d-flex align-items-start border border-dashed">
                                        <i class="bi bi-laptop text-dark fs-5 me-3 mt-1"></i>
                                        <div class="flex-grow-1">
                                            <small class="text-muted d-block mb-1 fw-bold text-uppercase" style="font-size: 0.65rem;">Assets Carried</small>
                                            <span class="fw-bold text-dark" style="font-size: 0.9rem;">${v.assets_carried || 'None recorded'}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Timeline Card -->
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-4">
                                <h6 class="fw-bold text-uppercase small text-primary mb-3 d-flex align-items-center">
                                    <i class="bi bi-clock-history me-2"></i>Timeline of Events
                                </h6>
                                ${timelineHtml}
                            </div>
                        </div>
                    </div>
                </div>`;
            } else {
                content.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
            }
        } catch (error) {
            console.error(error);
            content.innerHTML = `<div class="alert alert-danger">Failed to load details.</div>`;
        }
    }
    function viewPass(visitId, approvalStatus) {
        // Use BASE_URL if available for absolute mapping, otherwise local relative path
        let passUrl = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'security/pass.php?id=' + visitId : '';
        if (!passUrl) {
            const isSecurityPath = window.location.pathname.includes('/security/');
            passUrl = isSecurityPath ? `pass.php?id=${visitId}` : `../security/pass.php?id=${visitId}`;
        }

        if (approvalStatus === 'approved') {
            window.open(passUrl, '_blank');
        } else if (approvalStatus === 'pending') {
            AppDialog.show({
                title: 'Approval Pending',
                text: 'This visit has not been approved by the host yet. You can only print the entrance pass once the approval is granted.',
                icon: 'warning'
            });
        } else {
            alert('This visit has been rejected. Cannot print pass.');
        }
    }
</script>