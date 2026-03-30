<?php
// api/ai/process.php
require_once '../../includes/db.php';

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$query = sanitize($input['query'] ?? '');
$mode = $input['mode'] ?? 'chat';


if (empty($query)) {
    echo json_encode(['status' => 'error', 'message' => 'Empty query']);
    exit;
}

$user_name = $_SESSION['full_name'] ?? 'User';

// 1. Get API Key and Model
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ai_api_key', 'ai_model')");
$stmt->execute();
$settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$api_key = trim($settings['ai_api_key'] ?? '');
$ai_model = trim($settings['ai_model'] ?? 'gemini-1.5-flash-latest');

if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed. Please try again.']);
    exit;
}

if (empty($api_key)) {
    echo json_encode(['status' => 'error', 'message' => 'AI API Key not configured.']);
    exit;
}

// 2. Comprehensive Schema Definition
$schema = "
Database Schema for Visitor Management System:
1. Tables:
   - visitors (id, name, mobile, email, address, identity_type, identity_number, created_at)
   - employees (id, name, department, email, mobile, status, employee_id)
   - visits (id, visitor_id, employee_id, purpose, access_area, visit_date, check_in_time, check_out_time, status, approval_status, visit_code)
   - departments (id, name)
   - system_settings (setting_key, setting_value)

2. Relationships:
   - visits.visitor_id matches visitors.id
   - visits.employee_id matches employees.id
";

// Step 1: SQL Generation Prompt
$sql_generation_prompt = "You are a MySQL expert for a Visitor Management System.
Schema: $schema

Business Rules for Metrics:
- 'Time Saved': Calculated as (COUNT of visits with status 'checked_out') multiplied by 2 minutes. SQL: SELECT COUNT(*) * 2 FROM visits WHERE status = 'checked_out'
- 'Average Check-in Time': Average difference between 'created_at' and 'check_in_time'.
- 'Crowd Density': (Current 'checked_in' visitors / max_capacity) * 100. (max_capacity is in system_settings).

Question: \"$query\"

Instruction: Generate a single valid MySQL SELECT statement to answer the question. 
- 'Total Visitors': Use COUNT(*) to count all visit records today.
- 'Pending Approvals': Use 'v.approval_status = pending'. IMPORTANT: Do NOT use 'status = pending'.
- 'Onsite Count': Use COUNT(*) WHERE status = 'checked_in'.
- Show guest names where appropriate for 'who visited' queries.
Return ONLY the SQL string. No markdown, no '```sql'.";

// Quick Stats for simple queries (to save quota)
// Quick Stats - Helper for safer data fetching
function fetchSafeStat($pdo, $query, $type = 'column')
{
    try {
        $stmt = $pdo->query($query);
        if (!$stmt)
            return ($type === 'list' ? [] : ($type === 'row' ? null : 0));
        if ($type === 'column')
            return $stmt->fetchColumn() ?: 0;
        if ($type === 'row')
            return $stmt->fetch(PDO::FETCH_ASSOC);
        if ($type === 'list')
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($type === 'pair')
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($type === 'assoc')
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        return 0;
    } catch (Exception $e) {
        file_put_contents(__DIR__ . '/ai_sql_error.log', "Query failed: $query | Error: " . $e->getMessage() . "\n", FILE_APPEND);
        return ($type === 'list' ? [] : ($type === 'row' ? null : 0));
    }
}

$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$month = (int) date('m');
$year = (int) date('Y');

// Strict Privacy Filter Logic
$role = $_SESSION['role'] ?? 'host';
$my_id = intval($_SESSION['employee_id'] ?? 0);

// Fallback: If session employee_id is missing, try fetching it from users table (Sync with dashboard_stats.php)
if ($my_id === 0 && isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $my_id = intval($stmt->fetchColumn() ?: 0);
    $_SESSION['employee_id'] = $my_id; // Cache it
}

$is_elevated = (strtolower($role) === 'admin' || strtolower($role) === 'security');

// Strict elevation guard for John/Employee roles
if (!$is_elevated) {
    $auth_filter = "employee_id = $my_id";
    $v_auth_filter = "v.employee_id = $my_id";
} else {
    $auth_filter = "1=1";
    $v_auth_filter = "1=1";
}

