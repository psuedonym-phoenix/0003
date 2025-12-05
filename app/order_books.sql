-- Order book metadata table
-- Keeps human-friendly descriptions and visibility flags for each book code.
-- Run this script on the filiades_eems database before using the UI filter.

CREATE TABLE IF NOT EXISTS `order_books` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `book_code` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    `description_2` VARCHAR(255) NOT NULL DEFAULT '',
    `qty` INT NOT NULL DEFAULT 0,
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_order_books_code` (`book_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adds placeholder Description 2 and Qty values; admins can refine later.
INSERT IGNORE INTO `order_books` (`book_code`, `description`, `description_2`, `qty`, `is_visible`)
SELECT DISTINCT
    `order_book` AS book_code,
    COALESCE(NULLIF(`order_book`, ''), 'Unspecified Book') AS description,
    '' AS description_2,
    0 AS qty,
    1 AS is_visible
FROM `purchase_orders`
WHERE `order_book` IS NOT NULL AND `order_book` <> '';
