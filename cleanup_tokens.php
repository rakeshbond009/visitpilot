<?php
/**
 * One-time cleanup: stale FCM tokens + stuck background jobs
 * Access: https://yourdomain.com/cleanup_tokens.php?key=vmsclean2026
 * DELETE AFTER USE.
 */
if (($_GET['key'] ?? '') !== 'vmsclean2026') { http_response_code(403); die('Forbidden'); }
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/html; charset=utf-8');
echo '<style>body{font-family:monospace;background:#111;color:#0f0;padding:20px;} .ok{color:#4f4;} .err{color:#f44;} .warn{color:#fa0;}</style>';
echo '<h1>🧹 VMS Token Cleanup</h1>';

// ── 1. Delete ALL tokens except the most recent per user ─────────────────────
echo '<h2>Step 1: Remove duplicate/stale tokens per user</h2>';
try {
    // Keep only the latest token per user_id
    $result = $pdo->exec("
        DELETE ud FROM user_devices ud
        LEFT JOIN (
            SELECT MAX(id) as max_id, user_id
            FROM user_devices
            GROUP BY user_id
        ) keeper ON ud.id = keeper.max_id AND ud.user_id = keeper.user_id
        WHERE keeper.max_id IS NULL
    ");
    echo "<p class='ok'>✅ Deleted $result duplicate/old token rows</p>";
} catch (Exception $e) {
    echo "<p class='err'>❌ Error: " . htmlspecialchars($e->getMessage()) . '</p>';
}

// ── 2. Show what's left in user_devices ──────────────────────────────────────
echo '<h2>Step 2: Remaining tokens (1 per user)</h2>';
try {
    $rows = $pdo->query("
        SELECT ud.id, ud.user_id, u.username, u.role, 
               LEFT(ud.fcm_token,40) as token_preview, 
               LENGTH(ud.fcm_token) as len,
               ud.last_updated
        FROM user_devices ud JOIN users u ON u.id = ud.user_id
        ORDER BY ud.user_id
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo '<table border=1 cellpadding=5><tr><th>id</th><th>user_id</th><th>username</th><th>role</th><th>token</th><th>len</th><th>last_updated</th></tr>';
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td>{$r['user_id']}</td><td>{$r['username']}</td><td>{$r['role']}</td><td>{$r['token_preview']}...</td><td>{$r['len']}</td><td>{$r['last_updated']}</td></tr>";
    }
    echo '</table>';
} catch (Exception $e) {
    echo "<p class='err'>❌ " . htmlspecialchars($e->getMessage()) . '</p>';
}

// ── 3. Clear stuck background jobs ───────────────────────────────────────────
echo '<h2>Step 3: Clear stuck background jobs</h2>';
$jobDir = __DIR__ . '/storage/jobs/';
if (!is_dir($jobDir)) {
    echo "<p class='warn'>⚠️ No jobs directory found</p>";
} else {
    $files = glob($jobDir . '*.json');
    $deleted = 0;
    foreach ($files as $f) {
        if (@unlink($f)) $deleted++;
    }
    echo "<p class='ok'>✅ Deleted $deleted stuck job files</p>";
}

// ── 4. Test push to admin right now ──────────────────────────────────────────
echo '<h2>Step 4: Live push test to admin (employee_id=1)</h2>';
try {
    require_once __DIR__ . '/includes/push_helper.php';
    $result = sendPushNotification($pdo, 1, 'Test Notification', 'Push system is working!', [
        'visit_id' => '0',
        'visitor_name' => 'System Test',
        'visitor_mobile' => '',
        'visitor_photo' => '',
        'company' => 'VMS',
        'purpose' => 'Test',
        'assets_carried' => '',
    ]);
    if ($result) {
        echo "<p class='ok'>✅ Push dispatched! Check push_debug.log for HTTP response.</p>";
    } else {
        echo "<p class='err'>❌ Push returned false — no tokens found or error</p>";
    }
} catch (Exception $e) {
    echo "<p class='err'>❌ " . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '<hr><h2>Done! Check push_debug.log to see if the push got HTTP 200.</h2>';
echo '<p style="color:#555">Delete this file after use.</p>';
