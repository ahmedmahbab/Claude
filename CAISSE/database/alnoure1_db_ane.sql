-- Al Nour Elite - CAISSE
-- Minimal database bootstrap for `alnoure1_db_ane`
-- Generated: 2026-02-10

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `alnoure1_db_ane`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `alnoure1_db_ane`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `operations` (
  `رقم العملية` VARCHAR(50) NOT NULL,
  `التاريخ` DATE DEFAULT NULL,
  `إسم الزبون` VARCHAR(255) DEFAULT NULL,
  `المبلغ` DECIMAL(15,2) DEFAULT NULL,
  `الصندوق` VARCHAR(50) DEFAULT NULL,
  `الخدمة` VARCHAR(100) DEFAULT NULL,
  `تفصيل` TEXT DEFAULT NULL,
  `حالة الدفع` VARCHAR(50) DEFAULT NULL,
  `العملية` VARCHAR(50) DEFAULT NULL,
  `المحرر` VARCHAR(50) DEFAULT NULL,
  `project_id` VARCHAR(255) DEFAULT NULL,
  `supplier_name` VARCHAR(255) DEFAULT NULL,
  `supplier_cost` DECIMAL(10,2) DEFAULT 0.00,
  PRIMARY KEY (`رقم العملية`),
  KEY `idx_date` (`التاريخ`),
  KEY `project_id_index` (`project_id`),
  KEY `idx_editor` (`المحرر`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed admin account (password will be auto-hashed on first successful login by api.php)
INSERT INTO `users` (`username`, `password`, `full_name`, `role`, `is_active`)
VALUES ('admin', 'admin123', 'Administrator', 'admin', 1)
ON DUPLICATE KEY UPDATE
  `full_name` = VALUES(`full_name`),
  `role` = VALUES(`role`),
  `is_active` = VALUES(`is_active`);

