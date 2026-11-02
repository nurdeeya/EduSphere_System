-- Lecturer Module update (Siti Sarah) - run AFTER importing edusphere_system.sql.
-- phpMyAdmin: select database `edusphere_system` -> Import -> choose this file.
-- Safe to run more than once.

-- FR4: Upload Learning Materials (notes, slides) separate from assignment files.
CREATE TABLE IF NOT EXISTS `learning_materials` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `lecturer_id` int UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` int UNSIGNED NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_lecturer` (`lecturer_id`),
  CONSTRAINT `fk_material_lecturer` FOREIGN KEY (`lecturer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SR5: Deadline Change Notification - one row per enrolled student when a deadline changes.
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `assignment_id` int UNSIGNED DEFAULT NULL,
  `message` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`, `is_read`),
  CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notification_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
