<?php
require_once __DIR__ . '/../db.php';

// Helper to safely output HTML.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = get_db_connection();

// Pull only visible order books for end-user selection; fall back to existing purchase orders if no metadata exists yet.
$orderBooks = [];
try {
    $booksStmt = $pdo->query(
        'SELECT book_code, description
         FROM order_books
         WHERE is_visible = 1
         ORDER BY book_code ASC'
    );
    $orderBooks = $booksStmt->fetchAll();
} catch (Throwable $exception) {
    // If the admin has not created the metadata table yet we still want the page to render.
    $orderBooks = [];
}

if (empty($orderBooks)) {
    // Fallback mode uses distinct order_book values from purchase_orders.
    $fallbackStmt = $pdo->query(
        'SELECT DISTINCT order_book AS book_code
         FROM purchase_orders
         WHERE order_book IS NOT NULL AND order_book != ""
         ORDER BY order_book ASC'
    );

    while ($row = $fallbackStmt->fetch()) {
        $orderBooks[] = [
            'book_code' => $row['book_code'],
            'description' => 'Derived from purchase_orders',
        ];
    }
}

$bookCodes = array_column($orderBooks, 'book_code');

// Determine which book is selected for the detailed order list.
$selectedBook = $_GET['order_book'] ?? '';

$orders = [];
$orderQuery =
    'SELECT id, po_number, order_book, order_sheet_no, supplier_name, order_date, total_amount, created_at
     FROM purchase_orders';

// When a specific order book is chosen, apply the filter; otherwise show all order books.
if ($selectedBook !== '') {
    $orderQuery .= ' WHERE order_book = :book';
}

// Default the view to PO Number ascending so users immediately see sorted results.
$orderQuery .= ' ORDER BY po_number ASC';

$ordersStmt = $pdo->prepare($orderQuery);

if ($selectedBook !== '') {
    $ordersStmt->bindValue(':book', $selectedBook, PDO::PARAM_STR);
}

$ordersStmt->execute();
$orders = $ordersStmt->fetchAll();

