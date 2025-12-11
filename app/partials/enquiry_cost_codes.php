<style>
    /* Make the description easier to read on wide tables */
    .cost-enquiry-description {
        min-width: 280px;
        width: 40%;
        word-wrap: break-word;
        white-space: normal;
    }

    .sortable {
        cursor: pointer;
        user-select: none;
    }

    .sort-indicator {
        width: 1rem;
        display: inline-block;
        text-align: center;
    }
</style>

<div class="container-fluid" data-page-title="Cost Code Enquiry">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Cost Code Enquiry</h1>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <form id="enquiryForm" class="row g-3">
                <!-- Cost Code Filter -->
                <div class="col-md-2">
                    <label for="filterCostCodeInput" class="form-label">Cost Code</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="filterCostCodeInput" placeholder="Code"
                            autocomplete="off">
                        <div id="costCodeSuggestions" class="list-group position-absolute w-100 shadow-sm"
                            style="z-index: 1050; display: none; max-height: 200px; overflow-y: auto;"></div>
                    </div>
                    <input type="hidden" id="filterCostCodeId">
                </div>

                <!-- Description Filter -->
                <div class="col-md-3">
                    <label for="filterCostDescInput" class="form-label">Cost Code Description</label>
                    <div class="position-relative">
                        <input type="text" class="form-control" id="filterCostDescInput" placeholder="Description"
                            autocomplete="off">
                        <div id="costDescSuggestions" class="list-group position-absolute w-100 shadow-sm"
                            style="z-index: 1050; display: none; max-height: 200px; overflow-y: auto;"></div>
                    </div>
                </div>

                <!-- Supplier Filter -->
                <div class="col-md-3">
                    <label for="filterSupplier" class="form-label">Supplier</label>
                    <input type="text" class="form-control" id="filterSupplier" list="supplierList"
                        placeholder="Select or Type Supplier">
                    <datalist id="supplierList"></datalist>
                </div>

                <!-- Date Range -->
                <div class="col-md-2">
                    <label for="filterStartDate" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="filterStartDate">
                </div>
                <div class="col-md-2">
                    <label for="filterEndDate" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="filterEndDate">
                </div>

                <!-- Actions -->
                <div class="col-12 text-end mt-3">
                    <button type="submit" class="btn btn-primary px-4">Search</button>
                    <button type="button" id="resetBtn" class="btn btn-secondary px-3 ms-2">Reset</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="sortable" data-sort-key="po_number" aria-sort="none">
                                <span class="d-inline-flex align-items-center gap-1">PO Number <span class="sort-indicator"></span></span>
                            </th>
                            <th scope="col" class="sortable" data-sort-key="order_date" aria-sort="descending">
                                <span class="d-inline-flex align-items-center gap-1">Date <span class="sort-indicator">↓</span></span>
                            </th>
                            <th scope="col" class="sortable" data-sort-key="supplier_name" aria-sort="none">
                                <span class="d-inline-flex align-items-center gap-1">Supplier <span class="sort-indicator"></span></span>
                            </th>
                            <th scope="col" class="sortable" data-sort-key="cost_code" aria-sort="none">
                                <span class="d-inline-flex align-items-center gap-1">Cost Code <span class="sort-indicator"></span></span>
                            </th>
                            <th scope="col" class="sortable text-end" data-sort-key="total_amount" aria-sort="none">
                                <span class="d-inline-flex align-items-center gap-1 justify-content-end w-100">Amount <span class="sort-indicator"></span></span>
                            </th>
                            <th scope="col" class="sortable cost-enquiry-description" data-sort-key="description" aria-sort="none">
                                <span class="d-inline-flex align-items-center gap-1">Description <span class="sort-indicator"></span></span>
                            </th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="resultsBody">
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">Enter filters and click Search to find
                                records.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const form = document.getElementById('enquiryForm');
        const resetBtn = document.getElementById('resetBtn');

        // Inputs
        const costCodeInput = document.getElementById('filterCostCodeInput');
        const costDescInput = document.getElementById('filterCostDescInput');
        const costCodeIdInput = document.getElementById('filterCostCodeId');
        const supplierInput = document.getElementById('filterSupplier');
        const resultsBody = document.getElementById('resultsBody');
        const sortableHeaders = Array.from(document.querySelectorAll('[data-sort-key]'));

        // Suggestion Boxes
        const codeSuggestionsBox = document.getElementById('costCodeSuggestions');
        const descSuggestionsBox = document.getElementById('costDescSuggestions');
        const supplierList = document.getElementById('supplierList');

        let selectedCostCode = null;
        let sortBy = 'order_date';
        let sortDir = 'desc';
        let lastSearchParams = null;

        // --- 1. Init: Load Data ---

        async function loadInitialData() {
            // Load All Cost Codes for local fuzzy logic (assume list is manageable size < few thousands)
            // If list is massive, we stick to server-side search, but user asked for logic between code/desc.
            // `api_cost_codes_lookup.php` limits to 50 by default but let's try to fetch more or use search.
            // For accurate interaction, client-side filtering of a full list is smoothest if distinct codes < 2000.
            // If we can't load all, we do server lookups.
            // Let's rely on server lookups to be safe, but we need dual lookups.

            // Load Suppliers (Prepopulated drop down)
            await loadSuppliers();
        }

        loadInitialData();

        async function loadSuppliers(costCodeMeta = null) {
            try {
                const params = new URLSearchParams();
                params.append('all_suppliers', '1');

                if (costCodeMeta) {
                    if (costCodeMeta.id) params.append('cost_code_id', costCodeMeta.id);
                    if (costCodeMeta.cost_code) params.append('cost_code', costCodeMeta.cost_code);
                    if (costCodeMeta.description) params.append('cost_code_description', costCodeMeta.description);
                }

                const supRes = await fetch(`line_entry_supplier_suggestions.php?${params.toString()}`);
                const supJson = await supRes.json();

                supplierList.innerHTML = '';

                if (Array.isArray(supJson.suggestions)) {
                    supJson.suggestions.forEach(name => {
                        const opt = document.createElement('option');
                        opt.value = name;
                        supplierList.appendChild(opt);
                    });
                }
            } catch (e) {
                console.error('Failed to load suppliers', e);
            }
        }

        // --- 2. Cost Code Logic ---

        // Generic Fetch Helper
        async function searchCostCodes(term) {
            if (term.length < 1) return [];
            try {
                const res = await fetch(`api_cost_codes_lookup.php?term=${encodeURIComponent(term)}`);
                const json = await res.json();
                return json.success ? json.data : [];
            } catch (e) {
                console.error(e);
                return [];
            }
        }

        // Input: Cost Code
        costCodeInput.addEventListener('input', async function () {
            const val = this.value.trim();
            costCodeIdInput.value = ''; // Reset ID on edit
            selectedCostCode = null;

            if (val.length < 1) {
                codeSuggestionsBox.style.display = 'none';
                await loadSuppliers();
                return;
            }

            const matches = await searchCostCodes(val); // Server search

            // Filter out if user typed description in code box? No, server handles that.
            // Render
            if (matches.length > 0) {
                codeSuggestionsBox.innerHTML = '';
                matches.forEach(item => {
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'list-group-item list-group-item-action py-1';
                    a.innerHTML = `<small class="fw-bold">${item.cost_code}</small> <small class="text-muted">- ${item.description || ''}</small>`;
                    a.onclick = (e) => {
                        e.preventDefault();
                        selectCostCode(item);
                    };
                    codeSuggestionsBox.appendChild(a);
                });
                codeSuggestionsBox.style.display = 'block';
            } else {
                codeSuggestionsBox.style.display = 'none';
            }
        });

        // Input: Description
        costDescInput.addEventListener('input', async function () {
            const val = this.value.trim();
            // Don't clear code ID yet, user might be refining?
            // Actually if they change description, the old code ID is likely invalid.
            costCodeIdInput.value = '';
            selectedCostCode = null;
            // But we won't clear the Code Input just yet, maybe they are just fixing a typo.

            if (val.length < 2) {
                descSuggestionsBox.style.display = 'none';
                await loadSuppliers();
                return;
            }

            const matches = await searchCostCodes(val);

            if (matches.length > 0) {
                descSuggestionsBox.innerHTML = '';
                matches.forEach(item => {
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'list-group-item list-group-item-action py-1';
                    // Highlight description
                    a.innerHTML = `<small>${item.description || '(No Desc)'}</small> <small class="text-muted">(${item.cost_code})</small>`;
                    a.onclick = (e) => {
                        e.preventDefault();
                        selectCostCode(item);
                    };
                    descSuggestionsBox.appendChild(a);
                });
                descSuggestionsBox.style.display = 'block';
            } else {
                descSuggestionsBox.style.display = 'none';
            }
        });

        async function selectCostCode(item) {
            costCodeInput.value = item.cost_code;
            costDescInput.value = item.description || '';
            costCodeIdInput.value = item.id;
            selectedCostCode = item;
            supplierInput.value = '';
            await loadSuppliers(item);

            codeSuggestionsBox.style.display = 'none';
            descSuggestionsBox.style.display = 'none';
        }

        // Close suggestions on click outside
        document.addEventListener('click', function (e) {
            if (!costCodeInput.contains(e.target) && !codeSuggestionsBox.contains(e.target)) {
                codeSuggestionsBox.style.display = 'none';
            }
            if (!costDescInput.contains(e.target) && !descSuggestionsBox.contains(e.target)) {
                descSuggestionsBox.style.display = 'none';
            }
        });

        // --- 3. Search Action ---

        function setStatusRow(message, isError = false) {
            const classNames = isError ? 'text-danger' : 'text-muted';
            resultsBody.innerHTML = `<tr><td colspan="7" class="text-center py-4 ${classNames}">${message}</td></tr>`;
        }

        function buildSearchParams() {
            const payload = new URLSearchParams();
            const ccId = costCodeIdInput.value;
            const ccCode = costCodeInput.value.trim();
            const ccDesc = costDescInput.value.trim();

            if (ccId) {
                payload.append('cost_code_id', ccId);
            } else {
                if (ccDesc) payload.append('cost_code_description', ccDesc);
                if (ccCode) payload.append('cost_code', ccCode);
            }

            if (supplierInput.value.trim()) payload.append('supplier_name', supplierInput.value.trim());

            const startDate = document.getElementById('filterStartDate').value;
            const endDate = document.getElementById('filterEndDate').value;

            if (startDate) payload.append('start_date', startDate);
            if (endDate) payload.append('end_date', endDate);

            payload.append('sort_by', sortBy);
            payload.append('sort_dir', sortDir);

            return payload;
        }

        async function executeSearch(payload) {
            lastSearchParams = new URLSearchParams(payload);
            setStatusRow('Searching...');

            try {
                const res = await fetch(`api_enquiry_cost_codes.php?${payload.toString()}`);

                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                const json = await res.json();

                if (json.success) {
                    if (!json.data || json.data.length === 0) {
                        setStatusRow('No results found.');
                        return;
                    }

                    resultsBody.innerHTML = '';
                    json.data.forEach(row => {
                        const fmtMoney = (amount) => {
                            return new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR' }).format(amount || 0);
                        };

                        const searchParamsForReturn = new URLSearchParams(lastSearchParams);
                        searchParamsForReturn.set('view', 'purchase_order_view');
                        searchParamsForReturn.set('po_number', row.po_number || '');
                        searchParamsForReturn.set('origin_view', 'enquiry_cost_codes');

                        const poLink = `index.php?${searchParamsForReturn.toString()}`;

                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                        <td class="fw-semibold">${row.po_number || ''}</td>
                        <td>${row.order_date || ''}</td>
                        <td>${row.supplier_name || ''}</td>
                        <td>
                            <small class="d-block fw-bold">${row.cost_code || ''}</small>
                            <small class="text-muted">${row.cost_code_description || ''}</small>
                        </td>
                        <td class="text-end">${fmtMoney(row.total_amount)}</td>
                        <td class="cost-enquiry-description">${row.description || ''}</td>
                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm" href="${poLink}" data-view="purchase_order_view" data-params="${searchParamsForReturn.toString()}">View Purchase Order</a>
                        </td>
                    `;
                        resultsBody.appendChild(tr);
                    });

                } else {
                    setStatusRow(`Error: ${json.error || 'Unknown error'}`, true);
                }

            } catch (err) {
                console.error(err);
                setStatusRow('Network Error (Check Console)', true);
            }
        }

        function updateSortIndicators() {
            sortableHeaders.forEach((header) => {
                const indicator = header.querySelector('.sort-indicator');
                const key = header.dataset.sortKey;

                if (!indicator || !key) {
                    return;
                }

                if (key === sortBy) {
                    header.setAttribute('aria-sort', sortDir === 'asc' ? 'ascending' : 'descending');
                    indicator.textContent = sortDir === 'asc' ? '↑' : '↓';
                } else {
                    header.setAttribute('aria-sort', 'none');
                    indicator.textContent = '';
                }
            });
        }

        updateSortIndicators();

        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const payload = buildSearchParams();
            await executeSearch(payload);
        });

        sortableHeaders.forEach((header) => {
            header.addEventListener('click', () => {
                const key = header.dataset.sortKey;
                if (!key) return;

                if (key === sortBy) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortBy = key;
                    sortDir = 'asc';
                }

                updateSortIndicators();
                const payload = buildSearchParams();
                executeSearch(payload);
            });
        });

        resetBtn.addEventListener('click', () => {
            form.reset();
            costCodeIdInput.value = '';
            selectedCostCode = null;
            sortBy = 'order_date';
            sortDir = 'desc';
            lastSearchParams = null;
            updateSortIndicators();
            loadSuppliers();
            setStatusRow('Enter filters and click Search to find records.');
        });

    })();
</script>
