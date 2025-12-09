<?php
require_once __DIR__ . '/../db.php';

// Helper to safely output HTML.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Build a query string while skipping empty values so we keep URLs tidy.
function build_query(array $params): string
{
    $filtered = [];

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }

        $filtered[$key] = $value;
    }

    return http_build_query($filtered);
}

$pdo = get_db_connection();

$showHiddenBooks = ($_GET['show_hidden'] ?? '0') === '1';

// Pull visible order books by default; include hidden ones when explicitly requested.
$orderBooks = [];
try {
    $booksQuery = $showHiddenBooks
        ? 'SELECT book_code, description, is_visible FROM order_books ORDER BY book_code ASC'
        : 'SELECT book_code, description, is_visible FROM order_books WHERE is_visible = 1 ORDER BY book_code ASC';

    $booksStmt = $pdo->query($booksQuery);
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
            'is_visible' => 1,
        ];
    }
}

$bookCodes = array_column($orderBooks, 'book_code');

// Determine which book is selected for the detailed order list.
$selectedBook = $_GET['order_book'] ?? '';

$orders = [];
$itemsPerPage = 360;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$supplierFilter = trim($_GET['supplier'] ?? '');

$latestPerPoSubquery = 'SELECT po_number, MAX(id) AS latest_id FROM purchase_orders GROUP BY po_number';
$versionCountSubquery = 'SELECT po_number, COUNT(*) AS version_count FROM purchase_orders GROUP BY po_number';

// Count total unique purchase orders so we can paginate results cleanly.
$countQuery =
    "SELECT COUNT(*) AS total
     FROM purchase_orders po
     INNER JOIN ($latestPerPoSubquery) latest ON latest.po_number = po.po_number AND latest.latest_id = po.id";

// Fetch the latest version for each purchase order number and support filtering by order book.
$orderQuery =
    "SELECT po.id, po.po_number, po.order_book, po.supplier_name, po.order_date, po.total_amount, po.created_at, versions.version_count
     FROM purchase_orders po
     INNER JOIN ($latestPerPoSubquery) latest ON latest.po_number = po.po_number AND latest.latest_id = po.id
     INNER JOIN ($versionCountSubquery) versions ON versions.po_number = po.po_number";

$filters = [];

// When a specific order book is chosen, apply the filter; otherwise show all order books.
if ($selectedBook !== '') {
    $filters[] = 'po.order_book = :book';
}

// When filtering by supplier name, allow partial matches.
if ($supplierFilter !== '') {
    $filters[] = 'po.supplier_name LIKE :supplier';
}

if (!empty($filters)) {
    $whereClause = ' WHERE ' . implode(' AND ', $filters);
    $orderQuery .= $whereClause;
    $countQuery .= $whereClause;
}

// Default the view to PO Number ascending so users immediately see sorted results and stay within the current page.
$orderQuery .= ' ORDER BY po.po_number ASC';

// When viewing all order books and applying a supplier filter, bypass pagination so all matching suppliers are shown.
$shouldBypassPagination = $selectedBook === '' && $supplierFilter !== '';

if (!$shouldBypassPagination) {
    $orderQuery .= ' LIMIT :limit OFFSET :offset';
}

$ordersStmt = $pdo->prepare($orderQuery);
$countStmt = $pdo->prepare($countQuery);

if ($selectedBook !== '') {
    $ordersStmt->bindValue(':book', $selectedBook, PDO::PARAM_STR);
    $countStmt->bindValue(':book', $selectedBook, PDO::PARAM_STR);
}

if ($supplierFilter !== '') {
    $supplierLike = '%' . $supplierFilter . '%';
    $ordersStmt->bindValue(':supplier', $supplierLike, PDO::PARAM_STR);
    $countStmt->bindValue(':supplier', $supplierLike, PDO::PARAM_STR);
}

$countStmt->execute();
$totalOrdersCount = (int) $countStmt->fetchColumn();

