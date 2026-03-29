// Camera functionality for visitor registration
let videoStream = null;
let videoElement = null;

function initCamera() {
    videoElement = document.createElement('video');
    videoElement.setAttribute('playsinline', '');
    videoElement.setAttribute('autoplay', '');
}

function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Camera access is not supported in this browser.');
        return;
    }

    navigator.mediaDevices.getUserMedia({
        video: {
            width: { ideal: 640 },
            height: { ideal: 480 },
            facingMode: 'user'
        }
    })
        .then(stream => {
            videoStream = stream;
            if (!videoElement) initCamera();
            videoElement.srcObject = stream;
            videoElement.play();

            const container = document.getElementById('camera_view');
            container.innerHTML = '';
            container.style.display = 'block'; // Ensure it's visible
            videoElement.style.width = '100%';
            videoElement.style.height = '100%';
            videoElement.style.objectFit = 'cover';
            container.appendChild(videoElement);

            // Hide Instruction Overlay
            const instruction = document.getElementById('camera_instruction');
            if (instruction) instruction.style.display = 'none';

            // Hide Preview if it was shown
            const preview = document.getElementById('photo_preview');
            if (preview) preview.style.display = 'none';
        })
        .catch(err => {
            console.error('Camera error:', err);
            alert('Unable to access camera. Please check permissions.');
        });
}

function stopCamera() {
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
    }
}

function takeSnapshot() {
    if (!videoStream) {
        alert('Please start the camera first');
        return;
    }

    const canvas = document.createElement('canvas');
    canvas.width = 400;
    canvas.height = 300;

    const ctx = canvas.getContext('2d');
    ctx.drawImage(videoElement, 0, 0, canvas.width, canvas.height);

    const dataUrl = canvas.toDataURL('image/jpeg', 0.8);

    document.getElementById('photo_data').value = dataUrl;
    document.getElementById('captured_image').src = dataUrl;
    document.getElementById('photo_preview').style.display = 'block';
    document.getElementById('camera_view').style.display = 'none';

    // Stop pulse animation
    const capBtn = document.querySelector('.pulse-animation');
    if (capBtn) capBtn.classList.remove('pulse-animation');

    stopCamera();
}

// Form validation
function validateForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return true;

    const inputs = form.querySelectorAll('[required]');
    let isValid = true;

    inputs.forEach(input => {
        if (!input.value.trim()) {
            input.classList.add('is-invalid');
            isValid = false;
        } else {
            input.classList.remove('is-invalid');
        }
    });

    return isValid;
}

// Mobile number validation
function validateMobile(input) {
    const pattern = /^[0-9]{10}$/;
    if (!pattern.test(input.value)) {
        input.setCustomValidity('Please enter a valid 10-digit mobile number');
    } else {
        input.setCustomValidity('');
    }
}

// Auto-dismiss alerts
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert-dismissible');
    alerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 5000);
    });

    // Add mobile validation to all mobile inputs
    const mobileInputs = document.querySelectorAll('input[type="text"][name="mobile"]');
    mobileInputs.forEach(input => {
        input.addEventListener('input', function () {
            validateMobile(this);
        });
    });
});

// Confirm dialogs
function confirmAction(message) {
    return confirm(message);
}

// Print functionality
function printPass() {
    window.print();
}

// Export table to CSV
function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');

    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = Array.from(cols).map(col => {
            return '"' + col.innerText.replace(/"/g, '""') + '"';
        });
        csv.push(rowData.join(','));
    });

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || 'export.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// Real-time search
function liveSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);

    if (!input || !table) return;

    input.addEventListener('keyup', function () {
        const filter = this.value.toLowerCase();
        const rows = table.querySelectorAll('tbody tr');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}

// Toast notifications
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'position-fixed bottom-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
    }

    const toastId = 'toast-' + Date.now();
    const bgClass = {
        'success': 'bg-success',
        'error': 'bg-danger',
        'warning': 'bg-warning',
        'info': 'bg-info'
    }[type] || 'bg-info';

    const toastHTML = `
        <div id="${toastId}" class="toast align-items-center text-white ${bgClass} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;

    document.getElementById('toastContainer').insertAdjacentHTML('beforeend', toastHTML);
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, { delay: 3000 });
    toast.show();

    toastElement.addEventListener('hidden.bs.toast', () => {
        toastElement.remove();
    });
}
