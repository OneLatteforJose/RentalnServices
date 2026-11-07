-- Adds the table the manager side uses to recommend new providers to the admin.
-- Safe to run more than once. Run it in phpMyAdmin / Navicat on the booking_and_services database.
CREATE TABLE IF NOT EXISTS `provider_recommendations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `manager_id` int NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `business_name` varchar(150) NULL DEFAULT NULL,
  `phone` varchar(30) NULL DEFAULT NULL,
  `email` varchar(150) NULL DEFAULT NULL,
  `offers` varchar(255) NULL DEFAULT NULL,
  `notes` text NULL,
  `status` enum('pending','added','dismissed') NOT NULL DEFAULT 'pending',
  `handled_by` int NULL DEFAULT NULL,
  `handled_at` datetime NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  INDEX `idx_status_created` (`status`, `created_at`),
  INDEX `idx_manager` (`manager_id`),
  CONSTRAINT `provider_recommendations_ibfk_1` FOREIGN KEY (`manager_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT `provider_recommendations_ibfk_2` FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci;
