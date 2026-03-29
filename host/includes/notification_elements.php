<!-- New Visitor Notification Modal -->
<div class="modal fade" id="newVisitorModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning text-dark border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-bell-fill me-2"></i> New Visitor Arrival</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3 text-center">
                <div class="mb-2">
                    <img id="modal-visitor-img" src="../assets/img/visitor-icon.png"
                        class="rounded-circle border border-3 border-warning shadow-sm" width="90" height="90"
                        style="object-fit:cover">
                </div>
                <h5 class="fw-bold mb-1" id="modal-visitor-name">...</h5>
                <p class="text-muted small mb-3" id="modal-visitor-mobile">...</p>
                <div class="py-2 px-3 border rounded-3 bg-light small mb-3 text-center">
                    <div class="row g-0">
                        <div class="col-12 mb-2">
                            <span class="text-muted extra-small d-block text-uppercase fw-bold"
                                style="font-size: 0.6rem;">Purpose of Visit</span>
                            <span id="modal-visitor-purpose" class="fw-bold text-dark">...</span>
                        </div>
                        <div class="col-6 border-end">
                            <span class="text-muted extra-small d-block text-uppercase fw-bold"
                                style="font-size: 0.6rem;">Floor Access</span>
                            <span id="modal-visitor-access" class="fw-bold text-dark">...</span>
                        </div>
                        <div class="col-6 ps-2">
                            <span class="text-muted extra-small d-block text-uppercase fw-bold"
                                style="font-size: 0.6rem;">Assets</span>
                            <span id="modal-visitor-assets" class="fw-bold text-dark">...</span>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div id="modal-actions" class="row g-2">
                    <div class="col-6">
                        <button onclick="approveAndPrepareShare()" id="btn-approve"
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
                </div>

                <div id="modal-share-area" class="d-none animate__animated animate__fadeIn">
                    <div class="py-2 small mb-3 text-center border rounded-3 bg-light">
                        <i class="bi bi-check-circle-fill me-1 text-success"></i> <span
                            class="fw-bold text-success">Approved!</span>
                        <div class="text-muted small" style="font-size: 0.75rem;">Opening WhatsApp...</div>
                    </div>
                    <div class="row g-2">
                        <div class="col-8">
                            <button onclick="sharePassFromDashboard(event)" id="btn-resend-wa"
                                class="btn btn-success w-100 rounded-pill py-2 fw-bold btn-sm shadow-sm">
                                <i class="bi bi-whatsapp me-2"></i> RESEND VIA WHATSAPP
                            </button>
                        </div>
                        <div class="col-4">
                            <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold btn-sm border"
                                data-bs-dismiss="modal">
                                CLOSE
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- HIDDEN PASS TEMPLATE FOR PDF GENERATION -->
<div style="position:fixed; left:-9999px; top:0;">
    <div id="hiddenPassTemplate" class="vms-id-card mx-auto"
        style="transform: scale(1); background: transparent; width:380px; min-height:560px; box-shadow: none; border: none; font-family: 'Segoe UI', sans-serif;">
        <div
            style="background: #1161ee; color: white; padding: 35px 20px; text-align: center; border-top-left-radius: 35px; border-top-right-radius: 35px;">
            <div
                style="font-size: 0.65rem; letter-spacing: 2.5px; font-weight: 800; text-transform: uppercase; margin-bottom: 5px; opacity: 0.95;">
                VISITOR MANAGEMENT</div>
            <div style="font-size: 2.1rem; font-weight: 800; letter-spacing: 0.5px;">VISITOR PASS</div>
        </div>
        <div style="padding: 10px 30px; background: #fff; text-align: center;">
            <div style="margin-top: -30px;">
                <div
                    style="background: #fff; display: inline-block; border-radius: 30px; padding: 5px; box-shadow: 0 10px 25px rgba(0,0,0,0.08);">
                    <img id="tpl-img" src=""
                        style="width: 170px; height: 170px; border-radius: 25px; object-fit: cover; border: 2px solid #fff;">
                </div>
            </div>
            <h2 id="tpl-name"
                style="font-size: 1.8rem; font-weight: 800; color: #111; letter-spacing: -0.5px; margin-top: 15px; text-transform: uppercase;">
                ...</h2>
            <div id="tpl-code"
                style="font-size: 1.1rem; letter-spacing: 1px; margin-bottom: 20px; color: #1161ee; font-weight: bold;">
                ...</div>
            <div
                style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; text-align: left; max-width: 320px; margin: 0 auto 20px; padding: 15px; background: #f8f9fa; border-radius: 12px;">
                <div style="display: flex; flex-direction: column;">
                    <span
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">VISITING:</span>
                    <span id="tpl-host"
                        style="font-size: 0.85rem; color: #333; font-weight: 800; line-height: 1.2;">...</span>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">PURPOSE:</span>
                    <span id="tpl-purpose"
                        style="font-size: 0.85rem; color: #333; font-weight: 800; line-height: 1.2;">...</span>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">ACCESS
                        AREA:</span>
                    <span id="tpl-access"
                        style="font-size: 0.85rem; color: #333; font-weight: 800; line-height: 1.2;">General</span>
                </div>
                <div style="display: flex; flex-direction: column;">
                    <span
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">DATE:</span>
                    <span id="tpl-date"
                        style="font-size: 0.85rem; color: #0d6efd; font-weight: 800; line-height: 1.2;">-</span>
                </div>
                <div
                    style="display: flex; flex-direction: column; grid-column: span 2; border-top: 1px dashed #eee; padding-top: 8px; margin-top: 5px;">
                    <span
                        style="font-size: 0.65rem; color: #adb5bd; font-weight: 800; text-transform: uppercase; margin-bottom: 4px;">ASSETS
                        CARRIED:</span>
                    <span id="tpl-assets"
                        style="font-size: 0.8rem; color: #333; font-weight: 600; line-height: 1.2;">-</span>
                </div>
            </div>
            <img id="tpl-qr" src=""
                style="width: 100px; height: 100px; border: 1px solid #f0f0f0; padding: 5px; border-radius: 12px; margin-top: 20px;">
        </div>
        <div
            style="background: #fbfbfc; padding: 15px 0; letter-spacing: 2px; text-transform: uppercase; font-size: 0.7rem; font-weight: 900; color: #ccc; border-top: 1px solid #eee; text-align: center; border-bottom-left-radius: 35px; border-bottom-right-radius: 35px;">
            SECURE ENTRY SYSTEM</div>
    </div>
</div>