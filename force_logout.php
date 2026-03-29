<?php
/**
 * Force Logout Utility
 * Clears all sessions regardless of database availability.
 * Use this if you are 'stuck' in a deleted tenant.
 */
session_start();
session_unset();
session_destroy();

// Clear cookies if they exist
if (isset($_COOKIE['vms_persist'])) {
    setcookie('vms_persist', '', time() - 3600, '/');
}

echo "<h3>Session Cleared Successfully</h3>";
echo "<p>You have been force logged out. All tenant sessions have been reset.</p>";
echo "<a href='index.php'>Back to Login Page</a>";
?>
