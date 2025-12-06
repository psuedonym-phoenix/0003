<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../purchase_order_view_helpers.php';

// Restrict access to authenticated admins only.
require_authentication();

// Helper to safely escape output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$viewData = fetch_purchase_order_view($_GET);

if (isset($viewData['error'])) {
    http_response_code($viewData['status'] ?? 400);
    ?>
    <div class="alert alert-warning" role="alert"><?php echo e($viewData['error']); ?></div>
    <?php
    return;
}

$purchaseOrder = $viewData['purchaseOrder'];
$lineItems = $viewData['lineItems'];
$poType = $viewData['poType'];
$lineColumnCount = $viewData['lineColumnCount'];
$previousPo = $viewData['previousPo'];
$nextPo = $viewData['nextPo'];
$sharedParams = $viewData['sharedParams'];
$returnParams = $viewData['returnParams'];
$lineSummary = $viewData['lineSummary'];
$returnViewLabel = ($returnParams['view'] ?? 'purchase_orders') === 'line_entry_enquiry'
    ? 'Back to Line Entry Enquiry'
    : 'Back to Purchase Orders';

// Normalise key financial amounts so the view can show consistent figures
// regardless of whether older columns like exclusive_amount are still present.
$exclusiveAmount = (float) ($purchaseOrder['subtotal'] ?? $purchaseOrder['exclusive_amount'] ?? 0);
$inclusiveAmount = (float) ($purchaseOrder['total_amount'] ?? 0);
$vatPercent = (float) ($purchaseOrder['vat_percent'] ?? 0);
$vatAmount = (float) ($purchaseOrder['vat_amount'] ?? 0);
$calculatedLineTotal = (float) ($lineSummary['sum'] ?? 0);

// Build query strings for navigation so AJAX links can pass parameters via data-params and fall back to href navigation.
$returnQuery = build_query($returnParams);
$returnHref = $returnQuery !== '' ? 'index.php?' . e($returnQuery) : 'index.php';

$previousQuery = $previousPo !== null ? build_query(array_merge($sharedParams, ['po_number' => $previousPo])) : '';
$nextQuery = $nextPo !== null ? build_query(array_merge($sharedParams, ['po_number' => $nextPo])) : '';
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h5 mb-1">Purchase Order <?php echo e($purchaseOrder['po_number']); ?></h1>
            <div class="text-secondary">Latest version with line items from purchase_order_lines.</div>
        </div>
        <div class="d-flex gap-2">
            <a
                class="btn btn-outline-secondary"
                href="<?php echo $returnHref; ?>"
                data-view="<?php echo e($returnParams['view'] ?? 'purchase_orders'); ?>"
                data-params="<?php echo e($returnQuery); ?>"
            >
                &larr; <?php echo e($returnViewLabel); ?>
            </a>
            <?php if ($previousQuery !== '') : ?>
                <a
                    class="btn btn-outline-primary"
                    href="index.php?view=purchase_order_view&amp;<?php echo e($previousQuery); ?>"
                    data-view="purchase_order_view"
                    data-params="<?php echo e($previousQuery); ?>"
                >
                    &larr; Previous
                </a>
            <?php endif; ?>
            <?php if ($nextQuery !== '') : ?>
                <a
                    class="btn btn-outline-primary"
                    href="index.php?view=purchase_order_view&amp;<?php echo e($nextQuery); ?>"
                    data-view="purchase_order_view"
                    data-params="<?php echo e($nextQuery); ?>"
                >
                    Next &rarr;
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-secondary small">Order Book</div>
                <div class="fw-semibold"><?php echo e($purchaseOrder['order_book'] ?? 'Not set'); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Purchase Order Type</div>
                <div class="fw-semibold"><?php echo ucfirst($poType); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Supplier</div>
                <div class="fw-semibold"><?php echo e($purchaseOrder['supplier_name'] ?? ''); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Order Date</div>
                <div class="fw-semibold"><?php echo e($purchaseOrder['order_date'] ?? ''); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Order Sheet</div>
                <div class="fw-semibold"><?php echo e($purchaseOrder['order_sheet_no'] ?? ''); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Reference</div>
                <div class="fw-semibold"><?php echo e($purchaseOrder['reference'] ?? ''); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Exclusive Amount</div>
                <div class="fw-semibold">R <?php echo number_format($exclusiveAmount, 2); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">VAT %</div>
                <div class="fw-semibold"><?php echo number_format($vatPercent, 2); ?>%</div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">VAT Amount</div>
                <div class="fw-semibold">R <?php echo number_format($vatAmount, 2); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Inclusive Amount</div>
                <div class="fw-semibold">R <?php echo number_format($inclusiveAmount, 2); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Total Amount (Lines)</div>
                <div class="fw-semibold">R <?php echo number_format($calculatedLineTotal, 2); ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-secondary small">Uploaded</div>
                <div class="fw-semibold"><?php echo e($purchaseOrder['created_at'] ?? ''); ?></div>
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
                            <th scope="col" class="text-center">VATable</th>
                        <?php else : ?>
                            <th scope="col">Item Code</th>
                            <th scope="col">Description</th>
                            <th scope="col" class="text-end">Quantity</th>
                            <th scope="col">Unit</th>
                            <th scope="col" class="text-end">Unit Price</th>
                            <th scope="col" class="text-end">Discount %</th>
                            <th scope="col" class="text-end">Net Price</th>
                            <th scope="col" class="text-end">Running Total</th>
                            <th scope="col" class="text-center">VATable</th>
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
                            <?php
                            // If the VAT flag is missing, default to the purchase order VAT percentage being applied.
                            $lineIsVatable = ($line['is_vatable'] ?? null) === null
                                ? $vatPercent > 0
                                : ((int) $line['is_vatable'] === 1);
                            ?>
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
                                    <td class="text-center">
                                        <input
                                            type="checkbox"
                                            class="form-check-input position-static"
                                            disabled
                                            <?php echo $lineIsVatable ? 'checked' : ''; ?>
                                            aria-label="VATable"
                                        />
                                    </td>
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
                                    <td class="text-center">
                                        <input
                                            type="checkbox"
                                            class="form-check-input position-static"
                                            disabled
                                            <?php echo $lineIsVatable ? 'checked' : ''; ?>
                                            aria-label="VATable"
                                        />
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
