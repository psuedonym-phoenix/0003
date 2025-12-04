<?php
require_once __DIR__ . '/auth.php';

// Protect this page; unauthenticated users go to login.
require_authentication();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EEMS Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">EEMS Admin Dashboard</h1>
        <a href="logout.php" class="btn btn-outline-secondary btn-sm">Logout</a>
    </div>
    <div class="alert alert-info" role="alert">
        Welcome, <?php echo htmlspecialchars($_SESSION['auth_username'], ENT_QUOTES, 'UTF-8'); ?>. Build out dashboard widgets or navigation here.
    </div>
</div>
</body>
</html>
