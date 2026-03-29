<?php
// Unified Menu Structure - Include this in all headers
// This ensures consistent menu order across all pages

// Determine current folder for path resolution
$in_admin = strpos($_SERVER['PHP_SELF'], '/admin/') !== false;
$in_security = strpos($_SERVER['PHP_SELF'], '/security/') !== false;
$in_host = strpos($_SERVER['PHP_SELF'], '/host/') !== false;

if (!function_exists('menuPath')) {
    function menuPath($folder, $file)
    {
        global $in_admin, $in_security, $in_host;

        if ($folder === 'admin') {
            return $in_admin ? $file : "../admin/$file";
        } elseif ($folder === 'security') {
            return $in_security ? $file : "../security/$file";
        } elseif ($folder === 'host') {
            return $in_host ? $file : "../host/$file";
        }
        return $file;
    }
}
?>

<!-- Management Dropdown -->
<?php if (
    canView('admin_employees') || canView('admin_reports') || canView('admin_audit') || canView('admin_users') ||
    canView('settings_profile') || canView('settings_company') || canView('settings_general') || canView('settings_departments') || canView('settings_access') || canView('settings_email') || canView('settings_tenant') ||
    canView('settings_ai') || canView('settings_dahua') || canView('settings_whatsapp')
): ?>
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle <?php echo (in_array($current_page, ['employees.php', 'departments.php', 'reports.php', 'audit_logs.php', 'permissions.php', 'settings.php', 'tenants.php', 'cloud_deployment.php'])) ? 'active' : ''; ?>"
            href="#" id="mgmtDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i
                class="bi bi-grid-3x3-gap-fill me-1"></i> Management</a>
        <ul class="dropdown-menu shadow" aria-labelledby="mgmtDropdown">
            <?php if (canView('admin_employees')): ?>
                <li><a class="dropdown-item" href="<?php echo menuPath('admin', 'employees.php'); ?>"><i
                            class="bi bi-people me-2"></i> Employees</a></li>
            <?php endif; ?>

            <?php if (isset($_SESSION['is_super']) && $_SESSION['is_super']): ?>
                <li><a class="dropdown-item" href="<?php echo menuPath('admin', 'audit_logs.php'); ?>"><i
                            class="bi bi-journal-text me-2"></i> Logs</a></li>
            <?php endif; ?>

            <?php if (canView('admin_users')): ?>
                <li><a class="dropdown-item" href="<?php echo menuPath('admin', 'permissions.php'); ?>"><i
                            class="bi bi-shield-lock me-2"></i> Users</a></li>
            <?php endif; ?>

            <?php if (isset($_SESSION['is_super']) && $_SESSION['is_super'] && ($_SESSION['tenant_key'] ?? 'default') === 'default'): ?>
                <li><a class="dropdown-item" href="<?php echo menuPath('admin', 'tenants.php'); ?>"><i
                            class="bi bi-database me-2"></i> Clients / Tenants</a></li>
            <?php endif; ?>

            <?php if (canView('settings_profile') || canView('settings_company') || canView('settings_general') || canView('settings_departments') || canView('settings_access') || canView('settings_email') || canView('settings_tenant') || canView('settings_ai') || canView('settings_dahua') || canView('settings_whatsapp')): ?>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li><a class="dropdown-item" href="<?php echo menuPath('admin', 'settings.php'); ?>"><i
                            class="bi bi-sliders me-2"></i> Settings</a></li>
                <?php if (isset($_SESSION['is_super']) && $_SESSION['is_super'] && ($_SESSION['tenant_key'] ?? 'default') === 'default'): ?>
                    <li><a class="dropdown-item" href="<?php echo menuPath('admin', 'cloud_deployment.php'); ?>"><i class="bi bi-cloud-arrow-up me-2"></i> Cloud Deployment</a></li>
                <?php endif; ?>
            <?php endif; ?>
        </ul>
    </li>
<?php endif; ?>

<?php if (canView('report_issue')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'app_issues.php') ? 'active' : ''; ?>"
            href="<?php echo menuPath('admin', 'app_issues.php'); ?>"><i
                class="bi bi-exclamation-triangle me-1 text-warning"></i> Report Issue</a>
    </li>
<?php endif; ?>

<!-- Operational Links -->
<?php if (canView('security_register')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'register.php') ? 'active' : ''; ?>"
            href="<?php echo menuPath('security', 'register.php'); ?>"><i class="bi bi-person-plus me-1"></i> New
            Visitor</a>
    </li>
<?php endif; ?>

<?php if (canView('security_scan')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'scan_qr.php') ? 'active' : ''; ?>"
            href="<?php echo menuPath('security', 'scan_qr.php'); ?>"><i class="bi bi-qr-code-scan me-1"></i> Scan QR</a>
    </li>
<?php endif; ?>

<?php if (canView('security_search')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'search.php') ? 'active' : ''; ?>"
            href="<?php echo menuPath('security', 'search.php'); ?>"><i class="bi bi-search me-1"></i> Search</a>
    </li>
<?php endif; ?>

<?php if (canView('access_ai_rag_chat')): ?>
    <li class="nav-item">
        <a class="nav-link <?php echo ($current_page == 'ai_chat.php') ? 'active' : ''; ?>"
            href="<?php echo menuPath('security', 'ai_chat.php'); ?>"><i class="bi bi-robot me-1 text-info"></i> AI Chat</a>
    </li>
<?php endif; ?>

<!-- Host Dropdown -->
<?php if (canView('host_pending') || canView('host_history') || canView('host_reports') || canView('admin_reports')): ?>
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle <?php echo (in_array($current_page, ['pending_approvals.php', 'my_visitors.php', 'reports.php'])) ? 'active' : ''; ?>"
            href="#" id="visitorsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i
                class="bi bi-people-fill me-1"></i> Visitors<span class="badge rounded-pill bg-danger"
                id="pending-count-badge-top"></span></a>
        <ul class="dropdown-menu shadow" aria-labelledby="visitorsDropdown">
            <?php if (canView('host_invite')): ?>
                <li><a class="dropdown-item" href="<?php echo menuPath('host', 'invite.php'); ?>"><i
                            class="bi bi-person-plus-fill me-2 text-success"></i> Invite Visitor</a></li>
            <?php endif; ?>

            <?php if (canView('host_pending')): ?>
                <li><a class="dropdown-item d-flex justify-content-between align-items-center"
                        href="<?php echo menuPath('host', 'pending_approvals.php'); ?>"><span><i
                                class="bi bi-clock-history me-2"></i> Pending</span><span class="badge rounded-pill bg-danger"
                            id="pending-count-badge"></span></a></li>
            <?php endif; ?>

            <?php if (canView('host_history')): ?>
                <li><a class="dropdown-item" href="<?php echo menuPath('host', 'my_visitors.php'); ?>"><i
                            class="bi bi-person-badge me-2"></i> My History</a></li>
            <?php endif; ?>

            <?php if (canView('host_reports') || canView('admin_reports')): ?>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li><a class="dropdown-item" href="<?php echo menuPath('admin', 'reports.php'); ?>"><i
                            class="bi bi-bar-chart-line me-2"></i> Reports & Analytics</a></li>
            <?php endif; ?>
        </ul>
    </li>
<?php endif; ?>

<?php /* Redundant logout removed from main menu as it is present in user dropdown */ ?>