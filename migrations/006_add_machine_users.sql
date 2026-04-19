-- Migration 006: Add machine_users table for hardware identity persistence
-- Created at: 2026-04-19

CREATE TABLE IF NOT EXISTS machine_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(100) NOT NULL,
    person_id VARCHAR(100) NOT NULL,
    name VARCHAR(255),
    card_no VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_device_person (device_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
