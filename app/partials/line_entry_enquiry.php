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
$searchQuery = trim($_GET['query'] ?? '');
$itemsPerPage = 100;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

// Only run the search when the admin has supplied a term to avoid scanning the full table accidentally.
$hasSearch = $searchQuery !== '';

$latestPerPoSubquery = 'SELECT po_number, MAX(id) AS latest_id FROM purchase_orders GROUP BY po_number';

$totalMatches = 0;
$results = [];

if ($hasSearch) {
    $conditions = ['pol.description LIKE :search'];

    if ($selectedBook !== '') {
        $conditions[] = 'po.order_book = :book';
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
        ORDER BY po.po_number ASC, pol.line_no ASC, pol.id ASC
        LIMIT :limit OFFSET :offset
    ";

    $countStmt = $pdo->prepare($countSql);
    $queryStmt = $pdo->prepare($querySql);

    $searchTerm = '%' . $searchQuery . '%';

    $countStmt->bindValue(':search', $searchTerm, PDO::PARAM_STR);
    $queryStmt->bindValue(':search', $searchTerm, PDO::PARAM_STR);

    if ($selectedBook !== '') {
        $countStmt->bindValue(':book', $selectedBook, PDO::PARAM_STR);
        $queryStmt->bindValue(':book', $selectedBook, PDO::PARAM_STR);
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
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h5 mb-1">Line entry enquiry</h2>
            <small class="text-secondary">Search purchase order line descriptions and jump straight to the matching orders.</small>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form id="lineEnquiryForm" class="row gy-3 align-items-end">
            <div class="col-12 col-md-6">
                <label for="lineSearch" class="form-label">Search terms (description contains)</label>
                <input
                    type="search"
                    id="lineSearch"
                    name="query"
                    class="form-control"
                    placeholder="Enter words that appear in the line description"
                    value="<?php echo e($searchQuery); ?>"
                    required
                >
                <small class="text-secondary">At least one keyword is required before running the search.</small>
            </div>
            <div class="col-12 col-md-4">
                <label for="lineOrderBook" class="form-label">Order book (optional)</label>
                <select id="lineOrderBook" name="order_book" class="form-select">
                    <option value="" <?php echo $selectedBook === '' ? 'selected' : ''; ?>>All order books</option>
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
            <div class="col-12 col-md-2 d-grid">
                <button type="submit" class="btn btn-primary">Search lines</button>
            </div>
        </form>
    </div>
</div>

<div class="card border-0 shadow-sm mt-3">
    <div class="card-body">
        <?php if (!$hasSearch) : ?>
            <div class="alert alert-info mb-0">Enter a description keyword to start the line enquiry.</div>
        <?php elseif ($totalMatches === 0) : ?>
            <div class="alert alert-warning mb-0">No line items matched your search. Adjust the keywords or remove filters.</div>
        <?php else : ?>
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <strong><?php echo number_format($totalMatches); ?></strong> matching line items found.
                </div>
                <div>
                    <span class="badge text-bg-light border">Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?></span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-3">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">PO Number</th>
                            <th scope="col">Order book</th>
                            <th scope="col">Supplier</th>
                            <th scope="col">Order date</th>
                            <th scope="col">Line no.</th>
                            <th scope="col">Description</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
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
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1) : ?>
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
        <?php endif; ?>
    </div>
</div>

<script>
    (() => {
        const contentArea = document.getElementById('contentArea');
        const form = document.getElementById('lineEnquiryForm');
        const searchInput = document.getElementById('lineSearch');
        const orderBookSelect = document.getElementById('lineOrderBook');
        const paginationLinks = Array.from(document.querySelectorAll('.line-pagination'));

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

            if (orderBookSelect && orderBookSelect.value !== '') {
                params.set('order_book', orderBookSelect.value);
            }

            if (searchInput && searchInput.value !== '') {
                params.set('query', searchInput.value);
            }

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
                const params = buildParams(1);
                await fetchAndSwap(params, 'There was a problem running the line enquiry. Please try again.');
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