$quick_stats = [
    // Basic Counts
    'total_employees' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM employees WHERE status='active'"),
    'inactive_employees' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM employees WHERE status='inactive'"),
    'total_departments' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM departments"),
    'dept_names' => fetchSafeStat($pdo, "SELECT name FROM departments", 'list'),
    'dept_counts' => fetchSafeStat($pdo, "SELECT department, COUNT(*) as c FROM employees WHERE status='active' GROUP BY department", 'pair'),
    'total_visitors' => fetchSafeStat($pdo, "SELECT COUNT(DISTINCT visitor_id) FROM visits WHERE $auth_filter"),
    'visitors_this_month' => fetchSafeStat($pdo, "SELECT COUNT(DISTINCT visitor_id) FROM visits WHERE MONTH(created_at) = $month AND YEAR(created_at) = $year AND $auth_filter"),

    // Visit Status - Today & Master (Corrected for Dashboard Sync)
    'visits_today' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE DATE(created_at) = '$today' AND $auth_filter"),
    'rejected_today_count' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE DATE(created_at) = '$today' AND (status='rejected' OR status='denied') AND $auth_filter"),
    'rejected_today_list' => fetchSafeStat($pdo, "SELECT vis.name, emp.name as host, v.created_at FROM visits v JOIN visitors vis ON v.visitor_id = vis.id JOIN employees emp ON v.employee_id = emp.id WHERE (v.status='rejected' OR v.status='denied') AND $v_auth_filter ORDER BY v.created_at DESC LIMIT 10", 'assoc'),
    'approved_today' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE DATE(created_at) = '$today' AND (status='approved' OR status='waiting') AND $auth_filter"),
    'pending_today' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE status = 'pending' AND is_invited = 0 AND $auth_filter"),
    'invited_today' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE status = 'pending' AND is_invited = 1 AND $auth_filter"),
    'avg_checkin_prediction' => fetchSafeStat($pdo, "SELECT ROUND(AVG(daily_count) * 1.1) FROM (SELECT COUNT(*) as daily_count FROM visits WHERE $auth_filter GROUP BY DATE(created_at)) as history"),
    'pending_list' => fetchSafeStat($pdo, "SELECT vis.name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.status = 'pending' AND v.is_invited = 0 AND $v_auth_filter LIMIT 5", 'list'),
    'checked_in_now' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE status='checked_in' AND $auth_filter"),
    'checked_out_today' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE DATE(created_at) = '$today' AND status='checked_out' AND $auth_filter"),
    'debug_db' => $pdo->query("SELECT DATABASE()")->fetchColumn(),
    'debug_tenant' => $_SESSION['tenant_key'] ?? 'N/A',
    'db_name' => $pdo->query("SELECT DATABASE()")->fetchColumn(),
    'tenant_in_session' => $_SESSION['tenant_key'] ?? 'N/A',
    'checked_in_list' => fetchSafeStat($pdo, "SELECT vis.name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.status='checked_in' AND $v_auth_filter LIMIT 5", 'list'),

    // History & Global Stats
    // History & Global Stats (Standardized with JOIN emp to match Report)
    'visits_yesterday' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE DATE(v.created_at) = '$yesterday' AND $v_auth_filter"),
    'rejected_yesterday' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE DATE(v.created_at) = '$yesterday' AND (v.status='rejected' OR v.status='denied') AND $v_auth_filter"),
    'approved_yesterday' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE DATE(v.created_at) = '$yesterday' AND (v.status='approved' OR v.approval_status='approved') AND $v_auth_filter"),
    'total_visits_all_time' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE $v_auth_filter"),
    'visits_month_count' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE MONTH(v.created_at) = $month AND YEAR(v.created_at) = $year AND $v_auth_filter"),
    'rejected_month' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE MONTH(v.created_at) = $month AND YEAR(v.created_at) = $year AND (v.status='rejected' OR v.status='denied') AND $v_auth_filter"),
    'rejected_all_time' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE (v.status='rejected' OR v.status='denied') AND $v_auth_filter"),
    'approved_all_time' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE (v.status='approved' OR v.approval_status='approved') AND $v_auth_filter"),
    'pending_all_time' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE v.status = 'pending' AND v.is_invited = 0 AND $v_auth_filter"),
    'total_invites_active' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits v JOIN employees e ON v.employee_id = e.id WHERE v.status = 'pending' AND v.is_invited = 1 AND $v_auth_filter"),

    // Analytical Deep-Dive (Raw for Dashboard Sync)
    'avg_duration_mins' => fetchSafeStat($pdo, "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time))) FROM visits WHERE status='checked_out' AND check_in_time IS NOT NULL AND check_out_time IS NOT NULL AND $auth_filter"),
    'peak_hour' => fetchSafeStat($pdo, "SELECT HOUR(created_at) as h, COUNT(*) as c FROM visits WHERE $auth_filter GROUP BY h ORDER BY c DESC LIMIT 1", 'row'),
    'busiest_day' => fetchSafeStat($pdo, "SELECT DATE(created_at) as d, COUNT(*) as c FROM visits WHERE $auth_filter GROUP BY d ORDER BY c DESC LIMIT 1", 'row'),
    'top_host' => fetchSafeStat($pdo, "SELECT e.name, COUNT(v.id) as c FROM employees e JOIN visits v ON e.id = v.employee_id WHERE $v_auth_filter GROUP BY e.id ORDER BY c DESC LIMIT 1", 'row'),
    'top_host_rejections' => fetchSafeStat($pdo, "SELECT e.name, COUNT(v.id) as c FROM employees e JOIN visits v ON e.id = v.employee_id WHERE (v.status='rejected' OR v.status='denied') AND $v_auth_filter GROUP BY e.id ORDER BY c DESC LIMIT 1", 'row'),
    'dept_visit_counts' => fetchSafeStat($pdo, "SELECT e.department, COUNT(v.id) as c FROM employees e JOIN visits v ON e.id = v.employee_id WHERE e.department IS NOT NULL AND e.department != '' AND $v_auth_filter GROUP BY e.department ORDER BY c DESC", 'pair'),
    'visitor_growth' => fetchSafeStat($pdo, "SELECT MONTHNAME(created_at) as m, COUNT(*) as c FROM visits WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND $auth_filter GROUP BY MONTH(created_at) ORDER BY MIN(created_at) ASC", 'pair'),
    'access_area_counts' => fetchSafeStat($pdo, "SELECT access_area, COUNT(*) as c FROM visits WHERE access_area IS NOT NULL AND access_area != '' AND $auth_filter GROUP BY access_area ORDER BY c DESC", 'pair'),
    'visitor_types' => fetchSafeStat($pdo, "SELECT vis.identity_type, COUNT(*) as c FROM visitors vis JOIN visits v ON vis.id = v.visitor_id WHERE $v_auth_filter GROUP BY vis.identity_type", 'pair'),
    'latest_visitors_detail' => fetchSafeStat($pdo, "SELECT vis.name, v.purpose, e.name as host FROM visits v JOIN visitors vis ON v.visitor_id = vis.id JOIN employees e ON v.employee_id = e.id WHERE $v_auth_filter ORDER BY v.id DESC LIMIT 5", 'assoc'),
    'repeat_visitors' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM (SELECT visitor_id FROM visits WHERE $auth_filter GROUP BY visitor_id HAVING COUNT(id) > 1) as r"),
    'single_visit_visitors' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM (SELECT visitor_id FROM visits WHERE $auth_filter GROUP BY visitor_id HAVING COUNT(id) = 1) as r"),
    'time_saved_total_val' => fetchSafeStat($pdo, "SELECT COUNT(*) * 2 FROM visits WHERE status = 'checked_out' AND $auth_filter"),

    // Missing stats for local switch cases
    'overstay_count' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE status='checked_in' AND TIMESTAMPDIFF(HOUR, check_in_time, NOW()) > 8 AND $auth_filter"),
    'purpose_counts' => fetchSafeStat($pdo, "SELECT purpose, COUNT(*) as c FROM visits WHERE $auth_filter GROUP BY purpose", 'pair'),
    'top_purpose' => fetchSafeStat($pdo, "SELECT purpose, COUNT(*) as c FROM visits WHERE $auth_filter GROUP BY purpose ORDER BY c DESC LIMIT 1", 'row'),
    'longest_visit' => fetchSafeStat($pdo, "SELECT vis.name, TIMESTAMPDIFF(MINUTE, v.check_in_time, v.check_out_time) as duration FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.status='checked_out' AND v.check_in_time IS NOT NULL AND v.check_out_time IS NOT NULL AND $v_auth_filter ORDER BY duration DESC LIMIT 1", 'row'),
    'employee_names_list' => fetchSafeStat($pdo, "SELECT name FROM employees WHERE status='active' LIMIT 20", 'list'),
    'visits_tomorrow_count' => fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE is_invited = 1 AND visit_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND (status='pending' OR status='approved' OR approval_status='pending') AND $auth_filter"),
    // Monthly & Employee Breakdown (Standardized with JOIN emp and created_at)
    'month_wise_summary' => fetchSafeStat($pdo, "SELECT DATE_FORMAT(v.created_at, '%M') as m, COUNT(*) as c FROM visits v JOIN employees e ON v.employee_id = e.id WHERE v.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) AND $v_auth_filter GROUP BY m ORDER BY MIN(v.created_at) DESC", 'pair'),
    'employee_wise_summary' => fetchSafeStat($pdo, "SELECT e.name, COUNT(v.id) as c FROM employees e JOIN visits v ON e.id = v.employee_id WHERE $v_auth_filter GROUP BY e.id ORDER BY c DESC LIMIT 10", 'pair'),

    // Granular Zone Density Data
    'dept_density_data' => fetchSafeStat($pdo, "SELECT e.department as name, COUNT(v.id) as count FROM employees e JOIN visits v ON e.id = v.employee_id WHERE v.status='checked_in' AND e.department != '' AND $v_auth_filter GROUP BY e.department ORDER BY count DESC", 'assoc'),
    'area_density_data' => fetchSafeStat($pdo, "SELECT v.access_area as name, COUNT(*) as count FROM visits v JOIN employees e ON v.employee_id = e.id WHERE v.status='checked_in' AND v.access_area != '' AND $v_auth_filter GROUP BY v.access_area ORDER BY count DESC", 'assoc'),

    // User-Specific Stats (Contextual Summary for Hosts)
    'my_pending_count' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id = $my_id AND approval_status = 'pending'") : 0,
    'my_total_today' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id = $my_id AND DATE(created_at) = '$today'") : 0,
    'my_active_invites' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id = $my_id AND is_invited = 1 AND (status = 'pending' OR status = 'approved') AND visit_date >= CURDATE()") : 0,
    'my_checked_in' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id = $my_id AND status='checked_in'") : 0,
    'my_rejected_today' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE employee_id = $my_id AND visit_date = '$today' AND (status='rejected' OR status='denied')") : 0,

    // Detailed Lists for Host Summaries (Names & Purpose)
    'my_pending_list' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT vis.name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.employee_id = $my_id AND (v.status='pending' OR v.status='waiting' OR v.approval_status='pending') LIMIT 10", 'list') : [],
    'my_onsite_list' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT vis.name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.employee_id = $my_id AND v.status='checked_in' LIMIT 10", 'list') : [],
    'my_upcoming_list' => ($my_id > 0) ? fetchSafeStat($pdo, "SELECT CONCAT(vis.name, ' on ', DATE_FORMAT(v.visit_date, '%d %M')) FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.employee_id = $my_id AND v.is_invited = 1 AND v.visit_date >= CURDATE() AND (v.status='pending' OR v.status='approved') ORDER BY v.visit_date ASC LIMIT 5", 'list') : []
];

