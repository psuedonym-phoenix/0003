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
	$lineColumnCount = $viewData['lineColumnCount'];
	$previousPo = $viewData['previousPo'];
	$nextPo = $viewData['nextPo'];
	$sharedParams = $viewData['sharedParams'];
	$returnParams = $viewData['returnParams'];
        $lineSummary = $viewData['lineSummary'];
        $suppliers = $viewData['suppliers'];
        $supplierDetails = $viewData['supplierDetails'] ?? [];
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
        $returnViewLabel = ($returnParams['view'] ?? 'purchase_orders') === 'line_entry_enquiry'
    ? 'Back to Line Entry Enquiry'
    : 'Back to Purchase Orders';
	
        // Normalise key financial amounts so the view can show consistent figures
        // regardless of whether older columns like exclusive_amount are still present.
        $exclusiveAmount = round_currency((float) ($purchaseOrder['subtotal'] ?? $purchaseOrder['exclusive_amount'] ?? 0));
        $inclusiveAmount = round_currency((float) ($purchaseOrder['total_amount'] ?? 0));
        $vatPercent = (float) ($purchaseOrder['vat_percent'] ?? 0);
        $vatAmount = round_currency((float) ($purchaseOrder['vat_amount'] ?? 0));
        $calculatedLineTotal = round_currency((float) ($lineSummary['sum'] ?? 0));
        $amountsMatch = amounts_match($inclusiveAmount, $calculatedLineTotal);
        $totalHighlightClass = $amountsMatch ? 'bg-success-subtle' : 'bg-danger-subtle';
	
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

                                <div class="col-sm-1">Tel:</div>
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
                                <div class="col-sm-1">Fax:</div>
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

                                <div class="col-sm-1">Contact Name</div>
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
                                <div class="col-sm-1">Contact Email</div>
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
                                        class="form-control text-end font-monospace"
                                        id="exclusiveAmount"
                                        name="exclusive_amount"
                                        value="<?php echo format_amount($exclusiveAmount); ?>">
                                </div>
				
			</div>
			<div class="row mb-1">
				
				<div class="col-sm-8"></div>
				<label for="vatPercent" class="col-sm-1 col-form-label text-end">VAT %</label>
				<div class="col-sm-1 d-flex flex-column gap-2">
					<input
					type="text"
					step="0.01"
					class="form-control text-end font-monospace"
					id="vatPercent"
					name="vat_percent"
					value="<?php echo number_format($vatPercent, 2, '.', ''); ?>"
					>
				</div>
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input
                                        type="text"
                                        class="form-control text-end font-monospace"
                                        id="vatAmount"
                                        name="vat_amount"
                                        value="<?php echo format_amount($vatAmount); ?>"
                                        >
                                </div>
                        </div>
                        <div class="row mb-1">
                                <div class="col-sm-9"></div>
				
				
				<label for="totalAmount" class="col-sm-1 col-form-label text-end">Inclusive</label>
                                <div class="col-sm-2 d-flex flex-column gap-2">
                                        <input
                                        type="text"
                                        class="form-control text-end font-monospace"
                                        id="totalAmount"
                                        name="total_amount"
                                        value="<?php echo format_amount($inclusiveAmount); ?>"
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
                                                <tr class="<?php echo $poType === 'transactional' ? '' : 'font-monospace'; ?>" data-line-no="<?php echo e($line['line_no'] ?? ''); ?>">
                                                        <td class="fw-semibold"><?php echo e((string) ($line['line_no'] ?? '')); ?></td>
                                                        <?php if ($poType === 'transactional') : ?>
                                                        <td><?php echo e($line['line_date'] ?? ''); ?></td>
                                                        <td><?php echo e($line['description'] ?? ''); ?></td>
                                                        <td class="text-end"><?php echo number_format((float) ($line['deposit_amount'] ?? 0), 2); ?></td>
                                                        <td class="text-end"><?php echo number_format((float) ($line['ex_vat_amount'] ?? 0), 2); ?></td>
                                                        <td class="text-end"><?php echo number_format((float) ($line['line_vat_amount'] ?? 0), 2); ?></td>
                                                        <?php $lineTotalAmount = round_currency((float) ($line['line_total_amount'] ?? 0)); ?>
                                                        <?php $runningTotal = round_currency($runningTotal + $lineTotalAmount); ?>
                                                        <td class="text-end"><?php echo number_format($lineTotalAmount, 2); ?></td>
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
                                                        <td><input type="text" class="form-control form-control-sm line-item-code" value="<?php echo e($line['item_code'] ?? ''); ?>" /></td>
                                                        <td><input type="text" class="form-control form-control-sm line-description" value="<?php echo e($line['description'] ?? ''); ?>" /></td>
                                                        <td><input type="number" step="0.01" class="form-control form-control-sm text-end line-quantity" value="<?php echo number_format((float) ($line['quantity'] ?? 0), 2, '.', ''); ?>" /></td>
                                                        <td><input type="text" class="form-control form-control-sm line-unit" value="<?php echo e($line['unit'] ?? ''); ?>" /></td>
                                                        <td><input type="number" step="0.01" class="form-control form-control-sm text-end line-unit-price" value="<?php echo number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''); ?>" /></td>
                                                        <td><input type="number" step="0.01" class="form-control form-control-sm text-end line-discount" value="<?php echo number_format((float) ($line['discount_percent'] ?? 0), 2, '.', ''); ?>" /></td>
                                                        <?php $lineNetPrice = round_currency((float) ($line['net_price'] ?? 0)); ?>
                                                        <?php $runningTotal = round_currency($runningTotal + $lineNetPrice); ?>
                                                        <td class="text-end"><input type="text" class="form-control form-control-sm text-end line-net-price" value="<?php echo number_format($lineNetPrice, 2, '.', ''); ?>" readonly /></td>
                                                        <td class="text-end fw-semibold running-total-cell"><?php echo number_format($runningTotal, 2); ?></td>
                                                        <td class="text-center">
                                                                <input
                                                                type="checkbox"
                                                                class="form-check-input position-static line-vatable"
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
                        <?php if ($poType !== 'transactional') : ?>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                                <button type="button" class="btn btn-outline-primary" id="addLineButton">Add line</button>
                                <button type="button" class="btn btn-primary" id="saveLinesButton">Save lines</button>
                        </div>
                        <?php endif; ?>
                </div>
        </div>
	
	