// Unique supplier list supports autocomplete suggestions for filtering.
$supplierSuggestions = array_values(array_unique(array_map(static function ($order) {
    return $order['supplier_name'] ?? '';
}, $orders)));
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h5 mb-1">Purchase Orders</h2>
            <small class="text-secondary">Select an order book to view all matching purchase orders.</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm" type="button" data-view="order_books">Manage Order Books</button>
            <button class="btn btn-outline-secondary btn-sm" type="button">View History</button>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
            <div>
                <h3 class="h6 mb-1">Filter by order book</h3>
                <small class="text-secondary">Only books marked visible are listed below.</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="orderBookSelect" class="form-label mb-0">Order book:</label>
                <select id="orderBookSelect" class="form-select form-select-sm" <?php echo empty($bookCodes) ? 'disabled' : ''; ?>>
                    <?php if (empty($bookCodes)) : ?>
                        <option value="">No visible order books available</option>
                    <?php else : ?>
                        <option value="" <?php echo $selectedBook === '' ? 'selected' : ''; ?>>Show all order books</option>
                        <?php foreach ($orderBooks as $book) : ?>
                            <?php
                            $label = $book['description'] !== ''
                                ? sprintf('%s — %s', $book['book_code'], $book['description'])
                                : $book['book_code'];
                            ?>
                            <option value="<?php echo e($book['book_code']); ?>" <?php echo $book['book_code'] === $selectedBook ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div>
                <h3 class="h6 mb-1">
                    Orders in book: <?php echo $selectedBook !== '' ? e($selectedBook) : 'All order books'; ?>
                </h3>
                <small class="text-secondary">Showing purchase orders for the selected order book, or all when none is chosen.</small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <label for="supplierFilter" class="form-label mb-0">Supplier filter:</label>
                <input
                    type="search"
                    id="supplierFilter"
                    class="form-control form-control-sm"
                    placeholder="Type to filter suppliers"
                    list="supplierSuggestions"
                    autocomplete="off"
                    <?php echo empty($orders) ? 'disabled' : ''; ?>
                >
                <span class="badge text-bg-light border">Total orders: <?php echo count($orders); ?></span>
            </div>
        </div>

        <?php if (!empty($supplierSuggestions)) : ?>
            <datalist id="supplierSuggestions">
                <?php foreach ($supplierSuggestions as $supplierName) : ?>
                    <?php if ($supplierName !== '') : ?>
                        <option value="<?php echo e($supplierName); ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>

        <style>
            .sortable-header {
                background: none;
                border: none;
                padding: 0;
                font: inherit;
                color: inherit;
                cursor: pointer;
            }

            .sortable-header:focus-visible {
                outline: 2px solid var(--bs-primary);
                outline-offset: 2px;
            }
        </style>

        <div class="table-responsive">
            <table id="purchaseOrdersTable" class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">
                            <button type="button" class="sortable-header" data-sort-key="po_number">
                                PO Number
                            </button>
                        </th>
                        <th scope="col">Order Sheet</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">
                            <button type="button" class="sortable-header" data-sort-key="order_date" data-sort-type="date">
                                Order Date
                            </button>
                        </th>
                        <th scope="col" class="text-end">
                            <button type="button" class="sortable-header text-end" data-sort-key="total_amount" data-sort-type="number">
                                Total Amount
                            </button>
                        </th>
                        <th scope="col">Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)) : ?>
                        <tr>
                            <td colspan="6" class="text-secondary">No purchase orders were found for the current selection.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($orders as $order) : ?>
                            <tr
                                data-po-number="<?php echo e($order['po_number']); ?>"
                                data-order-date="<?php echo e($order['order_date']); ?>"
                                data-total-amount="<?php echo e($order['total_amount']); ?>"
                                data-supplier-name="<?php echo e($order['supplier_name']); ?>"
                            >
                                <td class="fw-semibold"><?php echo e($order['po_number']); ?></td>
                                <td><?php echo e($order['order_sheet_no']); ?></td>
                                <td><?php echo e($order['supplier_name']); ?></td>
                                <td><?php echo e($order['order_date']); ?></td>
                                <td class="text-end"><?php echo number_format((float) $order['total_amount'], 2); ?></td>
                                <td><?php echo e($order['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Reload the purchase orders view when the order book selection changes so the table refreshes.
(function () {
    const contentArea = document.getElementById('contentArea');
    const selector = document.getElementById('orderBookSelect');
    const ordersTable = document.getElementById('purchaseOrdersTable');
    const supplierFilter = document.getElementById('supplierFilter');

    if (!contentArea) {
        return;
    }

    // Swap the content area HTML and ensure inline scripts run after insertion so event handlers stay active.
    function replaceContentWithScripts(html) {
        contentArea.innerHTML = html;
        const scripts = contentArea.querySelectorAll('script');

        scripts.forEach((oldScript) => {
            const newScript = document.createElement('script');

            Array.from(oldScript.attributes).forEach((attr) => {
                newScript.setAttribute(attr.name, attr.value);
            });

            newScript.textContent = oldScript.textContent;
            oldScript.replaceWith(newScript);
        });
    }

    // Use delegated change handling so the order book dropdown continues to trigger reloads even after replacement.
    if (selector && contentArea.dataset.orderBookDelegateBound !== 'true') {
        contentArea.dataset.orderBookDelegateBound = 'true';

        contentArea.addEventListener('change', async (event) => {
            const target = event.target;

            if (!(target instanceof HTMLSelectElement) || target.id !== 'orderBookSelect') {
                return;
            }

            const params = new URLSearchParams({ view: 'purchase_orders', order_book: target.value });

            try {
                const response = await fetch(`content.php?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    throw new Error('Unable to load order book');
                }

                const html = await response.text();
                replaceContentWithScripts(html);
            } catch (error) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger mt-3';
                alert.textContent = 'There was a problem loading the selected order book. Please try again.';
                contentArea.prepend(alert);
            }
        });
    }

    // Provide client-side sorting and supplier filtering without extra server calls.
    if (!ordersTable) {
        return;
    }

    const tableBody = ordersTable.querySelector('tbody');
    const orderRows = tableBody ? Array.from(tableBody.querySelectorAll('tr[data-po-number]')) : [];

    // If there are no data rows (only placeholder messaging), skip attaching interactive handlers.
    if (!tableBody || orderRows.length === 0) {
        return;
    }

    let filteredRows = [...orderRows];
    let currentSort = { key: 'po_number', type: 'string', direction: 'asc' };

    function toCamelCase(key) {
        return key.replace(/_([a-z])/g, (_, letter) => letter.toUpperCase());
    }

    function getComparableValue(row, key, type) {
        const datasetKey = toCamelCase(key);
        const rawValue = row.dataset[datasetKey] || '';

        if (type === 'number') {
            return parseFloat(rawValue) || 0;
        }

        if (type === 'date') {
            const timestamp = Date.parse(rawValue);
            return Number.isNaN(timestamp) ? rawValue : timestamp;
        }

        return rawValue.toString().toLowerCase();
    }

    function renderRows(rows) {
        tableBody.innerHTML = '';

        if (rows.length === 0) {
            const emptyRow = document.createElement('tr');
            emptyRow.innerHTML = '<td colspan="6" class="text-secondary">No purchase orders match the current filters.</td>';
            tableBody.appendChild(emptyRow);
            return;
        }

        rows.forEach((row) => tableBody.appendChild(row));
    }

    function applySort(key, type, direction) {
        if (!key) {
            renderRows(filteredRows);
            return;
        }

        const sorted = [...filteredRows].sort((a, b) => {
            const aValue = getComparableValue(a, key, type);
            const bValue = getComparableValue(b, key, type);

            if (aValue < bValue) {
                return direction === 'asc' ? -1 : 1;
            }

            if (aValue > bValue) {
                return direction === 'asc' ? 1 : -1;
            }

            return 0;
        });

        renderRows(sorted);
    }

    function applyFilter(inputValue) {
        const normalized = inputValue.trim().toLowerCase();

        if (normalized === '') {
            filteredRows = [...orderRows];
        } else {
            filteredRows = orderRows.filter((row) => {
                const supplierName = (row.dataset.supplierName || '').toLowerCase();
                return supplierName.includes(normalized);
            });
        }

        if (currentSort.key) {
            applySort(currentSort.key, currentSort.type, currentSort.direction);
        } else {
            renderRows(filteredRows);
        }
    }

    ordersTable.querySelectorAll('[data-sort-key]').forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.sortKey;
            const type = button.dataset.sortType || 'string';

            if (!key) {
                return;
            }

            const direction = currentSort.key === key && currentSort.direction === 'asc' ? 'desc' : 'asc';
            currentSort = { key, type, direction };
            applySort(key, type, direction);
        });
    });

    // Apply the default ascending PO Number sort on initial load.
    applySort(currentSort.key, currentSort.type, currentSort.direction);

    if (supplierFilter) {
        supplierFilter.addEventListener('input', (event) => {
            const target = event.target;
            applyFilter(target.value || '');
        });
    }
})();
</script>
