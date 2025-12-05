<?php
require_once __DIR__ . '/auth.php';

// Only authenticated users may request partial content.
require_authentication();

$allowedViews = [
    'dashboard' => __DIR__ . '/partials/dashboard.php',
    'suppliers' => __DIR__ . '/partials/suppliers.php',
    'order_books' => __DIR__ . '/partials/order_books.php',
    'purchase_orders' => __DIR__ . '/partials/purchase_orders.php',
    'line_entry_enquiry' => __DIR__ . '/partials/line_entry_enquiry.php',
    'purchase_order_view' => __DIR__ . '/partials/purchase_order_view.php',
];

$requestedView = $_GET['view'] ?? 'dashboard';

if (!array_key_exists($requestedView, $allowedViews)) {
    http_response_code(400);
    echo '<div class="alert alert-danger" role="alert">Unknown view requested.</div>';
    exit;
}

// Include the requested partial; each file renders a HTML fragment.
include $allowedViews[$requestedView];
