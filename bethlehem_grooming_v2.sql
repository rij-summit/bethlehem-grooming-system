-- ============================================================
-- Bethlehem Animal Clinic & Pet Grooming
-- Complete Database Schema — Clean Version
-- Generated for Title Defense
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- Create Database
-- ============================================================
CREATE DATABASE IF NOT EXISTS `bethlehem_grooming`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `bethlehem_grooming`;

-- ============================================================
-- 1. users
-- ============================================================
CREATE TABLE `users` (
  `user_id`       INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`    VARCHAR(100) NOT NULL,
  `last_name`     VARCHAR(100) NOT NULL,
  `phone`         VARCHAR(20) NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `password`      VARCHAR(255) NOT NULL,
  `role`          ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
  `customer_tier` ENUM('new','regular','frequent','inactive') NOT NULL DEFAULT 'new',
  `profile_photo` VARCHAR(255) DEFAULT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `users_phone_unique` (`phone`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. pets
-- ============================================================
CREATE TABLE `pets` (
  `pet_id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`            INT(10) UNSIGNED NOT NULL,
  `pet_name`           VARCHAR(100) NOT NULL,
  `species`            VARCHAR(50) NOT NULL DEFAULT 'Dog',
  `breed`              VARCHAR(100) DEFAULT NULL,
  `weight`             DECIMAL(5,2) DEFAULT NULL,
  `color`              VARCHAR(50) DEFAULT NULL,
  `size`               ENUM('small','medium','large','extra_large') DEFAULT NULL,
  `fur_type`           ENUM('short','medium','long','wire','curl') DEFAULT NULL,
  `medical_conditions` TEXT DEFAULT NULL,
  `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`pet_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. time_windows
-- ============================================================
CREATE TABLE `time_windows` (
  `window_id`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `window_label` VARCHAR(50) NOT NULL,
  `start_time`   TIME NOT NULL,
  `end_time`     TIME NOT NULL,
  `max_slots`    TINYINT(4) UNSIGNED NOT NULL DEFAULT 4,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`window_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default time windows
INSERT INTO `time_windows` (`window_label`, `start_time`, `end_time`, `max_slots`) VALUES
('8:00 AM - 9:00 AM',   '08:00:00', '09:00:00', 4),
('9:00 AM - 10:00 AM',  '09:00:00', '10:00:00', 4),
('10:00 AM - 11:00 AM', '10:00:00', '11:00:00', 4),
('11:00 AM - 12:00 PM', '11:00:00', '12:00:00', 4),
('1:00 PM - 2:00 PM',   '13:00:00', '14:00:00', 4),
('2:00 PM - 3:00 PM',   '14:00:00', '15:00:00', 4);

-- ============================================================
-- 4. services
-- ============================================================
CREATE TABLE `services` (
  `service_id`   INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `service_name` VARCHAR(150) NOT NULL,
  `description`  TEXT DEFAULT NULL,
  `base_price`   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `price_small`  DECIMAL(8,2) DEFAULT NULL,
  `price_medium` DECIMAL(8,2) DEFAULT NULL,
  `price_large`  DECIMAL(8,2) DEFAULT NULL,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. addons
-- ============================================================
CREATE TABLE `addons` (
  `addon_id`    INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `addon_name`  VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `price`       DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`addon_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. bookings
-- ============================================================
CREATE TABLE `bookings` (
  `booking_id`          INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_reference`   VARCHAR(20) NOT NULL,
  `user_id`             INT(10) UNSIGNED NOT NULL,
  `window_id`           INT(10) UNSIGNED DEFAULT NULL,
  `booking_date`        DATE NOT NULL,
  `number_of_pets`      TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
  `booking_type`        ENUM('online','walk_in') NOT NULL DEFAULT 'online',
  `status`              ENUM(
                          'waiting_to_arrive',
                          'waiting',
                          'in_progress',
                          'groomed',
                          'waiting_for_payment',
                          'completed',
                          'cancelled',
                          'no_show'
                        ) NOT NULL DEFAULT 'waiting_to_arrive',
  `queue_number`        INT(10) DEFAULT NULL,
  `special_notes`       TEXT DEFAULT NULL,
  `cancellation_reason` TEXT DEFAULT NULL,
  `total_amount`        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `bookings_reference_unique` (`booking_reference`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
  FOREIGN KEY (`window_id`) REFERENCES `time_windows`(`window_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. booking_pets
-- ============================================================
CREATE TABLE `booking_pets` (
  `booking_pet_id`     INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`         INT(10) UNSIGNED NOT NULL,
  `pet_id`             INT(10) UNSIGNED DEFAULT NULL,
  `special_instructions` TEXT DEFAULT NULL,
  `groomer_id`         INT(10) UNSIGNED DEFAULT NULL,
  `grooming_start_time` DATETIME DEFAULT NULL,
  `grooming_end_time`  DATETIME DEFAULT NULL,
  PRIMARY KEY (`booking_pet_id`),
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`booking_id`) ON DELETE CASCADE,
  FOREIGN KEY (`pet_id`) REFERENCES `pets`(`pet_id`) ON DELETE SET NULL,
  FOREIGN KEY (`groomer_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. booking_services
-- ============================================================
CREATE TABLE `booking_services` (
  `booking_service_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`         INT(10) UNSIGNED NOT NULL,
  `booking_pet_id`     INT(10) UNSIGNED NOT NULL,
  `service_id`         INT(10) UNSIGNED DEFAULT NULL,
  `addon_id`           INT(10) UNSIGNED DEFAULT NULL,
  `price_at_booking`   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`booking_service_id`),
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`booking_id`) ON DELETE CASCADE,
  FOREIGN KEY (`booking_pet_id`) REFERENCES `booking_pets`(`booking_pet_id`) ON DELETE CASCADE,
  FOREIGN KEY (`service_id`) REFERENCES `services`(`service_id`) ON DELETE SET NULL,
  FOREIGN KEY (`addon_id`) REFERENCES `addons`(`addon_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. payments
-- ============================================================
CREATE TABLE `payments` (
  `payment_id`      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`      INT(10) UNSIGNED NOT NULL,
  `total_amount`    DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `amount_tendered` DECIMAL(8,2) DEFAULT NULL,
  `change_amount`   DECIMAL(8,2) DEFAULT NULL,
  `payment_method`  ENUM('cash','gcash','maya','card','others') NOT NULL DEFAULT 'cash',
  `payment_status`  ENUM('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at`         DATETIME DEFAULT NULL,
  `processed_by`    INT(10) UNSIGNED DEFAULT NULL,
  `notes`           TEXT DEFAULT NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `payments_booking_unique` (`booking_id`),
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`booking_id`) ON DELETE CASCADE,
  FOREIGN KEY (`processed_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. queue
-- ============================================================
CREATE TABLE `queue` (
  `queue_id`           INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`         INT(10) UNSIGNED NOT NULL,
  `queue_number`       INT(10) NOT NULL,
  `booking_date`       DATE NOT NULL,
  `check_in_time`      DATETIME DEFAULT NULL,
  `grooming_start_time` DATETIME DEFAULT NULL,
  `grooming_end_time`  DATETIME DEFAULT NULL,
  `current_status`     ENUM(
                         'waiting_to_arrive',
                         'waiting',
                         'in_progress',
                         'groomed',
                         'waiting_for_payment',
                         'completed',
                         'cancelled',
                         'no_show'
                       ) NOT NULL DEFAULT 'waiting_to_arrive',
  `updated_by`         INT(10) UNSIGNED DEFAULT NULL,
  `status_updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`queue_id`),
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`booking_id`) ON DELETE CASCADE,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. notifications
-- ============================================================
CREATE TABLE `notifications` (
  `notification_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT(10) UNSIGNED DEFAULT NULL,
  `booking_id`      INT(10) UNSIGNED DEFAULT NULL,
  `type`            ENUM(
                      'booking_confirmed',
                      'status_update',
                      'queue_update',
                      'payment_confirmed',
                      'walk_in_registered',
                      'reminder_24hr',
                      'reminder_3hr',
                      'general'
                    ) NOT NULL DEFAULT 'general',
  `message`         TEXT NOT NULL,
  `channel`         ENUM('in_app','sms','email') NOT NULL DEFAULT 'in_app',
  `is_read`         TINYINT(1) NOT NULL DEFAULT 0,
  `sent_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`booking_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. cancellations
-- ============================================================
CREATE TABLE `cancellations` (
  `cancellation_id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`      INT(10) UNSIGNED NOT NULL,
  `cancelled_by`    INT(10) UNSIGNED DEFAULT NULL,
  `reason`          TEXT DEFAULT NULL,
  `cancelled_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cancellation_id`),
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`booking_id`) ON DELETE CASCADE,
  FOREIGN KEY (`cancelled_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. consent_forms
-- ============================================================
CREATE TABLE `consent_forms` (
  `consent_id`        INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `booking_id`        INT(10) UNSIGNED NOT NULL,
  `form_content`      TEXT NOT NULL,
  `digital_signature` VARCHAR(200) NOT NULL,
  `scrolled_fully`    TINYINT(1) NOT NULL DEFAULT 0,
  `agreed`            TINYINT(1) NOT NULL DEFAULT 0,
  `submitted_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`consent_id`),
  UNIQUE KEY `consent_forms_booking_unique` (`booking_id`),
  FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. reports
-- ============================================================
CREATE TABLE `reports` (
  `report_id`        INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_type`      ENUM('daily','weekly','monthly') NOT NULL,
  `report_date`      DATE NOT NULL,
  `total_bookings`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_walk_ins`   INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_completed`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_cancelled`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_revenue`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `generated_by`     INT(10) UNSIGNED DEFAULT NULL,
  `generated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`),
  FOREIGN KEY (`generated_by`) REFERENCES `users`(`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. sessions (Laravel required)
-- ============================================================
CREATE TABLE `sessions` (
  `id`            VARCHAR(255) NOT NULL,
  `user_id`       BIGINT UNSIGNED DEFAULT NULL,
  `ip_address`    VARCHAR(45) DEFAULT NULL,
  `user_agent`    TEXT DEFAULT NULL,
  `payload`       LONGTEXT NOT NULL,
  `last_activity` INT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. personal_access_tokens (Laravel Sanctum required)
-- ============================================================
CREATE TABLE `personal_access_tokens` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` VARCHAR(255) NOT NULL,
  `tokenable_id`   BIGINT UNSIGNED NOT NULL,
  `name`           VARCHAR(255) NOT NULL,
  `token`          VARCHAR(64) NOT NULL,
  `abilities`      TEXT DEFAULT NULL,
  `last_used_at`   TIMESTAMP NULL DEFAULT NULL,
  `expires_at`     TIMESTAMP NULL DEFAULT NULL,
  `created_at`     TIMESTAMP NULL DEFAULT NULL,
  `updated_at`     TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Default Admin Account
-- Password: admin123 (hashed with bcrypt)
-- ============================================================
INSERT INTO `users`
  (`first_name`, `last_name`, `phone`, `email`, `password`, `role`, `customer_tier`, `is_active`)
VALUES
  ('Admin', 'Bethlehem', '09000000000', 'admin@bethlehem.com',
   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
   'admin', 'new', 1);

COMMIT;
