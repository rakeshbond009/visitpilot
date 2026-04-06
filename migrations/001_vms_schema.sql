-- Migration #001: Complete VMS Schema
-- Created: 2026-02-08
-- Updated to match actual vms_db structure

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','host','security','employee') NOT NULL DEFAULT 'security',
  `full_name` varchar(100) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `is_super` tinyint(1) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `department` varchar(100) DEFAULT NULL,
  `permissions_locked` tinyint(1) DEFAULT 0,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `bg_mode` tinyint(1) DEFAULT 0,
  `fcm_token` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `visit_purposes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purpose_name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `department` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `visitors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `mobile` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `id_proof_type` varchar(50) DEFAULT NULL,
  `id_proof_number` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `mobile` (`mobile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `visits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visitor_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `purpose` varchar(255) NOT NULL,
  `access_area` varchar(100) DEFAULT NULL,
  `visit_date` date DEFAULT NULL,
  `check_in_time` datetime DEFAULT NULL,
  `check_out_time` datetime DEFAULT NULL,
  `checked_in_by` int(11) DEFAULT NULL,
  `checked_out_by` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','checked_in','checked_out') DEFAULT 'pending',
  `approval_status` enum('pending','approved','rejected') DEFAULT 'pending',
  `is_invited` tinyint(1) DEFAULT 0,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `visit_code` varchar(20) NOT NULL,
  `pass_type` enum('digital','physical') DEFAULT 'digital',
  `qr_code_path` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `assets_carried` text DEFAULT NULL,
  `total_visitors` int(11) DEFAULT 1,
  `id_proof_type` varchar(50) DEFAULT NULL,
  `id_proof_number` varchar(100) DEFAULT NULL,
  `visit_photo` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `visit_code` (`visit_code`),
  KEY `visitor_id` (`visitor_id`),
  KEY `employee_id` (`employee_id`),
  KEY `approved_by` (`approved_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `visits_ibfk_1` FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visits_ibfk_2` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visits_ibfk_3` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `visits_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `visits_ibfk_5` FOREIGN KEY (`checked_in_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `visits_ibfk_6` FOREIGN KEY (`checked_out_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `fcm_token` text NOT NULL,
  `platform` varchar(20) DEFAULT 'android',
  `last_updated` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `visit_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visit_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `visit_id` (`visit_id`),
  CONSTRAINT `visit_members_ibfk_1` FOREIGN KEY (`visit_id`) REFERENCES `visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `access_areas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `area_name` varchar(100) NOT NULL,
  `machine_id` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `area_name` (`area_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `permission_key` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_perm` (`user_id`,`permission_key`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role` varchar(50) NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_perm_unique` (`role`,`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `visit_otps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mobile` varchar(15) DEFAULT NULL,
  `otp` varchar(6) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `verified` tinyint(1) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `support_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `device_info` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `last_activity` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed purposes
INSERT IGNORE INTO `visit_purposes` (`purpose_name`) VALUES 
('Interview'), ('Meeting'), ('Delivery'), ('Personal'), ('Maintenance');

-- ==========================================================
-- MASTER PLATFORM TABLES (Multi-Tenant Routing & Global Controls)
-- ==========================================================

-- 1. Tenant Registry: Stores database credentials and quotas for each client.
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_key` varchar(50) NOT NULL,
  `db_host` varchar(100) NOT NULL DEFAULT 'localhost',
  `db_name` varchar(100) NOT NULL,
  `db_user` varchar(100) NOT NULL,
  `db_pass` varchar(100) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `max_devices` int(11) DEFAULT 5 COMMENT 'Mobile hardware quota set by Super Admin',
  `schema_version` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_key` (`tenant_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Global Mobile Device Registry: Enforces quotas and hardware blocking.
CREATE TABLE IF NOT EXISTS `tenant_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_key` varchar(50) NOT NULL,
  `device_id` varchar(255) NOT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `last_user_name` varchar(255) DEFAULT NULL COMMENT 'Name of the last user who logged in on this phone',
  `status` enum('active','blocked') DEFAULT 'active' COMMENT 'Blocked devices are denied login and do not count towards quota',
  `last_login` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_tenant_device` (`tenant_key`, `device_id`),
  CONSTRAINT `fk_device_tenant` FOREIGN KEY (`tenant_key`) REFERENCES `tenants` (`tenant_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Global Audit Logs: Centralized trail for all administrative actions.
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_key` varchar(50) DEFAULT 'system',
  `user_id` int(11) DEFAULT NULL,
  `performed_by` varchar(255) DEFAULT NULL COMMENT 'Human readable name/username',
  `action` text NOT NULL,
  `old_value` JSON DEFAULT NULL COMMENT 'State before change',
  `new_value` JSON DEFAULT NULL COMMENT 'State after change',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_tenant` (`tenant_key`),
  KEY `idx_audit_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

