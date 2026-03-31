/**
 * VMS Notifications Handler v3.1 - Robust Unified Experience
 * Handles real-time status updates and robust background notifications for the Host.
 * Unified design across Dashboard and other pages.
 */

(function () {
    console.log("VMS Host Notifications Active (Unified v3.1)");

    // Only run if the notification modal is present in the DOM
    if (!document.getElementById('newVisitorModal')) return;

    // --- State & Config ---
    let lastCheckTime = null;
    let alarmPlaying = false;
    let currentVisitorId = null;
    let currentVisitorData = null;
    let isSharingMode = false;

    // Sounds
    let hbAudio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA');
    let notificationSound = new Audio('https://assets.mixkit.co/active_storage/sfx/951/951-preview.mp3');
    notificationSound.loop = true;

    // --- Core Functions ---

    /**
     * Poll for new notifications
     */
    async function checkNewNotifications() {
        // Heartbeat for background mode
        if (hbAudio && hbAudio.paused && localStorage.getItem('vms_host_bg_mode') === 'true') {
            hbAudio.play().catch(e => { });
        }

        try {
            const currentOrigin = window.location.origin;
            const currentPath = window.location.pathname;
            const isInsideHost = currentPath.includes('/host/');

            let apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL : (isInsideHost ? '../' : '');
            if (!apiPath.endsWith('/')) apiPath += '/';
            let url = apiPath + 'host/api/check_new_visits.php';

            if (lastCheckTime) {
                url += `?last_check=${encodeURIComponent(lastCheckTime)}`;
            }

            const response = await fetch(url);
            const data = await response.json();
            if (data.success) {
                // Keep track of check time to only show NEW visits in subsequent loops, 
                // but allow the first check to alert for any currently pending visits
                const isFirstCheck = !lastCheckTime;
                lastCheckTime = data.timestamp;

                if (data.new_visits && data.new_visits.length > 0) {
                    console.log("New Visitor Found:", data.new_visits[0].visitor_name);
                    triggerNewVisitorAlert(data.new_visits[0]);

                    // Browser Notification
                    if ("Notification" in window && Notification.permission === "granted") {
                        new Notification("New Visitor Arrival", {
                            body: `${data.new_visits[0].visitor_name} is waiting for you.`,
                            icon: (typeof BASE_URL !== 'undefined') ? BASE_URL + "assets/img/visitor-icon.png" : "../assets/img/visitor-icon.png"
                        });
                    }
                }
            }
        } catch (e) {
            console.error("Poll Error:", e);
        }
    }

    /**
     * Main Alert Trigger - Used by Poller and Direct Dashboard refresh
     */
    window.triggerNewVisitorAlert = function (visitor) {
        try {
            currentVisitorId = visitor.id;
            currentVisitorData = visitor;

            // Reset Modal State elements
            const btnApprove = document.getElementById('btn-approve');
            const btnReject = document.getElementById('btn-reject');
            const modalActions = document.getElementById('modal-actions');
            const modalShareArea = document.getElementById('modal-share-area');
            const modalTitle = document.querySelector('#newVisitorModal .modal-title');
            const modalHeader = document.querySelector('#newVisitorModal .modal-header');

            if (btnApprove) {
                btnApprove.disabled = false;
                btnApprove.innerHTML = '<i class="bi bi-check-circle me-1"></i> APPROVE';
            }
            if (btnReject) {
                btnReject.disabled = false;
                btnReject.innerHTML = '<i class="bi bi-x-circle me-1"></i> REJECT';
            }

            if (modalActions) modalActions.classList.remove('d-none');
            if (modalShareArea) modalShareArea.classList.add('d-none');

            // Logic for Invited Visitors who just checked in
            // Logic for Invited Visitors who just registered arrival at gate
            const isInvitedArrival = (visitor.is_invited == 1 && visitor.approval_status === 'pending' && visitor.status === 'approved');
            const isAlreadyCheckedIn = (visitor.approval_status === 'approved' && visitor.status === 'checked_in');

            if (isInvitedArrival || isAlreadyCheckedIn) {
                if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-person-check-fill me-2"></i> Invited Visitor Arrived';
                if (modalHeader) {
                    modalHeader.classList.remove('bg-warning');
                    modalHeader.classList.add('bg-success', 'text-white');
                }
                if (modalActions) {
                    modalActions.innerHTML = `
                        <div class="col-12">
                            <button type="button" class="btn btn-success w-100 rounded-pill py-2 fw-bold" id="btn-acknowledge">
                                <i class="bi bi-check2-all me-2"></i> ACKNOWLEDGE ARRIVAL
                            </button>
                        </div>
                    `;
                    document.getElementById('btn-acknowledge').onclick = () => {
                        window.stopAlarm();
                        approveAndPrepareShare('btn-acknowledge');
                    };
                }
            } else {
                // Standard Pending Approval (Walk-ins)
                if (modalTitle) modalTitle.innerHTML = '<i class="bi bi-bell-fill me-2"></i> New Visitor Arrival';
                if (modalHeader) {
                    modalHeader.classList.add('bg-warning');
                    modalHeader.classList.remove('bg-success', 'text-white');
                }
                if (modalActions) {
                    modalActions.innerHTML = `
                        <div class="col-6">
                            <button onclick="approveAndPrepareShare('btn-approve')" id="btn-approve"
                                class="btn btn-success w-100 rounded-pill py-2 fw-bold btn-sm">
                                <i class="bi bi-check-circle me-1"></i> APPROVE
                            </button>
                        </div>
                        <div class="col-6">
                            <button onclick="quickAction('reject')" id="btn-reject"
                                class="btn btn-danger w-100 rounded-pill py-2 fw-bold btn-sm">
                                <i class="bi bi-x-circle me-1"></i> REJECT
                            </button>
                        </div>
                    `;
                }
            }

            // Fill Modal Content
            const actualVisitPhoto = (visitor.visit_photo && visitor.visit_photo.trim() !== "") ? visitor.visit_photo : "";
            const visitorImg = actualVisitPhoto ? (actualVisitPhoto.startsWith('http') ? actualVisitPhoto : (typeof BASE_URL !== 'undefined' ? BASE_URL + actualVisitPhoto : '../' + actualVisitPhoto)) : (typeof BASE_URL !== 'undefined' ? BASE_URL + 'assets/img/visitor-icon.png' : '../assets/img/visitor-icon.png');

            const imgEl = document.getElementById('modal-visitor-img');
            const nameEl = document.getElementById('modal-visitor-name');
            const mobEl = document.getElementById('modal-visitor-mobile');
            const purEl = document.getElementById('modal-visitor-purpose');
            const accEl = document.getElementById('modal-visitor-access');
            const astEl = document.getElementById('modal-visitor-assets');

            if (imgEl) imgEl.src = visitorImg;
            if (nameEl) nameEl.innerText = visitor.visitor_name;
            if (mobEl) mobEl.innerText = visitor.mobile;
            if (purEl) purEl.innerText = visitor.purpose;
            if (accEl) accEl.innerText = visitor.access_area || 'Not Assigned';
            if (astEl) astEl.innerText = visitor.assets_carried || 'None';

            // Update Hidden Pass Template
            const tpl = document.getElementById('hiddenPassTemplate');
            if (tpl) {
                const tplImg = document.getElementById('tpl-img');
                const tplName = document.getElementById('tpl-name');
                const tplCode = document.getElementById('tpl-code');
                const tplHost = document.getElementById('tpl-host');
                const tplPur = document.getElementById('tpl-purpose');
                const tplAcc = document.getElementById('tpl-access');
                const tplAst = document.getElementById('tpl-assets');
                const tplDate = document.getElementById('tpl-date');
                const tplQr = document.getElementById('tpl-qr');

                if (tplImg) tplImg.src = visitorImg;
                if (tplName) tplName.innerText = visitor.visitor_name;
                const vCode = visitor.visit_code || 'PASS';
                if (tplCode) tplCode.innerText = vCode;
                if (tplHost) tplHost.innerText = (typeof USER_FULL_NAME !== 'undefined') ? USER_FULL_NAME : 'Host';
                if (tplPur) tplPur.innerText = visitor.purpose;
                if (tplAcc) tplAcc.innerText = visitor.access_area || 'General';
                if (tplAst) tplAst.innerText = visitor.assets_carried || 'None';

                const visitDate = new Date(visitor.created_at || new Date());
                const dateStr = visitDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
                if (tplDate) tplDate.innerText = dateStr;
                if (tplQr) tplQr.src = `https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(vCode)}`;
            }

            // Start Alarm
            if (!alarmPlaying) {
                notificationSound.currentTime = 0;
                notificationSound.play().catch(e => {
                    console.warn("Audio blocked. Interaction needed.");
                });
                alarmPlaying = true;
                const alarmCtrl = document.getElementById('alarm-control');
                if (alarmCtrl) alarmCtrl.classList.remove('d-none');
            }

            // Show Bootstrap Modal
            const modalEl = document.getElementById('newVisitorModal');
            if (modalEl) {
                const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modalEl.removeEventListener('hidden.bs.modal', resetSharingMode);
                modalEl.addEventListener('hidden.bs.modal', resetSharingMode);
                modal.show();
            }
        } catch (err) {
            console.error("Alert Trigger Error:", err);
        }
    };

    window.stopAlarm = function () {
        notificationSound.pause();
        notificationSound.currentTime = 0;
        alarmPlaying = false;
        const alarmCtrl = document.getElementById('alarm-control');
        if (alarmCtrl) alarmCtrl.classList.add('d-none');
    };

    function resetSharingMode() {
        window.stopAlarm();
        isSharingMode = false;
        const btnApprove = document.getElementById('btn-approve');
        const btnReject = document.getElementById('btn-reject');
        if (btnApprove) {
            btnApprove.disabled = false;
            btnApprove.innerHTML = '<i class="bi bi-check-circle me-1"></i> APPROVE';
        }
        if (btnReject) {
            btnReject.disabled = false;
            btnReject.innerHTML = '<i class="bi bi-x-circle me-1"></i> REJECT';
        }
        if (document.getElementById('modal-actions')) document.getElementById('modal-actions').classList.remove('d-none');
        if (document.getElementById('modal-share-area')) document.getElementById('modal-share-area').classList.add('d-none');
    }

    /**
     * Unified Approval & PDF Share Logic
     */
    window.approveAndPrepareShare = async function (btnId = 'btn-approve') {
        if (!currentVisitorId) return;
        isSharingMode = true;
        const btn = document.getElementById(btnId);
        const orgHtml = btn ? btn.innerHTML : '';
        const isAcknowledge = (btnId === 'btn-acknowledge');

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span> ${isAcknowledge ? 'Acknowledging...' : 'Approving...'}`;
        }

        try {
            const apiBase = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'host/' : '';
            const url = `${apiBase}pending_approvals.php?ajax_action=1&v_id=${currentVisitorId}&act=approve&reason=Approved via Global Notification`;

            const response = await fetch(url);
            if (response.ok) {
                window.stopAlarm();
                if (btn) btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending PDF...';

                // Ensure images in template are loaded
                const images = document.querySelectorAll('#hiddenPassTemplate img');
                await Promise.all(Array.from(images).map(img => {
                    if (img.complete) return Promise.resolve();
                    return new Promise(resolve => { img.onload = img.onerror = resolve; });
                }));

                const { jsPDF } = window.jspdf;
                const container = document.getElementById('hiddenPassTemplate');
                if (!container) throw new Error("Template not found");

                const canvas = await html2canvas(container, { scale: 2, useCORS: true, backgroundColor: null, logging: false });
                const imgData = canvas.toDataURL('image/png');
                const pdf = new jsPDF({ orientation: 'p', unit: 'px', format: [380, 560] });
                pdf.addImage(imgData, 'PNG', 0, 0, 380, 560);
                const pdfBlob = pdf.output('blob');

                const vCode = currentVisitorData.visit_code || 'Pass';
                let mob = currentVisitorData.mobile.replace(/\D/g, '');
                if (mob.length === 10) mob = "91" + mob;

                const formData = new FormData();
                formData.append('pdf', pdfBlob, `${vCode}.pdf`);
                formData.append('visit_id', currentVisitorId);

                const uploadApiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'api/visit/upload_pass.php' : '../api/visit/upload_pass.php';
                const uploadRes = await fetch(uploadApiPath, { method: 'POST', body: formData });
                const uploadData = await uploadRes.json();

                if (uploadData.success) {
                    Swal.fire({
                        title: 'Approved!',
                        text: 'Visitor has been successfully approved.',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    const modalActions = document.getElementById('modal-actions');
                    const modalShareArea = document.getElementById('modal-share-area');
                    if (modalActions) modalActions.classList.add('d-none');
                    if (modalShareArea) modalShareArea.classList.remove('d-none');

                    const shareAreaBtn = document.getElementById('btn-resend-wa');
                    if (shareAreaBtn) {
                        shareAreaBtn.disabled = false;
                        shareAreaBtn.innerHTML = '<i class="bi bi-whatsapp me-2"></i> RESEND VIA WHATSAPP';
                        shareAreaBtn.onclick = (e) => window.sharePassFromDashboard(e);
                    }

                    if (window.syncHostDashboard) window.syncHostDashboard();
                } else {
                    throw new Error("Upload failed");
                }
            }
        } catch (e) {
            Swal.fire('Error', e.message, 'error');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = orgHtml;
            }
            isSharingMode = false;
        }
    };

    /**
     * Resend the WhatsApp Pass via Cloud API (Template based)
     */
    window.sharePassFromDashboard = async function (event) {
        if (event) event.preventDefault();

        const visitId = currentVisitorId || (currentVisitorData ? currentVisitorData.id : null);
        if (!visitId) {
            console.error("No Visit ID found for resend.");
            return;
        }

        const btn = document.getElementById('btn-resend-wa');
        const orgHtml = btn ? btn.innerHTML : '<i class="bi bi-whatsapp me-2"></i> RESEND VIA WHATSAPP';

        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Sending...';
        }

        try {
            const apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'api/visit/resend_whatsapp.php' : '../api/visit/resend_whatsapp.php';
            const response = await fetch(`${apiPath}?visit_id=${visitId}&type=approval`);
            const data = await response.json();

            if (data.success) {
                if (btn) {
                    if (data.skipped) {
                        btn.innerHTML = '<i class="bi bi-whatsapp me-2"></i> RESEND VIA WHATSAPP';
                        btn.disabled = false;
                    } else {
                        btn.innerHTML = '<i class="bi bi-check-circle me-1"></i> DONE!';
                        btn.classList.replace('btn-success', 'btn-outline-success');
                        // Re-enable after short delay so user can resend again
                        setTimeout(() => {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-whatsapp me-2"></i> RESEND VIA WHATSAPP';
                            btn.classList.replace('btn-outline-success', 'btn-success');
                        }, 3000);
                    }
                }

                // Show a small success alert
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: data.skipped ? 'Notice' : 'Notification Status',
                        text: data.message || 'Pass Whatsapp to Visitor.',
                        icon: data.skipped ? 'info' : 'success',
                        confirmButtonText: 'OK'
                    });
                }
            } else {
                throw new Error(data.message || "Cloud API failed");
            }
        } catch (e) {
            console.error("Resend Error:", e);
            if (typeof Swal !== 'undefined') {
                Swal.fire('Error', e.message || 'Failed to send WhatsApp message', 'error');
            }

            if (btn) {
                btn.disabled = false;
                btn.innerHTML = orgHtml;
            }
        }
    };

    /**
     * Unified Rejection Logic
     */
    window.quickAction = async function (action) {
        if (!currentVisitorId) return;
        if (action === 'reject') {
            const visitorName = currentVisitorData ? currentVisitorData.visitor_name : 'Visitor';

            // Hide the Bootstrap modal FIRST before showing Swal to prevent focus trapping
            const modalEl = document.getElementById('newVisitorModal');
            let bootstrapModal = null;
            if (modalEl) {
                bootstrapModal = bootstrap.Modal.getInstance(modalEl);
                if (bootstrapModal) bootstrapModal.hide();
            }

            // Ask for rejection reason
            const { value: reason } = await Swal.fire({
                title: 'Reject Visit?',
                text: `Provide a reason for rejecting ${visitorName}:`,
                input: 'text',
                inputPlaceholder: 'Reason for rejection...',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, Reject',
                inputValidator: (value) => {
                    if (!value) {
                        return 'Rejection reason is mandatory!'
                    }
                }
            });

            if (!reason) {
                // If cancelled, show the visitor modal again
                if (bootstrapModal) bootstrapModal.show();
                return;
            }

            const btn = document.getElementById('btn-reject');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Rejecting...';
            }

            try {
                const apiBase = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'host/' : '';
                const url = `${apiBase}pending_approvals.php?ajax_action=1&v_id=${currentVisitorId}&act=reject&reason=${encodeURIComponent(reason)}`;

                const response = await fetch(url);
                if (response.ok) {
                    const modalEl = document.getElementById('newVisitorModal');
                    if (modalEl) {
                        const modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                    }
                    window.stopAlarm();
                    if (window.syncHostDashboard) window.syncHostDashboard();
                }
            } catch (e) {
                Swal.fire('Error', "Action failed", 'error');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-x-circle me-1"></i> REJECT';
                }
            }
        }
    };

    // --- BG MODE CONTROL ---
    window.toggleBackgroundMode = function (toggle) {
        if (!toggle) return;

        const apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'api/user/settings.php' : '../api/user/settings.php';
        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ bg_mode: toggle.checked })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                localStorage.setItem('vms_host_bg_mode', toggle.checked);
            }
        });

        if (toggle.checked) {
            if ("Notification" in window && Notification.permission !== "granted") {
                Notification.requestPermission();
            }
            hbAudio.loop = true;
            hbAudio.play().catch(e => {
                const startOnFirstClick = () => {
                    hbAudio.play();
                    document.removeEventListener('click', startOnFirstClick);
                };
                document.addEventListener('click', startOnFirstClick);
            });
        } else {
            hbAudio.pause();
        }
    };

    function healBGMode() {
        const toggle = document.getElementById('backgroundToggle');
        if (toggle && toggle.checked) {
            const startHeartbeat = () => {
                if (toggle.checked) window.toggleBackgroundMode(toggle);
                document.removeEventListener('click', startHeartbeat);
            };
            document.addEventListener('click', startHeartbeat);
        } else if (localStorage.getItem('vms_host_bg_mode') === 'true') {
            const startHeartbeatFallback = () => {
                hbAudio.loop = true;
                hbAudio.play().catch(e => { });
                document.removeEventListener('click', startHeartbeatFallback);
            };
            document.addEventListener('click', startHeartbeatFallback);
        }
    }

    // --- Initialization ---
    healBGMode();
    setInterval(checkNewNotifications, 2000);

    // --- Mobile App Bridge ---
    window.checkFCMRegistration = function (token) {
        if (!token) return;
        const lastToken = localStorage.getItem('last_registered_fcm');

        // Debug log (removed alert for better UX)
        console.log("Mobile Token Found: " + token.substring(0, 10) + "...");

        if (lastToken === token) {
            console.log("Token already registered, skipping.");
            return;
        }

        console.log("Registering FCM Token for Mobile:", token);
        const apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'api/user/update_fcm.php' : '../api/user/update_fcm.php';
        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fcm_token: token })
        }).then(res => res.json()).then(data => {
            if (data.success) {
                localStorage.setItem('last_registered_fcm', token);
                Swal.fire({
                    title: 'Sync Successful',
                    text: 'Mobile Notifications Active',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false,
                    position: 'top-end',
                    toast: true
                });
            } else {
                Swal.fire('Registration Failed', data.message, 'error');
            }
        }).catch(e => {
            Swal.fire('Network Error', 'Notification sync failed', 'error');
        });
    };

    // Auto-check on load if token was pre-injected by mobile app
    const preInjectedToken = localStorage.getItem('mobile_fcm_token');
    if (preInjectedToken) {
        window.checkFCMRegistration(preInjectedToken);
    }
})();
