<?php
require_once __DIR__ . '/db.php';

// Simple CLI helper to seed an admin account.
// Usage: php create_admin_user.php username plain_text_password
if (php_sapi_name() !== 'cli') {
    echo "This helper must be run from the command line.\n";
    exit(1);
}

if ($argc !== 3) {
    echo "Usage: php create_admin_user.php <username> <plain_text_password>\n";
    exit(1);
}

$username = trim($argv[1]);
$password = $argv[2];

if ($username === '' || $password === '') {
    echo "Username and password must not be empty.\n";
    exit(1);
}

try {
    $pdo = get_db_connection();

    $sql = "INSERT INTO admin_users (username, password_hash, is_active) VALUES (:username, :password_hash, 1)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':username' => $username,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    echo "Admin user created: {$username}\n";
} catch (PDOException $e) {
    echo "Error creating admin user: " . $e->getMessage() . "\n";
    exit(1);
}
