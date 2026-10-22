-- ===========================================================================
-- Migration: dfd_alignment
-- Brings an EXISTING database in line with the data flow diagram:
--   * students.phone            - mobile number for SMS notifications
--   * payments.method + 'cash'  - cashier walk-in payments
--   * sms_notifications (D:9)   - process status -> SMS / in-app notification
--
-- Safe to run more than once. Run via:
--   mysql -u root student-req < migrations/dfd_alignment.sql
-- or paste into phpMyAdmin -> SQL tab. (Opening setup.php applies the same
-- changes automatically.)
-- ===========================================================================

USE `student-req`;

SET @col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'phone'
);
SET @sql := IF(@col = 0,
    'ALTER TABLE students ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER year_level',
    'SELECT ''students.phone already exists'' AS note');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE payments
    MODIFY method ENUM('gcash','maya','card','bank_transfer','cash','mock') NOT NULL DEFAULT 'mock';

CREATE TABLE IF NOT EXISTS sms_notifications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id        INT UNSIGNED NOT NULL,
    request_id        INT UNSIGNED DEFAULT NULL,
    phone             VARCHAR(20)  DEFAULT NULL,
    process_status    VARCHAR(30)  NOT NULL,
    message           VARCHAR(320) NOT NULL,
    status            ENUM('queued','sent','failed','no_number') NOT NULL DEFAULT 'queued',
    provider_response VARCHAR(255) DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at           DATETIME DEFAULT NULL,
    read_at           DATETIME DEFAULT NULL,
    KEY idx_sms_student (student_id, read_at),
    KEY idx_sms_request (request_id),
    KEY idx_sms_status  (status),
    CONSTRAINT fk_sms_student FOREIGN KEY (student_id)
        REFERENCES students(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_sms_request FOREIGN KEY (request_id)
        REFERENCES requests(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
