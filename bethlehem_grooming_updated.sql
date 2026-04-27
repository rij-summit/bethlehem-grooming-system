-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 27, 2026 at 07:39 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bethlehem_grooming`
--

-- --------------------------------------------------------

--
-- Table structure for table `addons`
--

CREATE TABLE `addons` (
  `addon_id` int(10) UNSIGNED NOT NULL,
  `addon_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(8,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `booking_id` int(10) UNSIGNED NOT NULL,
  `booking_reference` varchar(20) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `window_id` int(10) UNSIGNED DEFAULT NULL,
  `booking_date` date NOT NULL,
  `number_of_pets` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `booking_type` enum('online','walk_in') NOT NULL DEFAULT 'online',
  `status` enum('waiting_to_arrive','checked_in','in_progress','for_pickup','for_payment','released','archived','waiting','groomed','waiting_for_payment','completed','cancelled','no_show') NOT NULL DEFAULT 'waiting_to_arrive',
  `paid` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `queue_number` int(10) DEFAULT NULL,
  `special_notes` text DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `total_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reschedule_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `cancel_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`booking_id`, `booking_reference`, `user_id`, `window_id`, `booking_date`, `number_of_pets`, `booking_type`, `status`, `paid`, `archived_at`, `queue_number`, `special_notes`, `cancellation_reason`, `total_amount`, `created_at`, `updated_at`, `reschedule_count`, `cancel_count`) VALUES
