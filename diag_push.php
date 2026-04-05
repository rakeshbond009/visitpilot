<?php
/**
 * Push Notification Diagnostics - TEMPORARY - DELETE AFTER USE
 * Access: https://yourdomain.com/visitpilot/diag_push.php?key=vmsdiag2026
 */
if (($_GET['key'] ?? '') !== 'vmsdiag2026') {
    http_response_code(403);
    die('Forbidden');
}

require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:monospace;background:#111;color:#0f0;padding:20px;} h2{color:#ff0;} table{border-collapse:collapse;width:100%;} td,th{border:1px solid #333;padding:6px 10px;} th{color:#0ff;} .err{color:#f44;} .ok{color:#4f4;} pre{background:#1a1a1a;padding:10px;overflow-x:auto;white-space:pre-wrap;word-break:break-all;}</style>';
echo '<h1>🔔 VMS Push Notification Diagnostics</h1>';
echo '<p>' . date('Y-m-d H:i:s') . ' IST</p>';

// ── 1. Log Files ──────────────────────────────────────────────────────────────
$logs = [
    'Push Debug Log'         => __DIR__ . '/includes/push_debug.log',
    'Register Push Trace'    => __DIR__ . '/api/visitor/register_push_trace.log',
    'Async Worker Log'       => __DIR__ . '/storage/logs/async.log',
    'FCM Update Debug'       => __DIR__ . '/api/user/fcm_update_debug.log',
    'DB Debug Log'           => __DIR__ . '/includes/db_debug.log',
];

echo '<h2>📄 Log Files (last 50 lines each)</h2>';
foreach ($logs as $name => $path) {
    echo "<h3>$name</h3>";
    if (!file_exists($path)) {
        echo "<p class='err'>❌ File not found: $path</p>";
        continue;
    }
    $lines = file($path);
    $last = array_slice($lines, -50);
    echo '<pre>' . htmlspecialchars(implode('', $last)) . '</pre>';
}

// ── 2. user_devices table ─────────────────────────────────────────────────────
echo '<h2>📱 user_devices Table</h2>';
try {
    $check = $pdo->query("SHOW TABLES LIKE 'user_devices'")->fetchAll();
    if (empty($check)) {
        echo "<p class='err'>❌ Table 'user_devices' does NOT exist!</p>";
    } else {
        $rows = $pdo->query("SELECT ud.id, ud.user_id, u.username, u.role, LEFT(ud.fcm_token, 40) as token_preview, LENGTH(ud.fcm_token) as token_len, ud.platform, ud.last_updated FROM user_devices ud JOIN users u ON u.id = ud.user_id ORDER BY ud.last_updated DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "<p class='err'>❌ user_devices table EXISTS but is EMPTY — tokens are not being saved!</p>";
        } else {
            echo '<table><tr>';
            foreach (array_keys($rows[0]) as $col) echo "<th>$col</th>";
            echo '</tr>';
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $val) echo '<td>' . htmlspecialchars($val ?? 'NULL') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
    }
} catch (Exception $e) {
    echo "<p class='err'>DB Error: " . htmlspecialchars($e->getMessage()) . '</p>';
}

// ── 3. users.fcm_token column (legacy) ───────────────────────────────────────
echo '<h2>👤 users.fcm_token (legacy column)</h2>';
try {
    $cols = $pdo->query("SHOW COLUMNS FROM users LIKE 'fcm_token'")->fetchAll();
    if (empty($cols)) {
        echo "<p class='err'>❌ users.fcm_token column doesn't exist</p>";
    } else {
        $rows = $pdo->query("SELECT id, username, role, LEFT(fcm_token, 40) as token_preview, LENGTH(fcm_token) as token_len FROM users WHERE fcm_token IS NOT NULL LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            echo "<p class='err'>❌ No legacy fcm_token values in users table either</p>";
        } else {
            echo '<table><tr>';
            foreach (array_keys($rows[0]) as $col) echo "<th>$col</th>";
            echo '</tr>';
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $val) echo '<td>' . htmlspecialchars($val ?? 'NULL') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }
    }
} catch (Exception $e) {
    echo "<p class='err'>DB Error: " . htmlspecialchars($e->getMessage()) . '</p>';
}

// ── 4. Firebase JSON ──────────────────────────────────────────────────────────
echo '<h2>🔑 Firebase Service Account</h2>';
$fbPath = __DIR__ . '/includes/vms-notification-c484b-firebase-adminsdk-fbsvc-b8987c9f5b.json';
if (!file_exists($fbPath)) {
    echo "<p class='err'>❌ Firebase JSON NOT FOUND at: $fbPath</p>";
} else {
    $fb = json_decode(file_get_contents($fbPath), true);
    echo "<p class='ok'>✅ Found. Project ID: <b>{$fb['project_id']}</b> | Client Email: <b>{$fb['client_email']}</b></p>";
    echo "<p>Private key starts: <b>" . substr($fb['private_key'], 0, 50) . "...</b></p>";
}

// ── 5. Test FCM Token Auth ────────────────────────────────────────────────────
echo '<h2>🔒 update_fcm.php Auth Test (Session)</h2>';
echo '<p>Session ID from Header (HTTP_X_SESSION_ID): <b>' . htmlspecialchars($_SERVER['HTTP_X_SESSION_ID'] ?? 'NOT PRESENT') . '</b></p>';
echo '<p>Current SESSION user_id: <b>' . htmlspecialchars($_SESSION['user_id'] ?? 'NOT SET') . '</b></p>';
echo '<p>Tenant Key: <b>' . htmlspecialchars($_SESSION['tenant_key'] ?? 'NOT SET') . '</b></p>';

// ── 6. Jobs folder ────────────────────────────────────────────────────────────
echo '<h2>📂 Background Jobs Queue</h2>';
$jobDir = __DIR__ . '/storage/jobs/';
if (!is_dir($jobDir)) {
    echo "<p class='err'>❌ Jobs directory missing: $jobDir</p>";
} else {
    $files = glob($jobDir . '*.json');
    if (empty($files)) {
        echo "<p class='ok'>✅ No pending jobs (queue is clear)</p>";
    } else {
        echo '<p>Pending jobs: ' . count($files) . '</p>';
        foreach (array_slice($files, -5) as $f) {
            echo '<pre>' . htmlspecialchars(file_get_contents($f)) . '</pre>';
        }
    }
}

// ── 7. FCM OAuth Token Test ───────────────────────────────────────────────────
echo '<h2>🌐 Live FCM OAuth Token Test</h2>';
try {
    require_once __DIR__ . '/includes/push_helper.php';
    $fbData = json_decode(file_get_contents($fbPath), true);
    $tok = getGoogleAccessToken($fbData);
    if ($tok) {
        echo "<p class='ok'>✅ Google OAuth token obtained successfully (" . strlen($tok) . " chars)</p>";
        echo "<p>Token preview: " . substr($tok, 0, 30) . "...</p>";
    } else {
        echo "<p class='err'>❌ Failed to get Google OAuth token! Check private key or internet connectivity.</p>";
    }
} catch (Exception $e) {
    echo "<p class='err'>Exception: " . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<hr><p style="color:#555">⚠️ Delete diag_push.php after debugging is complete.</p>';
