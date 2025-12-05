<?php
require_once __DIR__ . '/../db.php';

// Helper to safely output HTML without risking XSS issues.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Build a query string while omitting empty values so we keep links readable.
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

// Pull visible order books to drive the optional filter; fall back to distinct values when the metadata table is absent.
$orderBooks = [];

try {
    $booksStmt = $pdo->query(
        'SELECT book_code, description FROM order_books WHERE is_visible = 1 ORDER BY book_code ASC'
    );
    $orderBooks = $booksStmt->fetchAll();
} catch (Throwable $exception) {
    $orderBooks = [];
}

if (empty($orderBooks)) {
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

$selectedBook = trim($_GET['order_book'] ?? '');
$poNumber = trim($_GET['po_number'] ?? '');
$supplierQuery = trim($_GET['supplier'] ?? '');
$searchQuery = trim($_GET['query'] ?? '');
$orderDateFrom = trim($_GET['order_date_from'] ?? '');
$orderDateTo = trim($_GET['order_date_to'] ?? '');
$itemsPerPage = 100;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

// Allow searching by any combination of filters but avoid scanning the full table when nothing is supplied.
$hasFilters = $searchQuery !== ''
    || $selectedBook !== ''
    || $poNumber !== ''
    || $supplierQuery !== ''
    || $orderDateFrom !== ''
    || $orderDateTo !== '';

// Validate the date strings to ensure we only pass through real dates.
foreach (['orderDateFrom' => &$orderDateFrom, 'orderDateTo' => &$orderDateTo] as $label => &$dateValue) {
    if ($dateValue === '') {
        continue;
    }

    $parsedDate = DateTime::createFromFormat('Y-m-d', $dateValue);

    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $dateValue) {
        // Reset invalid dates so they do not slip into the query string.
        $dateValue = '';
    }
}
unset($dateValue, $label, $parsedDate);

$sortableColumns = [
    'po_number' => 'po.po_number',
    'order_book' => 'po.order_book',
    'supplier' => 'po.supplier_name',
    'order_date' => 'po.order_date',
    'line_no' => 'pol.line_no',
    'description' => 'pol.description',
];

$sortBy = $_GET['sort_by'] ?? 'po_number';

if (!array_key_exists($sortBy, $sortableColumns)) {
    $sortBy = 'po_number';
}

$sortDirection = strtolower($_GET['sort_dir'] ?? 'asc');

if (!in_array($sortDirection, ['asc', 'desc'], true)) {
    $sortDirection = 'asc';
}

$latestPerPoSubquery = 'SELECT po_number, MAX(id) AS latest_id FROM purchase_orders GROUP BY po_number';

$totalMatches = 0;
$results = [];