<script>
        (function () {
                const form = document.getElementById('poHeaderForm');
                const alertBox = document.getElementById('poUpdateAlert');
                const supplierCodeInput = document.getElementById('supplierCode');
                const supplierInput = document.getElementById('supplierInput');
                const supplierSuggestions = document.getElementById('supplierSuggestions');
                const supplierOptions = <?php echo json_encode(array_values(array_filter(array_map(static function ($supplier) {
                        return [
                                'name' => $supplier['supplier_name'] ?? '',
                                'code' => $supplier['supplier_code'] ?? '',
                        ];
                }, $suppliers), static function ($supplier) {
                        return $supplier['name'] !== '';
                })), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                let suggestionDebounce = null;

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

                function hideSupplierSuggestions() {
                        if (!supplierSuggestions) {
                                return;
                        }

                        supplierSuggestions.classList.add('d-none');
                        supplierSuggestions.innerHTML = '';
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

                                        sessionStorage.setItem('poUpdateNotice', data.message || 'Purchase order header updated successfully.');
                                        window.location.reload();
                                } catch (error) {
                                        showAlert('danger', error.message);
                                }
                        });
                }

                // Line editing is only enabled for standard purchase orders.
                const poLineTable = document.getElementById('poLineTable');

                if (poLineTable && poLineTable.dataset.poType !== 'transactional') {
                        const vatPercentInput = document.getElementById('vatPercent');
                        const exclusiveAmountInput = document.getElementById('exclusiveAmount');
                        const vatAmountInput = document.getElementById('vatAmount');
                        const totalAmountInput = document.getElementById('totalAmount');
                        const addLineButton = document.getElementById('addLineButton');
                        const saveLinesButton = document.getElementById('saveLinesButton');
                        const purchaseOrderId = <?php echo (int) $purchaseOrder['id']; ?>;

                        /**
                         * Parse a numeric string safely, returning 0 when invalid.
                         */
                        function toNumber(value) {
                                const parsed = parseFloat(String(value).replace(/[,\s]/g, ''));
                                return Number.isFinite(parsed) ? parsed : 0;
                        }

                        /**
                         * Round a numeric value to 2 decimals to keep currency maths stable.
                         */
                        function roundCurrency(value) {
                                return Math.round((Number(value) || 0) * 100) / 100;
                        }

                        /**
                         * Calculate the net price for a line using the requested formula.
                         */
                        function calculateNetPrice(quantity, unitPrice, discountPercent) {
                                const qty = Math.max(0, quantity);
                                const price = Math.max(0, unitPrice);
                                const discount = Math.max(0, discountPercent);
                                const discountMultiplier = 1 - discount / 100;

                                return qty * price * discountMultiplier;
                        }

                        /**
                         * Normalise displayed currency values so users can see running totals update cleanly.
                         */
                        function formatCurrency(value) {
                                return value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }

                        /**
                         * Update the running totals across all editable rows and refresh the header amounts.
                         */
                        function refreshRunningTotals() {
                                const rows = Array.from(poLineTable.querySelectorAll('tbody tr'));
                                let runningTotal = 0;
                                let exclusiveSum = 0;
                                let vatSum = 0;
                                const vatRate = toNumber(vatPercentInput ? vatPercentInput.value : 0);

                                rows.forEach((row) => {
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

                                if (exclusiveAmountInput) {
                                        exclusiveAmountInput.value = formatCurrency(exclusiveSum);
                                }

                                if (vatAmountInput) {
                                        vatAmountInput.value = formatCurrency(vatSum);
                                }

                                if (totalAmountInput) {
                                        totalAmountInput.value = formatCurrency(exclusiveSum + vatSum);
                                }
                        }

                        /**
                         * Recalculate the net price for a row when the user exits the row.
                         */
                        function recalculateRow(row) {
                                const quantityInput = row.querySelector('.line-quantity');
                                const unitPriceInput = row.querySelector('.line-unit-price');
                                const discountInput = row.querySelector('.line-discount');
                                const netPriceInput = row.querySelector('.line-net-price');

                                if (!quantityInput || !unitPriceInput || !discountInput || !netPriceInput) {
                                        return;
                                }

                                const quantity = toNumber(quantityInput.value);
                                const unitPrice = toNumber(unitPriceInput.value);
                                const discount = toNumber(discountInput.value);
                                const netPrice = calculateNetPrice(quantity, unitPrice, discount);

                                netPriceInput.value = netPrice.toFixed(2);
                        }

                        poLineTable.addEventListener('focusout', (event) => {
                                const row = event.target.closest('tr');

                                if (!row || !poLineTable.contains(row)) {
                                        return;
                                }

                                const related = event.relatedTarget;
                                const stillInsideRow = related instanceof HTMLElement && row.contains(related);

                                if (!stillInsideRow) {
                                        recalculateRow(row);
                                        refreshRunningTotals();
                                }
                        });

                        poLineTable.addEventListener('input', (event) => {
                                if (event.target.classList.contains('line-vatable')) {
                                        refreshRunningTotals();
                                }
                        });

                        function buildEditableRow(lineNumber) {
                                const template = document.createElement('tr');
                                template.className = 'font-monospace';
                                template.innerHTML = `
                                        <td class="fw-semibold">${lineNumber}</td>
                                        <td><input type="text" class="form-control form-control-sm line-item-code" value="" /></td>
                                        <td><input type="text" class="form-control form-control-sm line-description" value="" /></td>
                                        <td><input type="number" step="0.01" class="form-control form-control-sm text-end line-quantity" value="0.00" /></td>
                                        <td><input type="text" class="form-control form-control-sm line-unit" value="" /></td>
                                        <td><input type="number" step="0.01" class="form-control form-control-sm text-end line-unit-price" value="0.00" /></td>
                                        <td><input type="number" step="0.01" class="form-control form-control-sm text-end line-discount" value="0.00" /></td>
                                        <td class="text-end"><input type="text" class="form-control form-control-sm text-end line-net-price" value="0.00" readonly /></td>
                                        <td class="text-end fw-semibold running-total-cell">0.00</td>
                                        <td class="text-center"><input type="checkbox" class="form-check-input position-static line-vatable" checked aria-label="VATable" /></td>
                                `;

                                return template;
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
                                });
                        }

                        function collectLines() {
                                const rows = Array.from(poLineTable.querySelectorAll('tbody tr'));

                                return rows.map((row, index) => ({
                                        line_no: index + 1,
                                        item_code: (row.querySelector('.line-item-code')?.value || '').trim(),
                                        description: (row.querySelector('.line-description')?.value || '').trim(),
                                        quantity: toNumber(row.querySelector('.line-quantity')?.value || 0),
                                        unit: (row.querySelector('.line-unit')?.value || '').trim(),
                                        unit_price: toNumber(row.querySelector('.line-unit-price')?.value || 0),
                                        discount_percent: toNumber(row.querySelector('.line-discount')?.value || 0),
                                        is_vatable: row.querySelector('.line-vatable')?.checked !== false,
                                }));
                        }

                        async function saveLines() {
                                clearAlert();
                                const lines = collectLines();
                                // Make sure the latest net prices are stored before sending to the server.
                                poLineTable.querySelectorAll('tbody tr').forEach(recalculateRow);
                                refreshRunningTotals();

                                const vatPercent = toNumber(vatPercentInput ? vatPercentInput.value : 0);
                                const payload = new FormData();
                                payload.set('purchase_order_id', String(purchaseOrderId));
                                payload.set('vat_percent', String(vatPercent));
                                payload.set('lines', JSON.stringify(lines));

                                try {
                                        showAlert('info', 'Saving line changes...');
                                        const response = await fetch('purchase_order_lines_update.php', {
                                                method: 'POST',
                                                body: payload,
                                        });

                                        const data = await response.json();

                                        if (!response.ok || !data.success) {
                                                throw new Error(data.message || 'Unable to update purchase order lines.');
                                        }

                                        sessionStorage.setItem('poUpdateNotice', data.message || 'Purchase order lines updated successfully.');
                                        window.location.reload();
                                } catch (error) {
                                        showAlert('danger', error.message);
                                }
                        }

                        if (saveLinesButton) {
                                saveLinesButton.addEventListener('click', saveLines);
                        }

                        refreshRunningTotals();
                }
        })();
</script>
