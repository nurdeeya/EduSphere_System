-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 26, 2026 at 07:49 AM
-- Server version: 8.4.7
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `edusphere_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

DROP TABLE IF EXISTS `assignments`;
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `description` text,
  `file_path` varchar(255) DEFAULT NULL,
  `deadline` datetime NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `title`, `description`, `file_path`, `deadline`, `created_by`, `created_at`) VALUES
(1, 'Test', 'sad', 'c205e7432543e599be2b3e1703566295.pdf', '2026-10-27 00:43:00', 6, '2026-10-24 16:44:02'),
(2, 'Assignment 1: Late Upload Test', 'Testing Late Upload', NULL, '2026-10-25 04:48:00', 6, '2026-10-24 20:48:38'),
(3, 'Assignment 2 10% Energy Computing', '', '2bfa3156ad8ee3c4166b0a538551e09d.pdf', '2026-10-28 17:36:00', 3, '2026-10-25 09:36:42');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 2, 'SUBMISSION_CREATED', 'assignment_id=1; file=bca08ad460ecfb710786062377cf46ba.pdf; status=Submitted', '::1', '2026-10-24 20:46:32'),
(2, 2, 'SUBMISSION_CREATED', 'assignment_id=2; file=1f9574015903ed45bad5696e2f9d8df8.pdf; status=Late', '::1', '2026-10-24 20:49:22'),
(3, 2, 'ACCESS_DENIED', 'student_view_assignment id=3 not enrolled or missing', '::1', '2026-10-26 07:37:20'),
(4, 2, 'ACCESS_DENIED', 'student_view_assignment id=3 not enrolled or missing', '::1', '2026-10-26 07:37:24'),
(5, 2, 'ACCESS_DENIED', 'student_view_assignment id=4 not enrolled or missing', '::1', '2026-10-26 07:37:27'),
(6, 5, 'ACCESS_DENIED', 'student_view_assignment id=3 not enrolled or missing', '::1', '2026-10-26 07:44:16');

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

DROP TABLE IF EXISTS `enrollments`;
CREATE TABLE IF NOT EXISTS `enrollments` (
  `student_id` int UNSIGNED NOT NULL,
  `lecturer_id` int UNSIGNED NOT NULL,
  `enrolled_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`student_id`,`lecturer_id`),
  KEY `idx_lecturer` (`lecturer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`student_id`, `lecturer_id`, `enrolled_at`) VALUES
(1, 3, '2026-10-26 07:35:31'),
(2, 6, '2026-10-26 07:47:56'),
(5, 6, '2026-10-26 07:35:31');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `attempt_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email_submitted` varchar(254) DEFAULT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attempt_id`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`),
  KEY `idx_email_time` (`email_submitted`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

DROP TABLE IF EXISTS `submissions`;
CREATE TABLE IF NOT EXISTS `submissions` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `assignment_id` int UNSIGNED NOT NULL,
  `student_id` int UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` datetime NOT NULL,
  `status` enum('Submitted','Late') NOT NULL,
  `remarks` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assignment_student` (`assignment_id`,`student_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `submissions`
--

INSERT INTO `submissions` (`id`, `assignment_id`, `student_id`, `file_path`, `submitted_at`, `status`, `remarks`) VALUES
(1, 1, 2, 'bca08ad460ecfb710786062377cf46ba.pdf', '2026-10-25 04:46:32', 'Submitted', 'Test'),
(2, 2, 2, '1f9574015903ed45bad5696e2f9d8df8.pdf', '2026-10-25 04:49:22', 'Late', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `login_identifier` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','lecturer') NOT NULL DEFAULT 'student',
  `failed_login_attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `mfa_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `mfa_secret` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `login_identifier` (`login_identifier`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `login_identifier`, `full_name`, `email`, `password_hash`, `role`, `failed_login_attempts`, `locked_until`, `mfa_enabled`, `mfa_secret`, `is_active`, `created_at`) VALUES
(1, 'BSW98876541', 'Test Student', 'test.student@example.com', '$2y$10$Tyfe4LwT5TAFUGK6sHYOsucoZNK4OD68AmH3fKeBGN4o73yCEn6zC', 'student', 0, NULL, 0, NULL, 1, '2026-10-20 13:57:21'),
(2, 'BSW98888811', 'Naim Hakim', 'Hakim@gmail.com', '$2y$10$CIcYJJB.1HzfWiWa0mr4OuHa/aDcezIn.pEJr4n2ho3eSYt5FLho.', 'student', 0, NULL, 0, NULL, 1, '2026-10-20 17:49:03'),
(3, 'AhmadDanial', 'Ahmad Danial', 'Danial@gmail.com', '$2y$10$pJGqResjTxYkmwj6tM/vaOHlcV0AFINPYOkiRhMvl/y6h4vtDIcG6', 'lecturer', 0, NULL, 0, NULL, 1, '2026-10-20 17:49:03'),
(4, 'KamalAbdul', 'Kamal Abdullah', 'Kamal@gmail.com', '$2y$10$7McwUQyhdJ3Dw227U.6QBe/.H.KR2BrUkY2bRSZQKy0umitS6b1Su', 'lecturer', 1, NULL, 0, NULL, 1, '2026-10-21 15:20:18'),
(5, 'BSW12345678', 'Aqilah Balqis', 'Aqilah@gmail.com', '$2y$10$VhmJUJ0PbcyK/Ki4utgo4uIadRiq6fVr4lCYplSQ5gRhMBxx41XZm', 'student', 0, NULL, 0, NULL, 1, '2026-10-21 15:41:22'),
(6, 'HashimBakar', 'Hashim Bakar', 'Hashim@gmail.com', '$2y$10$iKU6bbv.MOo7RBY/wVoV8eCVAitiu2XLMlRUh4FbeAm6Mwi89BXJG', 'lecturer', 0, NULL, 0, NULL, 1, '2026-10-21 15:55:32');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `fk_enrol_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_enrol_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `submissions`
--
ALTER TABLE `submissions`
  ADD CONSTRAINT `fk_submissions_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_submissions_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
