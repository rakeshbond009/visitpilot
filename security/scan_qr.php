<?php require_once 'header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-6 text-center">
        <h2 class="mb-4">Scan Visitor QR Code</h2>

        <div id="reader" style="width: 100%;" class="border rounded p-2 bg-white shadow-sm"></div>

        <div class="mt-4">
            <p class="text-muted">Point camera at the QR code on the visitor Pass.</p>
            <a href="<?php echo $home_url; ?>" class="btn btn-secondary">Cancel</a>
        </div>
    </div>
</div>

<script>
    function onScanSuccess(decodedText, decodedResult) {
        // Handle the scanned code as you like, for example:
        console.log(`Code matched = ${decodedText}`, decodedResult);
        // Stop scanning
        html5QrcodeScanner.clear();
        // Redirect to process
        window.location.href = `process_visit.php?action=checkin_by_code&code=${decodedText}`;
    }

    function onScanFailure(error) {
        // handle scan failure, usually better to ignore and keep scanning.
        // console.warn(`Code scan error = ${error}`);
    }

    let html5QrcodeScanner = new Html5QrcodeScanner(
        "reader",
        { fps: 10, qrbox: { width: 250, height: 250 } },
        /* verbose= */ false);
    html5QrcodeScanner.render(onScanSuccess, onScanFailure);
</script>

<?php require_once 'footer.php'; ?>