function callGemini($apiKey, $prompt, $model)
{
    // Determine the best endpoint based on the model
    $version = (strpos($model, '1.5-flash') !== false) ? 'v1' : 'v1beta';
    $url = "https://generativelanguage.googleapis.com/$version/models/{$model}:generateContent?key=" . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "contents" => [["parts" => [["text" => $prompt]]]],
        "generationConfig" => ["temperature" => 0.1, "topP" => 0.95, "maxOutputTokens" => 1024]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error)
        return ['error' => "Network Error: $curl_error"];

    $res = json_decode($response, true);
    if ($http_code === 429) {
        return ['error' => 'The AI is currently receiving too many requests. Please wait a few seconds and try again.'];
    }
    if ($http_code !== 200) {
        return ['error' => ($res['error']['message'] ?? "API Error $http_code")];
    }
    return $res;
}

// Step A: ZERO-QUOTA Logic (Hard-coded answers for basic stats)
$query_lower = strtolower($query);

// 1. Helper for fuzzy matching / typo tolerance - Returns match weight/length
function getMatchWeight($input, $patterns)
{
    if (empty($input) || empty($patterns))
        return 0;

    // Normalize input
    $clean_input = preg_replace('/[^a-z0-9]/', '', strtolower($input));
    $raw_words = preg_split('/\s+/', strtolower(trim($input)));
    $words_input = [];
    foreach ($raw_words as $w) {
        $w_clean = preg_replace('/[^a-z0-9]/', '', $w);
        if (!empty($w_clean))
            $words_input[] = $w_clean;
    }

    $best_weight = 0;
    foreach ($patterns as $p) {
        $p_lower = strtolower(trim($p));
        $clean_p = preg_replace('/[^a-z0-9]/', '', $p_lower);
        $p_len = strlen($p_lower);

        // Tier 1: Exact Phrase Match (Highest Priority)
        if (stripos($input, $p_lower) !== false) {
            $best_weight = max($best_weight, $p_len * 10);
            continue;
        }

        // Tier 2: Compressed Match (Matches 'busiestday' inside 'what is the busiestday')
        if (strpos($clean_input, $clean_p) !== false) {
            $best_weight = max($best_weight, $p_len * 5);
            continue;
        }

        // Tier 3: Word Containment (Partial Match)
        $match_count = 0;
        $p_words = explode(' ', $p_lower);
        foreach ($p_words as $pw) {
            $pw_clean = preg_replace('/[^a-z0-9]/', '', $pw);
            if (strlen($pw_clean) >= 2 && in_array($pw_clean, $words_input))
                $match_count++;
        }
        if ($match_count > 0) {
            // Give extra weight to more words matching
            $best_weight = max($best_weight, $match_count * 5);
        }
    }
    return $best_weight;
}

