<?php
require_once __DIR__ . '/db.php';

/**
 * Start a secure session if it is not already active.
 */
function ensure_session_started(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/**
 * Attempt to authenticate a user by username and password.
 *
 * Expects a table `admin_users` with fields:
 *  - username (unique)
 *  - password_hash (bcrypt recommended)
 *  - is_active (tinyint/bool flag)
 */
function authenticate_user(string $username, string $password): bool
{
    $pdo = get_db_connection();

    $sql = "SELECT username, password_hash, is_active FROM admin_users WHERE username = :username LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['is_active'] !== 1) {
        return false;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return false;
    }

    ensure_session_started();
    $_SESSION['auth_username'] = $user['username'];
    $_SESSION['auth_login_time'] = time();

    return true;
}

/**
 * Check if the current session is authenticated.
 */
function is_authenticated(): bool
{
    ensure_session_started();
    return !empty($_SESSION['auth_username']);
}

/**
 * Require authentication for a protected page; redirects to login if missing.
 */
function require_authentication(): void
{
    if (!is_authenticated()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Destroy session and log out.
 */
function logout_user(): void
{
    ensure_session_started();

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}
