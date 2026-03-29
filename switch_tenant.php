<?php
/**
 * Tenant Quick Login Handler
 * Allows Super Admins to quickly switch to a tenant's context
 */
require_once 'includes/db.php';

// Only Super Admins can use this
if (!isset($_SESSION['is_super']) || !$_SESSION['is_super']) {
    die("Access Denied: Super Admin only");
}

// Get the tenant key from URL
$target_tenant = $_GET['tenant'] ?? '';

if (empty($target_tenant)) {
    die("Error: No tenant specified");
}

// Verify tenant exists and is active
try {
    $stmt = $master_pdo->prepare("SELECT * FROM tenants WHERE tenant_key = ? AND status = 'active'");
    $stmt->execute([$target_tenant]);
    $tenant_info = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant_info) {
        die("Error: Tenant '$target_tenant' not found or inactive");
    }
}
catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Redirect to the tenant's login page with tenant parameter
// This keeps the admin's session intact in the original window
header("Location: tenant_login.php?tenant=" . urlencode($target_tenant));
exit;
?>