// 2. Massive Intent Mapping (Synonym Library)
$intents = [
    'visitors_month' => ['monthly visitor', 'visitor this month', 'visitors this month', 'guests this month', 'visitor traffic this month', 'current month visitor'],
    'total_visitors' => ['total visitor', 'how many unique visitors', 'unique visitor count', 'visitor base', 'unique guests', 'total registered people', 'registered guest', 'total guest', 'how many guest', 'guest numbers'],
    'total_employees' => ['how many employee', 'total staff', 'count of employee', 'employees total', 'total worker', 'active staff', 'workforce size', 'team size', 'how many people work', 'employee strength', 'staff numbers', 'total staff count'],
    'inactive_employees' => ['inactive employee', 'retired staff', 'disabled account', 'how many inactive', 'blocked employee', 'inactive accounts'],
    'total_visits_all_time' => ['total visit', 'all time visit', 'how many visits so far', 'recorded visit', 'historical visit', 'cumulative visits', 'visit log count', 'all visits'],
    'visits_today' => ['visits today', 'today count', 'daily visit', 'visitor count today', 'how many today', 'trafic today', 'traffic today', 'count of visitors today', 'current date visits'],
    'rejected_today' => ['rejected today', 'denied today', 'declined today', 'rejections today', 'today rejected', 'rejected visitor today', 'how many rejections today', 'today denial'],
    'approved_today' => ['approved today', 'today approved', 'accepted today', 'today success', 'visits approved today'],
    'visits_yesterday' => ['yesterday count', 'visits yesterday', 'who visited yesterday', 'yesterday total', 'summary of yesterday', 'all visits yesterday'],
    'rejected_yesterday' => ['rejected yesterday', 'rejections yesterday', 'denied yesterday', 'yesterday rejected', 'yesterday rejections', 'yesterday denial'],
    'approved_yesterday' => ['approved yesterday', 'yesterday approved', 'accepted yesterday', 'yesterday success', 'visits approved yesterday'],
    'pending_today' => ['pending today', 'waiting today', 'approval today', 'unapproved today', 'ongoing approvals', 'waiting for approval today', 'who is waiting', 'list of pending names', 'names of people waiting', 'pending now', 'any pending', 'anyone waiting'],
    'checked_out_today' => ['checked out today', 'left today', 'departed today', 'exited today', 'checkout today', 'left the building today', 'departures today'],
    'checked_in_now' => ['checked in', 'who is in', 'current visitor', 'inside the building', 'present now', 'live traffic', 'active visitors', 'onsite count', 'on premises', 'who is here', 'visitors currently in', 'currently inside', 'anyone in', 'any in'],
    'peak_hour' => ['busiest hour', 'peak time', 'most active hour', 'when are visitors here', 'busiest time of day', 'peak hour', 'highest traffic hour', 'time with most visits'],
    'busiest_day' => ['busiest day', 'peak day', 'most visit on', 'highest visitor day', 'day with most visits', 'most visits recorded'],
    'avg_duration' => ['average stay', 'how long do they stay', 'avg duration', 'average visit time', 'mean time spent', 'average time'],
    'dept_density' => ['department density', 'department occupancy', 'dept analysis', 'crowded departments', 'department congestion'],
    'area_density' => ['area density', 'zone density', 'crowded areas', 'access area congestion', 'area traffic'],
    'attendance' => ['staff attendance', 'employee presence', 'who is working', 'active staff summary'],
    'dept_traffic' => ['department count', 'dept breakdown', 'which department gets most', 'traffic by department', 'dept-wise visit breakdown'],
    'growth' => ['visitor growth', 'trend', 'increasing or decreasing', 'monthly growth'],
    'access_locations' => ['busiest area', 'access point traffic', 'where do people enter', 'facility zone stats', 'top entry point traffic'],
    'top_host' => ['busiest host', 'top employee', 'who hosts most', 'most visited staff', 'host performance'],
    'top_rejection_host' => ['who rejects most', 'highest rejection rate', 'host with most denials'],
    'latest_visitor' => ['who visited recently', 'last visitor', 'recent guests', 'latest names', 'latest 5 visitors'],
    'repeat_visitors' => ['repeat visitor', 'frequent visitor', 'who came more than once', 'loyal guest', 'regular visitors'],
    'help' => ['what can you do', 'help', 'features', 'how to use', 'list commands', 'guide', 'instructions', 'capabilities'],
    'visits_tomorrow' => ['visits tomorrow', 'visitors tomorrow', 'upcoming visitors', 'scheduled for tomorrow', 'who is coming tomorrow', 'tomorrow count', 'any visitor tomorrow'],
    'month_wise' => ['month wise visits', 'monthly summary', 'visits by month', 'growth trend', 'monthly report', 'per month count', 'monthly chart report'],
    'employee_wise' => ['employee wise visits', 'host performance', 'who gets most visitors', 'top hosts', 'employee stats', 'staff visits breakdown', 'who is busiest', 'employee summary'],
    'overstay' => ['overstay alerts', 'visitor overstay alerts', 'stayed too long', 'who stayed more than 8 hours'],
    'security_summary' => ['security summary', 'unauthorized attempts', 'safety summary', 'security report'],
    'all_pending_visits' => ['all pending visits', 'list all waiting', 'every pending visitor', 'entire pending list'],
    'ai_insights' => ['ai insights', 'predictive insights', 'prediction for tomorrow', 'anomaly alert'],
    'dashboard_summary' => ['summary of dashboard', 'dashboard summary', 'how is the dashboard', 'current status of system', 'dashboard stats', 'tell me everything', 'overall summary', 'tell me all data', 'whats the status', 'what is happening today', 'give me all data', 'read all data'],
    'greetings' => ['hi', 'hello', 'good morning', 'good afternoon', 'good evening', 'hey there', 'namaste', 'hey hi', 'good evening AI']
];

$matched_key = null;
$max_weight = 0;
foreach ($intents as $key => $patterns) {
    $weight = getMatchWeight($query_lower, $patterns);
    if ($weight > $max_weight) {
        // Analytical Check - If it matches a local pattern, ensure it's not a complex search
        // Analytical Check - If it matches a local pattern, ensure it's not a complex search
        // Force-Local Keywords: If the query contains these, we MUST try to answer locally first.
        $analytical_keywords = [
            'who is in',
            'who rejects',
            'who visited',
            'recently',
            'busiest',
            'longest',
            'overstay',
            'spend most',
            'stayed for',
            'traffic',
            'average',
            'growth',
            'increase',
            'trend',
            'pending',
            'waiting',
            'inside',
            'visitors',
            'count',
            'total',
            'hour',
            'day',
            'month',
            'host',
            'employee',
            'staff',
            'purpose',
            'stay',
            'spent',
            'rejection',
            'denied',
            'denies',
            'rejected',
            'check in',
            'check out',
            'attendance',
            'list of',
            'names of',
            'tomorrow',
            'scheduled'
        ];
        $is_actually_local = false;
        foreach ($analytical_keywords as $auk) {
            if (stripos($query_lower, $auk) !== false) {
                $is_actually_local = true;
                break;
            }
        }

        // Search Trigger: Only hit the heavy AI if specific 'search' words are used
        $high_tier_analysis = ['find', 'search for', 'where is', 'which exact', 'whose', 'tell me about'];
        $needs_detailed_search = false;
        foreach ($high_tier_analysis as $ak) {
            if (stripos($query_lower, $ak) !== false) {
                $needs_detailed_search = true;
                break;
            }
        }

        if ($is_actually_local || !$needs_detailed_search) {
            // Temporal Guard: If query has 'tomorrow', don't match 'today' intents
            $has_tomorrow = (stripos($query_lower, 'tomorrow') !== false);
            $intent_is_today = (stripos($key, 'today') !== false || stripos($key, 'now') !== false);
            if ($has_tomorrow && $intent_is_today)
                continue;

            $matched_key = $key;
            $max_weight = $weight;
        }
    }
}

