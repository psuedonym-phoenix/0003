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

        /**
         * Round currency values to 2 decimal places so comparisons align with stored amounts.
         */
        function round_currency(float $amount): float
        {
                return round($amount, 2);
        }

        /**
         * Compare two currency values using rounded cents to avoid precision drift.
         */
        function amounts_match(float $amountA, float $amountB): bool
        {
                return round_currency($amountA) === round_currency($amountB);
        }

        /**
         * Format currency values with a space as the thousands separator.
         */
        function format_amount(float $amount): string
        {
                return number_format($amount, 2, '.', ' ');
        }

        /**
         * Return the first available value from the supplier master data or purchase order fallback fields.
         * Supplier data is preferred so the view shows the latest address and contact details.
         */
        function get_supplier_field(array $supplierDetails, array $purchaseOrder, array $keys): string
        {
                foreach ($keys as $key) {
                        if (array_key_exists($key, $supplierDetails) && trim((string) $supplierDetails[$key]) !== '') {
                                return (string) $supplierDetails[$key];
                        }

                        if (array_key_exists($key, $purchaseOrder)) {
                                return (string) ($purchaseOrder[$key] ?? '');
                        }
                }

                return '';
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
        $isTransactional = $poType === 'transactional';
        $lineColumnCount = $viewData['lineColumnCount'];
        $previousPo = $viewData['previousPo'];
        $nextPo = $viewData['nextPo'];
        $sharedParams = $viewData['sharedParams'];
        $returnParams = $viewData['returnParams'];
        $lineSummary = $viewData['lineSummary'];
        $suppliers = $viewData['suppliers'];
        $supplierDetails = $viewData['supplierDetails'] ?? [];
        $unitOptions = $viewData['unitOptions'] ?? [];
        $supplierContact = [
                'address1' => get_supplier_field($supplierDetails, $purchaseOrder, ['address_line1']),
                'address2' => get_supplier_field($supplierDetails, $purchaseOrder, ['address_line2']),
                'address3' => get_supplier_field($supplierDetails, $purchaseOrder, ['address_line3']),
                'address4' => get_supplier_field($supplierDetails, $purchaseOrder, ['address_line4']),
                'telephone' => get_supplier_field($supplierDetails, $purchaseOrder, ['telephone_no', 'telephone_number']),
                'fax' => get_supplier_field($supplierDetails, $purchaseOrder, ['fax_no', 'fax_number']),
                'contact_name' => get_supplier_field($supplierDetails, $purchaseOrder, ['Contact_Person', 'contact_person']),
                'contact_number' => get_supplier_field($supplierDetails, $purchaseOrder, ['contact_person_no', 'contact_person_number', 'Contact_Person_No']),
                'contact_email' => get_supplier_field($supplierDetails, $purchaseOrder, ['contact_email']),
        ];
        $originView = $returnParams['view'] ?? 'purchase_orders';
        if ($originView === 'line_entry_enquiry') {
            $returnViewLabel = 'Back to Line Entry Enquiry';
        } elseif ($originView === 'enquiry_cost_codes') {
            $returnViewLabel = 'Back to Cost Code Enquiry';
        } else {
            $returnViewLabel = 'Back to Purchase Orders';
        }

        // Normalise key financial amounts so the view can show consistent figures
        // regardless of whether older columns like exclusive_amount are still present.
        $exclusiveAmount = round_currency((float) ($lineSummary['exclusive_sum'] ?? $purchaseOrder['subtotal'] ?? $purchaseOrder['exclusive_amount'] ?? 0));
        $storedInclusiveAmount = round_currency((float) ($purchaseOrder['total_amount'] ?? 0));
        $vatPercent = (float) ($purchaseOrder['vat_percent'] ?? 0);
        $vatAmount = round_currency((float) ($lineSummary['vat_sum'] ?? $purchaseOrder['vat_amount'] ?? 0));
        $calculatedLineTotal = round_currency((float) ($lineSummary['sum'] ?? 0));
        $inclusiveAmount = $calculatedLineTotal;
        $amountsMatch = amounts_match($storedInclusiveAmount, $calculatedLineTotal);
        $totalHighlightClass = $amountsMatch ? 'bg-success-subtle' : 'bg-danger-subtle';
        $amountInputClass = 'form-control text-end font-monospace' . ($isTransactional ? ' bg-body-secondary' : '');
        $vatPercentClasses = 'form-control text-end font-monospace' . ($isTransactional ? ' bg-body-secondary' : '');
	
	// Convert order date to an ISO value the date picker can render.
	$orderDateValue = '';
        if (!empty($purchaseOrder['order_date'])) {
                $orderDateTimestamp = strtotime((string) $purchaseOrder['order_date']);
                if ($orderDateTimestamp !== false) {
                        $orderDateValue = date('Y-m-d', $orderDateTimestamp);
                }
        }

        $pageTitleParts = ['Purchase Order ' . ($purchaseOrder['po_number'] ?? '')];

        if (!empty($purchaseOrder['supplier_name'])) {
                $pageTitleParts[] = (string) $purchaseOrder['supplier_name'];
        }

        $pageTitle = trim(implode(' - ', array_filter($pageTitleParts)));

        // Build query strings for navigation so AJAX links can pass parameters via data-params and fall back to href navigation.
        $returnQuery = build_query($returnParams);
        $returnHref = $returnQuery !== '' ? 'index.php?' . e($returnQuery) : 'index.php';

        $previousQuery = $previousPo !== null ? build_query(array_merge($sharedParams, ['po_number' => $previousPo])) : '';
        $nextQuery = $nextPo !== null ? build_query(array_merge($sharedParams, ['po_number' => $nextPo])) : '';
?>
<div class="visually-hidden" data-page-title="<?php echo e($pageTitle); ?>"></div>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1 fw-bold">Purchase Order <?php echo e($purchaseOrder['po_number']); ?></h1>
		</div>
		<div id="poUpdateAlert" class="alert d-none" role="alert"></div>
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
        <form class="row g-3" id="poHeaderForm">
			
			
			
			
            <input type="hidden" name="purchase_order_id" value="<?php echo (int) $purchaseOrder['id']; ?>">
            <input type="hidden" name="supplier_code" id="supplierCode" value="<?php echo e($purchaseOrder['supplier_code'] ?? ''); ?>">
            <input type="hidden" name="order_sheet_no" value="<?php echo e($purchaseOrder['order_sheet_no'] ?? ''); ?>">
			<div class="row mb-1">
				
                                <label for="supplierInput" class="col-sm-1 col-form-label">Supplier</label>
                                <div class="col-sm-3 position-relative">
                                        <input
                                        type="text"
                                        class="form-control"
                                        id="supplierInput"
                                        name="supplier_name"
                                        value="<?php echo e($purchaseOrder['supplier_name'] ?? ''); ?>"
                                        placeholder="Type or select a supplier"
                                        autocomplete="off"
                                        list="supplierDatalist"
                                        required
                                        >
                                        <div
                                        id="supplierSuggestions"
                                        class="list-group position-absolute w-100 shadow-sm d-none"
                                        style="max-height: 260px; overflow-y: auto; z-index: 1050;"
                                        aria-label="Supplier suggestions"
                                        ></div>
                                </div>
                                <datalist id="supplierDatalist">
                                        <?php foreach ($suppliers as $supplier) : ?>
                                        <?php $supplierNameOption = $supplier['supplier_name'] ?? ''; ?>
                                        <?php if ($supplierNameOption !== '') : ?>
                                                <option value="<?php echo e($supplierNameOption); ?>">
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                </datalist>
				
				
				<div class="col-sm-4"></div>
				
				
				<div class="col-sm-1"></div>
				<label for="orderDate" class="col-sm-1 col-form-label">Order Date</label>
				<div class="col-sm-2">
					<input type="date" class="form-control" id="orderDate" name="order_date" value="<?php echo e($orderDateValue); ?>">
				</div>
				
			</div>
                        <div class="row mb-1">
                                <label class="col-sm-1 col-form-label">Address 1</label>
                                <div class="col-sm-3 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['address1']); ?>" readonly>
                                </div>
								<label class="col-sm-1 col-form-label">Tel: </label>

                                <div class="col-sm-3 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['telephone']); ?>" readonly>
                                </div>
                                <div class="col-sm-1"></div>
                                <label for="reference" class="col-sm-1 col-form-label">Reference</label>
                                <div class="col-sm-2">
					<input type="text" 
					class="form-control" 
					id="reference" 
					name="reference" 
					value="<?php echo e($purchaseOrder['reference'] ?? ''); ?>">
				</div>
				
			</div>

                        <div class="row mb-1">

                                <label class="col-sm-1 col-form-label">Address 2</label>
                                <div class="col-sm-3 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['address2']); ?>" readonly>
                                </div>
                                <label class="col-sm-1 col-form-label">Fax:</label>
								
                                <div class="col-sm-3 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['fax']); ?>" readonly>
                                </div>
                                <div class="col-sm-1"></div>
                                <label class="col-sm-1 col-form-label">Order Type</label>
                                <div class="col-sm-2">
					<input type="text" class="form-control" value="<?php echo ucfirst($poType); ?>" readonly>				
				</div>
				
			</div>
			
                        <div class="row mb-1">

                                <label class="col-sm-1 col-form-label">Address 3</label>
                                <div class="col-sm-3 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['address3']); ?>" readonly>
                                </div>
								<label class="col-sm-1 col-form-label">Contact:</label>

                                
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['contact_name']); ?>" readonly>
                                </div>
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['contact_number']); ?>" readonly>
                                </div>
								<label class="col-sm-1 col-form-label">Uploaded</label>
                                <div class="col-sm-2">
                                        <input type="text" class="form-control" value="<?php echo e($purchaseOrder['created_at'] ?? ''); ?>" readonly>
                                </div>
                        </div>

                        <div class="row mb-1">
                                <label class="col-sm-1 col-form-label">Address 4</label>
                                <div class="col-sm-3 d-flex flex-column gap-2">
                                        <input type="text" class="form-control" value="<?php echo e($supplierContact['address4']); ?>" readonly>
                                </div>
								<label class="col-sm-1 col-form-label">Email:</label>
                                
                                <div class="col-sm-4 d-flex flex-column gap-2">
                                        <input type="email" class="form-control" value="<?php echo e($supplierContact['contact_email']); ?>" readonly>
                                </div>
                                
                        </div>
			
			<div class="row mb-1">
				<div class="col-sm-9"></div>
                                <label for="exclusiveAmount" class="col-sm-1 col-form-label text-end">Exclusive</label>
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input
                                        type="text"
                                        class="<?php echo $amountInputClass; ?>"
                                        id="exclusiveAmount"
                                        name="exclusive_amount"
                                        value="<?php echo format_amount($exclusiveAmount); ?>"
                                        <?php echo $isTransactional ? 'readonly aria-readonly="true"' : ''; ?>
                                        >
                                </div>
				
			</div>
			<div class="row mb-1">
				
				<div class="col-sm-8"></div>
				<label for="vatPercent" class="col-sm-1 col-form-label text-end">VAT %</label>
				<div class="col-sm-1 d-flex flex-column gap-2">
                                        <input
                                        type="text"
                                        step="0.01"
                                        class="<?php echo $vatPercentClasses; ?>"
                                        id="vatPercent"
                                        name="vat_percent"
                                        value="<?php echo number_format($vatPercent, 2, '.', ''); ?>"
                                        <?php echo $isTransactional ? 'readonly aria-readonly="true"' : ''; ?>
                                        >
                                </div>
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input
                                        type="text"
                                        class="<?php echo $amountInputClass; ?>"
                                        id="vatAmount"
                                        name="vat_amount"
                                        value="<?php echo format_amount($vatAmount); ?>"
                                        <?php echo $isTransactional ? 'readonly aria-readonly="true"' : ''; ?>
                                        >
                                </div>
                        </div>
                        <div class="row mb-1">
                                <div class="col-sm-9"></div>
				
				
				<label for="totalAmount" class="col-sm-1 col-form-label text-end">Inclusive</label>
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input
                                        type="text"
                                        class="<?php echo $amountInputClass; ?>"
                                        id="totalAmount"
                                        name="total_amount"
                                        value="<?php echo format_amount($inclusiveAmount); ?>"
                                        <?php echo $isTransactional ? 'readonly aria-readonly="true"' : ''; ?>
                                        >

                                </div>
			</div>
			<div class="row mb-1">
				<div class="col-sm-9"></div>
				<label class="col-sm-1 col-form-label text-end">Total Amount</label>
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input
                                        type="text"
                                        class="form-control text-end font-monospace <?php echo $totalHighlightClass; ?>"
                                        value="R <?php echo format_amount($calculatedLineTotal); ?>"
                                        readonly
                                        >
                                </div>
			</div>
			<div class="row mb-3">
				<div class="col-sm-11"></div>
				<div class="col-sm-1 d-flex flex-column gap-2">
					<button type="submit" class="btn btn-primary">Save</button>
				</div>
			</form>
			
		</div>
	</div>

