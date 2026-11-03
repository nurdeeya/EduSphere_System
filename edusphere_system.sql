-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 03, 2026 at 03:21 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.33

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

CREATE TABLE `assignments` (
  `id` int UNSIGNED NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text,
  `file_path` varchar(255) DEFAULT NULL,
  `deadline` datetime NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `title`, `description`, `file_path`, `deadline`, `created_by`, `created_at`) VALUES
(1, 'Test', 'sad', 'c205e7432543e599be2b3e1703566295.pdf', '2026-10-27 00:43:00', 6, '2026-10-24 16:44:02'),
(2, 'Assignment 1: Late Upload Test', 'Testing Late Upload', NULL, '2026-10-25 04:48:00', 6, '2026-10-24 20:48:38'),
(3, 'Assignment 2 10% Energy Computing', '', '2bfa3156ad8ee3c4166b0a538551e09d.pdf', '2026-10-28 17:36:00', 3, '2026-10-25 09:36:42'),
(4, 'Testing New', NULL, '8f79f2b7b932d00e39c80ffc74505a4d.pdf', '2026-11-04 14:46:00', 6, '2026-11-03 06:46:49');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 2, 'SUBMISSION_CREATED', 'assignment_id=1; file=bca08ad460ecfb710786062377cf46ba.pdf; status=Submitted', '::1', '2026-10-24 20:46:32'),
(2, 2, 'SUBMISSION_CREATED', 'assignment_id=2; file=1f9574015903ed45bad5696e2f9d8df8.pdf; status=Late', '::1', '2026-10-24 20:49:22'),
(3, 2, 'ACCESS_DENIED', 'student_view_assignment id=3 not enrolled or missing', '::1', '2026-10-26 07:37:20'),
(4, 2, 'ACCESS_DENIED', 'student_view_assignment id=3 not enrolled or missing', '::1', '2026-10-26 07:37:24'),
(5, 2, 'ACCESS_DENIED', 'student_view_assignment id=4 not enrolled or missing', '::1', '2026-10-26 07:37:27'),
(6, 5, 'ACCESS_DENIED', 'student_view_assignment id=3 not enrolled or missing', '::1', '2026-10-26 07:44:16'),
(7, 6, 'ASSIGNMENT_CREATED', 'assignment_id=4; deadline=2026-11-04T14:46; file=8f79f2b7b932d00e39c80ffc74505a4d.pdf', '::1', '2026-11-03 06:46:49'),
(8, 6, 'MATERIAL_UPLOADED', 'material_id=1; file=d19576665c18836aff0a91dcb1f75e7d.pdf', '::1', '2026-11-03 07:03:11'),
(9, 6, 'MATERIAL_UPLOADED', 'material_id=2; file=e329e466b6a8240b5188620f6581e0c8.pdf', '::1', '2026-11-03 07:16:45');

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `student_id` int UNSIGNED NOT NULL,
  `lecturer_id` int UNSIGNED NOT NULL,
  `enrolled_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
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
-- Table structure for table `learning_materials`
--

CREATE TABLE `learning_materials` (
  `id` int UNSIGNED NOT NULL,
  `lecturer_id` int UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int UNSIGNED NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `learning_materials`
--

INSERT INTO `learning_materials` (`id`, `lecturer_id`, `title`, `description`, `file_path`, `file_size`, `uploaded_at`) VALUES
(1, 6, 'Week 2', NULL, 'd19576665c18836aff0a91dcb1f75e7d.pdf', 968299, '2026-11-03 07:03:11'),
(2, 6, 'Testing', NULL, 'e329e466b6a8240b5188620f6581e0c8.pdf', 1102544, '2026-11-03 07:16:45');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `attempt_id` bigint UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `email_submitted` varchar(254) DEFAULT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `assignment_id` int UNSIGNED DEFAULT NULL,
  `message` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submissions`
--