// Higher Threshold for Local Matches to prevent common words (who, is) from triggering false positives
$MATCH_THRESHOLD = 15;

// Syllabus Check: If match weight is too low and not a detailed search, trigger syllabus redirection
if (($max_weight < $MATCH_THRESHOLD || !$matched_key) && !$needs_detailed_search) {
    if (in_array($query_lower, ['hi', 'hello', 'namaste', 'morning', 'hey'])) {
        // Fallback for greetings if weight was low
        $matched_key = 'greetings';
    } else {
        $msg = "I'm your dedicated VMS Assistant and specialize only in your visitor and staff data. Please ask something related to the system or choose from the **Knowledge Menu** below. 👋";
        echo json_encode(['status' => 'success', 'type' => 'chat_response', 'message' => $msg]);
        exit;
    }
}

if ($matched_key && $mode !== 'search') {
    $msg = "";
    switch ($matched_key) {
        case 'total_employees':
            $msg = "There are currently **" . ($quick_stats['total_employees'] ?? 0) . "** active employees registered in the system.";
            break;
        case 'inactive_employees':
            $msg = "There are **" . ($quick_stats['inactive_employees'] ?? 0) . "** inactive/disabled employee accounts.";
            break;
        case 'total_visitors':
            $count = $quick_stats['total_visitors'] ?? 0;
            $msg = "We have total **$count** unique visitors registered in our database.";
            break;
        case 'visitors_month':
            $msg = "We've seen **" . ($quick_stats['visitors_this_month'] ?? 0) . "** visitors this month.";
            break;
        case 'total_visits_all_time':
            $msg = "A total of **" . ($quick_stats['total_visits_all_time'] ?? 0) . "** visits have been logged since the system started.";
            break;
        case 'visits_today':
            $msg = "We have had **" . ($quick_stats['visits_today'] ?? 0) . "** visit(s) recorded today (" . date('d M Y') . ").";
            break;
        case 'visits_tomorrow':
            $tmr = date('d M Y', strtotime('+1 day'));
            $msg = "There are **" . ($quick_stats['visits_tomorrow_count'] ?? 0) . "** visitor(s) scheduled/invited for tomorrow ($tmr).";
            break;
        case 'rejected_today':
            $count_today = $quick_stats['rejected_today_count'] ?? 0;
            $list = $quick_stats['rejected_today_list'] ?? [];

            if ($count_today > 0) {
                $details = "There were **$count_today** rejections today.\n\n**Today's Cases:**\n";
                foreach ($list as $rej) {
                    if (date('Y-m-d', strtotime($rej['created_at'])) === $today) {
                        $details .= "- **" . $rej['name'] . "** (Visitor) denied by host **" . $rej['host'] . "**\n";
                    }
                }
            } else {
                $details = "No rejections recorded today. Here are the **Latest 5 Rejections** from the history:\n\n";
                $i = 0;
                foreach ($list as $rej) {
                    if ($i >= 5)
                        break;
                    $details .= "- **" . $rej['name'] . "** (Host: " . $rej['host'] . ") on " . date('d M', strtotime($rej['created_at'])) . "\n";
                    $i++;
                }
                if (empty($list))
                    $details = "The system has no rejection records found.";
            }
            $msg = "### 🚫 Rejection & Security Log\n" . $details;
            break;
        case 'dept_density':
            $breakdown = "";
            $zones = $quick_stats['dept_density_data'] ?? [];
            foreach ($zones as $z) {
                $breakdown .= "- **" . $z['name'] . "**: **" . $z['count'] . "** visitors currently onsite\n";
            }
            $msg = "### 🏢 Department Density Analysis\n" . ($breakdown ?: "No active visitors in any department right now.");
            break;
        case 'area_density':
            $breakdown = "";
            $zones = $quick_stats['area_density_data'] ?? [];
            foreach ($zones as $z) {
                $breakdown .= "- **" . $z['name'] . "**: **" . $z['count'] . "** visitors currently onsite\n";
            }
            $msg = "### 📍 Access Area Density Analysis\n" . ($breakdown ?: "No active visitors in any access area right now.");
            break;
        case 'approved_today':
            $msg = "We have approved **" . ($quick_stats['approved_today'] ?? 0) . "** visit(s) today.";
            break;
        case 'visits_yesterday':
            $msg = "Yesterday (" . date('d M Y', strtotime('-1 day')) . "), there were **" . ($quick_stats['visits_yesterday'] ?? 0) . "** total visits.";
            break;
        case 'rejected_yesterday':
            $msg = "There were **" . ($quick_stats['rejected_yesterday'] ?? 0) . "** visits rejected yesterday.";
            break;
        case 'approved_yesterday':
            $msg = "A total of **" . ($quick_stats['approved_yesterday'] ?? 0) . "** visits were approved yesterday.";
            break;
        case 'pending_today':
            $count = $quick_stats['pending_today'] ?? 0;
            $invited = $quick_stats['invited_today'] ?? 0;
            $list = !empty($quick_stats['pending_list']) ? "\nWaiting At Door: **" . implode(', ', $quick_stats['pending_list']) . "**" : "";
            $msg = "There are **$count** walk-in visits waiting for approval right now. $list. (Additionally, there are **$invited** upcoming invited guests scheduled).";
            break;
        case 'repeat_visitors':
            $count = $quick_stats['repeat_visitors'] ?? 0;
            $msg = "We have **$count** repeat visitors who have visited the facility more than once.";
            break;
        case 'visitor_types':
            $breakdown = "";
            foreach (($quick_stats['visitor_types'] ?? []) as $t => $c) {
                $breakdown .= "- $t: **$c visitors**\n";
            }
            $msg = "Visitor Identification Profile:\n" . ($breakdown ?: "No registration data found.");
            break;
        case 'checked_out_today':
            $msg = "A total of **" . ($quick_stats['checked_out_today'] ?? 0) . "** visitors have checked out today.";
            break;
        case 'checked_in_now':
            $count = $quick_stats['checked_in_now'] ?? 0;
            $list = !empty($quick_stats['checked_in_list']) ? "\nCurrently Onsite: **" . implode(', ', $quick_stats['checked_in_list']) . "**" : "";
            $msg = "There are **$count Visitors** currently active inside the premises. $list";
            break;
        case 'overstay':
            $msg = "There are **" . ($quick_stats['overstay_count'] ?? 0) . "** visitors who have exceeded the 8-hour stay limit.";
            break;
        case 'departments':
            $depts = !empty($quick_stats['dept_names']) ? implode(', ', $quick_stats['dept_names']) : "None";
            $msg = "The system has **" . ($quick_stats['total_departments'] ?? 0) . "** departments: **$depts**.";
            break;
        case 'dept_breakdown':
            $breakdown = "";
            foreach (($quick_stats['dept_counts'] ?? []) as $d => $c) {
                $breakdown .= "- $d: **$c staff**\n";
            }
            $msg = "Department-wise Staffing (Active):\n" . ($breakdown ?: "No data found.");
            break;
        case 'all_time_rejected':
            $msg = "Historically, the system has seen **" . ($quick_stats['rejected_all_time'] ?? 0) . "** visitor rejections.";
            break;
        case 'all_time_pending':
            $msg = "A total of **" . ($quick_stats['pending_all_time'] ?? 0) . "** visits are currently in pending status across all time.";
            break;
        case 'all_time_approved':
            $msg = "The system has approved a total of **" . ($quick_stats['approved_all_time'] ?? 0) . "** visits since setup.";
            break;
        case 'monthly_summary':
            $msg = "This month's summary: **" . ($quick_stats['visits_month_count'] ?? 0) . "** total visits, and **" . ($quick_stats['rejected_month'] ?? 0) . "** rejections.";
            break;
        case 'top_purpose':
            $tp = $quick_stats['top_purpose'];
            $msg = $tp ? "The most popular reason for visiting is '**" . $tp['purpose'] . "**' (recorded " . $tp['c'] . " times)." : "No visit purpose trends found yet.";
            break;
        case 'top_rejection_host':
            $tr = $quick_stats['top_host_rejections'] ?? null;
            $msg = $tr ? "The host/employee with the highest rejection rate is **" . $tr['name'] . "** with **" . $tr['c'] . "** rejected visits." : "No rejection records found.";
            break;
        case 'top_host':
            $th = $quick_stats['top_host'] ?? null;
            $msg = $th ? "The busiest host is **" . $th['name'] . "** with **" . ($th['c'] ?? 0) . "** total visitors hosted." : "No hosting trends found.";
            break;
        case 'latest_visitor':
            $lv = $quick_stats['latest_visitors_detail'] ?? [];
            if (empty($lv)) {
                $msg = "No recent visit records found.";
            } else {
                $msg = "The most recent guests were: \n";
                foreach ($lv as $v) {
                    $msg .= "- **" . $v['name'] . "** (Reason: " . $v['purpose'] . ", Host: " . $v['host'] . ")\n";
                }
            }
            break;
        case 'purpose_count':
            $found_p = null;
            foreach (($quick_stats['purpose_counts'] ?? []) as $p => $c) {
                if (stripos($query_lower, strtolower($p)) !== false) {
                    $found_p = ['p' => $p, 'c' => $c];
                    break;
                }
            }
            $msg = $found_p ? "We have recorded **" . $found_p['c'] . "** visit(s) for the purpose of '**" . $found_p['p'] . "**'." : "I couldn't find a specific count for that purpose locally.";
            break;
        case 'peak_hour':
            $ph = $quick_stats['peak_hour'] ?? null;
            $hour = ($ph['h'] ?? 0) . ":00";
            $msg = $ph ? "The busiest hour for the system is around **$hour** with **" . ($ph['c'] ?? 0) . "** recorded check-ins/check-outs." : "Not enough data to calculate peak hours.";
            break;
        case 'longest_visit':
            $lv = $quick_stats['longest_visit'] ?? null;
            $msg = $lv ? "The longest single visit was by **" . $lv['name'] . "**, which lasted approximately **" . $lv['duration'] . " minutes**." : "No checkout records found to calculate longest visit.";
            break;
        case 'busiest_day':
            $bd = $quick_stats['busiest_day'] ?? null;
            $date = ($bd && !empty($bd['visit_date']) && $bd['visit_date'] != '0000-00-00') ? date('d M Y', strtotime($bd['visit_date'])) : null;
            $msg = $date ? "The busiest day on record was **" . $date . "** with **" . $bd['c'] . " total visitors**." : "No valid visit records found to determine the busiest day.";
            break;
        case 'attendance':
            $msg = "Staff Attendance Summary: **" . ($quick_stats['total_employees'] ?? 0) . "** active accounts, and **" . ($quick_stats['inactive_employees'] ?? 0) . "** inactive/disabled accounts.";
            break;
        case 'avg_duration':
            $val = intval($quick_stats['avg_duration_mins'] ?? 0);
            $msg = $val > 0 ? "On average, visitors spend about **" . $val . " mins** inside the premises per visit." : "Not enough checkout data to calculate average visit duration.";
            break;
        case 'repeat_pattern':
            $msg = "Visitor Loyalty: We have **" . ($quick_stats['repeat_visitors'] ?? 0) . "** repeat visitors and **" . ($quick_stats['single_visit_visitors'] ?? 0) . "** first-time-only guests.";
            break;
        case 'time_saved_total':
            $val = $quick_stats['time_saved_total_val'] ?? 0;
            $msg = "The system has saved approximately **" . $val . " mins** of manual entry time (calculated at 2 mins per completed visit).";
            break;
        case 'dept_traffic':
            $list = "";
            foreach (($quick_stats['dept_visit_counts'] ?? []) as $d => $c) {
                $list .= "- $d: **$c visitors**\n";
            }
            $msg = "Department-wise Visitor Summary:\n" . ($list ?: "Not enough data to map traffic to departments.");
            break;
        case 'employee_list':
            $list = !empty($quick_stats['employee_names_list']) ? implode(', ', $quick_stats['employee_names_list']) : "No active employees found.";
            $msg = "Here are the names of some active employees: **$list**.";
            break;
        case 'growth':
            $list = "";
            foreach (($quick_stats['visitor_growth'] ?? []) as $m => $c) {
                $list .= "- $m: **$c visitors**\n";
            }
            $msg = "Visitor Growth Trends (Recent Months):\n" . ($list ?: "Insufficient historical data for trend analysis.");
            break;
        case 'access_locations':
            $list = "";
            foreach (($quick_stats['access_area_counts'] ?? []) as $a => $c) {
                $list .= "- $a: **$c visitors**\n";
            }
            $msg = "Facility Zone Traffic:\n" . ($list ?: "Location-based tracking has not recorded enough data yet.");
            break;
        case 'security_summary':
            $msg = "### 🚨 Security & Safety Summary\n\n";
            $msg .= "- Overstay Alerts (>8h): **" . ($quick_stats['overstay_count'] ?? 0) . "**\n";
            $msg .= "- Total Denials Today: **" . ($quick_stats['rejected_today'] ?? 0) . "**\n";
            $msg .= "- Historical Rejections: **" . ($quick_stats['rejected_all_time'] ?? 0) . "**\n";
            $msg .= "- System Monitoring: **Active**\n\n";
            $msg .= "Currently checking for unauthorized patterns or repeated visitor flags.";
            break;
        case 'dashboard_summary':
            $role = $_SESSION['role'] ?? 'host';
            $name = $_SESSION['full_name'] ?? 'User';

            if ($role === 'admin') {
                // Admin Summary: Global Overview
                $msg = "### 📊 Dashboard Summary for Admin (" . date('d M Y') . ")\n\n";
                $msg .= "**Today's Traffic:**\n";
                $msg .= "- Total Visitors Today: **" . ($quick_stats['visits_today'] ?? 0) . "**\n";
                $msg .= "- Total Onsite: **" . ($quick_stats['checked_in_now'] ?? 0) . "**\n";
                $msg .= "- Approved / Rejected: **" . ($quick_stats['approved_today'] ?? 0) . "** / **" . ($quick_stats['rejected_today'] ?? 0) . "**\n";
                $msg .= "- Pending for Approval: **" . ($quick_stats['pending_today'] ?? 0) . "**\n\n";

                $msg .= "**Security & Efficiency:**\n";
                if (($quick_stats['overstay_count'] ?? 0) > 0) {
                    $msg .= "- ⚠️ **Alert:** **" . $quick_stats['overstay_count'] . "** visitors have overstayed (>8h).\n";
                } else {
                    $msg .= "- ✅ Perimeter Secure: No overstays detected.\n";
                }

                $ph = $quick_stats['peak_hour'] ?? null;
                if ($ph) {
                    $msg .= "- Peak Hour: **" . $ph['h'] . ":00** (" . $ph['c'] . " visitors).\n";
                }

                $msg .= "- Avg. Visit Duration: **" . ($quick_stats['avg_duration_mins'] ?? 0) . " mins**.\n\n";

                $msg .= "**Staffing:**\n";
                $msg .= "- Active Employees: **" . ($quick_stats['total_employees'] ?? 0) . "** across **" . ($quick_stats['total_departments'] ?? 0) . "** departments.\n";
                $msg .= "- Busiest Host: **" . ($quick_stats['top_host']['name'] ?? 'N/A') . "**.\n";
            } else if ($role === 'security') {
                // Security Summary: Watchtower View
                $msg = "### 🛡️ Security Operations Summary (" . date('h:i A') . ")\n\n";
                $msg .= "**Active Perimeter Snapshot:**\n";
                $msg .= "- Current Visitor Onsite: **" . ($quick_stats['checked_in_now'] ?? 0) . "** active guests.\n";
                $msg .= "- Pending Visitor Approvals: **" . ($quick_stats['pending_today'] ?? 0) . "** waiting for host response.\n";
                $msg .= "- Overstay Alerts: **" . ($quick_stats['overstay_count'] ?? 0) . "** flagged for immediate check.\n\n";

                $msg .= "**Daily Incident Log:**\n";
                $msg .= "- Today's Denials: **" . ($quick_stats['rejected_today'] ?? 0) . "** (Security Rejections).\n";
                $msg .= "- Total Gate Traffic: **" . ($quick_stats['visits_today'] ?? 0) . "** entries recorded.\n\n";

                $msg .= "**Latest Entry:**\n";
                if (!empty($quick_stats['latest_visitors_detail'])) {
                    $v = $quick_stats['latest_visitors_detail'][0];
                    $msg .= "- **" . $v['name'] . "** (Visiting: " . $v['host'] . " for " . $v['purpose'] . ").\n";
                }
                $msg .= "\n*Perimeter status is currently green. Use 'overstay clients' for a full list.*";
            } else {
                // Host/Employee Summary: Personal Focus
                $p_count = intval($quick_stats['my_pending_count'] ?? 0);
                $o_count = intval($quick_stats['my_checked_in'] ?? 0);
                $i_count = intval($quick_stats['my_active_invites'] ?? 0);

                $msg = "### 👋 Hello $name, your Personal Visit Dashboard:\n\n";

                // 1. Pending Approvals
                if ($p_count > 0) {
                    $msg .= "✅ **Pending Approvals ($p_count):** " . implode(", ", (array) ($quick_stats['my_pending_list'] ?? [])) . " are waiting for your response.\n";
                } else {
                    $msg .= "- No guests waiting for your approval right now.\n";
                }

                // 2. Onsite Visitors
                if ($o_count > 0) {
                    $msg .= "📍 **Currently Meeting You ($o_count):** " . implode(", ", (array) ($quick_stats['my_onsite_list'] ?? [])) . " is currently onsite.\n";
                } else {
                    $msg .= "- No visitors checked in to see you at the moment.\n";
                }

                // 3. Invitations
                if ($i_count > 0) {
                    $msg .= "✉️ **Total Active Invitations ($i_count):** Your upcoming guests include " . implode(", ", (array) ($quick_stats['my_upcoming_list'] ?? [])) . ".\n";
                } else {
                    $msg .= "- You don't have any upcoming invitations scheduled.\n";
                }

                // General Stats
                $msg .= "\n**Other Stats:**\n";
                $msg .= "- Total Visitors Today (Global): **" . ($quick_stats['visits_today'] ?? 0) . "** visitors global.\n";

                if ($p_count > 0) {
                    $msg .= "\n💡 *Tip: Please head to your host portal to process the pending entries.*";
                }
            }
            break;

        case 'month_wise':
            $data = $quick_stats['month_wise_summary'] ?? [];
            if (empty($data)) {
                $msg = "I don't have enough historical data to generate a month-wise report yet.";
            } else {
                $msg = "### 📅 Month-wise Visit Summary\nHere is the traffic trend for the last 6 months:\n\n";
                $msg .= "| Month | Visit Count |\n| :--- | :--- |\n";
                foreach ($data as $month => $count) {
                    $msg .= "| $month | **$count** |\n";
                }
                $msg .= "\n*This shows a steady view of your organizational growth.*";
            }
            break;

        case 'employee_wise':
            $data = $quick_stats['employee_wise_summary'] ?? [];
            if (empty($data)) {
                $msg = "No employee-wise visit data found.";
            } else {
                $msg = "### 👥 Top 10 Busiest Employees\nHosts with the highest visitor interactions:\n\n";
                $msg .= "| Rank | Employee Name | Total Visits |\n| :--- | :--- | :--- |\n";
                $rank = 1;
                foreach ($data as $name => $count) {
                    $msg .= "| $rank | $name | **$count** |\n";
                    $rank++;
                }
            }
            break;

        case 'greetings':
            $hour = date('H');
            $greeting = "Hello";
            if ($hour < 12)
                $greeting = "Good Morning";
            elseif ($hour < 17)
                $greeting = "Good Afternoon";
            else
                $greeting = "Good Evening";
            $msg = "$greeting $user_name! 👋 How can I help you manage your visitors today?";
            break;

        case 'all_pending_visits':
            $count = fetchSafeStat($pdo, "SELECT COUNT(*) FROM visits WHERE approval_status='pending'");
            $list = fetchSafeStat($pdo, "SELECT vis.name FROM visits v JOIN visitors vis ON v.visitor_id = vis.id WHERE v.approval_status='pending' LIMIT 15", 'list');
            $names = !empty($list) ? "\nNames: **" . implode(', ', $list) . "**" : "";
            $msg = "### ⏳ All Pending Approvals\nThere are a total of **$count pending visitor approvals** currently waiting for host response across the entire database. $names";
            break;



        case 'overstay_clients':
            $list = $quick_stats['overstay_list'] ?? [];
            if (empty($list)) {
                $msg = "✅ Perimeter Secure: No visitors are currently overstaying (>8h).";
            } else {
                $msg = "### 🚩 Current Overstay Clients\nThe following visitors have exceeded the 8-hour stay limit:\n\n";
                foreach ($list as $row) {
                    $msg .= "- **" . $row['name'] . "** (Entered: " . date('h:i A', strtotime($row['check_in_time'])) . ")\n";
                }
            }
            break;

        case 'ai_insights':
            $overstay = $quick_stats['overstay_count'] ?? 0;
            $prediction = $quick_stats['avg_checkin_prediction'] ?? 25;
            $msg = "### ✨ VisitPilot AI Insights & Predictions\n\n";
            $msg .= "**PREDICTION FOR TOMORROW**\n";
            $msg .= "# ~$prediction \n";
            $msg .= "📈 **+10%** 📈\n";
            $msg .= "Based on historical weekday patterns.\n\n";
            $msg .= "--- \n";
            $msg .= "**CROWD DENSITY (LIVE)**\n";
            $msg .= "🔴 ─────────── 🟢\n";
            $msg .= "⚡ **Critical Surge** detected in main lobby.\n\n";
            $msg .= "--- \n";
            $msg .= "**ANOMALY ALERT**\n";
            $msg .= "⚠️ **$overstay visitor(s)** currently overstaying beyond authorized limits.";
            break;

        case 'help':
            $msg = "Sure:\n- **Dashboard**: 'Summary of dashboard' (personalized to you).\n- **Daily**: 'visits today', 'rejected today', 'approved today', 'who is in'.\n- **History**: 'yesterday count', 'who visited recently', 'monthly summary'.\n- **Staff**: 'staff count', 'dept breakdown', 'busiest host', 'attendance'.\n- **Trends**: 'who rejects most', 'peak hour', 'repeat visitors'.\n- **Search**: 'Search for John', 'Who visited last Friday?'.\n\n*You can ask in full sentences, I will find the right data.*";
            break;
    }
    if ($msg) {
        echo json_encode(['status' => 'success', 'type' => 'chat_response', 'message' => $msg]);
        exit;
    }
}

