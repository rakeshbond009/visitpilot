<?php
require_once '../../includes/db.php';
header('Content-Type: application/json');

try {
    date_default_timezone_set('Asia/Kolkata');
    $pdo->exec("SET time_zone = '+05:30'");

    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['host', 'employee', 'admin', 'security'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }

    // Get host's employee ID
    $stmt = $pdo->prepare("SELECT employee_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $host_employee_id = $stmt->fetchColumn();

    // Parameters
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = 50;
    $offset = ($page - 1) * $limit;

    // Build query
    $where_clauses = [];
    $params = [];

    // Role-based: hosts see only their visitors, admin/security see all
    if (!in_array($_SESSION['role'], ['admin', 'security']) && $host_employee_id) {
        $where_clauses[] = "v.employee_id = ?";
        $params[] = $host_employee_id;
    }

    // Date filter
    if ($start_date) {
        $where_clauses[] = "DATE(v.created_at) >= ?";
        $params[] = $start_date;
    }
    if ($end_date) {
        $where_clauses[] = "DATE(v.created_at) <= ?";
        $params[] = $end_date;
    }

    // Status filter
    if ($status) {
        if ($status === 'checked_in' || $status === 'checked_out') {
            $where_clauses[] = "v.status = ?";
            $params[] = $status;
        } elseif ($status === 'pending' || $status === 'approved' || $status === 'rejected') {
            $where_clauses[] = "v.approval_status = ?";
            $params[] = $status;
        }
    }

    // Search
    if ($search) {
        $where_clauses[] = "(vis.name LIKE ? OR vis.mobile LIKE ? OR v.visit_code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

    // Count total
    $count_sql = "SELECT COUNT(*) FROM visits v 
                  JOIN visitors vis ON v.visitor_id = vis.id 
                  LEFT JOIN employees e ON v.employee_id = e.id 
                  $where_sql";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    // Fetch visits
    $sql = "SELECT v.*, v.visit_photo, vis.name as visitor_name, vis.mobile, vis.email, 
                   vis.photo_path, e.name as host_name, e.department
            FROM visits v 
            JOIN visitors vis ON v.visitor_id = vis.id 
            LEFT JOIN employees e ON v.employee_id = e.id 
            $where_sql
            ORDER BY v.created_at DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats for the period
    $stats_params = $params;
    $stats_sql = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN v.status = 'checked_in' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN v.status = 'checked_out' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN v.approval_status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN v.approval_status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN v.approval_status = 'rejected' THEN 1 ELSE 0 END) as rejected
        FROM visits v 
        JOIN visitors vis ON v.visitor_id = vis.id 
        LEFT JOIN employees e ON v.employee_id = e.id 
        $where_sql";
    $stmt = $pdo->prepare($stats_sql);
    $stmt->execute($stats_params);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'visits' => $visits,
        'total' => $total,
        'page' => $page,
        'pages' => ceil($total / $limit),
        'stats' => [
            'total' => (int) ($stats['total'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'completed' => (int) ($stats['completed'] ?? 0),
            'pending' => (int) ($stats['pending'] ?? 0),
            'approved' => (int) ($stats['approved'] ?? 0),
            'rejected' => (int) ($stats['rejected'] ?? 0),
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>