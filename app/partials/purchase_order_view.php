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
$suppliers = $viewData['suppliers'];
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

// Convert order date to an ISO value the date picker can render.
$orderDateValue = '';
if (!empty($purchaseOrder['order_date'])) {
    $orderDateTimestamp = strtotime((string) $purchaseOrder['order_date']);
    if ($orderDateTimestamp !== false) {
        $orderDateValue = date('Y-m-d', $orderDateTimestamp);
    }
}

// Build query strings for navigation so AJAX links can pass parameters via data-params and fall back to href navigation.
$returnQuery = build_query($returnParams);
$returnHref = $returnQuery !== '' ? 'index.php?' . e($returnQuery) : 'index.php';

$previousQuery = $previousPo !== null ? build_query(array_merge($sharedParams, ['po_number' => $previousPo])) : '';
$nextQuery = $nextPo !== null ? build_query(array_merge($sharedParams, ['po_number' => $nextPo])) : '';
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1 fw-bold">Purchase Order <?php echo e($purchaseOrder['po_number']); ?></h1>
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
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h2 class="h6 mb-1">Purchase order header</h2>
                <small class="text-secondary">Update header details and select a supplier from the catalogue.</small>
            </div>
            <span class="badge text-bg-light border fs-6">PO Number: <?php echo e($purchaseOrder['po_number']); ?></span>
        </div>

        <div id="poUpdateAlert" class="alert d-none" role="alert"></div>

        <form class="row g-3" id="poHeaderForm">
            <input type="hidden" name="purchase_order_id" value="<?php echo (int) $purchaseOrder['id']; ?>">
            <input type="hidden" name="supplier_code" id="supplierCode" value="<?php echo e($purchaseOrder['supplier_code'] ?? ''); ?>">
            <input type="hidden" name="order_sheet_no" value="<?php echo e($purchaseOrder['order_sheet_no'] ?? ''); ?>">

            <div class="col-md-6">
                <label class="form-label">Purchase Order Type</label>
                <input type="text" class="form-control" value="<?php echo ucfirst($poType); ?>" readonly>
                <div class="form-text">This type is fixed for the purchase order and cannot be changed.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Uploaded</label>
                <input type="text" class="form-control" value="<?php echo e($purchaseOrder['created_at'] ?? ''); ?>" readonly>
            </div>

            <div class="col-md-6">
                <label for="supplierSelect" class="form-label">Supplier</label>
                <select class="form-select" id="supplierSelect" name="supplier_name" required>
                    <option value="">Select a supplier</option>
                    <?php foreach ($suppliers as $supplier) : ?>
                        <?php $supplierNameOption = $supplier['supplier_name'] ?? ''; ?>
                        <option
                            value="<?php echo e($supplierNameOption); ?>"
                            data-supplier-code="<?php echo e($supplier['supplier_code'] ?? ''); ?>"
                            <?php echo $supplierNameOption === ($purchaseOrder['supplier_name'] ?? '') ? 'selected' : ''; ?>
                        >
                            <?php echo e($supplierNameOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Use the dropdown to choose a supplier by name.</div>
            </div>
            <div class="col-md-6">
                <label for="orderDate" class="form-label">Order Date</label>
                <input type="date" class="form-control" id="orderDate" name="order_date" value="<?php echo e($orderDateValue); ?>">
            </div>

            <div class="col-md-4">
                <label for="reference" class="form-label">Reference</label>
                <input type="text" class="form-control" id="reference" name="reference" value="<?php echo e($purchaseOrder['reference'] ?? ''); ?>">
            </div>
            <div class="col-md-4">
                <label for="exclusiveAmount" class="form-label">Exclusive Amount</label>
                <input
                    type="number"
                    step="0.01"
                    class="form-control"
                    id="exclusiveAmount"
                    name="exclusive_amount"
                    value="<?php echo number_format($exclusiveAmount, 2, '.', ''); ?>"
                >
            </div>
            <div class="col-md-4">
                <label for="vatPercent" class="form-label">VAT %</label>
                <input
                    type="number"
                    step="0.01"
                    class="form-control"
                    id="vatPercent"
                    name="vat_percent"
                    value="<?php echo number_format($vatPercent, 2, '.', ''); ?>"
                >
            </div>

            <div class="col-md-4">
                <label for="vatAmount" class="form-label">VAT Amount</label>
                <input
                    type="number"
                    step="0.01"
                    class="form-control"
                    id="vatAmount"
                    name="vat_amount"
                    value="<?php echo number_format($vatAmount, 2, '.', ''); ?>"
                >
            </div>
            <div class="col-md-4">
                <label for="totalAmount" class="form-label">Inclusive Amount</label>
                <input
                    type="number"
                    step="0.01"
                    class="form-control"
                    id="totalAmount"
                    name="total_amount"
                    value="<?php echo number_format($inclusiveAmount, 2, '.', ''); ?>"
                >
            </div>
            <div class="col-md-4">
                <label class="form-label">Total Amount (Lines)</label>
                <input
                    type="text"
                    class="form-control"
                    value="R <?php echo number_format($calculatedLineTotal, 2); ?>"
                    readonly
                >
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center">
                <div class="text-secondary small">Only the latest version of a purchase order can be edited.</div>
                <button type="submit" class="btn btn-primary">Save header</button>
            </div>
        </form>

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

<script>
    (function () {
        const form = document.getElementById('poHeaderForm');
        const alertBox = document.getElementById('poUpdateAlert');
        const supplierCodeInput = document.getElementById('supplierCode');
        const supplierSelect = document.getElementById('supplierSelect');

        function showAlert(type, message) {
            if (!alertBox) {
                return;
            }

            alertBox.className = `alert alert-${type}`;
            alertBox.textContent = message;
        }

        function clearAlert() {
            if (!alertBox) {
                return;
            }

            alertBox.className = 'alert d-none';
            alertBox.textContent = '';
        }

        function syncSupplierCodeFromSelect() {
            if (!supplierSelect || !supplierCodeInput) {
                return;
            }

            const selectedOption = supplierSelect.options[supplierSelect.selectedIndex];
            supplierCodeInput.value = selectedOption?.dataset?.supplierCode || '';
        }

        if (supplierSelect) {
            supplierSelect.addEventListener('change', () => {
                syncSupplierCodeFromSelect();
                showAlert('info', 'Supplier selected. Save the header to apply this change.');
            });

            // Ensure the hidden supplier code matches the initial selection.
            syncSupplierCodeFromSelect();
        }

        if (form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                clearAlert();

                const formData = new FormData(form);
                showAlert('info', 'Saving purchase order header...');

                try {
                    const response = await fetch('purchase_order_update.php', {
                        method: 'POST',
                        body: formData,
                    });

                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        throw new Error(data.message || 'Unable to update the purchase order header.');
                    }

                    showAlert('success', data.message || 'Purchase order header updated successfully.');

                    // Refresh key fields with the latest values returned from the server.
                    if (data.purchaseOrder) {
                        const updated = data.purchaseOrder;

                        if (supplierSelect && updated.supplier_name !== undefined) {
                            const matchingOption = Array.from(supplierSelect.options).find(
                                (option) => option.value === updated.supplier_name
                            );

                            if (matchingOption) {
                                supplierSelect.value = matchingOption.value;
                            } else {
                                const fallbackOption = document.createElement('option');
                                fallbackOption.value = updated.supplier_name;
                                fallbackOption.textContent = updated.supplier_name;
                                fallbackOption.dataset.supplierCode = updated.supplier_code || '';
                                supplierSelect.appendChild(fallbackOption);
                                supplierSelect.value = updated.supplier_name;
                            }
                        }

                        if (supplierCodeInput && updated.supplier_code !== undefined) {
                            supplierCodeInput.value = updated.supplier_code;
                        }

                        const orderDateInput = document.getElementById('orderDate');
                        if (orderDateInput && updated.order_date) {
                            const parsedDate = new Date(updated.order_date);
                            const isoValue = Number.isNaN(parsedDate.getTime())
                                ? ''
                                : parsedDate.toISOString().slice(0, 10);
                            orderDateInput.value = isoValue;
                        }

                        const fieldMap = {
                            reference: document.getElementById('reference'),
                            vat_percent: document.getElementById('vatPercent'),
                            vat_amount: document.getElementById('vatAmount'),
                            total_amount: document.getElementById('totalAmount'),
                        };

                        Object.keys(fieldMap).forEach((key) => {
                            if (updated[key] !== undefined && fieldMap[key]) {
                                fieldMap[key].value = updated[key];
                            }
                        });

                        const exclusiveField = document.getElementById('exclusiveAmount');
                        if (exclusiveField) {
                            const exclusiveKey = Object.prototype.hasOwnProperty.call(updated, 'subtotal')
                                ? 'subtotal'
                                : 'exclusive_amount';
                            if (updated[exclusiveKey] !== undefined) {
                                exclusiveField.value = updated[exclusiveKey];
                            }
                        }
                    }
                } catch (error) {
                    showAlert('danger', error.message);
                }
            });
        }
    })();
</script>