$totalPages = $shouldBypassPagination ? 1 : max(1, (int) ceil($totalOrdersCount / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;

if (!$shouldBypassPagination) {
    $ordersStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
    $ordersStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
}

$ordersStmt->execute();
$orders = $ordersStmt->fetchAll();

// Unique supplier list supports autocomplete suggestions for filtering.
$supplierSuggestions = array_values(array_unique(array_map(static function ($order) {
    return $order['supplier_name'] ?? '';
}, $orders)));
?>
<div class="visually-hidden" data-page-title="Purchase Orders"></div>
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
                <small class="text-secondary">
                    <?php if ($showHiddenBooks) : ?>
                        Visible and hidden books are available in the dropdown.
                    <?php else : ?>
                        Only books marked visible are listed below. Use the button to add hidden books temporarily.
                    <?php endif; ?>
                </small>
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
                            $isHidden = isset($book['is_visible']) && (int) $book['is_visible'] === 0;
                            $labelParts = [$book['book_code']];

                            if ($book['description'] !== '') {
                                $labelParts[] = $book['description'];
                            }

                            $label = implode(' — ', $labelParts);

                            if ($isHidden) {
                                $label .= ' (hidden)';
                            }
                            ?>
                            <option value="<?php echo e($book['book_code']); ?>" <?php echo $book['book_code'] === $selectedBook ? 'selected' : ''; ?>>
                                <?php echo e($label); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div class="mt-2">
            <button
                type="button"
                id="toggleHiddenBooks"
                class="btn btn-link btn-sm px-0"
                data-is-showing-hidden="<?php echo $showHiddenBooks ? '1' : '0'; ?>"
            >
                <?php echo $showHiddenBooks ? 'Hide hidden order books' : 'Show hidden order books'; ?>
            </button>
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
                    value="<?php echo e($supplierFilter); ?>"
                    <?php echo empty($orders) ? 'disabled' : ''; ?>
                >
                <span class="badge text-bg-light border">Total orders: <?php echo $totalOrdersCount ?? 0; ?></span>
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
                        <th scope="col">
                            <button type="button" class="sortable-header" data-sort-key="version_count" data-sort-type="number">
                                Versions
                            </button>
                        </th>
                        <th scope="col">
                            <button type="button" class="sortable-header" data-sort-key="supplier_name">
                                Supplier
                            </button>
                        </th>
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
                        <th scope="col" class="text-end">Actions</th>
                        <th scope="col">
                            <button type="button" class="sortable-header" data-sort-key="created_at" data-sort-type="date">
                                Uploaded
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)) : ?>
                        <tr>
                            <td colspan="7" class="text-secondary">No purchase orders were found for the current selection.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($orders as $order) : ?>
                            <tr
                                data-po-number="<?php echo e($order['po_number']); ?>"
                                data-order-date="<?php echo e($order['order_date']); ?>"
                                data-total-amount="<?php echo e($order['total_amount']); ?>"
                                data-version-count="<?php echo (int) $order['version_count']; ?>"
                                data-supplier-name="<?php echo e($order['supplier_name']); ?>"
                                data-created-at="<?php echo e($order['created_at']); ?>"
                            >
                                <td class="fw-semibold"><?php echo e($order['po_number']); ?></td>
                                <td><?php echo number_format((int) $order['version_count']); ?></td>
                                <td><?php echo e($order['supplier_name']); ?></td>
                                <td><?php echo e($order['order_date']); ?></td>
                                <td class="text-end"><?php echo number_format((float) $order['total_amount'], 2); ?></td>
                                <td class="text-end">
                                    <?php
                                    $viewParams = [
                                        'po_number' => $order['po_number'],
                                        'order_book' => $selectedBook,
                                        'supplier' => $supplierFilter,
                                        'page' => $currentPage,
                                    ];

                                    if ($showHiddenBooks) {
                                        $viewParams['show_hidden'] = '1';
                                    }

                                    $viewQuery = build_query($viewParams);
                                    ?>
                                    <a
                                        class="btn btn-outline-primary btn-sm"
                                        href="index.php?view=purchase_order_view<?php echo $viewQuery !== '' ? '&amp;' . e($viewQuery) : ''; ?>"
                                        data-view="purchase_order_view"
                                        data-params="<?php echo e($viewQuery); ?>"
                                    >
                                        View
                                    </a>
                                </td>
                                <td><?php echo e($order['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (($totalPages ?? 1) > 1) : ?>
            <nav class="mt-3" aria-label="Purchase orders pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link pagination-link" href="#" data-page="<?php echo $currentPage - 1; ?>">Previous</a>
                    </li>
                    <?php for ($page = 1; $page <= $totalPages; $page++) : ?>
                        <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                            <a class="page-link pagination-link" href="#" data-page="<?php echo $page; ?>"><?php echo $page; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link pagination-link" href="#" data-page="<?php echo $currentPage + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<script>
// Reload the purchase orders view when the order book selection changes so the table refreshes.
(function () {
    const contentArea = document.getElementById('contentArea');
    const selector = document.getElementById('orderBookSelect');
    const ordersTable = document.getElementById('purchaseOrdersTable');
    const supplierFilter = document.getElementById('supplierFilter');
    const toggleHiddenBooks = document.getElementById('toggleHiddenBooks');
    const currentShowHidden = '<?php echo $showHiddenBooks ? '1' : '0'; ?>';
    const currentPage = Number('<?php echo $currentPage; ?>') || 1;

    function buildParams(overrides = {}) {
        const shouldShowHidden = overrides.showHidden ?? currentShowHidden === '1';
        const supplierValue = (overrides.supplier ?? (supplierFilter ? supplierFilter.value : '') ?? '').trim();
        const params = new URLSearchParams({
            view: 'purchase_orders',
            order_book: overrides.orderBook ?? (selector ? selector.value : ''),
            page: String(overrides.page ?? currentPage),
        });

        if (shouldShowHidden) {
            params.set('show_hidden', '1');
        }

        if (supplierValue !== '') {
            params.set('supplier', supplierValue);
        }

        return params;
    }

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

    async function fetchAndReplace(params, errorMessage) {
        try {
            const response = await fetch(`content.php?${params.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(errorMessage);
            }

            const html = await response.text();
            replaceContentWithScripts(html);
        } catch (error) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger mt-3';
            alert.textContent = errorMessage;
            contentArea.prepend(alert);
        }
    }

    // Use delegated change handling so the order book dropdown continues to trigger reloads even after replacement.
    if (selector && contentArea.dataset.orderBookDelegateBound !== 'true') {
        contentArea.dataset.orderBookDelegateBound = 'true';

        contentArea.addEventListener('change', async (event) => {
            const target = event.target;

            if (!(target instanceof HTMLSelectElement) || target.id !== 'orderBookSelect') {
                return;
            }

            if (supplierFilter) {
                // Reset supplier filtering when switching books so the next view starts unfiltered.
                supplierFilter.value = '';
            }

            const params = buildParams({ orderBook: target.value, page: 1, supplier: '' });

            await fetchAndReplace(params, 'There was a problem loading the selected order book. Please try again.');
        });
    }

    if (toggleHiddenBooks && contentArea) {
        toggleHiddenBooks.addEventListener('click', async () => {
            const isShowingHidden = toggleHiddenBooks.dataset.isShowingHidden === '1';
            const params = buildParams({
                orderBook: selector ? selector.value : '',
                showHidden: !isShowingHidden,
                page: 1,
            });

            await fetchAndReplace(params, 'There was a problem loading hidden order books. Please try again.');
        });
    }

    if (contentArea && contentArea.dataset.paginationDelegateBound !== 'true') {
        contentArea.dataset.paginationDelegateBound = 'true';

        contentArea.addEventListener('click', async (event) => {
            const target = event.target;

            if (!(target instanceof HTMLElement) || !target.classList.contains('pagination-link')) {
                return;
            }

            event.preventDefault();

            const parent = target.closest('.page-item');
            const requestedPage = Number(target.dataset.page);

            if (parent && parent.classList.contains('disabled')) {
                return;
            }

            if (!Number.isInteger(requestedPage) || requestedPage < 1) {
                return;
            }

            const params = buildParams({ page: requestedPage });

            await fetchAndReplace(params, 'There was a problem loading that page of orders. Please try again.');
        });
    }

    // Provide client-side sorting and supplier filtering, while allowing server reloads when filtering all books.
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
            emptyRow.innerHTML = '<td colspan="7" class="text-secondary">No purchase orders match the current filters.</td>';
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
        let supplierFilterTimeout;

        const triggerSupplierReload = async (value) => {
            const params = buildParams({ supplier: value, page: 1 });
            await fetchAndReplace(params, 'There was a problem filtering suppliers. Please try again.');
        };

        supplierFilter.addEventListener('input', (event) => {
            const target = event.target;
            const newValue = target.value || '';

            applyFilter(newValue);

            window.clearTimeout(supplierFilterTimeout);

            supplierFilterTimeout = window.setTimeout(() => {
                // Only reload from the server when all order books are selected so we can return every matching supplier.
                if (selector && selector.value !== '') {
                    return;
                }

                triggerSupplierReload(newValue.trim());
            }, 400);
        });
    }
})();
</script>
