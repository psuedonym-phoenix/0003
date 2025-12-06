<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/purchase_order_view_helpers.php';

// Restrict access to authenticated admins only.
require_authentication();

// Helper to safely escape output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Provide consistent display values for optional text fields.
function display_text($value): string
{
    if ($value === null || $value === '') {
        return 'Not set';
    }

    return e((string) $value);
}

// Provide a currency-friendly display with a sensible default.
function display_amount($value): string
{
    return 'R ' . number_format((float) ($value ?? 0), 2);
}

$viewData = fetch_purchase_order_view($_GET);

if (isset($viewData['error'])) {
    http_response_code($viewData['status'] ?? 400);
    ?>
    <!DOCTYPE html>
    <html lang="en" data-bs-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Purchase Order Error</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="assets/admin.css">
    </head>
    <body>
        <div class="container py-5">
            <div class="alert alert-warning" role="alert"><?php echo e($viewData['error']); ?></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$purchaseOrder = $viewData['purchaseOrder'];
$lineItems = $viewData['lineItems'];
$poType = $viewData['poType'];
$lineColumnCount = $viewData['lineColumnCount'];
$lineSummary = $viewData['lineSummary'];
$previousPo = $viewData['previousPo'];
$nextPo = $viewData['nextPo'];
$sharedParams = $viewData['sharedParams'];
$returnParams = $viewData['returnParams'];

$returnQuery = build_query($returnParams);
$returnUrl = $returnQuery !== '' ? 'index.php?' . $returnQuery : 'index.php';

$previousUrl = $previousPo !== null ? 'po_view.php?' . build_query(array_merge($sharedParams, ['po_number' => $previousPo])) : null;
$nextUrl = $nextPo !== null ? 'po_view.php?' . build_query(array_merge($sharedParams, ['po_number' => $nextPo])) : null;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order <?php echo e($purchaseOrder['po_number']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1">Purchase Order <?php echo e($purchaseOrder['po_number']); ?></h1>
            <div class="text-secondary">Latest version with line items from purchase_order_lines.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?php echo e($returnUrl); ?>">&larr; Back to Purchase Orders</a>
            <?php if ($previousUrl !== null) : ?>
                <a class="btn btn-outline-primary" href="<?php echo e($previousUrl); ?>">&larr; Previous</a>
            <?php endif; ?>
            <?php if ($nextUrl !== null) : ?>
                <a class="btn btn-outline-primary" href="<?php echo e($nextUrl); ?>">Next &rarr;</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h2 class="h6 text-uppercase text-secondary mb-3">Header details</h2>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-secondary small">Header ID</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['id'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">PO Number</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['po_number'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Order Book</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['order_book'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Order Sheet</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['order_sheet_no'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Order Type (header)</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['order_type'] ?? ($purchaseOrder['po_type'] ?? $poType)); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Line layout</div>
                    <div class="fw-semibold"><?php echo ucfirst($poType); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Supplier ID</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['supplier_id'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Supplier Code</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['supplier_code'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Supplier Name</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['supplier_name'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Order Date</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['order_date'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Cost Code</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['cost_code'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Cost Code Description</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['cost_code_description'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Terms</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['terms'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Reference</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['reference'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Created By</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['created_by'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Source Filename</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['source_filename'] ?? ''); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Uploaded</div>
                    <div class="fw-semibold"><?php echo display_text($purchaseOrder['created_at'] ?? ''); ?></div>
                </div>
            </div>

            <hr class="my-4">

            <h3 class="h6 text-uppercase text-secondary mb-3">Financial summary</h3>
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-secondary small">Subtotal</div>
                    <div class="fw-semibold"><?php echo display_amount($purchaseOrder['subtotal'] ?? 0); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">VAT %</div>
                    <div class="fw-semibold"><?php echo number_format((float) ($purchaseOrder['vat_percent'] ?? 0), 2); ?>%</div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">VAT Amount</div>
                    <div class="fw-semibold"><?php echo display_amount($purchaseOrder['vat_amount'] ?? 0); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small"><?php echo display_text($purchaseOrder['misc1_label'] ?? 'Misc 1'); ?></div>
                    <div class="fw-semibold"><?php echo display_amount($purchaseOrder['misc1_amount'] ?? 0); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small"><?php echo display_text($purchaseOrder['misc2_label'] ?? 'Misc 2'); ?></div>
                    <div class="fw-semibold"><?php echo display_amount($purchaseOrder['misc2_amount'] ?? 0); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Total Amount</div>
                    <div class="fw-semibold"><?php echo display_amount($purchaseOrder['total_amount'] ?? 0); ?></div>
                </div>
                <div class="col-md-3">
                    <div class="text-secondary small">Calculated Lines Total</div>
                    <div class="fw-semibold"><?php echo display_amount($lineSummary['sum'] ?? 0); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h6 mb-0">Line items</h2>
                    <?php if ($poType === 'transactional') : ?>
                        <small class="text-secondary">Transactional layout showing deposits and VAT breakdowns.</small>
                    <?php else : ?>
                        <small class="text-secondary">Standard layout showing item quantities, pricing, and discounts.</small>
                    <?php endif; ?>
                </div>
                <span class="badge text-bg-light border">Total lines: <?php echo count($lineItems); ?></span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Line #</th>
                            <?php if ($poType === 'transactional') : ?>
                                <th scope="col">Date</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Deposit Amount</th>
                                <th scope="col" class="text-end">Ex VAT Amount</th>
                                <th scope="col" class="text-end">VAT Amount</th>
                                <th scope="col" class="text-end">Line Total</th>
                                <th scope="col" class="text-end">Running Total</th>
                            <?php else : ?>
                                <th scope="col">Item Code</th>
                                <th scope="col">Description</th>
                                <th scope="col" class="text-end">Quantity</th>
                                <th scope="col">Unit</th>
                                <th scope="col" class="text-end">Unit Price</th>
                                <th scope="col" class="text-end">Discount %</th>
                                <th scope="col" class="text-end">Net Price</th>
                                <th scope="col" class="text-end">Running Total</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lineItems)) : ?>
                            <tr>
                                <td colspan="<?php echo $lineColumnCount; ?>" class="text-secondary">No line items were captured for this purchase order version.</td>
                            </tr>
                        <?php else : ?>
                            <?php $runningTotal = 0.0; ?>
                            <?php foreach ($lineItems as $line) : ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e((string) ($line['line_no'] ?? '')); ?></td>
                                    <?php if ($poType === 'transactional') : ?>
                                        <td><?php echo e($line['line_date'] ?? ''); ?></td>
                                        <td><?php echo e($line['description'] ?? ''); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($line['deposit_amount'] ?? 0), 2); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($line['ex_vat_amount'] ?? 0), 2); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($line['line_vat_amount'] ?? 0), 2); ?></td>
                                        <?php $runningTotal += (float) ($line['line_total_amount'] ?? 0); ?>
                                        <td class="text-end"><?php echo number_format((float) ($line['line_total_amount'] ?? 0), 2); ?></td>
                                        <td class="text-end fw-semibold"><?php echo number_format($runningTotal, 2); ?></td>
                                    <?php else : ?>
                                        <td><?php echo e($line['item_code'] ?? ''); ?></td>
                                        <td><?php echo e($line['description'] ?? ''); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($line['quantity'] ?? 0), 2); ?></td>
                                        <td><?php echo e($line['unit'] ?? ''); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($line['unit_price'] ?? 0), 2); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($line['discount_percent'] ?? 0), 2); ?></td>
                                        <?php $runningTotal += (float) ($line['net_price'] ?? 0); ?>
                                        <td class="text-end"><?php echo number_format((float) ($line['net_price'] ?? 0), 2); ?></td>
                                        <td class="text-end fw-semibold"><?php echo number_format($runningTotal, 2); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