CREATE TABLE `submissions` (
  `id` int UNSIGNED NOT NULL,
  `assignment_id` int UNSIGNED NOT NULL,
  `student_id` int UNSIGNED NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `submitted_at` datetime NOT NULL,
  `status` enum('Submitted','Late') NOT NULL,
  `remarks` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

CREATE TABLE `users` (
  `user_id` int UNSIGNED NOT NULL,
  `login_identifier` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','lecturer','admin') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'student',
  `failed_login_attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `mfa_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `mfa_secret` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `login_identifier`, `full_name`, `email`, `password_hash`, `role`, `failed_login_attempts`, `locked_until`, `mfa_enabled`, `mfa_secret`, `is_active`, `created_at`) VALUES
(1, 'BSW98876541', 'Test Student', 'test.student@example.com', '$2y$10$Tyfe4LwT5TAFUGK6sHYOsucoZNK4OD68AmH3fKeBGN4o73yCEn6zC', 'student', 3, NULL, 0, NULL, 1, '2026-10-20 13:57:21'),
(2, 'BSW98888811', 'Naim Hakim', 'Hakim@gmail.com', '$2y$10$CIcYJJB.1HzfWiWa0mr4OuHa/aDcezIn.pEJr4n2ho3eSYt5FLho.', 'student', 0, NULL, 0, NULL, 1, '2026-10-20 17:49:03'),
(3, 'AhmadDanial', 'Ahmad Danial', 'Danial@gmail.com', '$2y$10$pJGqResjTxYkmwj6tM/vaOHlcV0AFINPYOkiRhMvl/y6h4vtDIcG6', 'lecturer', 0, NULL, 0, NULL, 1, '2026-10-20 17:49:03'),
(4, 'KamalAbdul', 'Kamal Abdullah', 'Kamal@gmail.com', '$2y$10$7McwUQyhdJ3Dw227U.6QBe/.H.KR2BrUkY2bRSZQKy0umitS6b1Su', 'lecturer', 1, NULL, 0, NULL, 1, '2026-10-21 15:20:18'),
(5, 'BSW12345678', 'Aqilah Balqis', 'Aqilah@gmail.com', '$2y$10$VhmJUJ0PbcyK/Ki4utgo4uIadRiq6fVr4lCYplSQ5gRhMBxx41XZm', 'student', 0, NULL, 0, NULL, 1, '2026-10-21 15:41:22'),
(6, 'HashimBakar', 'Hashim Bakar', 'Hashim@gmail.com', '$2y$10$iKU6bbv.MOo7RBY/wVoV8eCVAitiu2XLMlRUh4FbeAm6Mwi89BXJG', 'lecturer', 0, NULL, 0, NULL, 1, '2026-10-21 15:55:32'),
(7, 'admin.esphere', 'EduSphere Administrator', 'admin@edusphere.local', '$2y$10$kkd5JYQhjyCRx9EwTymKfeHlIUpFjCkE8Zpj0tI7zNfuFIDwaNijG', 'admin', 0, NULL, 1, 'XXV3LA7OL4DCGIW4WBWRLKU3KHB5AMT4', 1, '2026-11-03 14:02:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`student_id`,`lecturer_id`),
  ADD KEY `idx_lecturer` (`lecturer_id`);

--
-- Indexes for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lecturer` (`lecturer_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`attempt_id`),
  ADD KEY `idx_ip_time` (`ip_address`,`attempted_at`),
  ADD KEY `idx_email_time` (`email_submitted`,`attempted_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `fk_notification_assignment` (`assignment_id`);

--
-- Indexes for table `submissions`
--
ALTER TABLE `submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_assignment_student` (`assignment_id`,`student_id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `login_identifier` (`login_identifier`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `learning_materials`
--
ALTER TABLE `learning_materials`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `attempt_id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `submissions`
--
ALTER TABLE `submissions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

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
-- Constraints for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD CONSTRAINT `fk_material_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notification_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

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