if ($hasFilters) {
    $conditions = [];

    if ($searchQuery !== '') {
        $conditions[] = 'pol.description LIKE :search';
    }

    if ($selectedBook !== '') {
        $conditions[] = 'po.order_book = :book';
    }

    if ($poNumber !== '') {
        $conditions[] = 'po.po_number LIKE :poNumber';
    }

    if ($supplierQuery !== '') {
        $conditions[] = 'po.supplier_name LIKE :supplier';
    }

    if ($orderDateFrom !== '') {
        $conditions[] = 'po.order_date >= :orderDateFrom';
    }

    if ($orderDateTo !== '') {
        $conditions[] = 'po.order_date <= :orderDateTo';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $countSql = "
        SELECT COUNT(*)
        FROM purchase_order_lines pol
        INNER JOIN purchase_orders po ON po.id = pol.purchase_order_id
        INNER JOIN ($latestPerPoSubquery) latest ON latest.po_number = po.po_number AND latest.latest_id = po.id
        $whereClause
    ";

    $querySql = "
        SELECT
            po.po_number,
            po.order_book,
            po.supplier_name,
            po.order_date,
            pol.line_no,
            pol.description
        FROM purchase_order_lines pol
        INNER JOIN purchase_orders po ON po.id = pol.purchase_order_id
        INNER JOIN ($latestPerPoSubquery) latest ON latest.po_number = po.po_number AND latest.latest_id = po.id
        $whereClause
        ORDER BY {$sortableColumns[$sortBy]} {$sortDirection}, po.po_number ASC, pol.line_no ASC, pol.id ASC
        LIMIT :limit OFFSET :offset
    ";

    $countStmt = $pdo->prepare($countSql);
    $queryStmt = $pdo->prepare($querySql);

    if ($searchQuery !== '') {
        $searchTerm = '%' . $searchQuery . '%';
        $countStmt->bindValue(':search', $searchTerm, PDO::PARAM_STR);
        $queryStmt->bindValue(':search', $searchTerm, PDO::PARAM_STR);
    }

    if ($selectedBook !== '') {
        $countStmt->bindValue(':book', $selectedBook, PDO::PARAM_STR);
        $queryStmt->bindValue(':book', $selectedBook, PDO::PARAM_STR);
    }

    if ($poNumber !== '') {
        $poNumberTerm = '%' . $poNumber . '%';
        $countStmt->bindValue(':poNumber', $poNumberTerm, PDO::PARAM_STR);
        $queryStmt->bindValue(':poNumber', $poNumberTerm, PDO::PARAM_STR);
    }

    if ($supplierQuery !== '') {
        $supplierTerm = '%' . $supplierQuery . '%';
        $countStmt->bindValue(':supplier', $supplierTerm, PDO::PARAM_STR);
        $queryStmt->bindValue(':supplier', $supplierTerm, PDO::PARAM_STR);
    }

    if ($orderDateFrom !== '') {
        $countStmt->bindValue(':orderDateFrom', $orderDateFrom, PDO::PARAM_STR);
        $queryStmt->bindValue(':orderDateFrom', $orderDateFrom, PDO::PARAM_STR);
    }

    if ($orderDateTo !== '') {
        $countStmt->bindValue(':orderDateTo', $orderDateTo, PDO::PARAM_STR);
        $queryStmt->bindValue(':orderDateTo', $orderDateTo, PDO::PARAM_STR);
    }

    $countStmt->execute();
    $totalMatches = (int) $countStmt->fetchColumn();

    $totalPages = max(1, (int) ceil($totalMatches / $itemsPerPage));
    $currentPage = min($currentPage, $totalPages);
    $offset = ($currentPage - 1) * $itemsPerPage;

    $queryStmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
    $queryStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $queryStmt->execute();

    $results = $queryStmt->fetchAll();
} else {
    $totalPages = 1;
}
?>
<form id="lineEnquiryForm" class="mb-3">
    <input type="hidden" name="view" value="line_entry_enquiry">
    <input type="hidden" id="lineSortBy" name="sort_by" value="<?php echo e($sortBy); ?>">
    <input type="hidden" id="lineSortDir" name="sort_dir" value="<?php echo e($sortDirection); ?>">

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h2 class="h5 mb-1">Line entry enquiry</h2>
                    <small class="text-secondary">Filter by PO number, order book, supplier, or description. Click a column heading to sort.</small>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply filters</button>
                    <button type="button" id="lineResetFilters" class="btn btn-outline-secondary">Clear filters</button>
                </div>
            </div>

            <div class="bg-light border rounded-3 p-3 mt-3">
                <div class="row g-3">
                    <div class="col-12 col-md-3 col-lg-2">
                        <label class="form-label mb-1" for="linePoNumber">PO Number</label>
                        <input
                            type="search"
                            class="form-control form-control-sm"
                            id="linePoNumber"
                            name="po_number"
                            placeholder="Contains"
                            value="<?php echo e($poNumber); ?>"
                        >
                    </div>
                    <div class="col-12 col-md-3 col-lg-2">
                        <label class="form-label mb-1" for="lineOrderBook">Order book</label>
                        <select id="lineOrderBook" name="order_book" class="form-select form-select-sm">
                            <option value="" <?php echo $selectedBook === '' ? 'selected' : ''; ?>>All</option>
                            <?php foreach ($orderBooks as $book) : ?>
                                <?php
                                $labelParts = [$book['book_code']];

                                if (($book['description'] ?? '') !== '') {
                                    $labelParts[] = $book['description'];
                                }

                                $label = implode(' — ', $labelParts);
                                ?>
                                <option value="<?php echo e($book['book_code']); ?>" <?php echo $book['book_code'] === $selectedBook ? 'selected' : ''; ?>>
                                    <?php echo e($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 col-lg-3">
                        <label class="form-label mb-1" for="lineSupplier">Supplier</label>
                        <input
                            type="search"
                            class="form-control form-control-sm"
                            id="lineSupplier"
                            name="supplier"
                            placeholder="Contains"
                            value="<?php echo e($supplierQuery); ?>"
                        >
                    </div>
                    <div class="col-12 col-md-3 col-lg-3">
                        <label class="form-label mb-1" for="lineSearch">Description</label>
                        <input
                            type="search"
                            id="lineSearch"
                            name="query"
                            class="form-control form-control-sm"
                            placeholder="Contains"
                            value="<?php echo e($searchQuery); ?>"
                        >
                    </div>
                    <div class="col-12 col-md-6 col-lg-2">
                        <div class="row g-2 align-items-end">
                            <div class="col-12">
                                <label class="form-label mb-1" for="lineOrderDateFrom">Order date from</label>
                                <input
                                    type="date"
                                    class="form-control form-control-sm"
                                    id="lineOrderDateFrom"
                                    name="order_date_from"
                                    value="<?php echo e($orderDateFrom); ?>"
                                    placeholder="From"
                                >
                            </div>
                            <div class="col-12">
                                <label class="form-label mb-1" for="lineOrderDateTo">Order date to</label>
                                <input
                                    type="date"
                                    class="form-control form-control-sm"
                                    id="lineOrderDateTo"
                                    name="order_date_to"
                                    value="<?php echo e($orderDateTo); ?>"
                                    placeholder="To"
                                >
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if ($hasFilters && $totalMatches > 0) : ?>
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <strong><?php echo number_format($totalMatches); ?></strong> matching line items found.
                    </div>
                    <div>
                        <span class="badge text-bg-light border">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3">
                    <thead class="table-light">
                        <tr>
                            <?php
                            $headingConfig = [
                                'po_number' => 'PO Number',
                                'order_book' => 'Order book',
                                'supplier' => 'Supplier',
                                'order_date' => 'Order date',
                                'line_no' => 'Line no.',
                                'description' => 'Description',
                            ];

                            foreach ($headingConfig as $key => $label) :
                                $nextDirection = ($sortBy === $key && $sortDirection === 'asc') ? 'desc' : 'asc';
                                $isActive = $sortBy === $key;
                                ?>
                                <th scope="col">
                                    <button
                                        type="button"
                                        class="btn btn-link p-0 text-decoration-none line-sort"
                                        data-sort-by="<?php echo e($key); ?>"
                                        data-next-direction="<?php echo $nextDirection; ?>"
                                        aria-label="Sort by <?php echo e($label); ?>"
                                    >
                                        <span class="fw-semibold text-dark"><?php echo e($label); ?></span>
                                        <?php if ($isActive) : ?>
                                            <span class="ms-1 text-secondary"><?php echo $sortDirection === 'asc' ? '▲' : '▼'; ?></span>
                                        <?php endif; ?>
                                    </button>
                                </th>
                            <?php endforeach; ?>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$hasFilters) : ?>
                            <tr>
                                <td colspan="7" class="text-center text-secondary py-4">Add a filter above to start the line enquiry.</td>
                            </tr>
                        <?php elseif ($totalMatches === 0) : ?>
                            <tr>
                                <td colspan="7" class="text-center text-warning py-4">No line items matched your filters.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($results as $row) : ?>
                                <?php
                                $poLinkParams = build_query([
                                    'po_number' => $row['po_number'],
                                    'order_book' => $selectedBook,
                                    'view' => 'purchase_orders',
                                ]);
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($row['po_number']); ?></td>
                                    <td><?php echo e($row['order_book'] ?? ''); ?></td>
                                    <td><?php echo e($row['supplier_name'] ?? ''); ?></td>
                                    <td><?php echo e($row['order_date'] ?? ''); ?></td>
                                    <td><?php echo e($row['line_no']); ?></td>
                                    <td><?php echo e($row['description']); ?></td>
                                    <td class="text-end">
                                        <a
                                            class="btn btn-outline-primary btn-sm"
                                            href="po_view.php?<?php echo $poLinkParams; ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            View PO
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($hasFilters && $totalPages > 1) : ?>
                <nav aria-label="Line enquiry pagination">
                    <ul class="pagination mb-0 flex-wrap">
                        <li class="page-item <?php echo $currentPage === 1 ? 'disabled' : ''; ?>">
                            <a class="page-link line-pagination" href="#" data-page="<?php echo $currentPage - 1; ?>">Previous</a>
                        </li>
                        <?php for ($page = 1; $page <= $totalPages; $page++) : ?>
                            <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                                <a class="page-link line-pagination" href="#" data-page="<?php echo $page; ?>"><?php echo $page; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $currentPage === $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link line-pagination" href="#" data-page="<?php echo $currentPage + 1; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</form>

<script>
    (() => {
        const contentArea = document.getElementById('contentArea');
        const form = document.getElementById('lineEnquiryForm');
        const searchInput = document.getElementById('lineSearch');
        const supplierInput = document.getElementById('lineSupplier');
        const poNumberInput = document.getElementById('linePoNumber');
        const orderBookSelect = document.getElementById('lineOrderBook');
        const orderDateFromInput = document.getElementById('lineOrderDateFrom');
        const orderDateToInput = document.getElementById('lineOrderDateTo');
        const paginationLinks = Array.from(document.querySelectorAll('.line-pagination'));
        const sortLinks = Array.from(document.querySelectorAll('.line-sort'));
        const sortByField = document.getElementById('lineSortBy');
        const sortDirField = document.getElementById('lineSortDir');
        const resetButton = document.getElementById('lineResetFilters');

        let activeSortBy = sortByField ? sortByField.value : 'po_number';
        let activeSortDirection = sortDirField ? sortDirField.value : 'asc';

        if (!contentArea) {
            return;
        }

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

        function buildParams(page = null) {
            const params = new URLSearchParams();
            params.set('view', 'line_entry_enquiry');

            if (poNumberInput && poNumberInput.value.trim() !== '') {
                params.set('po_number', poNumberInput.value.trim());
            }

            if (orderBookSelect && orderBookSelect.value !== '') {
                params.set('order_book', orderBookSelect.value);
            }

            if (supplierInput && supplierInput.value.trim() !== '') {
                params.set('supplier', supplierInput.value.trim());
            }

            if (orderDateFromInput && orderDateFromInput.value !== '') {
                params.set('order_date_from', orderDateFromInput.value);
            }

            if (orderDateToInput && orderDateToInput.value !== '') {
                params.set('order_date_to', orderDateToInput.value);
            }

            if (searchInput && searchInput.value.trim() !== '') {
                params.set('query', searchInput.value.trim());
            }

            params.set('sort_by', activeSortBy);
            params.set('sort_dir', activeSortDirection);

            if (page !== null) {
                params.set('page', String(page));
            }

            return params;
        }

        async function fetchAndSwap(params, errorMessage) {
            try {
                const response = await fetch(`content.php?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                if (!response.ok) {
                    throw new Error('Request failed');
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

        if (form) {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                if (sortByField) {
                    sortByField.value = activeSortBy;
                }

                if (sortDirField) {
                    sortDirField.value = activeSortDirection;
                }

                const params = buildParams(1);
                await fetchAndSwap(params, 'There was a problem running the line enquiry. Please try again.');
            });
        }

        sortLinks.forEach((link) => {
            link.addEventListener('click', async (event) => {
                event.preventDefault();

                const sortBy = link.dataset.sortBy;
                const nextDirection = link.dataset.nextDirection;

                if (!sortBy || !nextDirection) {
                    return;
                }

                activeSortBy = sortBy;
                activeSortDirection = nextDirection;

                if (sortByField) {
                    sortByField.value = activeSortBy;
                }

                if (sortDirField) {
                    sortDirField.value = activeSortDirection;
                }

                const params = buildParams(1);
                await fetchAndSwap(params, 'There was a problem sorting the results. Please try again.');
            });
        });

        if (resetButton) {
            resetButton.addEventListener('click', async () => {
                if (poNumberInput) {
                    poNumberInput.value = '';
                }

                if (orderBookSelect) {
                    orderBookSelect.value = '';
                }

                if (supplierInput) {
                    supplierInput.value = '';
                }

                if (orderDateFromInput) {
                    orderDateFromInput.value = '';
                }

                if (orderDateToInput) {
                    orderDateToInput.value = '';
                }

                if (searchInput) {
                    searchInput.value = '';
                }

                activeSortBy = 'po_number';
                activeSortDirection = 'asc';

                if (sortByField) {
                    sortByField.value = activeSortBy;
                }

                if (sortDirField) {
                    sortDirField.value = activeSortDirection;
                }

                const params = buildParams(1);
                await fetchAndSwap(params, 'There was a problem resetting the filters. Please try again.');
            });
        }

        paginationLinks.forEach((link) => {
            link.addEventListener('click', async (event) => {
                event.preventDefault();

                const parent = link.closest('.page-item');
                if (parent && (parent.classList.contains('disabled') || parent.classList.contains('active'))) {
                    return;
                }

                const page = Number(link.dataset.page);
                if (!Number.isInteger(page) || page < 1) {
                    return;
                }

                const params = buildParams(page);
                await fetchAndSwap(params, 'There was a problem loading that page of results. Please try again.');
            });
        });
    })();
</script>
