<?php
require_once 'header.php'; // Includes SweetAlert2 and DB

$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? 0;
$code = $_GET['code'] ?? '';
$home_url = getHomeUrl($_SESSION['role']);

echo '<div class="row justify-content-center py-5"><div class="col-md-6 text-center"><div class="spinner-border text-primary mb-3"></div><h4>Processing...</h4></div></div>';

echo "<script>
document.addEventListener('DOMContentLoaded', function() {";
if ($action == 'checkin') {
    $stmt = $pdo->prepare("SELECT approval_status FROM visits WHERE id=?");
    $stmt->execute([$id]);
    $approval = $stmt->fetchColumn();

    if ($approval !== 'approved') {
        echo "Swal.fire('Cannot check-in', 'Visit not yet approved by host', 'error').then(() => { window.location.href='$home_url'; });";
    } else {
        // Fetch visitor name for confirmation
        $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.id = ?");
        $stmt->execute([$id]);
        $visit = $stmt->fetch();
        
        echo "Swal.fire({
            title: 'Confirm Check-In',
            text: 'Do you want to check in " . addslashes($visit['visitor_name']) . "?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, Check In',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Perform check-in via internal call or reload with a flag
                window.location.href='process_visit.php?action=do_checkin&id=$id';
            } else {
                window.location.href='$home_url';
            }
        });";
    }
}
elseif ($action == 'do_checkin') {
    $current_time = current_datetime();
    $stmt = $pdo->prepare("UPDATE visits SET status='checked_in', check_in_time=? WHERE id=?");
    $stmt->execute([$current_time, $id]);
    logAction($pdo, $_SESSION['user_id'], "Checked in visitor ID: $id (Process Visit)");
    echo "Swal.fire('Success', 'Visitor checked in successfully', 'success').then(() => { window.location.href='$home_url'; });";
}
elseif ($action == 'checkout') {
    $current_time = current_datetime();
    $stmt = $pdo->prepare("UPDATE visits SET status='checked_out', check_out_time=? WHERE id=?");
    $stmt->execute([$current_time, $id]);
    logAction($pdo, $_SESSION['user_id'], "Checked out visitor ID: $id (Process Visit)");
    echo "Swal.fire('Success', 'Visitor checked out successfully', 'success').then(() => { window.location.href='$home_url'; });";
}
elseif ($action == 'checkin_by_code') {
    $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.visit_code = ?");
    $stmt->execute([$code]);
    $visit = $stmt->fetch();

    if ($visit) {
        if ($visit['status'] == 'approved' || $visit['status'] == 'registered') {
            if ($visit['approval_status'] == 'approved') {
                echo "Swal.fire({
                    title: 'Confirm Check-In',
                    text: 'Do you want to check in " . addslashes($visit['visitor_name']) . "?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Check In',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href='process_visit.php?action=checkin&id=" . $visit['id'] . "';
                    } else {
                        window.location.href='$home_url';
                    }
                });";
            } else {
                echo "Swal.fire('Approval Pending', 'This visit is pending host approval.', 'warning').then(() => { window.location.href='$home_url'; });";
            }
        }
        elseif ($visit['status'] == 'pending' && $visit['is_invited'] == 1) {
            // It's a pending invitation - redirect to registration for pre-fill
            echo "window.location.href='register.php?code=$code';";
        }
        elseif ($visit['status'] == 'checked_in') {
            echo "Swal.fire({
                title: 'Already Checked In',
                text: 'Visitor is already inside. Do you want to check OUT?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, Check Out',
                cancelButtonText: 'No, Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href='process_visit.php?action=checkout&id=" . $visit['id'] . "';
                } else {
                    window.location.href='$home_url';
                }
            });";
        }
        elseif ($visit['approval_status'] == 'pending' || $visit['status'] == 'pending') {
            echo "Swal.fire('Approval Pending', 'This visit request is currently awaiting host approval.', 'warning').then(() => { window.location.href='$home_url'; });";
        }
        else {
            echo "Swal.fire('Already Processed', 'This visit has been checked out, rejected, or is no longer active.', 'info').then(() => { window.location.href='$home_url'; });";
        }
    }
    else {
        echo "Swal.fire('Invalid Code', 'The scanned QR code was not found in our records', 'error').then(() => { window.location.href='scan_qr.php'; });";
    }
}
else {
    echo "window.location.href='$home_url';";
}
echo "});
</script>";

require_once 'footer.php';
?>