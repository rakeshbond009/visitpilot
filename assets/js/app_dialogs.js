/**
 * App Dialogs Helper
 * Replaces SweetAlert with Native Bootstrap Modals
 */

const AppDialog = {
    show: function (options) {
        // If options is a string (title, text, icon format)
        if (typeof options === 'string') {
            options = {
                title: arguments[0] || 'Notification',
                text: arguments[1] || '',
                icon: arguments[2] || 'info'
            };
        }

        const title = options.title || 'Notification';
        const text = options.text || options.html || '';
        const icon = options.icon || 'info';
        const showCancel = options.showCancelButton || false;
        const isToast = options.toast || false;

        if (options.input === 'text') {
            return this.prompt(options);
        }

        if (showCancel) {
            return this.confirm(options);
        }

        // Notification Modal (or Toast)
        const modalEl = document.getElementById('notificationModal');
        if (!modalEl) return Promise.resolve({ isConfirmed: true });

        const titleBody = modalEl.querySelector('.modal-title');
        const textBody = modalEl.querySelector('.modal-body p');
        const header = modalEl.querySelector('.modal-header');
        const okBtn = modalEl.querySelector('.modal-footer button');
        const dialog = modalEl.querySelector('.modal-dialog');

        // Reset classes
        header.className = 'modal-header border-0';
        okBtn.className = 'btn btn-sm px-4 rounded-pill fw-bold';
        dialog.className = 'modal-dialog modal-dialog-centered modal-sm';

        let iconClass = 'bi-info-circle-fill';
        if (icon === 'success') {
            header.classList.add('bg-success', 'text-white');
            okBtn.classList.add('btn-outline-success');
            iconClass = 'bi-check-circle-fill';
        } else if (icon === 'error' || icon === 'danger') {
            header.classList.add('bg-danger', 'text-white');
            okBtn.classList.add('btn-outline-danger');
            iconClass = 'bi-exclamation-octagon-fill';
        } else if (icon === 'warning') {
            header.classList.add('bg-warning', 'text-dark');
            okBtn.classList.add('btn-outline-warning');
            iconClass = 'bi-exclamation-triangle-fill';
        } else {
            header.classList.add('bg-primary', 'text-white');
            okBtn.classList.add('btn-outline-primary');
        }

        okBtn.innerHTML = options.confirmButtonText || 'OK';
        titleBody.innerHTML = `<i class="bi ${iconClass} me-2"></i>${title}`;
        textBody.innerHTML = text;

        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        if (isToast || options.timer) {
            setTimeout(() => {
                modal.hide();
            }, options.timer || 3000);
        }

        return new Promise((resolve) => {
            let confirmed = false;
            
            const handleOk = (e) => {
                if (e) e.preventDefault();
                confirmed = true;
                modal.hide();
            };
            
            okBtn.addEventListener('click', handleOk);

            modalEl.addEventListener('hidden.bs.modal', function handler() {
                resolve({ isConfirmed: confirmed });
                okBtn.removeEventListener('click', handleOk);
                modalEl.removeEventListener('hidden.bs.modal', handler);
            }, { once: true });
        });
    },

    confirm: function (options) {
        const modalEl = document.getElementById('deleteConfirmModal');
        if (!modalEl) return Promise.resolve({ isConfirmed: false });

        const titleBody = modalEl.querySelector('.modal-title');
        const textBody = modalEl.querySelector('#deleteConfirmMsg');
        const confirmBtn = modalEl.querySelector('#deleteConfirmBtn');
        const header = modalEl.querySelector('.modal-header');
        const iconCont = modalEl.querySelector('#deleteConfirmIconCont');

        const isError = options.icon === 'error' || options.icon === 'danger' || (options.confirmButtonColor && options.confirmButtonColor.includes('d33'));

        // Dynamic Header/Icon Colors
        header.className = 'modal-header border-0 ' + (isError ? 'bg-danger text-white' : 'bg-primary text-white');
        iconCont.className = 'mb-3 ' + (isError ? 'text-danger' : 'text-primary');
        iconCont.innerHTML = `<i class="bi ${isError ? 'bi-exclamation-circle-fill' : 'bi-question-circle-fill'} display-4"></i>`;

        titleBody.innerHTML = options.title || 'Confirm Action';
        textBody.innerHTML = options.text || options.html || 'Are you sure you want to proceed?';

        confirmBtn.removeAttribute('href');
        confirmBtn.innerHTML = options.confirmButtonText || 'Confirm';
        confirmBtn.className = 'btn px-4 rounded-pill fw-bold ' + (isError ? 'btn-danger' : 'btn-primary');

        const cancelBtn = modalEl.querySelector('.btn-light');
        if (cancelBtn) cancelBtn.innerHTML = options.cancelButtonText || 'Cancel';

        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        return new Promise((resolve) => {
            let confirmed = false;
            const handleConfirm = (e) => {
                if (e) e.preventDefault();
                confirmed = true;
                modal.hide();
            };

            confirmBtn.addEventListener('click', handleConfirm);

            modalEl.addEventListener('hidden.bs.modal', function handler() {
                resolve({ isConfirmed: confirmed });
                confirmBtn.removeEventListener('click', handleConfirm);
                modalEl.removeEventListener('hidden.bs.modal', handler);
            }, { once: true });
        });
    },

    prompt: function (options) {
        const modalEl = document.getElementById('inputModal');
        if (!modalEl) return Promise.resolve({ isConfirmed: false });

        const titleBody = modalEl.querySelector('.modal-title');
        const textBody = modalEl.querySelector('#inputModalMsg');
        const inputField = modalEl.querySelector('#inputModalField');
        const errorMsg = modalEl.querySelector('#inputModalError');
        const submitBtn = modalEl.querySelector('#inputModalSubmit');

        titleBody.innerHTML = options.title || 'Input Required';
        textBody.innerHTML = options.text || options.html || 'Please enter a value:';
        inputField.value = '';
        inputField.placeholder = options.inputPlaceholder || 'Enter details...';
        errorMsg.classList.add('d-none');
        submitBtn.innerHTML = options.confirmButtonText || 'Submit';

        const cancelBtn = modalEl.querySelector('.btn-light');
        if (cancelBtn) cancelBtn.innerHTML = options.cancelButtonText || 'Cancel';

        const modal = new bootstrap.Modal(modalEl);
        modal.show();

        return new Promise((resolve) => {
            let confirmed = false;
            let val = '';

            const handleSubmit = (e) => {
                if (e) e.preventDefault();
                val = inputField.value.trim();
                if (options.inputValidator) {
                    const error = options.inputValidator(val);
                    if (error) {
                        errorMsg.innerText = error;
                        errorMsg.classList.remove('d-none');
                        return;
                    }
                } else if (!val) {
                    errorMsg.classList.remove('d-none');
                    return;
                }

                confirmed = true;
                modal.hide();
            };

            submitBtn.addEventListener('click', handleSubmit);

            modalEl.addEventListener('hidden.bs.modal', function handler() {
                resolve({ isConfirmed: confirmed, value: val });
                submitBtn.removeEventListener('click', handleSubmit);
                modalEl.removeEventListener('hidden.bs.modal', handler);
            }, { once: true });
        });
    }
};

// Only define the Swal mock if the real SweetAlert2 library is not loaded
if (typeof Swal === 'undefined') {
    window.Swal = {
        fire: function () {
            if (arguments.length > 1 || typeof arguments[0] === 'string') {
                return AppDialog.show({
                    title: arguments[0] || 'Notification',
                    text: arguments[1] || '',
                    icon: arguments[2] || 'info'
                });
            }
            return AppDialog.show(arguments[0]);
        }
    };
}
