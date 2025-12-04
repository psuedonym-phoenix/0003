<?php
require_once __DIR__ . '/auth.php';

ensure_session_started();

// Redirect authenticated users straight to the admin landing page (placeholder index.php).
if (is_authenticated()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } elseif (authenticate_user($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid credentials or inactive account.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EEMS Admin Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-header text-center bg-primary text-white">
                    <strong>EEMS Admin</strong>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger" role="alert">
                            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" autocomplete="off">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">Sign in</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
