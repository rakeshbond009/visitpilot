// Camera functionality for visitor registration
let videoStream = null;
let videoElement = null;
let currentDeviceId = null;

function initCamera() {
    videoElement = document.createElement('video');
    videoElement.setAttribute('playsinline', '');
    videoElement.setAttribute('autoplay', '');
}

async function enumerateCameras(selectElementId = 'cameraSelect') {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
        return [];
    }

    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoDevices = devices.filter(device => device.kind === 'videoinput');
        
        const select = document.getElementById(selectElementId);
        if (select) {
            select.innerHTML = '';
            videoDevices.forEach((device, index) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.text = device.label || `Camera ${index + 1}`;
                if (currentDeviceId === device.deviceId) option.selected = true;
                select.appendChild(option);
            });

            // Listen for changes
            select.onchange = () => {
                startCamera(select.value);
            };
        }
        return videoDevices;
    } catch (err) {
        console.error('Error listing cameras:', err);
        return [];
    }
}

function startCamera(deviceId = null) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Camera access is not supported in this browser.');
        return;
    }

    // If already running, stop it
    stopCamera();

    const constraints = {
        video: deviceId ? { deviceId: { exact: deviceId } } : {
            width: { ideal: 1280 },
            height: { ideal: 720 },
            facingMode: 'user'
        }
    };

    navigator.mediaDevices.getUserMedia(constraints)
        .then(stream => {
            videoStream = stream;
            if (!videoElement) initCamera();
            videoElement.srcObject = stream;
            videoElement.play();

            // Store current device ID from the track
            const track = stream.getVideoTracks()[0];
            if (track) {
                currentDeviceId = track.getSettings().deviceId;
                // Update any list if it exists
                const select = document.getElementById('cameraSelect');
                if (select && !select.value) {
                     // First time load, refresh list to get labels
                     enumerateCameras('cameraSelect');
                }
            }

            const container = document.getElementById('camera_view');
            if (container) {
                container.innerHTML = '';
                container.style.display = 'block';
                videoElement.style.width = '100%';
                videoElement.style.height = '100%';
                videoElement.style.objectFit = 'cover';
                container.appendChild(videoElement);
            }

            // Hide instruction/preview
            const instruction = document.getElementById('camera_instruction');
            if (instruction) instruction.style.display = 'none';
            const preview = document.getElementById('photo_preview');
            if (preview) preview.style.display = 'none';
        })
        .catch(err => {
            console.error('Camera error:', err);
            // If specific device failed, try default
            if (deviceId) {
                console.warn('Specific camera failed, falling back to default.');
                startCamera(null);
            } else {
                alert('Unable to access camera. Please check permissions.');
            }
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
