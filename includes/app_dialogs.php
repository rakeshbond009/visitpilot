<?php
/**
 * Shared App Dialog Modals
 */
?>
<!-- App Notification Modal -->
<div class="modal fade" id="notificationModal" tabindex="-1" style="z-index: 9999;">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0">
                <h6 class="modal-title fw-bold m-0"></h6>
            </div>
            <div class="modal-body text-center p-4">
                <p class="mb-0 fw-semibold text-dark"></p>
            </div>
            <div class="modal-footer border-0 justify-content-center pt-0 pb-3">
                <button type="button" class="btn btn-sm px-4 rounded-pill fw-bold" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- App Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" style="z-index: 9998;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title fw-bold m-0"></h5>
            </div>
            <div class="modal-body text-center p-4">
                <div id="deleteConfirmIconCont" class="text-danger mb-3"><i class="bi bi-exclamation-circle-fill display-4"></i></div>
                <p id="deleteConfirmMsg" class="fw-bold fs-5 mb-1"></p>
                <p class="text-muted small">Are you sure you want to proceed with this action?</p>
            </div>
            <div class="modal-footer bg-light border-0 justify-content-center py-3">
                <button type="button" class="btn btn-light border px-4 rounded-pill"
                    data-bs-dismiss="modal">Cancel</button>
                <a id="deleteConfirmBtn" href="#" class="btn btn-danger px-4 rounded-pill fw-bold">Proceed</a>
            </div>
        </div>
    </div>
</div>

<!-- App Input Modal -->
<div class="modal fade" id="inputModal" tabindex="-1" style="z-index: 10001;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold m-0"><i class="bi bi-pencil-square me-2"></i>Input Required</h5>
            </div>
            <div class="modal-body p-4">
                <p id="inputModalMsg" class="fw-bold mb-3"></p>
                <input type="text" id="inputModalField" class="form-control rounded-pill px-3"
                    placeholder="Enter details...">
                <div id="inputModalError" class="text-danger small mt-2 d-none">This field is required.</div>
            </div>
            <div class="modal-footer bg-light border-0 justify-content-center py-3">
                <button type="button" class="btn btn-light border px-4 rounded-pill small fw-bold"
                    data-bs-dismiss="modal">Cancel</button>
                <button id="inputModalSubmit" type="button"
                    class="btn btn-primary px-4 rounded-pill fw-bold">Submit</button>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo BASE_URL; ?>assets/js/app_dialogs.js?v=<?php echo time(); ?>"></script>

<?php include_once 'chatbot.php'; ?>
