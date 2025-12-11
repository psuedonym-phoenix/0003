<?php
// Sidebar navigation rendered separately so it can be reused in other layouts.
?>
<div class="d-flex align-items-center mb-4">
    <div class="bg-primary-subtle rounded-circle d-inline-flex align-items-center justify-content-center"
        style="width: 40px; height: 40px;">
        <span class="fw-bold text-primary">E</span>
    </div>
    <div class="ms-2">
        <div class="fw-semibold">EEMS Admin</div>
        <small class="text-secondary">Purchase Orders</small>
    </div>
</div>
<nav class="nav flex-column gap-1" id="sidebarNav">
    <a class="nav-link" href="#" data-view="dashboard">Dashboard</a>
    <a class="nav-link" href="#" data-view="suppliers">Suppliers</a>
    <a class="nav-link" href="#" data-view="order_books">Order Books</a>
    <a class="nav-link" href="#" data-view="purchase_orders">Purchase Orders</a>
    <a class="nav-link" href="#" data-view="line_entry_enquiry">Line Entry Enquiry</a>
    <a class="nav-link" href="#" data-view="enquiry_cost_codes">Cost Code Enquiry</a>
</nav>
<div class="mt-4 pt-3 border-top">
    <button id="themeToggle" type="button" class="btn btn-outline-primary w-100">Toggle Dark / Light</button>
</div>