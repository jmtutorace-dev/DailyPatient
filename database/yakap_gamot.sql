-- ============================================================
-- YAKAP GAMOT SYSTEM - Database Setup
-- Database: yakap_gamot
-- Import this file into phpMyAdmin to create the database,
-- all tables, and seed data including default user accounts.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `yakap_gamot` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `yakap_gamot`;

-- -----------------------------------------------------------
-- Table: physicians
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `physicians` (
    `physician_id` INT AUTO_INCREMENT PRIMARY KEY,
    `physician_name` VARCHAR(255) NOT NULL,
    `consultation_rate` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- Table: patients (optional master list)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `patients` (
    `patient_id` INT AUTO_INCREMENT PRIMARY KEY,
    `patient_name` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- Table: meds_types
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meds_types` (
    `meds_type_id` INT AUTO_INCREMENT PRIMARY KEY,
    `meds_type_name` VARCHAR(255) NOT NULL,
    `is_consultation` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- Table: daily_records
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `daily_records` (
    `record_id` INT AUTO_INCREMENT PRIMARY KEY,
    `record_date` DATE NOT NULL,
    `patient_name` VARCHAR(255) NOT NULL,
    `physician_id` INT DEFAULT NULL,
    `meds_type_id` INT DEFAULT NULL,
    `has_meds` TINYINT(1) NOT NULL DEFAULT 0,
    `has_labs` TINYINT(1) NOT NULL DEFAULT 0,
    `has_gamot_meds` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_record_date` (`record_date`),
    INDEX `idx_physician_id` (`physician_id`),
    INDEX `idx_meds_type_id` (`meds_type_id`),
    INDEX `idx_patient_name` (`patient_name`),
    FOREIGN KEY (`physician_id`) REFERENCES `physicians`(`physician_id`) ON DELETE SET NULL,
    FOREIGN KEY (`meds_type_id`) REFERENCES `meds_types`(`meds_type_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- Table: transmit_log
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transmit_log` (
    `transmit_id` INT AUTO_INCREMENT PRIMARY KEY,
    `transmit_year` INT NOT NULL,
    `transmit_month` TINYINT(1) NOT NULL,
    `pcsf` TINYINT(1) NOT NULL DEFAULT 0,
    `sap` TINYINT(1) NOT NULL DEFAULT 0,
    `transmitted_by` VARCHAR(255) DEFAULT NULL,
    `transmitted_at` TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY `unique_year_month` (`transmit_year`, `transmit_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- Table: users (authentication)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------
-- Table: staff
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `staff` (
    `staff_id` INT AUTO_INCREMENT PRIMARY KEY,
    `staff_name` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Default user accounts (passwords hashed via PHP password_hash)
-- admin / admin123 (role: admin)
-- viewer / viewer123 (role: viewer)
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`, `is_active`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin', 1),
('viewer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Viewer', 'viewer', 1);

-- Physicians
INSERT INTO `physicians` (`physician_name`, `consultation_rate`, `is_active`) VALUES
('Dr. Psyche Nebrada', 150.00, 1),
('Dr. Claire Aala', 150.00, 1),
('Dr. Archimedis Brodith', 150.00, 1),
('Dr. Rose Mag-Isa', 0.00, 1);

-- Meds Types
INSERT INTO `meds_types` (`meds_type_name`, `is_consultation`) VALUES
('FPE', 0),
('Consultation', 1),
('Follow Up', 1),
('Walk-in', 1);

-- Staff
INSERT INTO `staff` (`staff_name`, `is_active`) VALUES
('Claire Joy Tubo', 1);

-- Sample daily record (today's date)
INSERT INTO `daily_records` (`record_date`, `patient_name`, `physician_id`, `meds_type_id`, `has_meds`, `has_labs`, `has_gamot_meds`)
VALUES (CURDATE(), 'Sample Patient', 1, 2, 1, 0, 1);

-- Sample transmit log (June 2026)
INSERT INTO `transmit_log` (`transmit_year`, `transmit_month`, `pcsf`, `sap`, `transmitted_by`, `transmitted_at`)
VALUES (2026, 6, 1, 1, 'Claire Joy Tubo', NOW());
