-- Migration #003: Add Approval Matrix Setting
-- Created: 2026-04-19

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES 
('approval_matrix', '1');
