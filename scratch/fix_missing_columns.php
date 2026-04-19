<?php
/**
 * Migration: Add dahua_person_id to employees table if missing
 * Also verifies machine_logs table structure
 */
require_once '../includes/db.php';
header('Content-Type: application/json');

$results = [];

// Check and add dahua_person_id to employees
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN IF NOT EXISTS `dahua_person_id` VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE employees ADD INDEX IF NOT EXISTS `idx_dahua_person_id` (`dahua_person_id`)");
    $results['employees_dahua_person_id'] = 'OK - column ensured';
} catch (Exception $e) {
    // MySQL 8.0 style - try direct check
    try {
        $check = $pdo->query("SHOW COLUMNS FROM employees LIKE 'dahua_person_id'");
        if ($check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN `dahua_person_id` VARCHAR(100) DEFAULT NULL");
            $results['employees_dahua_person_id'] = 'ADDED';
        } else {
            $results['employees_dahua_person_id'] = 'ALREADY EXISTS';
        }
    } catch (Exception $e2) {
        $results['employees_dahua_person_id'] = 'ERROR: ' . $e2->getMessage();
    }
}

// Check machine_logs columns
try {
    $cols = $pdo->query("SHOW COLUMNS FROM machine_logs")->fetchAll(PDO::FETCH_COLUMN);
    $results['machine_logs_columns'] = $cols;
} catch (Exception $e) {
    $results['machine_logs_columns'] = 'ERROR: ' . $e->getMessage();
}

// Check employees columns
try {
    $cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
    $results['employees_columns'] = $cols;
} catch (Exception $e) {
    $results['employees_columns'] = 'ERROR: ' . $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
