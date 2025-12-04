<?php
require_once __DIR__ . '/config.php';

/**
 * Create a PDO connection using the configured credentials.
 *
 * Using a function keeps the connection logic consistent across pages and makes
 * it easier to introduce pooling or logging later if required.
 */
function get_db_connection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = "mysql:host={$GLOBALS['DB_HOST']};dbname={$GLOBALS['DB_NAME']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $pdo = new PDO($dsn, $GLOBALS['DB_USER'], $GLOBALS['DB_PASS'], $options);
    }

    return $pdo;
}
