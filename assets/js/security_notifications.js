/**
 * Security Notifications Handler v2.5
 * Handles real-time status updates and robust background notifications for Security
 */

(function () {
    console.log("VMS Security Notifications Active");

    let lastCheckTime = null;
    let hbAudio = new Audio('data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAEA');
    let notificationSound = new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');

    // Track notified visits to avoid duplicates across polls
    const notifiedVisits = new Set();

    // Unlock audio on first user interaction to ensure notifications work after redirects
    const unlockAudio = () => {
        notificationSound.play().then(() => {
            notificationSound.pause();
            notificationSound.currentTime = 0;
            console.log("Security Notification Audio Unlocked");
        }).catch(e => { });
        document.removeEventListener('click', unlockAudio);
        document.removeEventListener('keydown', unlockAudio);
    };
    document.addEventListener('click', unlockAudio);
    document.addEventListener('keydown', unlockAudio);

    async function checkStatusUpdates() {
        // --- Wake Lock for Background ---
        if (hbAudio && hbAudio.paused && localStorage.getItem('vms_security_bg_mode') === 'true') {
            hbAudio.play().catch(e => { });
        }

        try {
            let apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'security/api/check_status_updates.php' : 'api/check_status_updates.php';

            let url = apiPath;
            if (lastCheckTime) {
                url += `?last_check=${encodeURIComponent(lastCheckTime)}`;
            }

            const response = await fetch(url, { credentials: 'include' });
            const data = await response.json();

            if (data.success) {
                if (!lastCheckTime) {
                    lastCheckTime = data.timestamp;
                    // First load, don't notify old ones, but store them to avoid future notifications
                    if (data.updates) data.updates.forEach(v => notifiedVisits.add(v.id));
                    return;
                }
                lastCheckTime = data.timestamp;

                // Process new status changes
                if (data.updates && data.updates.length > 0) {
                    data.updates.forEach(visit => {
                        if (!notifiedVisits.has(visit.id)) {
                            notifiedVisits.add(visit.id);

                            // 1. Play Sound
                            // Notification sound should always trigger for status updates 
                            // to ensure immediate feedback to security/admin.
                            if (notificationSound) {
                                notificationSound.play().catch(e => console.warn("Audio blocked"));
                            }

                            // 2. Show UI Notification
                            // If notifyStatusChange exists (on dashboards), use the rich UI popup.
                            // otherwise (on other pages), use the generic showSecurityPopup.
                            if (typeof window.notifyStatusChange === 'function') {
                                window.notifyStatusChange(visit);
                                // If on dashboard, also force a table refresh to show new status
                                if (typeof window.VMS_REFRESH_DASHBOARD === 'function') {
                                    window.VMS_REFRESH_DASHBOARD();
                                }
                            } else {
                                // On other pages, just show the generic popup
                                showSecurityPopup(visit);
                            }

                            // 3. Browser Notification
                            if ("Notification" in window && Notification.permission === "granted") {
                                new Notification(`Visit ${visit.approval_status.toUpperCase()}`, {
                                    body: `${visit.visitor_name}'s visit has been ${visit.approval_status} by ${visit.host_name}.`,
                                    icon: (typeof BASE_URL !== 'undefined') ? BASE_URL + "assets/img/visitor-icon.png" : "../assets/img/visitor-icon.png"
                                });
                            }
                        }
                    });
                }
            }
        } catch (e) {
            console.error("Poll Error:", e);
        }
    }

    function showSecurityPopup(visit) {
        const isApproved = visit.approval_status === 'approved';
        const color = isApproved ? '#28a745' : '#dc3545';

        if (typeof AppDialog !== 'undefined') {
            AppDialog.show({
                title: `<span class="fw-bold text-dark mt-2" style="font-size: 1.1rem; letter-spacing: -0.5px;">Arrival Status Update</span>`,
                html: `
                    <div class="text-center p-2">
                         <div class="mx-auto mb-3 rounded-pill py-1 px-3 d-inline-block animate__animated animate__fadeInDown" 
                             style="background: ${isApproved ? 'rgba(25, 135, 84, 0.1)' : 'rgba(220, 53, 69, 0.1)'}; 
                                    border: 1px solid ${isApproved ? 'rgba(25, 135, 84, 0.2)' : 'rgba(220, 53, 69, 0.2)'};">
                            <span class="fw-bold small" style="color: ${color};"><i class="bi ${isApproved ? 'bi-patch-check-fill' : 'bi-patch-exclamation-fill'} me-1"></i> ${visit.approval_status.toUpperCase()}</span>
                        </div>
                        <h5 class="fw-bold mb-1">${visit.visitor_name}</h5>
                        <p class="text-muted small mb-3">Host Assigned: ${visit.host_name}</p>
                        
                        <div class="row text-start small mt-2 bg-light p-3 rounded-4">
                            <div class="col-6 border-end pe-2">
                                <small class="text-muted d-block text-uppercase fw-bold ls-1" style="font-size: 0.55rem;">Floor Access</small>
                                <span class="fw-bold text-dark small">${visit.access_area || 'General'}</span>
                            </div>
                            <div class="col-6 ps-2">
                                <small class="text-muted d-block text-uppercase fw-bold ls-1" style="font-size: 0.55rem;">Assets Carried</small>
                                <span class="fw-bold text-dark small">${visit.assets_carried || 'None'}</span>
                            </div>
                        </div>
                    </div>
                `,
                confirmButtonText: 'Manage Visit',
                showCancelButton: true,
                confirmButtonColor: color,
                cancelButtonText: 'Dismiss'
            }).then((result) => {
                if (result.isConfirmed) {
                    console.log("Manage Visit clicked for visit:", visit.id);
                    // Increased delay to ensure the AppDialog modal is fully hidden before opening the next modal
                    setTimeout(() => {
                        if (typeof window.viewVisitDetails === 'function') {
                            window.viewVisitDetails(visit.id);
                        } else {
                            console.error("viewVisitDetails not found on this page.");
                        }
                    }, 400);
                }
            });
        }
    }

    // Export Globally
    window.toggleSecurityBackgroundMode = function (toggle) {
        if (!toggle) return;
        localStorage.setItem('vms_security_bg_mode', toggle.checked);

        if (toggle.checked) {
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

    function restoreSecurityBGMode() {
        if (localStorage.getItem('vms_security_bg_mode') === 'true') {
            const startHeartbeat = () => {
                hbAudio.loop = true;
                hbAudio.play().catch(e => console.log("Silent BG mode waiting for interaction"));
                document.removeEventListener('click', startHeartbeat);
            };
            document.addEventListener('click', startHeartbeat);
        }
    }

    restoreSecurityBGMode();
    setInterval(checkStatusUpdates, 4000);

    // --- Mobile App FCM Bridge ---
    window.checkFCMRegistration = function (token) {
        if (!token) return;
        const lastToken = localStorage.getItem('last_registered_fcm');

        // Always try to update if possible to ensure sync
        console.log("Registering FCM Token for Mobile (Security):", token);

        // Correct path for security dashboard (security/dashboard.php -> ../api/user/update_fcm.php)
        const apiPath = '../api/user/update_fcm.php';

        fetch(apiPath, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ fcm_token: token })
        })
            .then(res => {
                if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);
                return res.json();
            })
            .then(data => {
                console.log("FCM Update Result:", data);
                if (data.success) {
                    localStorage.setItem('last_registered_fcm', token);
                }
            })
            .catch(err => console.error("FCM Update Error:", err));
    };

    // Auto-check on load if token was pre-injected by mobile app
    const preInjectedToken = localStorage.getItem('mobile_fcm_token');
    if (preInjectedToken) {
        window.checkFCMRegistration(preInjectedToken);
    }
})();
