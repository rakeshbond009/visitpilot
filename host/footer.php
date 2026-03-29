</div> <!-- End Container -->

<!-- Global Notification Elements -->
<?php include_once 'includes/notification_elements.php'; ?>

<footer class="text-center mt-5 py-3 text-muted border-top bg-white">
    <div class="container d-flex flex-column align-items-center">
        <small>&copy; <?php echo date('Y'); ?> <a href="https://codepilotx.com/" target="_blank"
                class="text-decoration-none fw-bold text-primary">Codepilotx by Rakesh Verma</a>. All Rights
            Reserved.</small>
        <span class="badge bg-light text-secondary border rounded-pill mt-1" style="font-size: 0.65rem; padding: 4px 10px;">
            VisitPilot v<?php echo defined('APP_VERSION') ? APP_VERSION : '2.0.0'; ?>
        </span>
    </div>
</footer>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<?php include_once '../includes/app_dialogs.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- PDF Generation Libraries -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script src="../assets/js/notifications.js?v=2.9"></script>
</body>

</html>