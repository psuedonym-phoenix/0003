<?php
// Dashboard landing content. Keep it lightweight so it can be fetched dynamically.
?>
<div class="visually-hidden" data-page-title="Dashboard"></div>
<div class="row g-3">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h5 mb-3">Admin Overview</h2>
                <p class="text-secondary mb-0">Use the navigation to manage suppliers or review purchase orders. More dashboard widgets can be added here to surface KPIs and recent activity.</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Suppliers</h3>
                    <button class="btn btn-outline-primary btn-sm" type="button" data-view="suppliers">Open</button>
                </div>
                <p class="text-secondary">Maintain supplier details and verify codes before uploading purchase orders.</p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h3 class="h6 mb-0">Purchase Orders</h3>
                    <button class="btn btn-outline-primary btn-sm" type="button" data-view="purchase_orders">Open</button>
                </div>
                <p class="text-secondary">Track uploaded order books, compare versions, and drill into lines.</p>
            </div>
        </div>
    </div>
</div>