</div>
<div class="card border-0 shadow-sm mb-3">
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
                                <table class="table table-sm align-middle mb-0" id="poLineTable" data-po-type="<?php echo e($poType); ?>">
                                        <thead class="table-light">
                                                <tr>
                                                        <th scope="col" class="column-line-number">Line #</th>
							<?php if ($poType === 'transactional') : ?>
							<th scope="col">Date</th>
							<th scope="col">Description</th>
							<th scope="col" class="text-end">Deposit Amount</th>
                                                        <th scope="col" class="text-end">Ex VAT Amount</th>
                                                        <th scope="col" class="text-end">VAT Amount</th>
                                                        <th scope="col" class="text-end">Line Total</th>
                                                        <th scope="col" class="text-end">Running Total</th>
                                                        <th scope="col" class="text-center">VATable</th>
                                                        <th scope="col" class="text-center column-actions">Actions</th>
                                                        <?php else : ?>
                                                        <th scope="col" class="column-item-code">Item Code</th>
                                                        <th scope="col" class="column-description">Description</th>
                                                        <th scope="col" class="text-center column-quantity">QTY</th>
                                                        <th scope="col" class="text-center column-unit">UOM</th>
                                                        <th scope="col" class="text-end column-unit-price">Unit Price</th>
                                                        <th scope="col" class="text-end column-discount">Discount %</th>
                                                        <th scope="col" class="text-end column-net-price">Net Price</th>
                                                        <th scope="col" class="text-end column-running-total">Running Total</th>
                                                        <th scope="col" class="text-center column-vatable">VATable</th>
                                                        <th scope="col" class="text-center column-actions">Actions</th>
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
                                                        $depositAmount = round_currency((float) ($line['deposit_amount'] ?? 0));
                                                        $exVatAmount = round_currency((float) ($line['ex_vat_amount'] ?? 0));
                                                        $lineVatAmount = round_currency((float) ($line['line_vat_amount'] ?? 0));
                                                        $lineTotalAmount = round_currency((float) ($line['line_total_amount'] ?? ($exVatAmount + $lineVatAmount)));
                                                        if ($poType === 'transactional') {
                                                                $runningTotal = round_currency($runningTotal + $depositAmount - $lineTotalAmount);
                                                        }
                                                ?>
                                                <tr class="font-monospace" data-line-no="<?php echo e($line['line_no'] ?? ''); ?>">
                                                        <td class="fw-semibold column-line-number"><?php echo e((string) ($line['line_no'] ?? '')); ?></td>
                                                        <?php if ($poType === 'transactional') : ?>
                                                        <td class="column-date">
                                                                <input type="date" class="form-control form-control-sm line-date" value="<?php echo e($line['line_date'] ?? ''); ?>">
                                                        </td>
                                                        <td class="column-description"><input type="text" class="form-control form-control-sm line-description" value="<?php echo e($line['description'] ?? ''); ?>" /></td>
                                                        <td class="text-end column-deposit"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-deposit" value="<?php echo number_format($depositAmount, 2, '.', ''); ?>" /></td>
                                                        <td class="text-end column-ex-vat"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-ex-vat" value="<?php echo number_format($exVatAmount, 2, '.', ''); ?>" /></td>
                                                        <td class="text-end column-vat-amount"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-vat-amount" value="<?php echo number_format($lineVatAmount, 2, '.', ''); ?>" /></td>
                                                        <td class="text-end column-line-total"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-total" value="<?php echo number_format($lineTotalAmount, 2, '.', ''); ?>" /></td>
                                                        <td class="text-end fw-semibold running-total-cell column-running-total"><?php echo number_format($runningTotal, 2); ?></td>
                                                        <td class="text-center column-vatable">
                                                                <input
                                                                type="checkbox"
                                                                class="form-check-input position-static line-vatable"
                                                                <?php echo $lineIsVatable ? 'checked' : ''; ?>
                                                                aria-label="VATable"
                                                                />
                                                        </td>
                                                        <td class="text-center column-actions">
                                                                <button type="button" class="btn btn-outline-danger btn-sm delete-line">Delete</button>
                                                        </td>
                                                        <?php else : ?>
                                                        <td class="column-item-code"><input type="text" class="form-control form-control-sm line-item-code" value="<?php echo e($line['item_code'] ?? ''); ?>" /></td>
                                                        <td class="column-description"><input type="text" class="form-control form-control-sm line-description" value="<?php echo e($line['description'] ?? ''); ?>" /></td>
                                                        <td class="column-quantity"><input type="number" step="1" min="0" inputmode="decimal" class="form-control form-control-sm text-end line-quantity" value="<?php echo rtrim(rtrim(number_format((float) ($line['quantity'] ?? 0), 4, '.', ''), '0'), '.'); ?>" /></td>
                                                        <td class="column-unit text-start"><input type="text" class="form-control form-control-sm line-unit" list="unitOptionsDatalist" value="<?php echo e($line['unit'] ?? ''); ?>" autocomplete="off" /></td>
                                                        <td class="column-unit-price"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-unit-price" value="<?php echo number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''); ?>" /></td>
                                                        <td class="column-discount"><input type="number" step="0.01" class="form-control form-control-sm text-end line-discount" value="<?php echo number_format((float) ($line['discount_percent'] ?? 0), 2, '.', ''); ?>" /></td>
                                                        <?php $lineNetPrice = round_currency((float) ($line['net_price'] ?? 0)); ?>
                                                        <?php $runningTotal = round_currency($runningTotal + $lineNetPrice); ?>
                                                        <td class="text-end column-net-price"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-net-price" value="<?php echo number_format($lineNetPrice, 2, '.', ''); ?>" /></td>
                                                        <td class="text-end fw-semibold running-total-cell column-running-total"><?php echo number_format($runningTotal, 2); ?></td>
                                                        <td class="text-center column-vatable">
                                                                <input
                                                                type="checkbox"
                                                                class="form-check-input position-static line-vatable"
                                                                <?php echo $lineIsVatable ? 'checked' : ''; ?>
                                                                aria-label="VATable"
                                                                />
                                                        </td>
                                                        <td class="text-center column-actions">
                                                                <button type="button" class="btn btn-outline-danger btn-sm delete-line">Delete</button>
                                                        </td>
                                                        <?php endif; ?>
                                                </tr>
                                                <?php endforeach; ?>
                                                <?php endif; ?>
                                        </tbody>
                                </table>
                        </div>
                        <datalist id="unitOptionsDatalist"></datalist>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                                <button type="button" class="btn btn-outline-primary" id="addLineButton">Add line</button>
                                <button type="button" class="btn btn-primary" id="saveLinesButton">Save lines</button>
                        </div>
                </div>
        </div>
	
	
