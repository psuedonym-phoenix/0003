<?php
require_once __DIR__ . '/../auth.php';

// Ensure only authenticated users see the header.
require_authentication();
?>
<header class="app-header px-4 py-3 d-flex justify-content-between align-items-center">
    <div>
        <div class="fw-semibold">Welcome back</div>
        <small class="text-secondary">Signed in as <?php echo htmlspecialchars($_SESSION['auth_username'], ENT_QUOTES, 'UTF-8'); ?></small>
    </div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm" type="button" disabled>Profile (soon)</button>
        <a href="logout.php" class="btn btn-primary btn-sm">Logout</a>
    </div>
</header>
