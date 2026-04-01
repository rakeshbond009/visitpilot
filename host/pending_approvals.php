<?php
// Action Handler for Ajax/Real-time - MUST be before header.php to avoid HTML output
if (isset($_GET['ajax_action'])) {
    require_once '../includes/db.php';
    requireLogin();

    // Get host's employee ID for authorization
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $host_employee_id = $stmt->fetchColumn();

    // Admin role should bypass host_employee_id restriction for approvals/rejections
    $is_admin = ($_SESSION['role'] === 'admin');

    header('Content-Type: application/json');
    $v_id = $_GET['v_id'];
    $act = $_GET['act'];

    if ($act == 'approve') {
        $current_time = date('Y-m-d H:i:s');
        if ($host_employee_id && !$is_admin) {
            $stmt = $pdo->prepare("UPDATE visits SET approval_status='approved', status='approved', approved_by=?, approved_at=? WHERE id=? AND employee_id=?");
            $stmt->execute([$_SESSION['user_id'], $current_time, $v_id, $host_employee_id]);
        } else {
            // Security or Admin user - can approve any visit
            $stmt = $pdo->prepare("UPDATE visits SET approval_status='approved', status='approved', approved_by=?, approved_at=? WHERE id=?");
            $stmt->execute([$_SESSION['user_id'], $current_time, $v_id]);
        }
        logAction($pdo, $_SESSION['user_id'], "Approved visit ID: $v_id");

        // WhatsApp Notification to Visitor
        try {
            require_once '../includes/whatsapp_helper.php';
            $vStmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.id = ?");
            $vStmt->execute([$v_id]);
            $visitor = $vStmt->fetch(PDO::FETCH_ASSOC);
            if ($visitor && !empty($visitor['mobile'])) {
                // PDF generation is optional - WhatsApp template handles document internally
                $pdfUrl = null;
                try {
                    require_once '../includes/pass_pdf_helper.php';
                    $pdfUrl = generatePassPdf($v_id, $pdo);
                } catch (Throwable $pdfErr) {
                    $errLog = "[" . date('Y-m-d H:i:s') . "] PDF_GEN_ERR: " . $pdfErr->getMessage() . "\n";
                    file_put_contents('../whatsapp_log.txt', $errLog, FILE_APPEND);
                }
                sendWhatsAppNotification(
                    $visitor['mobile'],
                    "Your visit request has been approved.",
                    'visit_approval_visitor_notify',
                    ["*{$visitor['visitor_name']}*"],
                    $pdfUrl
                );
            }
        } catch (Throwable $e) {
            $errLog = "[" . date('Y-m-d H:i:s') . "] APPROVAL_WA_ERR: " . $e->getMessage() . "\n";
            file_put_contents('../whatsapp_log.txt', $errLog, FILE_APPEND);
        }

        echo json_encode(['success' => true]);
    } elseif ($act == 'reject') {
        $current_time = date('Y-m-d H:i:s');
        $reason = sanitize($_GET['reason'] ?? 'No reason provided');
        if ($host_employee_id && !$is_admin) {
            $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_by=?, approved_at=?, rejection_reason=? WHERE id=? AND employee_id=?");
            $stmt->execute([$_SESSION['user_id'], $current_time, $reason, $v_id, $host_employee_id]);
        } else {
            // Security or Admin user - can reject any visit
            $stmt = $pdo->prepare("UPDATE visits SET approval_status='rejected', status='rejected', approved_by=?, approved_at=?, rejection_reason=? WHERE id=?");
            $stmt->execute([$_SESSION['user_id'], $current_time, $reason, $v_id]);
        }
        logAction($pdo, $_SESSION['user_id'], "Rejected visit ID: $v_id");

        // WhatsApp Notification to Visitor
        require_once '../includes/whatsapp_helper.php';
        $vStmt = $pdo->prepare("SELECT v.visit_code, vis.name as visitor_name, vis.mobile FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.id = ?");
        $vStmt->execute([$v_id]);
        $visitor = $vStmt->fetch(PDO::FETCH_ASSOC);
        if ($visitor && !empty($visitor['mobile'])) {
            sendWhatsAppNotification(
                $visitor['mobile'],
                "Your visit request has been rejected.",
                'visit_rejection_visitor_notify',
                ["*{$visitor['visitor_name']}*", "*{$reason}*"]
            );
        }

        echo json_encode(['success' => true]);
    } elseif ($act == 'cancel_invite') {
        if ($host_employee_id || $is_admin) {
            // Fetch visitor details before canceling for notification
            $sql = "SELECT vis.name, vis.mobile, e.name as host_name 
                    FROM visits v 
                    JOIN visitors vis ON v.visitor_id = vis.id
                    LEFT JOIN employees e ON v.employee_id = e.id
                    WHERE v.id = ?";
            if (!$is_admin)
                $sql .= " AND v.employee_id = ?";

            $stmt = $pdo->prepare($sql);
            if (!$is_admin) {
                $stmt->execute([$v_id, $host_employee_id]);
            } else {
                $stmt->execute([$v_id]);
            }
            $visitor_info = $stmt->fetch();

            $sql = "UPDATE visits SET status='rejected', approval_status='rejected', approved_at=NOW() WHERE id=? AND is_invited=1";
            if (!$is_admin)
                $sql .= " AND employee_id=?";

            $stmt = $pdo->prepare($sql);
            if (!$is_admin) {
                $stmt->execute([$v_id, $host_employee_id]);
            } else {
                $stmt->execute([$v_id]);
            }
            logAction($pdo, $_SESSION['user_id'], "Canceled invitation ID: $v_id");

            $message = 'The invitation has been rejected.';
            $waResponse = true; // Default

            // --- WhatsApp: invite_cancelled template ---
            // Header: "Meeting Cancelled"
            // Body: Hello {{1}}, your scheduled meeting with {{2}} has been cancelled.
            if (!empty($visitor_info['mobile'])) {
                try {
                    require_once '../includes/whatsapp_helper.php';
                    $visitorName = $visitor_info['name'] ?? ($_GET['visitor_name'] ?? 'Visitor');
                    $hostName = $visitor_info['host_name'] ?? ($_GET['host_name'] ?? 'your host');

                    $waResponse = sendWhatsAppNotification(
                        $visitor_info['mobile'],
                        "Your meeting has been cancelled.",
                        'invite_cancelled',
                        [$visitorName, $hostName],
                        null,
                        null
                    );
                } catch (Throwable $waErr) {
                    error_log("invite_cancelled WA error: " . $waErr->getMessage());
                    $waResponse = false;
                }
            }

            if ($waResponse === 'skipped_disabled') {
                $message .= ' (WhatsApp Disabled in Settings)';
            } else if ($waResponse === 'skipped_not_live') {
                $message .= ' (WhatsApp API not configured)';
            } else if ($waResponse === true && !empty($visitor_info['mobile'])) {
                $message = 'The invitation has been rejected and visitor notified via WhatsApp.';
            }

            echo json_encode([
                'success' => true,
                'skipped' => ($waResponse === 'skipped_disabled' || $waResponse === 'skipped_not_live'),
                'visitor_name' => $visitor_info['name'] ?? 'Visitor',
                'visitor_mobile' => $visitor_info['mobile'] ?? '',
                'message' => $message
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        }
    }
    exit;
}

require_once 'header.php';

// Get pending approvals
// Admin role should see all pending visits, regardless of host_employee_id
$is_admin = ($_SESSION['role'] === 'admin');

if ($host_employee_id && !$is_admin) {
    $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name
                           FROM visits v 
                           JOIN visitors vis ON v.visitor_id = vis.id 
                           JOIN employees e ON v.employee_id = e.id
                           WHERE v.employee_id = ? AND v.approval_status = 'pending' 
                           ORDER BY v.created_at DESC");
    $stmt->execute([$host_employee_id]);
} else {
    // Security user or Admin - show all pending
    $stmt = $pdo->prepare("SELECT v.*, vis.name as visitor_name, vis.mobile, vis.photo_path, e.name as host_name
                           FROM visits v 
                           JOIN visitors vis ON v.visitor_id = vis.id 
                           JOIN employees e ON v.employee_id = e.id
                           WHERE v.approval_status = 'pending' 
                           ORDER BY v.created_at DESC");
    $stmt->execute();
}
$pending = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="fw-bold mb-0">Pending Approvals</h3>
    <span class="badge bg-light text-success border px-2 py-1" style="font-size:0.6rem">REAL-TIME SYNC ACTIVE</span>
</div>

<div id="pending-container" class="row">
    <?php if (empty($pending)): ?>
        <div class="col-12 text-center py-5">
            <i class="bi bi-check2-circle display-1 text-light"></i>
            <h4 class="text-muted mt-3">All caught up!</h4>
            <p class="text-muted">No pending visitor requests at the moment.</p>
        </div>
        <?php
    else:
        foreach ($pending as $v): ?>
            <div class="col-md-6 mb-4" id="card-<?php echo $v['id']; ?>">
                <div class="card shadow-sm border-warning rounded-4 h-100 overflow-hidden">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center mb-3">
                            <img src="../<?php echo $v['visit_photo'] ?: 'assets/img/visitor-icon.png'; ?>"
                                class="rounded-circle border border-3 border-warning shadow-sm me-3" width="80" height="80"
                                style="object-fit:cover" onerror="this.src='../assets/img/visitor-icon.png';">
                            <div>
                                <h5 class="fw-bold mb-0"><?php echo htmlspecialchars($v['visitor_name']); ?></h5>
                                <p class="text-muted mb-0"><?php echo $v['mobile']; ?></p>
                                <small class="text-warning fw-bold text-uppercase" style="font-size:0.65rem">Awaiting
                                    Approval</small>
                                <div class="tpl-code-hidden d-none"><?php echo $v['visit_code']; ?></div>
                            </div>
                        </div>
                        <div class="alert alert-light bg-light border-0 small py-2 mb-2">
                            <strong>Purpose:</strong> <?php echo htmlspecialchars($v['purpose']); ?>
                        </div>
                        <?php if (!empty($v['assets_carried'])): ?>
                            <div class="alert alert-warning bg-warning bg-opacity-10 border-warning border-opacity-25 small py-2">
                                <i class="bi bi-laptop me-1"></i> <strong>Assets:</strong>
                                <?php echo htmlspecialchars($v['assets_carried']); ?>
                            </div>
                            <?php
                        endif; ?>
                        <div id="actions-<?php echo $v['id']; ?>" class="d-grid gap-2 d-md-flex mt-3">
                            <button onclick="handleApprove(<?php echo $v['id']; ?>)"
                                class="btn btn-success flex-grow-1 rounded-pill fw-bold btn-approve">
                                <i class="bi bi-check-circle me-1"></i> Approve
                            </button>
                            <button
                                onclick="openRejectDialog(<?php echo $v['id']; ?>, '<?php echo addslashes($v['visitor_name']); ?>')"
                                class="btn btn-outline-danger flex-grow-1 rounded-pill btn-reject">
                                <i class="bi bi-x-circle me-1"></i> Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php
        endforeach;
    endif; ?>
</div>

<script>
    if (typeof BASE_URL === 'undefined') {
        var BASE_URL = '<?php echo BASE_URL; ?>';
    }

    // Use the global approveAndPrepareShare from notifications.js but adapt for list context
    async function handleApprove(vId) {
        // Find the visitor data from some source or trigger the unified alert
        // For simple list approval, we can just trigger the unified alert for this visitor
        try {
            const apiPath = (typeof BASE_URL !== 'undefined') ? BASE_URL + 'host/api/get_dashboard_data.php' : 'api/get_dashboard_data.php';
            const response = await fetch(apiPath);
            const data = await response.json();
            if (data.success && data.pending_list) {
                const visitor = data.pending_list.find(v => v.id == vId);
                if (visitor) {
                    if (window.triggerNewVisitorAlert) {
                        window.triggerNewVisitorAlert(visitor);
                    }
                }
            }
        } catch (e) { console.error("Sync error", e); }
    }

    async function openRejectDialog(vId, name) {
        // Use Global AppDialog for native UI
        const result = await AppDialog.show({
            title: 'Reject Visitor?',
            text: `Please provide a reason for rejecting ${name}:`,
            input: 'text',
            inputPlaceholder: 'Reason for rejection...',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Reject',
            inputValidator: (value) => {
                if (!value) return 'You need to provide a reason!';
            }
        });

        if (result.isConfirmed) {
            processReject(vId, result.value);
        }
    }

    async function processReject(vId, reason) {
        try {
            const url = `pending_approvals.php?ajax_action=1&v_id=${vId}&act=reject&reason=${encodeURIComponent(reason)}`;
            const response = await fetch(url);
            if (response.ok) {
                const card = document.getElementById('card-' + vId);
                if (card) {
                    card.classList.add('animate__animated', 'animate__zoomOut');
                    setTimeout(() => {
                        card.remove();
                        if (document.querySelectorAll('[id^="card-"]').length === 0) window.location.reload();
                    }, 500);
                }
                Swal.fire('Rejected', 'Visitor has been rejected.', 'success');
            }
        } catch (e) { Swal.fire('Error', "Operation failed", 'error'); }
    }

    async function syncList() {
        try {
            const response = await fetch('api/get_dashboard_data.php');
            const data = await response.json();
            if (data.success) {
                const localCards = document.querySelectorAll('[id^="card-"]').length;
                if (data.pending_count != localCards) {
                    // Slight delay to allow animations if any
                    setTimeout(() => {
                        // Only reload if we are not in the middle of a share
                        if (!document.getElementById('newVisitorModal').classList.contains('show')) {
                            window.location.reload();
                        }
                    }, 3000);
                }
            }
        } catch (e) { }
    }
    setInterval(syncList, 10000);
</script>

<?php require_once 'footer.php'; ?>