<script>
        (function () {
                const form = document.getElementById('poHeaderForm');
                const alertBox = document.getElementById('poUpdateAlert');
                const supplierCodeInput = document.getElementById('supplierCode');
                const supplierInput = document.getElementById('supplierInput');
                const supplierSuggestions = document.getElementById('supplierSuggestions');
                const purchaseOrderIdInput = document.querySelector('input[name="purchase_order_id"]');
                const supplierOptions = <?php echo json_encode(array_values(array_filter(array_map(static function ($supplier) {
                        return [
                                'name' => $supplier['supplier_name'] ?? '',
                                'code' => $supplier['supplier_code'] ?? '',
                        ];
                }, $suppliers), static function ($supplier) {
                        return $supplier['name'] !== '';
                })), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                const unitOptions = <?php echo json_encode(array_values(array_map('strval', $unitOptions)), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                const unitDatalist = document.getElementById('unitOptionsDatalist');
                let saveLinesHandler = null;
                let suggestionDebounce = null;
                let purchaseOrderId = purchaseOrderIdInput ? Number(purchaseOrderIdInput.value) : null;

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

                function fuzzyMatch(term, candidate) {
                        const normalizedTerm = term.toLowerCase();
                        const normalizedCandidate = candidate.toLowerCase();

                        if (normalizedCandidate.includes(normalizedTerm)) {
                                return true;
                        }

                        let position = 0;

                        for (const character of normalizedTerm) {
                                position = normalizedCandidate.indexOf(character, position);

                                if (position === -1) {
                                        return false;
                                }

                                position += 1;
                        }

                        return true;
                }

                function renderUnitSuggestions(term = '') {
                        if (!unitDatalist) {
                                return;
                        }

                        const query = term.trim().toLowerCase();
                        const matches = unitOptions
                                .filter((option) => query === '' || fuzzyMatch(query, option))
                                .slice(0, 50);

                        unitDatalist.innerHTML = '';

                        matches.forEach((option) => {
                                const optionElement = document.createElement('option');
                                optionElement.value = option;
                                unitDatalist.appendChild(optionElement);
                        });
                }

                function hideSupplierSuggestions() {
                        if (!supplierSuggestions) {
                                return;
                        }

                        supplierSuggestions.classList.add('d-none');
                        supplierSuggestions.innerHTML = '';
                }

                function attachUnitInput(input) {
                        if (!input || !unitDatalist) {
                                return;
                        }

                        const refreshSuggestions = () => renderUnitSuggestions(input.value);
                        input.addEventListener('focus', refreshSuggestions);
                        input.addEventListener('input', refreshSuggestions);
                }

                function selectSupplier(name, code) {
                        if (supplierInput) {
                                supplierInput.value = name;
                        }

                        if (supplierCodeInput) {
                                supplierCodeInput.value = code || '';
                        }

                        hideSupplierSuggestions();
                        showAlert('info', 'Supplier selected. Save the header to apply this change.');
                }

                function renderSupplierSuggestions(term = '') {
                        if (!supplierSuggestions || !supplierInput) {
                                return;
                        }

                        const query = term.trim().toLowerCase();
                        const matches = supplierOptions
                                .filter((option) => query === '' || fuzzyMatch(query, option.name))
                                .slice(0, 15);

                        supplierSuggestions.innerHTML = '';

                        if (matches.length === 0) {
                                supplierSuggestions.classList.add('d-none');
                                return;
                        }

                        matches.forEach((option) => {
                                const button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'list-group-item list-group-item-action';
                                button.textContent = option.name;
                                button.dataset.supplierCode = option.code || '';
                                button.addEventListener('click', () => selectSupplier(option.name, option.code));
                                supplierSuggestions.appendChild(button);
                        });

                        supplierSuggestions.classList.remove('d-none');
                }

                function queueSupplierSuggestions() {
                        if (suggestionDebounce) {
                                window.clearTimeout(suggestionDebounce);
                        }

                        suggestionDebounce = window.setTimeout(() => {
                                renderSupplierSuggestions(supplierInput ? supplierInput.value : '');
                        }, 150);
                }

                function syncSupplierCodeFromInput() {
                        if (!supplierInput || !supplierCodeInput) {
                                return;
                        }

                        const name = supplierInput.value.trim().toLowerCase();
                        const match = supplierOptions.find((option) => option.name.toLowerCase() === name);
                        supplierCodeInput.value = match ? (match.code || '') : '';
                }

                if (supplierInput) {
                        supplierInput.addEventListener('focus', () => renderSupplierSuggestions(supplierInput.value));
                        supplierInput.addEventListener('input', () => {
                                syncSupplierCodeFromInput();
                                queueSupplierSuggestions();
                        });

                        supplierInput.addEventListener('keydown', (event) => {
                                if (event.key === 'Escape') {
                                        hideSupplierSuggestions();
                                }
                        });

                        supplierInput.addEventListener('blur', () => {
                                window.setTimeout(hideSupplierSuggestions, 150);
                                syncSupplierCodeFromInput();
                        });

                        syncSupplierCodeFromInput();
                }

                renderUnitSuggestions('');

                document.addEventListener('click', (event) => {
                        if (!supplierSuggestions || supplierSuggestions.contains(event.target)) {
                                return;
                        }

                        if (supplierInput && supplierInput.contains(event.target)) {
                                return;
                        }

                        hideSupplierSuggestions();
                });

                const storedNotice = sessionStorage.getItem('poUpdateNotice');
                if (storedNotice) {
                        showAlert('success', storedNotice);
                        sessionStorage.removeItem('poUpdateNotice');
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

                                        const updatedPurchaseOrderId = Number(data.purchaseOrder?.id ?? purchaseOrderId ?? 0) || null;

                                        if (updatedPurchaseOrderId) {
                                                purchaseOrderId = updatedPurchaseOrderId;

                                                if (purchaseOrderIdInput) {
                                                        purchaseOrderIdInput.value = String(updatedPurchaseOrderId);
                                                }
                                        }

                                        let combinedMessage = data.message || 'Purchase order header updated successfully.';

                                        // When lines are editable, save them alongside the header using the same version so
                                        // only one purchase order entry is created.
                                        if (typeof saveLinesHandler === 'function') {
                                                const lineSaveResult = await saveLinesHandler({
                                                        skipReload: true,
                                                        updateCurrentHeader: true,
                                                        purchaseOrderIdOverride: purchaseOrderId,
                                                });

                                                if (!lineSaveResult?.success) {
                                                        throw new Error(lineSaveResult?.message || 'Unable to update purchase order lines.');
                                                }

                                                if (lineSaveResult.message) {
                                                        combinedMessage = `${combinedMessage} ${lineSaveResult.message}`.trim();
                                                }
                                        }

                                        sessionStorage.setItem('poUpdateNotice', combinedMessage);
                                        window.location.reload();
                                } catch (error) {
                                        const errorMessage = error instanceof Error
                                                ? error.message
                                                : 'Unable to update the purchase order header.';
                                        showAlert('danger', errorMessage);
                                }
                        });
                }

                                const poLineTable = document.getElementById('poLineTable');
                
                if (poLineTable) {
                    const poType = poLineTable.dataset.poType || 'standard';
                    const isTransactional = poType === 'transactional';
                    const vatPercentInput = document.getElementById('vatPercent');
                    const exclusiveAmountInput = document.getElementById('exclusiveAmount');
                    const vatAmountInput = document.getElementById('vatAmount');
                    const totalAmountInput = document.getElementById('totalAmount');
                    const addLineButton = document.getElementById('addLineButton');
                    const saveLinesButton = document.getElementById('saveLinesButton');
                
                    function toNumber(value) {
                        const cleaned = String(value)
                            .replace(/\s+/g, '')
                            .replace(',', '.');
                
                        const parsed = parseFloat(cleaned.replace(/,/g, ''));
                        return Number.isFinite(parsed) ? parsed : 0;
                    }
                
                    function roundCurrency(value) {
                        return Math.round((Number(value) || 0) * 100) / 100;
                    }
                
                    function calculateNetPrice(quantity, unitPrice, discountPercent) {
                        const qty = Math.max(0, quantity);
                        const price = Number(unitPrice) || 0;
                        const discount = Math.max(0, discountPercent);
                        const discountMultiplier = 1 - discount / 100;
                
                        return qty * price * discountMultiplier;
                    }
                
                    function enforceDecimalInput(input) {
                        if (!input) {
                            return;
                        }
                
                        input.setAttribute('inputmode', 'decimal');
                
                        input.addEventListener('input', () => {
                            const cleaned = input.value.replace(/[^0-9.,-]/g, '');
                
                            if (cleaned !== input.value) {
                                input.value = cleaned;
                            }
                        });
                
                        input.addEventListener('blur', () => {
                            const value = roundCurrency(toNumber(input.value));
                            input.value = value.toFixed(2);
                        });
                    }
                
                    function enforceQuantityInput(input) {
                        if (!input) {
                            return;
                        }
                
                        input.setAttribute('inputmode', 'decimal');
                
                        input.addEventListener('input', () => {
                            const cleaned = input.value.replace(/[^0-9.,-]/g, '');
                
                            if (cleaned !== input.value) {
                                input.value = cleaned;
                            }
                        });
                
                        input.addEventListener('blur', () => {
                            const value = Math.max(0, toNumber(input.value));
                            input.value = value === 0 ? '0' : String(value);
                        });
                    }
                
                    function applyRowInputConstraints(row) {
                        if (!row) {
                            return;
                        }
                
                        if (isTransactional) {
                            enforceDecimalInput(row.querySelector('.line-deposit'));
                            enforceDecimalInput(row.querySelector('.line-ex-vat'));
                            enforceDecimalInput(row.querySelector('.line-vat-amount'));
                            enforceDecimalInput(row.querySelector('.line-total'));
                            return;
                        }
                
                        enforceDecimalInput(row.querySelector('.line-unit-price'));
                        enforceDecimalInput(row.querySelector('.line-net-price'));
                        enforceQuantityInput(row.querySelector('.line-quantity'));
                        attachUnitInput(row.querySelector('.line-unit'));
                    }
                
                    function formatCurrency(value) {
                        return roundCurrency(value).toFixed(2);
                    }
                
                    function refreshRunningTotals() {
                        const rows = Array.from(poLineTable.querySelectorAll('tbody tr'));
                        let runningTotal = 0;
                        let exclusiveSum = 0;
                        let vatSum = 0;
                        let lineSum = 0;
                        const vatRate = toNumber(vatPercentInput ? vatPercentInput.value : 0);
                
                        rows.forEach((row) => {
                            if (isTransactional) {
                                const depositAmount = roundCurrency(toNumber(row.querySelector('.line-deposit')?.value || 0));
                                const exVatAmount = roundCurrency(toNumber(row.querySelector('.line-ex-vat')?.value || 0));
                                const lineVatAmount = roundCurrency(toNumber(row.querySelector('.line-vat-amount')?.value || 0));
                                const lineTotalInput = row.querySelector('.line-total');
                                let lineTotalAmount = roundCurrency(toNumber(lineTotalInput ? lineTotalInput.value : 0));
                
                                if (!lineTotalInput || lineTotalInput.value.trim() === '') {
                                    lineTotalAmount = roundCurrency(exVatAmount + lineVatAmount);
                                }
                
                                if (lineTotalInput) {
                                    lineTotalInput.value = formatCurrency(lineTotalAmount);
                                }
                
                                exclusiveSum = roundCurrency(exclusiveSum + exVatAmount);
                                vatSum = roundCurrency(vatSum + lineVatAmount);
                                lineSum = roundCurrency(lineSum + lineTotalAmount);
                                runningTotal = roundCurrency(runningTotal + depositAmount - lineTotalAmount);
                
                                const runningCell = row.querySelector('.running-total-cell');
                                if (runningCell) {
                                    runningCell.textContent = formatCurrency(runningTotal);
                                }
                
                                return;
                            }
                
                            const netInput = row.querySelector('.line-net-price');
                            const runningCell = row.querySelector('.running-total-cell');
                            const vatableCheckbox = row.querySelector('.line-vatable');
                            const netAmount = roundCurrency(netInput ? toNumber(netInput.value) : 0);
                            const isVatable = vatableCheckbox ? vatableCheckbox.checked : true;
                
                            exclusiveSum = roundCurrency(exclusiveSum + netAmount);
                            vatSum = roundCurrency(vatSum + (isVatable ? netAmount * vatRate : 0));
                            runningTotal = roundCurrency(runningTotal + netAmount);
                
                            if (runningCell) {
                                runningCell.textContent = formatCurrency(runningTotal);
                            }
                        });
                
                        lineSum = isTransactional ? lineSum : roundCurrency(exclusiveSum + vatSum);
                
                        if (exclusiveAmountInput) {
                            exclusiveAmountInput.value = formatCurrency(exclusiveSum);
                        }
                
                        if (vatAmountInput) {
                            vatAmountInput.value = formatCurrency(vatSum);
                        }
                
                        if (totalAmountInput) {
                            totalAmountInput.value = formatCurrency(lineSum);
                        }
                    }
                
                    Array.from(poLineTable.querySelectorAll('tbody tr')).forEach(applyRowInputConstraints);
                    renumberLines();
                    refreshRunningTotals();
                
                    function recalculateRow(row) {
                        if (isTransactional) {
                            const exVatInput = row.querySelector('.line-ex-vat');
                            const vatAmountInputRow = row.querySelector('.line-vat-amount');
                            const lineTotalInput = row.querySelector('.line-total');
                
                            if (lineTotalInput) {
                                const exVatAmount = roundCurrency(toNumber(exVatInput ? exVatInput.value : 0));
                                const vatAmount = roundCurrency(toNumber(vatAmountInputRow ? vatAmountInputRow.value : 0));
                                const combinedTotal = roundCurrency(exVatAmount + vatAmount);
                                lineTotalInput.value = formatCurrency(lineTotalInput.value.trim() === '' ? combinedTotal : toNumber(lineTotalInput.value));
                            }
                
                            return;
                        }
                
                        const quantityInput = row.querySelector('.line-quantity');
                        const unitPriceInput = row.querySelector('.line-unit-price');
                        const discountInput = row.querySelector('.line-discount');
                        const netPriceInput = row.querySelector('.line-net-price');
                
                        if (!quantityInput || !unitPriceInput || !discountInput || !netPriceInput) {
                            return;
                        }
                
                        const quantity = Math.max(0, toNumber(quantityInput.value));
                        const unitPrice = toNumber(unitPriceInput.value);
                        const discount = toNumber(discountInput.value);
                        const netPrice = calculateNetPrice(quantity, unitPrice, discount);
                
                        quantityInput.value = String(quantity);
                        netPriceInput.value = formatCurrency(netPrice);
                    }
                
                    poLineTable.addEventListener('focusout', (event) => {
                        const target = event.target;
                        const row = target instanceof HTMLElement ? target.closest('tr') : null;
                
                        if (!row || !poLineTable.contains(row)) {
                            return;
                        }
                
                        if (isTransactional) {
                            if (target.classList.contains('line-deposit')
                                    || target.classList.contains('line-ex-vat')
                                    || target.classList.contains('line-vat-amount')
                                    || target.classList.contains('line-total')) {
                                if (target instanceof HTMLInputElement) {
                                    target.value = formatCurrency(toNumber(target.value));
                                }
                
                                recalculateRow(row);
                                refreshRunningTotals();
                            }
                
                            return;
                        }
                
                        const triggersRecalculation = target.classList.contains('line-quantity')
                                || target.classList.contains('line-unit-price')
                                || target.classList.contains('line-discount');
                
                        if (triggersRecalculation) {
                            recalculateRow(row);
                            refreshRunningTotals();
                        }
                
                        if (target.classList.contains('line-net-price')) {
                            const netPriceInput = row.querySelector('.line-net-price');
                
                            if (netPriceInput) {
                                netPriceInput.value = formatCurrency(toNumber(netPriceInput.value));
                            }
                
                            refreshRunningTotals();
                        }
                    });
                
                    poLineTable.addEventListener('input', (event) => {
                        const target = event.target;
                        const row = target instanceof HTMLElement ? target.closest('tr') : null;
                
                        if (!row || !poLineTable.contains(row)) {
                            return;
                        }
                
                        if (isTransactional) {
                            if (target.classList.contains('line-deposit')
                                    || target.classList.contains('line-ex-vat')
                                    || target.classList.contains('line-vat-amount')
                                    || target.classList.contains('line-total')
                                    || target.classList.contains('line-vatable')) {
                                recalculateRow(row);
                                refreshRunningTotals();
                            }
                
                            return;
                        }
                
                        const recalculatesRow = target.classList.contains('line-quantity')
                                || target.classList.contains('line-unit-price')
                                || target.classList.contains('line-discount');
                        const updatesTotalsOnly = target.classList.contains('line-net-price')
                                || target.classList.contains('line-vatable');
                
                        if (recalculatesRow) {
                            recalculateRow(row);
                        }
                
                        if (recalculatesRow || updatesTotalsOnly) {
                            refreshRunningTotals();
                        }
                    });
                
                    function buildEditableRow(lineNumber) {
                        const template = document.createElement('tr');
                        template.className = 'font-monospace';
                        template.dataset.lineNo = String(lineNumber);
                
                        if (isTransactional) {
                            template.innerHTML = `
                                    <td class="fw-semibold column-line-number">${lineNumber}</td>
                                    <td class="column-date"><input type="date" class="form-control form-control-sm line-date" /></td>
                                    <td class="column-description"><input type="text" class="form-control form-control-sm line-description" /></td>
                                    <td class="text-end column-deposit"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-deposit" value="0.00" /></td>
                                    <td class="text-end column-ex-vat"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-ex-vat" value="0.00" /></td>
                                    <td class="text-end column-vat-amount"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-vat-amount" value="0.00" /></td>
                                    <td class="text-end column-line-total"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-total" value="0.00" /></td>
                                    <td class="text-end fw-semibold running-total-cell column-running-total">0.00</td>
                                    <td class="text-center column-vatable"><input type="checkbox" class="form-check-input position-static line-vatable" checked aria-label="VATable" /></td>
                                    <td class="text-center column-actions"><button type="button" class="btn btn-outline-danger btn-sm delete-line">Delete</button></td>
                            `;
                
                            return template;
                        }
                
                        template.innerHTML = `
                                <td class="fw-semibold column-line-number">${lineNumber}</td>
                                <td class="column-item-code"><input type="text" class="form-control form-control-sm line-item-code" /></td>
                                <td class="column-description"><input type="text" class="form-control form-control-sm line-description" /></td>
                                <td class="column-quantity"><input type="number" step="1" min="0" inputmode="decimal" class="form-control form-control-sm text-end line-quantity" value="0" /></td>
                                <td class="column-unit text-start"><input type="text" class="form-control form-control-sm line-unit" value="each" list="unitOptionsDatalist" autocomplete="off" /></td>
                                <td class="column-unit-price"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-unit-price" value="0.00" /></td>
                                <td class="column-discount"><input type="number" step="0.01" class="form-control form-control-sm text-end line-discount" value="0.00" /></td>
                                <td class="text-end column-net-price"><input type="text" inputmode="decimal" class="form-control form-control-sm text-end line-net-price" value="0.00" /></td>
                                <td class="text-end fw-semibold running-total-cell column-running-total">0.00</td>
                                <td class="text-center column-vatable"><input type="checkbox" class="form-check-input position-static line-vatable" checked aria-label="VATable" /></td>
                                <td class="text-center column-actions"><button type="button" class="btn btn-outline-danger btn-sm delete-line">Delete</button></td>
                        `;
                
                        return template;
                    }
                
                    function renumberLines() {
                        const rows = Array.from(poLineTable.querySelectorAll('tbody tr'));
                
                        rows.forEach((row, index) => {
                            const lineNumber = index + 1;
                            const lineNumberCell = row.querySelector('.column-line-number');
                
                            if (lineNumberCell) {
                                lineNumberCell.textContent = String(lineNumber);
                            }
                
                            row.dataset.lineNo = String(lineNumber);
                        });
                    }
                
                    if (addLineButton) {
                        addLineButton.addEventListener('click', () => {
                            const tbody = poLineTable.querySelector('tbody');
                            if (!tbody) {
                                return;
                            }
                
                            const nextLineNumber = tbody.children.length + 1;
                            const newRow = buildEditableRow(nextLineNumber);
                            tbody.appendChild(newRow);
                            applyRowInputConstraints(newRow);
                            renumberLines();
                            refreshRunningTotals();
                        });
                    }
                
                    poLineTable.addEventListener('click', (event) => {
                        const target = event.target instanceof HTMLElement ? event.target : null;
                        if (!target) {
                            return;
                        }
                
                        const deleteButton = target.closest('.delete-line');
                        if (!deleteButton) {
                            return;
                        }
                
                        const row = deleteButton.closest('tr');
                        if (!row) {
                            return;
                        }
                
                        row.remove();
                        renumberLines();
                        refreshRunningTotals();
                    });
                
                    function collectLines() {
                        const rows = Array.from(poLineTable.querySelectorAll('tbody tr'));
                
                        if (isTransactional) {
                            return rows
                                .map((row, index) => {
                                    const exVatAmount = roundCurrency(toNumber(row.querySelector('.line-ex-vat')?.value || 0));
                                    const lineVatAmount = roundCurrency(toNumber(row.querySelector('.line-vat-amount')?.value || 0));
                                    const lineTotalInput = row.querySelector('.line-total');
                                    const lineTotalAmount = roundCurrency(toNumber(lineTotalInput ? lineTotalInput.value : exVatAmount + lineVatAmount)) || roundCurrency(exVatAmount + lineVatAmount);
                
                                    return {
                                        line_no: index + 1,
                                        item_code: '',
                                        line_date: (row.querySelector('.line-date')?.value || '').trim(),
                                        description: (row.querySelector('.line-description')?.value || '').trim(),
                                        deposit_amount: roundCurrency(toNumber(row.querySelector('.line-deposit')?.value || 0)),
                                        ex_vat_amount: exVatAmount,
                                        line_vat_amount: lineVatAmount,
                                        line_total_amount: lineTotalAmount,
                                        is_vatable: row.querySelector('.line-vatable')?.checked !== false,
                                    };
                                })
                                .filter((line) =>
                                    line.description !== '' ||
                                    line.deposit_amount !== 0 ||
                                    line.ex_vat_amount !== 0 ||
                                    line.line_vat_amount !== 0 ||
                                    line.line_total_amount !== 0
                                )
                                .map((line, index) => ({
                                    ...line,
                                    line_no: index + 1,
                                }));
                        }
                
                        const populatedRows = rows
                                .map((row, index) => ({
                                        line_no: index + 1,
                                        item_code: (row.querySelector('.line-item-code')?.value || '').trim(),
                                        description: (row.querySelector('.line-description')?.value || '').trim(),
                                        quantity: Math.max(0, toNumber(row.querySelector('.line-quantity')?.value || 0)),
                                        unit: (row.querySelector('.line-unit')?.value || '').trim(),
                                        unit_price: roundCurrency(toNumber(row.querySelector('.line-unit-price')?.value || 0)),
                                        discount_percent: toNumber(row.querySelector('.line-discount')?.value || 0),
                                        net_price: roundCurrency(toNumber(row.querySelector('.line-net-price')?.value || 0)),
                                        is_vatable: row.querySelector('.line-vatable')?.checked !== false,
                                }))
                                .filter((line) =>
                                        line.item_code !== '' ||
                                        line.description !== '' ||
                                        line.quantity > 0 ||
                                        line.net_price > 0
                                )
                                .map((line, index) => ({
                                        ...line,
                                        line_no: index + 1,
                                }));
                
                        return populatedRows;
                    }
                
                    async function saveLines(options = {}) {
                        clearAlert();
                        const skipReload = typeof options === 'boolean'
                                ? options
                                : Boolean(options.skipReload);
                        const updateCurrentHeader = typeof options === 'object' && options !== null
                                ? Boolean(options.updateCurrentHeader)
                                : false;
                        const purchaseOrderIdOverride = typeof options === 'object' && options !== null
                                ? (options.purchaseOrderIdOverride ?? null)
                                : null;
                        const targetPurchaseOrderId = purchaseOrderIdOverride ?? purchaseOrderId;
                        const lines = collectLines();
                
                        if (lines.length === 0) {
                            showAlert('danger', 'Add at least one populated line before saving.');
                            return;
                        }
                
                        const missingDescriptions = lines.filter((line) => {
                            if (isTransactional) {
                                return line.description === '';
                            }
                
                            return line.description === '' && line.item_code === '';
                        });
                
                        if (missingDescriptions.length > 0) {
                            showAlert('danger', 'Each saved line needs either an item code or a description.');
                            return;
                        }
                
                        refreshRunningTotals();
                
                        const vatPercent = toNumber(vatPercentInput ? vatPercentInput.value : 0);
                        const payload = new FormData();
                        payload.set('purchase_order_id', String(targetPurchaseOrderId));
                        payload.set('vat_percent', String(vatPercent));
                        payload.set('lines', JSON.stringify(lines));
                        if (updateCurrentHeader) {
                            payload.set('update_current_header', '1');
                        }
                
                        try {
                            showAlert('info', 'Saving line changes...');
                            const response = await fetch('purchase_order_lines_update.php', {
                                    method: 'POST',
                                    body: payload,
                            });
                
                            let data;
                            try {
                                data = await response.json();
                            } catch (parseError) {
                                throw new Error('Received an invalid response while saving purchase order lines.');
                            }
                
                            const errorDetail = [data.message, data.error].filter(Boolean).join(' - ');
                
                            if (!response.ok || !data.success) {
                                throw new Error(errorDetail || 'Unable to update purchase order lines.');
                            }
                
                            if (data.purchase_order_id) {
                                purchaseOrderId = Number(data.purchase_order_id);
                
                                if (purchaseOrderIdInput) {
                                    purchaseOrderIdInput.value = String(purchaseOrderId);
                                }
                            }
                
                            const successMessage = data.message || 'Purchase order lines updated successfully.';
                            if (skipReload) {
                                return { success: true, message: successMessage };
                            }
                
                            sessionStorage.setItem('poUpdateNotice', successMessage);
                            window.location.reload();
                            return { success: true, message: successMessage };
                        } catch (error) {
                            const errorMessage = error instanceof Error
                                    ? error.message
                                    : 'Unable to update purchase order lines. Please try again.';
                            showAlert('danger', errorMessage);
                            return { success: false, message: errorMessage };
                        }
                    }
                
                    saveLinesHandler = saveLines;
                
                    if (saveLinesButton) {
                        saveLinesButton.addEventListener('click', () => saveLinesHandler());
                    }
                
                    refreshRunningTotals();
                }
        })();
</script>
