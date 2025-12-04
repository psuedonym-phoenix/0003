-- Admin user table for EEMS login
-- Run this in the MySQL database (e.g., via phpMyAdmin or mysql CLI)

CREATE TABLE IF NOT EXISTS `admin_users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Example seed user (replace placeholders before running):
-- INSERT INTO admin_users (username, password_hash, is_active)
-- VALUES ('admin', '$2y$10$exampleHashFromPasswordHashFunction', 1);
