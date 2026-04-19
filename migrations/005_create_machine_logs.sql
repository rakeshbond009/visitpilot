-- Migration #005: Create machine_logs table
-- Created: 2026-04-19

CREATE TABLE IF NOT EXISTS `machine_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `machine_id` varchar(100) DEFAULT NULL,
  `person_id` varchar(100) DEFAULT NULL,
  `person_name` varchar(100) DEFAULT NULL,
  `event_type` varchar(50) DEFAULT NULL,
  `event_time` datetime DEFAULT NULL,
  `image_path` longtext DEFAULT NULL,
  `person_type` varchar(50) DEFAULT NULL,
  `raw_payload` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `machine_id` (`machine_id`),
  KEY `person_id` (`person_id`),
  KEY `event_time` (`event_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