(1, 'BAC-20260415-0001', 6, 1, '2026-04-15', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, 'My dog is friendly', NULL, 0.00, '2026-04-11 21:23:56', '2026-04-11 21:23:56', 0, 0),
(2, 'BAC-20260413-0002', 7, 1, '2026-04-13', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-11 21:47:08', '2026-04-11 21:47:08', 0, 0),
(3, 'BAC-20260412-0003', 7, 1, '2026-04-12', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-11 21:59:58', '2026-04-11 21:59:58', 0, 0),
(4, 'BAC-20260414-0001', 7, 3, '2026-04-14', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-13 23:09:15', '2026-04-13 23:09:15', 0, 0),
(5, 'BAC-20260415-0002', 7, 1, '2026-04-15', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-13 23:20:08', '2026-04-13 23:20:08', 0, 0),
(6, 'BAC-20260416-0001', 6, 3, '2026-04-21', 1, 'online', 'cancelled', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-14 23:02:38', '2026-04-16 10:59:39', 1, 1),
(7, 'BAC-20260416-0002', 10, 4, '2026-04-16', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-14 23:15:02', '2026-04-14 23:15:02', 0, 0),
(8, 'BAC-20260415-0003', 10, 3, '2026-04-15', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-14 23:19:05', '2026-04-14 23:19:05', 0, 0),
(11, 'BAC-20260417-0001', 6, 1, '2026-04-17', 1, 'online', 'cancelled', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-16 23:32:17', '2026-04-16 23:39:50', 0, 1),
(12, 'BAC-20260417-0002', 6, 4, '2026-04-17', 1, 'online', 'waiting_to_arrive', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-16 23:40:35', '2026-04-16 23:40:35', 0, 0),
(13, 'BAC-20260419-0001', 6, 3, '2026-04-19', 1, 'online', 'archived', 1, '2026-04-19 06:56:06', 1, NULL, NULL, 0.00, '2026-04-19 00:17:51', '2026-04-19 22:56:06', 1, 0),
(14, 'BAC-20260420-0001', 6, 1, '2026-04-20', 1, 'online', 'archived', 1, '2026-04-19 07:19:28', 1, NULL, NULL, 0.00, '2026-04-19 22:52:50', '2026-04-19 23:19:28', 0, 0),
(15, 'BAC-20260420-0002', 13, 5, '2026-04-20', 1, 'online', 'archived', 1, '2026-04-19 19:51:42', 2, NULL, NULL, 0.00, '2026-04-20 11:48:38', '2026-04-20 11:51:42', 0, 0),
(16, 'BAC-20260420-0003', 13, 5, '2026-04-20', 1, 'online', 'archived', 1, '2026-04-19 20:34:44', 3, NULL, NULL, 0.00, '2026-04-20 12:32:47', '2026-04-20 12:34:44', 0, 0),
(26, 'BAC-20260421-0001', 6, 1, '2026-04-21', 1, 'online', 'cancelled', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-20 14:31:35', '2026-04-20 14:40:18', 1, 1),
(27, 'BAC-20260421-0002', 13, 1, '2026-04-21', 1, 'online', 'no_show', 0, NULL, NULL, NULL, NULL, 0.00, '2026-04-20 14:42:02', '2026-04-21 12:55:09', 0, 0),
(29, 'BAC-20260421-0004', 6, 2, '2026-04-21', 1, 'online', 'archived', 1, '2026-04-20 02:16:31', 2, NULL, NULL, 0.00, '2026-04-20 18:11:25', '2026-04-20 18:16:31', 0, 0),
(30, 'BAC-20260421-0005', 20, 5, '2026-04-21', 2, 'online', 'archived', 1, '2026-04-20 20:52:14', 2, NULL, NULL, 0.00, '2026-04-21 12:35:43', '2026-04-21 12:52:14', 0, 0),
(31, 'BAC-20260421-0006', 21, 5, '2026-04-21', 1, 'online', 'archived', 1, '2026-04-21 07:25:46', 4, NULL, NULL, 0.00, '2026-04-21 12:47:39', '2026-04-21 23:25:46', 0, 0),
(32, 'BAC-20260422-0001', 21, 1, '2026-04-22', 1, 'online', 'archived', 1, '2026-04-21 07:46:29', 1, NULL, NULL, 0.00, '2026-04-21 23:41:23', '2026-04-21 23:46:29', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `booking_pets`
--

CREATE TABLE `booking_pets` (
  `booking_pet_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `pet_id` int(10) UNSIGNED DEFAULT NULL,
  `special_instructions` text DEFAULT NULL,
  `groomer_id` int(10) UNSIGNED DEFAULT NULL,
  `grooming_start_time` datetime DEFAULT NULL,
  `grooming_end_time` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `booking_pets`
--

INSERT INTO `booking_pets` (`booking_pet_id`, `booking_id`, `pet_id`, `special_instructions`, `groomer_id`, `grooming_start_time`, `grooming_end_time`) VALUES
(1, 1, 1, 'Gentle please', NULL, NULL, NULL),
(2, 2, 2, 'sda f wesd ew r', NULL, NULL, NULL),
(3, 3, 3, 'fd  rse dsf regs', NULL, NULL, NULL),
(4, 4, 4, 'sfdgdf', NULL, NULL, NULL),
(5, 5, 2, 'asdf hdf', NULL, NULL, NULL),
(6, 6, 1, NULL, NULL, NULL, NULL),
(7, 7, 5, NULL, NULL, NULL, NULL),
(8, 8, 6, NULL, NULL, NULL, NULL),
(9, 11, 1, NULL, NULL, NULL, NULL),
(10, 12, 1, NULL, NULL, NULL, NULL),
(11, 13, 1, NULL, NULL, NULL, NULL),
(12, 14, 1, NULL, NULL, NULL, NULL),
(13, 15, 7, NULL, NULL, NULL, NULL),
(14, 16, 7, NULL, NULL, NULL, NULL),
(24, 26, 1, NULL, NULL, NULL, NULL),
(25, 27, 17, NULL, NULL, NULL, NULL),
(27, 29, 1, 'you make pet good', NULL, NULL, NULL),
(28, 30, 19, NULL, NULL, NULL, NULL),
(29, 30, 20, 'fd', NULL, NULL, NULL),
(30, 31, 21, NULL, NULL, NULL, NULL),
(31, 32, 21, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `booking_services`
--

CREATE TABLE `booking_services` (
  `booking_service_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `booking_pet_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(10) UNSIGNED DEFAULT NULL,
  `addon_id` int(10) UNSIGNED DEFAULT NULL,
  `price_at_booking` decimal(8,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cancellations`
--

CREATE TABLE `cancellations` (
  `cancellation_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `cancelled_by` int(10) UNSIGNED DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `cancelled_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clinic_closures`
--

CREATE TABLE `clinic_closures` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('stop_today','blocked_date') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clinic_closures`
--

INSERT INTO `clinic_closures` (`id`, `type`, `start_date`, `end_date`, `reason`, `is_active`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'stop_today', '2026-04-18', '2026-04-18', NULL, 0, 1, '2026-04-17 19:10:29', '2026-04-17 19:10:30'),
(2, 'blocked_date', '2026-04-25', '2026-04-27', 'Summer break', 0, 1, '2026-04-17 19:10:48', '2026-04-17 19:11:07'),
(3, 'stop_today', '2026-04-18', '2026-04-18', NULL, 0, 1, '2026-04-18 05:50:22', '2026-04-18 05:50:36'),
(4, 'stop_today', '2026-04-18', '2026-04-18', NULL, 0, 1, '2026-04-18 05:50:43', '2026-04-18 05:58:05'),
(5, 'stop_today', '2026-04-18', '2026-04-18', NULL, 0, 1, '2026-04-18 05:58:15', '2026-04-18 05:58:22'),
(6, 'blocked_date', '2026-05-01', '2026-05-05', 'Labor Day vacation', 0, 1, '2026-04-18 06:02:42', '2026-04-18 06:03:06'),
(7, 'blocked_date', '2026-04-18', '2026-04-21', 'gusto ko lang', 0, 1, '2026-04-18 06:04:27', '2026-04-18 06:05:37'),
(8, 'stop_today', '2026-04-20', '2026-04-20', NULL, 0, 1, '2026-04-19 22:23:47', '2026-04-19 22:24:14'),
(9, 'stop_today', '2026-04-20', '2026-04-20', NULL, 0, 1, '2026-04-19 22:27:22', '2026-04-19 22:27:28'),
(10, 'blocked_date', '2026-04-20', '2026-04-23', 'natatae ako', 0, 1, '2026-04-19 22:27:50', '2026-04-19 22:29:04'),
(11, 'stop_today', '2026-04-20', '2026-04-20', NULL, 0, 1, '2026-04-20 02:05:53', '2026-04-20 02:06:19'),
(12, 'stop_today', '2026-04-21', '2026-04-21', NULL, 0, 1, '2026-04-20 20:55:09', '2026-04-20 20:56:26'),
(13, 'blocked_date', '2026-04-21', '2026-04-23', NULL, 0, 1, '2026-04-20 20:56:45', '2026-04-21 07:32:51');

-- --------------------------------------------------------

--
-- Table structure for table `consent_forms`
--

CREATE TABLE `consent_forms` (
  `consent_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `form_content` text NOT NULL,
  `digital_signature` varchar(200) NOT NULL,
  `scrolled_fully` tinyint(1) NOT NULL DEFAULT 0,
  `agreed` tinyint(1) NOT NULL DEFAULT 0,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customer_notifications`
--

CREATE TABLE `customer_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED DEFAULT NULL,
  `type` enum('reminder_24h','reminder_3h','grooming_started','ready_for_pickup') NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customer_notifications`
--

INSERT INTO `customer_notifications` (`id`, `user_id`, `booking_id`, `type`, `message`, `is_read`, `created_at`) VALUES
(1, 13, 15, 'grooming_started', 'Great news! rij\'s grooming session has started. We\'ll let you know as soon as they\'re ready for pickup! 🐾', 1, '2026-04-19 19:50:04'),
(2, 13, 15, 'ready_for_pickup', '🐾 rij is all done and looking fabulous! Please come to the clinic to pick them up.', 1, '2026-04-19 19:50:36'),
(3, 13, 16, 'grooming_started', 'Great news! rij\'s grooming session has started. We\'ll let you know as soon as they\'re ready for pickup! 🐾', 0, '2026-04-19 20:33:18'),
(4, 13, 16, 'ready_for_pickup', '🐾 rij is all done and looking fabulous! Please come to the clinic to pick them up.', 1, '2026-04-19 20:34:01'),
(5, 19, 28, 'grooming_started', 'Great news! Dog\'s grooming session has started. We\'ll let you know as soon as they\'re ready for pickup! 🐾', 1, '2026-04-19 22:56:19'),
(6, 6, 29, 'grooming_started', 'Great news! Buddy\'s grooming session has started. We\'ll let you know as soon as they\'re ready for pickup! 🐾', 1, '2026-04-20 02:13:44'),
(7, 6, 29, 'ready_for_pickup', '🐾 Buddy is all done and looking fabulous! Please come to the clinic to pick them up.', 1, '2026-04-20 02:13:52'),
(8, 20, 30, 'grooming_started', 'Great news! Dog\'s grooming session has started. We\'ll let you know as soon as they\'re ready for pickup! 🐾', 0, '2026-04-20 20:51:43'),
(9, 21, 31, 'grooming_started', 'Great news! Dog\'s grooming session has started. We\'ll let you know as soon as they\'re ready for pickup! 🐾', 1, '2026-04-21 07:24:21'),
(10, 21, 31, 'ready_for_pickup', '🐾 Dog is all done and looking fabulous! Please come to the clinic to pick them up.', 1, '2026-04-21 07:25:27'),
(11, 21, 32, 'grooming_started', 'Great news! Dog\'s grooming session has started. We\'ll let you know as soon as they\'re ready for pickup! 🐾', 1, '2026-04-21 07:45:48'),
(12, 21, 32, 'ready_for_pickup', '🐾 Dog is all done and looking fabulous! Please come to the clinic to pick them up.', 1, '2026-04-21 07:46:29');

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000001_create_cache_table', 1),
(2, '0001_01_01_000002_create_jobs_table', 1),
(3, '2026_04_01_133041_create_sessions_table', 2),
(4, '2026_04_01_143732_add_customer_tier_to_users_table', 2),
(5, '2026_04_01_145449_create_personal_access_tokens_table', 2),
(6, '2026_04_16_000001_add_checked_in_for_pickup_to_bookings_status', 2),
(7, '2026_04_17_142057_add_archived_at_to_bookings_table', 2),
(8, '2026_04_17_142718_add_archived_to_bookings_status_enum', 2),
(9, '2026_04_17_145112_add_is_archived_to_users_table', 2),
(10, '2026_04_18_100001_create_clinic_closures_table', 3),
(11, '2026_04_18_200001_add_payment_statuses_to_bookings', 4),
(12, '2026_04_18_200002_create_payments_table', 5),
(13, '2026_04_19_000001_create_customer_notifications_table', 6);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(10) UNSIGNED NOT NULL,
  `type` enum('booked','cancelled','rescheduled','payment_due') NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `type`, `booking_id`, `message`, `is_read`, `created_at`) VALUES
(1, 'booked', 6, 'New booking BAC-20260416-0001 by Juan Dela Cruz on 2026-04-16 at 11:00 AM - 12:00 PM.', 1, '2026-04-14 07:02:38'),
(2, 'booked', 7, 'New booking BAC-20260416-0002 by fds sytre on 2026-04-16 at 11:00 AM - 12:00 PM.', 1, '2026-04-14 07:15:02'),
(3, 'booked', 8, 'New booking BAC-20260415-0003 by fds sytre on 2026-04-15 at 10:00 AM - 11:00 AM.', 1, '2026-04-14 07:19:05'),
(4, 'rescheduled', 6, 'Booking BAC-20260416-0001 was rescheduled by Juan Dela Cruz to 2026-04-21 at 10:00 AM - 11:00 AM.', 1, '2026-04-15 18:34:59'),
(5, 'cancelled', 6, 'Booking BAC-20260416-0001 was cancelled by Juan Dela Cruz.', 1, '2026-04-15 18:59:39'),
(6, 'cancelled', 11, 'Booking BAC-20260417-0001 was cancelled by Juan Dela Cruz.', 1, '2026-04-16 07:39:50'),
(7, 'booked', 13, 'New booking BAC-20260419-0001 by Juan Dela Cruz on 2026-04-19 at 8:00 AM - 9:00 AM.', 1, '2026-04-18 08:17:51'),
(8, 'rescheduled', 13, 'Booking BAC-20260419-0001 was rescheduled by Juan Dela Cruz to 2026-04-19 at 10:00 AM - 11:00 AM.', 1, '2026-04-18 08:34:28'),
(9, 'booked', 14, 'New booking BAC-20260420-0001 by Juan Dela Cruz on 2026-04-20 at 8:00 AM - 9:00 AM.', 1, '2026-04-19 06:52:51'),
(10, 'booked', 15, 'New booking BAC-20260420-0002 by Ridge Marino on 2026-04-20 at 1:00 PM - 2:00 PM.', 0, '2026-04-19 19:48:38'),
(11, 'payment_due', 15, 'Grooming done for Ridge Marino. Pet is ready — please collect payment.', 0, '2026-04-19 19:50:36'),
(12, 'booked', 16, 'New booking BAC-20260420-0003 by Ridge Marino on 2026-04-20 at 1:00 PM - 2:00 PM.', 0, '2026-04-19 20:32:47'),
(13, 'payment_due', 16, 'Grooming done for Ridge Marino. Pet is ready — please collect payment.', 0, '2026-04-19 20:34:01'),
(23, 'booked', 26, 'New booking BAC-20260421-0001 by Juan Dela Cruz on 2026-04-21 at 8:00 AM - 9:00 AM.', 0, '2026-04-19 22:31:35'),
(24, 'rescheduled', 26, 'Booking BAC-20260421-0001 was rescheduled by Juan Dela Cruz to 2026-04-21 at 8:00 AM - 9:00 AM.', 0, '2026-04-19 22:39:53'),
(25, 'cancelled', 26, 'Booking BAC-20260421-0001 was cancelled by Juan Dela Cruz.', 0, '2026-04-19 22:40:18'),
(26, 'booked', 27, 'New booking BAC-20260421-0002 by Ridge Marino on 2026-04-21 at 8:00 AM - 9:00 AM.', 0, '2026-04-19 22:42:02'),
(28, 'booked', 29, 'New booking BAC-20260421-0004 by Juan Dela Cruz on 2026-04-21 at 9:00 AM - 10:00 AM.', 1, '2026-04-20 02:11:25'),
(29, 'payment_due', 29, 'Grooming done for Juan Dela Cruz. Pet is ready — please collect payment.', 0, '2026-04-20 02:13:52'),
(30, 'booked', 30, 'New booking BAC-20260421-0005 by Gerald Riva on 2026-04-21 at 1:00 PM - 2:00 PM.', 0, '2026-04-20 20:35:43'),
(31, 'booked', 31, 'New booking BAC-20260421-0006 by Ridge Mariano on 2026-04-21 at 1:00 PM - 2:00 PM.', 0, '2026-04-20 20:47:39'),
(32, 'payment_due', 31, 'Grooming done for Ridge Mariano. Pet is ready — please collect payment.', 0, '2026-04-21 07:25:27'),
(33, 'booked', 32, 'New booking BAC-20260422-0001 by Ridge Mariano on 2026-04-22 at 8:00 AM - 9:00 AM.', 0, '2026-04-21 07:41:23');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `total_amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `amount_tendered` decimal(8,2) DEFAULT NULL,
  `change_amount` decimal(8,2) DEFAULT NULL,
  `payment_method` enum('cash','gcash','maya','card','others') NOT NULL DEFAULT 'cash',
  `payment_status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `processed_by` int(10) UNSIGNED DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `booking_id`, `total_amount`, `amount_tendered`, `change_amount`, `payment_method`, `payment_status`, `paid_at`, `processed_by`, `notes`, `created_at`) VALUES
(1, 13, 500.00, 800.00, 300.00, 'cash', 'paid', '2026-04-19 14:56:06', NULL, NULL, '2026-04-19 14:56:06'),
(2, 14, 550.00, 565.00, 15.00, 'cash', 'paid', '2026-04-19 15:19:13', NULL, NULL, '2026-04-19 15:19:13'),
(3, 15, 100.00, 100.00, 0.00, 'cash', 'paid', '2026-04-20 03:51:42', NULL, NULL, '2026-04-20 03:51:42'),
(4, 16, 150.00, 150.00, 0.00, 'cash', 'paid', '2026-04-20 04:34:44', NULL, NULL, '2026-04-20 04:34:44'),
(6, 29, 750.00, 1000.00, 250.00, 'cash', 'paid', '2026-04-20 10:16:31', NULL, NULL, '2026-04-20 10:16:31'),
(7, 30, 750.00, 1000.00, 250.00, 'cash', 'paid', '2026-04-21 04:50:45', NULL, NULL, '2026-04-21 04:50:45'),
(8, 31, 750.00, 1000.00, 250.00, 'cash', 'paid', '2026-04-21 15:25:46', NULL, NULL, '2026-04-21 15:25:46'),
(9, 32, 500.00, 500.00, 0.00, 'cash', 'paid', '2026-04-21 15:45:37', NULL, NULL, '2026-04-21 15:45:37');

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `personal_access_tokens`
--

INSERT INTO `personal_access_tokens` (`id`, `tokenable_type`, `tokenable_id`, `name`, `token`, `abilities`, `last_used_at`, `expires_at`, `created_at`, `updated_at`) VALUES
(2, 'App\\Models\\User', 2, 'auth_token', 'ca63a0318579c97b0c06f066fe510961f0bbc486729b1a435e125ea63fa9b2a8', '[\"*\"]', '2026-04-10 15:57:25', NULL, '2026-04-10 15:57:01', '2026-04-10 15:57:25'),
(4, 'App\\Models\\User', 3, 'auth_token', '86d4ddb52cab2d028f29973dea378c51ebc10402eee79ba6dce181d5e8b10485', '[\"*\"]', '2026-04-10 16:55:09', NULL, '2026-04-10 15:59:54', '2026-04-10 16:55:09'),
(25, 'App\\Models\\User', 8, 'auth_token', '6d4fa92992c942e990e434b1e2d89b7f298a2bf49b1b1f871cee7d077853f3d5', '[\"*\"]', NULL, NULL, '2026-04-14 05:04:00', '2026-04-14 05:04:00'),
(27, 'App\\Models\\User', 9, 'auth_token', '9bb0e24c3c80f4ab1c8dc3a8b23f79632791f40da1634ae50427b0c377c739b9', '[\"*\"]', NULL, NULL, '2026-04-14 05:12:05', '2026-04-14 05:12:05'),
(54, 'App\\Models\\User', 11, 'auth_token', 'e46a12fee486d00b682ac7bcacc14daad8d87f26e68261bc242369c95a7f0a5a', '[\"*\"]', NULL, NULL, '2026-04-15 21:02:28', '2026-04-15 21:02:28'),
(55, 'App\\Models\\User', 12, 'auth_token', 'de650341e73676450a933e5b10b90d76131d3c2c57de551bde26549c39a60d32', '[\"*\"]', NULL, NULL, '2026-04-15 21:03:16', '2026-04-15 21:03:16'),
(86, 'App\\Models\\User', 13, 'auth_token', '8601e7aee0758d6fe0f3f390b0f9297c59fb0982afe95077eb51028ca32b03b2', '[\"*\"]', '2026-04-19 22:45:28', NULL, '2026-04-19 22:41:03', '2026-04-19 22:45:28'),
(95, 'App\\Models\\User', 20, 'auth_token', '4d1e9906d8e6a84f9b4ca435f7158bc023e3d74a90a6dbc7e01c802affcde333', '[\"*\"]', '2026-04-20 21:01:39', NULL, '2026-04-20 20:55:40', '2026-04-20 21:01:39'),
(98, 'App\\Models\\User', 21, 'auth_token', 'c56ec958f3664abafe1496d3e948ac073f3356a8ce3e3858f205672b50d7e2be', '[\"*\"]', '2026-04-21 09:29:32', NULL, '2026-04-21 07:24:46', '2026-04-21 09:29:32'),
(100, 'App\\Models\\User', 6, 'auth_token', '1dbf8da401a29b1196cf09a10549e53936d82fdaedea21e99612cd6aba3de815', '[\"*\"]', '2026-04-24 01:01:48', NULL, '2026-04-23 22:37:05', '2026-04-24 01:01:48'),
(101, 'App\\Models\\User', 1, 'admin_token', '00b7bbbb25d7c0d632076d4987f7657b8b5388a83f93170cdb0af189278540a0', '[\"*\"]', '2026-04-24 01:02:06', NULL, '2026-04-23 23:08:05', '2026-04-24 01:02:06');

-- --------------------------------------------------------

--
-- Table structure for table `pets`
--

CREATE TABLE `pets` (
  `pet_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `pet_name` varchar(100) NOT NULL,
  `species` varchar(50) NOT NULL DEFAULT 'Dog',
  `breed` varchar(100) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `size` enum('small','medium','large','extra_large') DEFAULT NULL,
  `fur_type` enum('short','medium','long','wire','curl') DEFAULT NULL,
  `medical_conditions` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pets`
--

INSERT INTO `pets` (`pet_id`, `user_id`, `pet_name`, `species`, `breed`, `weight`, `color`, `size`, `fur_type`, `medical_conditions`, `created_at`) VALUES
(1, 6, 'Buddy', 'Dog', 'Aspin', 5.50, 'Brown', 'medium', 'short', NULL, '2026-04-11 21:23:56'),
(2, 7, 'max', 'Dog', NULL, 12.00, NULL, 'extra_large', 'short', 'dfs a e asdf', '2026-04-11 21:47:08'),
(3, 7, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'short', 'ssd  dsg', '2026-04-11 21:59:58'),
(4, 7, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'medium', 'gffdg', '2026-04-13 23:09:15'),
(5, 10, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'short', 'ssd  dsg', '2026-04-14 23:15:02'),
(6, 10, 'max', 'Dog', NULL, 12.00, NULL, 'extra_large', 'short', 'dfs a e asdf', '2026-04-14 23:19:05'),
(7, 13, 'rij', 'Cat', 'persian', 4.00, NULL, 'small', 'short', NULL, '2026-04-20 11:48:38'),
(17, 13, 'Buddy', 'Dog', 'Aspin', 5.50, NULL, 'medium', 'short', NULL, '2026-04-20 14:42:02'),
(19, 20, 'Dog', 'Cat', 'Spanish', NULL, NULL, 'small', 'short', 'galisin', '2026-04-21 12:35:43'),
(20, 20, 'Dog', 'Dog', 'doberman', 67.00, NULL, 'extra_large', 'short', 'overweight', '2026-04-21 12:35:43'),
(21, 21, 'Dog', 'Cat', 'Spanish', NULL, NULL, 'small', 'short', 'galisin', '2026-04-21 12:47:39');

-- --------------------------------------------------------

--
-- Table structure for table `queue`
--

CREATE TABLE `queue` (
  `queue_id` int(10) UNSIGNED NOT NULL,
  `booking_id` int(10) UNSIGNED NOT NULL,
  `queue_number` int(10) NOT NULL,
  `booking_date` date NOT NULL,
  `check_in_time` datetime DEFAULT NULL,
  `grooming_start_time` datetime DEFAULT NULL,
  `grooming_end_time` datetime DEFAULT NULL,
  `current_status` enum('waiting_to_arrive','waiting','in_progress','groomed','waiting_for_payment','completed','cancelled','no_show') NOT NULL DEFAULT 'waiting_to_arrive',
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `status_updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `report_id` int(10) UNSIGNED NOT NULL,
  `report_type` enum('daily','weekly','monthly') NOT NULL,
  `report_date` date NOT NULL,
  `total_bookings` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_walk_ins` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_completed` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_cancelled` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_revenue` decimal(10,2) NOT NULL DEFAULT 0.00,
  `generated_by` int(10) UNSIGNED DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `service_id` int(10) UNSIGNED NOT NULL,
  `service_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(8,2) NOT NULL DEFAULT 0.00,
  `price_small` decimal(8,2) DEFAULT NULL,
  `price_medium` decimal(8,2) DEFAULT NULL,
  `price_large` decimal(8,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `slug` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `time_windows`
--

CREATE TABLE `time_windows` (
  `window_id` int(10) UNSIGNED NOT NULL,
  `window_label` varchar(50) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `max_slots` tinyint(4) UNSIGNED NOT NULL DEFAULT 4,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `time_windows`
--

INSERT INTO `time_windows` (`window_id`, `window_label`, `start_time`, `end_time`, `max_slots`, `is_active`) VALUES
(1, '8:00 AM - 9:00 AM', '08:00:00', '09:00:00', 4, 1),
(2, '9:00 AM - 10:00 AM', '09:00:00', '10:00:00', 4, 1),
(3, '10:00 AM - 11:00 AM', '10:00:00', '11:00:00', 4, 1),
(4, '11:00 AM - 12:00 PM', '11:00:00', '12:00:00', 4, 1),
(5, '1:00 PM - 2:00 PM', '13:00:00', '14:00:00', 4, 1),
(6, '2:00 PM - 3:00 PM', '14:00:00', '15:00:00', 4, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('customer','staff','admin') NOT NULL DEFAULT 'customer',
  `customer_tier` enum('new','regular','frequent','inactive') NOT NULL DEFAULT 'new',
  `profile_photo` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_archived` tinyint(4) NOT NULL DEFAULT 0,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `phone`, `email`, `password_hash`, `role`, `customer_tier`, `profile_photo`, `is_active`, `is_archived`, `archived_at`, `created_at`, `updated_at`) VALUES
(1, 'Admin', 'Bethlehem', '09000000000', 'admin@bethlehem.com', '$2y$12$3oQ4z6SOCmdWNpgL1J9k/e8jNG1iamRiE3Fjwdrd2YUeNGVDAka9.', 'admin', 'new', NULL, 1, 0, NULL, '2026-04-10 23:40:18', '2026-04-13 21:52:34'),
(2, 'Juan', 'Dela Cruz', '09171234567', 'juan@example.com', '$2y$12$faVX2VR5SzyqGR86QqJdBebpXb62FoNQSomcvOHPBDrqXB1wzxzWu', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-11 07:53:32', '2026-04-11 07:53:32'),
(3, 'Ridge', 'Oniram', '234324', 'ridgemarino.biio23@gmail.com', '$2y$12$aCdUEk6ylE6VACAjy7q1tu/wVsprsJLY8ok7DXZYj9CXLPtMaF.Sq', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-11 07:59:41', '2026-04-11 07:59:41'),
(6, 'Juan', 'Dela Cruz', '09123456789', 'juan23@test.com', '$2y$12$7sXzVCyHkDXThS8EFiOo1uq4zy6AmSxRugqabhDUS9Jq2J51UXoPK', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-11 09:37:01', '2026-04-11 09:37:01'),
(7, 'Ridge', 'Marino', '09987654321', 'ridgee.marino@gmail.com', '$2y$12$DY9RJM1AKsKAzp1SsCrIEud2RBEG8nlx7efc6F9NTTLuf8fY8h9Ha', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-11 10:36:43', '2026-04-18 23:18:56'),
(8, 'rij', 'Marino', '09121234567', 'ridge@gmail.con', '$2y$12$.7Yamcsy4F0h7GIpkWJ0IOMxXI8oITtNe.t9vq2dGQJQ/Ei4oCwWu', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-14 21:04:00', '2026-04-14 21:04:00'),
(9, 'Ridge', 'Oniram', '09123768577', 'fhg@jdgk.jfj', '$2y$12$QiqtINOCDLOD5D2/JDLPj.7rsVQh9CJHkQgh/onFAs8gsmbe4P8Eq', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-14 21:12:05', '2026-04-14 21:12:05'),
(10, 'fds', 'sytre', '09999999999', '091863236@agd', '$2y$12$4atrH1l0fD78eNDcETDs2O8/TupcTUgHLE4lLogixefe6LPLiTWVS', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-14 23:04:30', '2026-04-18 23:18:02'),
(11, 'Xei', 'Ki', '09124747254', 'idgaf@gmail.com', '$2y$12$P7xCF3/aUTMkrkS9fBSTue/rJSiJHfW411V9kr/twiq/dkJmYLdPy', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-16 13:02:28', '2026-04-16 13:02:28'),
(12, 'Ridge', 'Mariano', '09454875487', 'no@yes.gmail.com', '$2y$12$MXOxt8DVjpilK2LUjn8.w.TIoxTOXWtDWyGE2AbCy3efgb49I72VO', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-16 13:03:16', '2026-04-16 13:03:16'),
(13, 'Ridge', 'Marino', '09612088339', 'rij@marino.com', '$2y$12$WJa.lCtqk.Y30phIC7KerupcFKJv2amzAvxghnmNubv9gPW2B0yuK', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-20 11:46:00', '2026-04-20 11:46:00'),
(20, 'Gerald', 'Riva', '09111111111', 'gerald@gerald', '$2y$12$RlcLlIq8PaCTWHKrbYOK4O/8QDKge4Tojuo2BqVvUn4Xkc3XFoqqC', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-21 12:27:14', '2026-04-21 12:27:14'),
(21, 'Ridge', 'Mariano', '09123123123', 'ridge2@GMAIL.COM', '$2y$12$Ct4Wvx9KKx2G8D.cFwxJueLsbw2ifyoiVT1IqY.GS2ZZzcsHVvBA6', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-21 12:41:02', '2026-04-21 12:41:02');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `addons`
--
ALTER TABLE `addons`
  ADD PRIMARY KEY (`addon_id`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`booking_id`),
  ADD UNIQUE KEY `bookings_reference_unique` (`booking_reference`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `window_id` (`window_id`);

--
-- Indexes for table `booking_pets`
--
ALTER TABLE `booking_pets`
  ADD PRIMARY KEY (`booking_pet_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `pet_id` (`pet_id`),
  ADD KEY `groomer_id` (`groomer_id`);

--
-- Indexes for table `booking_services`
--
ALTER TABLE `booking_services`
  ADD PRIMARY KEY (`booking_service_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `booking_pet_id` (`booking_pet_id`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `addon_id` (`addon_id`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_expiration_index` (`expiration`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`),
  ADD KEY `cache_locks_expiration_index` (`expiration`);

--
-- Indexes for table `cancellations`
--
ALTER TABLE `cancellations`
  ADD PRIMARY KEY (`cancellation_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `cancelled_by` (`cancelled_by`);

--
-- Indexes for table `clinic_closures`
--
ALTER TABLE `clinic_closures`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `consent_forms`
--
ALTER TABLE `consent_forms`
  ADD PRIMARY KEY (`consent_id`),
  ADD UNIQUE KEY `consent_forms_booking_unique` (`booking_id`);

--
-- Indexes for table `customer_notifications`
--
ALTER TABLE `customer_notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `payments_booking_unique` (`booking_id`),
  ADD KEY `processed_by` (`processed_by`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `pets`
--
ALTER TABLE `pets`
  ADD PRIMARY KEY (`pet_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `queue`
--
ALTER TABLE `queue`
  ADD PRIMARY KEY (`queue_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `updated_by` (`updated_by`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `generated_by` (`generated_by`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `time_windows`
--
ALTER TABLE `time_windows`
  ADD PRIMARY KEY (`window_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `users_phone_unique` (`phone`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `addons`
--
ALTER TABLE `addons`
  MODIFY `addon_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `booking_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `booking_pets`
--
ALTER TABLE `booking_pets`
  MODIFY `booking_pet_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `booking_services`
--
ALTER TABLE `booking_services`
  MODIFY `booking_service_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cancellations`
--
ALTER TABLE `cancellations`
  MODIFY `cancellation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clinic_closures`
--
ALTER TABLE `clinic_closures`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `consent_forms`
--
ALTER TABLE `consent_forms`
  MODIFY `consent_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_notifications`
--
ALTER TABLE `customer_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=102;

--
-- AUTO_INCREMENT for table `pets`
--
ALTER TABLE `pets`
  MODIFY `pet_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `queue`
--
ALTER TABLE `queue`
  MODIFY `queue_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `report_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `service_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `time_windows`
--
ALTER TABLE `time_windows`
  MODIFY `window_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`window_id`) REFERENCES `time_windows` (`window_id`) ON DELETE SET NULL;

--
-- Constraints for table `booking_pets`
--
ALTER TABLE `booking_pets`
  ADD CONSTRAINT `booking_pets_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `booking_pets_ibfk_2` FOREIGN KEY (`pet_id`) REFERENCES `pets` (`pet_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `booking_pets_ibfk_3` FOREIGN KEY (`groomer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `booking_services`
--
ALTER TABLE `booking_services`
  ADD CONSTRAINT `booking_services_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `booking_services_ibfk_2` FOREIGN KEY (`booking_pet_id`) REFERENCES `booking_pets` (`booking_pet_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `booking_services_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `booking_services_ibfk_4` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`addon_id`) ON DELETE SET NULL;

--
-- Constraints for table `cancellations`
--
ALTER TABLE `cancellations`
  ADD CONSTRAINT `cancellations_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cancellations_ibfk_2` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `consent_forms`
--
ALTER TABLE `consent_forms`
  ADD CONSTRAINT `consent_forms_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `pets`
--
ALTER TABLE `pets`
  ADD CONSTRAINT `pets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `queue`
--
ALTER TABLE `queue`
  ADD CONSTRAINT `queue_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `queue_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
