-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 21, 2026 at 03:23 PM
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
  `status` enum('waiting_to_arrive','checked_in','in_progress','for_pickup','archived','waiting','groomed','waiting_for_payment','completed','cancelled','no_show') NOT NULL DEFAULT 'waiting_to_arrive',
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

INSERT INTO `bookings` (`booking_id`, `booking_reference`, `user_id`, `window_id`, `booking_date`, `number_of_pets`, `booking_type`, `status`, `archived_at`, `queue_number`, `special_notes`, `cancellation_reason`, `total_amount`, `created_at`, `updated_at`, `reschedule_count`, `cancel_count`) VALUES
(1, 'BAC-20260415-0001', 6, 3, '2026-04-23', 1, 'online', 'cancelled', NULL, NULL, 'My dog is friendly', NULL, 0.00, '2026-04-11 21:23:56', '2026-04-15 21:58:57', 1, 1),
(2, 'BAC-20260413-0002', 7, 1, '2026-04-13', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-11 21:47:08', '2026-04-11 21:47:08', 0, 0),
(3, 'BAC-20260412-0003', 7, 1, '2026-04-12', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-11 21:59:58', '2026-04-11 21:59:58', 0, 0),
(4, 'BAC-20260414-0001', 7, 3, '2026-04-14', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-13 23:09:15', '2026-04-13 23:09:15', 0, 0),
(5, 'BAC-20260415-0002', 7, 1, '2026-04-15', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-13 23:20:08', '2026-04-13 23:20:08', 0, 0),
(6, 'BAC-20260416-0001', 6, 4, '2026-04-16', 1, 'online', 'cancelled', NULL, NULL, NULL, NULL, 0.00, '2026-04-14 23:02:38', '2026-04-15 22:40:29', 0, 1),
(7, 'BAC-20260416-0002', 10, 4, '2026-04-16', 1, 'online', 'archived', '2026-04-17 06:31:50', 1, NULL, NULL, 0.00, '2026-04-14 23:15:02', '2026-04-17 22:31:50', 0, 0),
(8, 'BAC-20260415-0003', 10, 3, '2026-04-15', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-14 23:19:05', '2026-04-14 23:19:05', 0, 0),
(9, 'BAC-20260417-0001', 6, 3, '2026-04-17', 1, 'online', 'cancelled', NULL, NULL, NULL, NULL, 0.00, '2026-04-15 21:11:12', '2026-04-15 22:39:48', 0, 1),
(12, 'BAC-20260417-0002', 11, 1, '2026-04-17', 1, 'online', 'cancelled', NULL, 1, NULL, NULL, 0.00, '2026-04-16 21:19:35', '2026-04-16 21:43:52', 0, 1),
(13, 'BAC-20260417-0003', 6, 4, '2026-04-16', 1, 'online', 'cancelled', NULL, 2, NULL, NULL, 0.00, '2026-04-16 21:33:57', '2026-04-16 21:40:57', 1, 1),
(14, 'BAC-20260418-0001', 6, 1, '2026-04-18', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-16 21:42:09', '2026-04-16 21:42:09', 0, 0),
(25, 'BAC-20260417-0004', 12, 2, '2026-04-17', 1, 'online', 'for_pickup', NULL, 2, NULL, NULL, 0.00, '2026-04-16 21:56:02', '2026-04-17 22:29:30', 0, 0),
(26, 'BAC-20260417-0005', 11, 6, '2026-04-17', 1, 'online', 'archived', '2026-04-17 06:27:56', 1, NULL, NULL, 0.00, '2026-04-16 22:50:40', '2026-04-17 22:27:56', 0, 0),
(27, 'BAC-20260418-0002', 12, 3, '2026-04-18', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-17 21:55:06', '2026-04-17 21:57:24', 1, 0),
(28, 'BAC-20260418-0003', 11, 3, '2026-04-18', 1, 'online', 'waiting_to_arrive', NULL, NULL, NULL, NULL, 0.00, '2026-04-17 21:56:01', '2026-04-17 21:56:01', 0, 0);

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
(9, 9, 7, NULL, NULL, NULL, NULL),
(10, 12, 8, NULL, NULL, NULL, NULL),
(11, 13, 9, NULL, NULL, NULL, NULL),
(12, 14, 10, NULL, NULL, NULL, NULL),
(13, 25, 11, NULL, NULL, NULL, NULL),
(14, 26, 12, NULL, NULL, NULL, NULL),
(15, 27, 11, NULL, NULL, NULL, NULL),
(16, 28, 8, NULL, NULL, NULL, NULL);

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

--
-- Dumping data for table `booking_services`
--

INSERT INTO `booking_services` (`booking_service_id`, `booking_id`, `booking_pet_id`, `service_id`, `addon_id`, `price_at_booking`) VALUES
(1, 26, 14, 1, NULL, 0.00),
(2, 27, 15, 4, NULL, 0.00),
(3, 28, 16, 4, NULL, 0.00);

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
(3, '2026_04_17_142057_add_archived_at_to_bookings_table', 2),
(4, '2026_04_17_142718_add_archived_to_bookings_status_enum', 3),
(5, '2026_04_17_145112_add_is_archived_to_users_table', 4);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(10) UNSIGNED NOT NULL,
  `type` enum('booked','cancelled','rescheduled') NOT NULL,
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
(4, 'booked', 9, 'New booking BAC-20260417-0001 by Juan Dela Cruz on 2026-04-17 at 10:00 AM - 11:00 AM.', 1, '2026-04-15 05:11:12'),
(5, 'rescheduled', 1, 'Booking BAC-20260415-0001 was rescheduled by Juan Dela Cruz to 2026-04-23 at 10:00 AM - 11:00 AM.', 1, '2026-04-15 05:15:33'),
(6, 'cancelled', 1, 'Booking BAC-20260415-0001 was cancelled by Juan Dela Cruz.', 1, '2026-04-15 05:58:57'),
(7, 'cancelled', 9, 'Booking BAC-20260417-0001 was cancelled by Juan Dela Cruz.', 1, '2026-04-15 06:39:48'),
(8, 'cancelled', 6, 'Booking BAC-20260416-0001 was cancelled by Juan Dela Cruz.', 1, '2026-04-15 06:40:29'),
(9, 'booked', 12, 'New booking BAC-20260417-0002 by Gerald Senining on 2026-04-17 at 8:00 AM - 9:00 AM.', 1, '2026-04-16 05:19:35'),
(10, 'booked', 13, 'New booking BAC-20260417-0003 by Juan Dela Cruz on 2026-04-17 at 10:00 AM - 11:00 AM.', 1, '2026-04-16 05:33:57'),
(11, 'rescheduled', 13, 'Booking BAC-20260417-0003 was rescheduled by Juan Dela Cruz to 2026-04-16 at 11:00 AM - 12:00 PM.', 1, '2026-04-16 05:39:09'),
(12, 'cancelled', 13, 'Booking BAC-20260417-0003 was cancelled by Juan Dela Cruz.', 1, '2026-04-16 05:40:57'),
(13, 'booked', 14, 'New booking BAC-20260418-0001 by Juan Dela Cruz on 2026-04-18 at 8:00 AM - 9:00 AM.', 1, '2026-04-16 05:42:09'),
(14, 'cancelled', 12, 'Booking BAC-20260417-0002 was cancelled by Gerald Senining.', 1, '2026-04-16 05:43:52'),
(15, 'booked', 25, 'New booking BAC-20260417-0004 by Juan Oniram on 2026-04-17 at 9:00 AM - 10:00 AM.', 1, '2026-04-16 05:56:02'),
(16, 'booked', 26, 'New booking BAC-20260417-0005 by Gerald Senining on 2026-04-17 at 2:00 PM - 3:00 PM.', 0, '2026-04-16 06:50:40'),
(17, 'booked', 27, 'New booking BAC-20260418-0002 by Juan Oniram on 2026-04-18 at 10:00 AM - 11:00 AM.', 0, '2026-04-17 05:55:06'),
(18, 'booked', 28, 'New booking BAC-20260418-0003 by Gerald Senining on 2026-04-18 at 10:00 AM - 11:00 AM.', 0, '2026-04-17 05:56:01'),
(19, 'rescheduled', 27, 'Booking BAC-20260418-0002 was rescheduled by Juan Oniram to 2026-04-18 at 10:00 AM - 11:00 AM.', 0, '2026-04-17 05:57:24');

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
(43, 'App\\Models\\User', 10, 'auth_token', '7286e9926d9619dae19c4dde6bca92f230de37c152885c694b9e528a9ec64a83', '[\"*\"]', '2026-04-14 07:19:05', NULL, '2026-04-14 07:18:39', '2026-04-14 07:19:05'),
(68, 'App\\Models\\User', 11, 'auth_token', '42607f740bdb3ec218cd20edae9e8c8c20d874126f79128e41a5c804a0cdd74b', '[\"*\"]', '2026-04-17 05:56:37', NULL, '2026-04-17 05:55:30', '2026-04-17 05:56:37'),
(69, 'App\\Models\\User', 12, 'auth_token', '51b22990f48518752499730e0229fa2d1c956216a4feae819e0e943304b773d1', '[\"*\"]', '2026-04-17 06:54:19', NULL, '2026-04-17 05:56:53', '2026-04-17 06:54:19'),
(73, 'App\\Models\\User', 1, 'admin_token', 'ede9dee71c3bf5a47a6d6c3f93b12d7f21f93767c88003886f85ee764e9d3193', '[\"*\"]', '2026-04-21 05:06:10', NULL, '2026-04-21 05:06:09', '2026-04-21 05:06:10'),
(74, 'App\\Models\\User', 6, 'auth_token', '2dc6a887d105a37feb617eccb9d3b57d029de7562de3cc9043f00b6d2c42d8eb', '[\"*\"]', '2026-04-21 05:13:24', NULL, '2026-04-21 05:11:09', '2026-04-21 05:13:24');

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
(7, 6, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'medium', 'gffdg', '2026-04-15 21:11:12'),
(8, 11, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'short', 'ssd  dsg', '2026-04-16 21:19:35'),
(9, 6, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'short', 'ssd  dsg', '2026-04-16 21:33:57'),
(10, 6, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'short', 'ssd  dsg', '2026-04-16 21:42:09'),
(11, 12, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'medium', 'gffdg', '2026-04-16 21:56:02'),
(12, 11, 'max', 'Dog', 'golden retriever', 12.00, NULL, 'extra_large', 'medium', 'gffdg', '2026-04-16 22:50:40');

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
  `slug` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `base_price` decimal(8,2) NOT NULL DEFAULT 0.00,
  `price_small` decimal(8,2) DEFAULT NULL,
  `price_medium` decimal(8,2) DEFAULT NULL,
  `price_large` decimal(8,2) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `service_name`, `slug`, `description`, `base_price`, `price_small`, `price_medium`, `price_large`, `is_active`, `created_at`) VALUES
(1, 'Partial Grooming', 'partial_grooming', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(2, 'Regular Dog Grooming', 'regular_dog_grooming', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(3, 'Deluxe Dog Grooming', 'deluxe_dog_grooming', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(4, 'Bath and Go', 'bath_and_go', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(5, 'Cat Full Grooming', 'cat_full_grooming', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(6, 'Nail Clipping', 'nail_clipping', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(7, 'Ear Cleaning', 'ear_cleaning', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(8, 'Facial Trimming', 'facial_trimming', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(9, 'Anal Sac Draining', 'anal_sac_draining', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47'),
(10, 'Tooth Brushing', 'tooth_brushing', NULL, 0.00, NULL, NULL, NULL, 1, '2026-04-16 22:47:47');

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
(7, 'Ridge', 'Marino', '09987654321', 'ridgee.marino@gmail.com', '$2y$12$DY9RJM1AKsKAzp1SsCrIEud2RBEG8nlx7efc6F9NTTLuf8fY8h9Ha', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-11 10:36:43', '2026-04-11 10:36:43'),
(8, 'rij', 'Marino', '09121234567', 'ridge@gmail.con', '$2y$12$.7Yamcsy4F0h7GIpkWJ0IOMxXI8oITtNe.t9vq2dGQJQ/Ei4oCwWu', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-14 21:04:00', '2026-04-14 21:04:00'),
(9, 'Ridge', 'Oniram', '09123768577', 'fhg@jdgk.jfj', '$2y$12$QiqtINOCDLOD5D2/JDLPj.7rsVQh9CJHkQgh/onFAs8gsmbe4P8Eq', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-14 21:12:05', '2026-04-14 21:12:05'),
(10, 'fds', 'sytre', '09999999999', '091863236@agd', '$2y$12$4atrH1l0fD78eNDcETDs2O8/TupcTUgHLE4lLogixefe6LPLiTWVS', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-14 23:04:30', '2026-04-14 23:04:30'),
(11, 'Gerald', 'Senining', '09090909099', 'juan23@test.comert', '$2y$12$luN32Kq9JBTWN4gZVe/LUOWQ1EjYSEpzniT2HDWytNtrR7vc5cn2y', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-16 21:14:49', '2026-04-16 21:14:49'),
(12, 'Juan', 'Oniram', '09090909090', 'hgf@hdf', '$2y$12$LCfM2kgbI/do9CQhue560uQHTAmRQEA8UrL.LEaCrEo6lVkmjM8r6', 'customer', 'new', NULL, 1, 0, NULL, '2026-04-16 21:47:11', '2026-04-16 21:47:11');

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
-- Indexes for table `consent_forms`
--
ALTER TABLE `consent_forms`
  ADD PRIMARY KEY (`consent_id`),
  ADD UNIQUE KEY `consent_forms_booking_unique` (`booking_id`);

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
  ADD PRIMARY KEY (`service_id`),
  ADD UNIQUE KEY `slug` (`slug`);

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
  MODIFY `booking_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `booking_pets`
--
ALTER TABLE `booking_pets`
  MODIFY `booking_pet_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `booking_services`
--
ALTER TABLE `booking_services`
  MODIFY `booking_service_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `cancellations`
--
ALTER TABLE `cancellations`
  MODIFY `cancellation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consent_forms`
--
ALTER TABLE `consent_forms`
  MODIFY `consent_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `pets`
--
ALTER TABLE `pets`
  MODIFY `pet_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

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
  MODIFY `service_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `time_windows`
--
ALTER TABLE `time_windows`
  MODIFY `window_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

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