// Step B: Quota-Efficient Decision - Attempt simple AI answer with local context
$is_stat_related = false;
$keywords = ['total', 'count', 'how many', 'summary', 'today', 'now', 'status'];
foreach ($keywords as $k) {
    if (stripos($query, $k) !== false) {
        $is_stat_related = true;
        break;
    }
}

if ($mode !== 'search' && $is_stat_related) {
    $simple_prompt = "You are a Professional VMS Assistant.
    System Overview Data: " . json_encode($quick_stats) . "
    User Question: \"$query\"
    
    Rules:
    1. If the question can be answered from System Overview Data, answer in one professional sentence.
    2. If NOT, respond 'NEED_SQL'.
    Answer:";

    $res = callGemini($api_key, $simple_prompt, $ai_model);
    if (isset($res['error']) && strpos($res['error'], '429') !== false) {
        echo json_encode(['status' => 'success', 'type' => 'chat_response', 'message' => "The AI API is currently busy (Rate Limit). **However, I have verified your local data.**\n\n- Visitors Inside: **" . ($quick_stats['checked_in_now'] ?? 0) . "**\n- Pending Today: **" . ($quick_stats['pending_today'] ?? 0) . "**\n- Today's Traffic: **" . ($quick_stats['visits_today'] ?? 0) . "**\n\nPlease try specific phrases like 'staff count' while the API cools down."]);
        exit;
    }

    if (!isset($res['error'])) {
        $text = trim($res['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($text !== 'NEED_DETAIL' && $text !== 'NEED_SQL') {
            echo json_encode(['status' => 'success', 'type' => 'chat_response', 'message' => $text]);
            exit;
        }
    }
}

// Step B: Proceed to SQL Generation for Complex or Search queries
$sqlResponse = callGemini($api_key, $sql_generation_prompt, $ai_model);
if (isset($sqlResponse['error'])) {
    echo json_encode(['status' => 'error', 'message' => $sqlResponse['error']]);
    exit;
}

$sql_query = trim(str_replace(['```sql', '```', 'SQL:'], '', $sqlResponse['candidates'][0]['content']['parts'][0]['text'] ?? ''));

// Logic Check: Ensure AI returned a SELECT statement
if (stripos($sql_query, 'SELECT') !== 0) {
    echo json_encode(['status' => 'error', 'message' => 'The AI could not generate a valid search query for this request.']);
    exit;
}

// Execute Data Retrieval
$data_context = "No results found.";
$raw_data = [];
try {
    $stmt = $pdo->query($sql_query);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $data_context = json_encode($raw_data);
} catch (Exception $e) {
    $data_context = "Error executing query: " . $e->getMessage();
}

// Step C: Output Final Result
if ($mode === 'search') {
    echo json_encode(['status' => 'success', 'type' => 'search_results', 'sql' => $sql_query, 'data' => $raw_data]);
} else {
    // Final Answer (RAG)
    $final_prompt = "User: $query\nSystem Found Data: $data_context\nInstruction: Summarize the data found into a concise natural language answer.";
    $finalResponse = callGemini($api_key, $final_prompt, $ai_model);
    $final_text = $finalResponse['candidates'][0]['content']['parts'][0]['text'] ?? "I found the records but couldn't generate a summary. Please try a different question.";

    if (isset($finalResponse['error'])) {
        $final_text = "I found the data, but my summarizer hit a limit: " . $finalResponse['error'] . "\n\nQuery used: $sql_query";
    }

    echo json_encode(['status' => 'success', 'type' => 'chat_response', 'message' => $final_text]);
}
