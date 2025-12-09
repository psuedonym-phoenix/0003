-- Catalogue of units of measurement used for purchase order lines.
-- Run this script to create the table and seed the default unit labels.

CREATE TABLE IF NOT EXISTS units_of_measurement (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    unit_label VARCHAR(50) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO units_of_measurement (unit_label)
VALUES
    ('Each'),
    ('kg'),
    ('g'),
    ('T'),
    ('Lot'),
    ('ml'),
    ('l'),
    ('Length'),
    ('Roll'),
    ('Pack'),
    ('Box'),
    ('Tube'),
    ('m'),
    ('Set'),
    ('Blocks'),
    ('Units'),
    ('5L'),
    ('Strip'),
    ('Cut'),
    ('Pair'),
    ('Bag'),
    ('Ream'),
    ('m²'),
    ('Panel'),
    ('Trip'),
    ('km'),
    ('Sheet'),
    ('hr'),
    ('m³'),
    ('25L'),
    ('MWK'),
    ('p/day'),
    ('kWh')
ON DUPLICATE KEY UPDATE unit_label = VALUES(unit_label);
