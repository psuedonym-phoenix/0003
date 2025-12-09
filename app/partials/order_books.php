<?php
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../db.php';

// Protect this partial from unauthenticated access when requested via AJAX.
require_authentication();

// Helper for safe HTML output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Render a single order book row for either table section.
function renderOrderBookRow(array $book): void
{
    ?>
    <tr data-id="<?php echo (int) $book['id']; ?>" data-book-code="<?php echo e($book['book_code']); ?>">
        <td class="fw-semibold align-middle"><?php echo e($book['book_code']); ?></td>
        <td>
            <input
                type="text"
                class="form-control form-control-sm"
                name="description"
                value="<?php echo e($book['description']); ?>"
                aria-label="Description for <?php echo e($book['book_code']); ?>"
            >
        </td>
        <td>
            <input
                type="text"
                class="form-control form-control-sm"
                name="description_2"
                value="<?php echo e($book['description_2']); ?>"
                aria-label="Description 2 for <?php echo e($book['book_code']); ?>"
            >
        </td>
        <td>
            <select class="form-select form-select-sm" name="is_visible" aria-label="Visibility for <?php echo e($book['book_code']); ?>">
                <option value="1" <?php echo (int) $book['is_visible'] === 1 ? 'selected' : ''; ?>>Shown</option>
                <option value="0" <?php echo (int) $book['is_visible'] === 0 ? 'selected' : ''; ?>>Hidden</option>
            </select>
        </td>
        <td>
            <input
                type="number"
                class="form-control form-control-sm text-end"
                name="qty"
                value="<?php echo is_null($book['qty']) ? '' : (float) $book['qty']; ?>"
                step="1"
                min="0"
                aria-label="Quantity for <?php echo e($book['book_code']); ?>"
            >
        </td>
        <td class="align-middle"><?php echo $book['created_at'] ? e($book['created_at']) : '—'; ?></td>
        <td class="align-middle" data-field="updated_at"><?php echo $book['updated_at'] ? e($book['updated_at']) : '—'; ?></td>
        <td class="text-end align-middle">
            <button type="button" class="btn btn-primary btn-sm" data-action="update-order-book">
                Update
            </button>
        </td>
    </tr>
    <?php
}

$pdo = get_db_connection();
$orderBooks = [];
$errorMessage = '';

try {
    $stmt = $pdo->query(
        'SELECT id, book_code, description, description_2, qty, is_visible, created_at, updated_at
         FROM order_books
         ORDER BY book_code ASC'
    );
    $orderBooks = $stmt->fetchAll();
} catch (Throwable $exception) {
    // Keep the UI usable even if the metadata table has not been created yet.
    $errorMessage = 'Order book metadata table is missing. Please run order_books.sql to create it.';
}

$visibleBooks = array_values(array_filter($orderBooks, fn ($book) => (int) $book['is_visible'] === 1));
$hiddenBooks = array_values(array_filter($orderBooks, fn ($book) => (int) $book['is_visible'] === 0));
?>
<div class="visually-hidden" data-page-title="Order Books"></div>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h5 mb-1">Order Books</h2>
            <small class="text-secondary">Manage descriptions and visibility for each order book code.</small>
        </div>
        <button class="btn btn-outline-secondary btn-sm" type="button" data-view="purchase_orders">Back to Purchase Orders</button>
    </div>
</div>

<?php if ($errorMessage !== '') : ?>
    <div class="alert alert-danger" role="alert"><?php echo e($errorMessage); ?></div>
<?php else : ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h3 class="h6 mb-1">Inline Editor</h3>
                    <small class="text-secondary">Update descriptions or toggle visibility, then save each row individually.</small>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge text-bg-light border">Total: <?php echo count($orderBooks); ?></span>
                    <span class="badge text-bg-light border">Visible: <?php echo count($visibleBooks); ?></span>
                    <span class="badge text-bg-light border">Hidden: <?php echo count($hiddenBooks); ?></span>
                </div>
            </div>

            <?php if (empty($orderBooks)) : ?>
                <div class="alert alert-info mb-0" role="alert">
                    No order books found. Upload purchase orders or run order_books.sql to seed metadata entries.
                </div>
            <?php else : ?>
                <div id="orderBookAlert" class="alert d-none" role="alert"></div>

                <div class="row g-3 align-items-center mb-4">
                    <div class="col-sm-6 col-lg-4">
                        <label for="orderBooksFilter" class="form-label mb-1">Filter</label>
                        <input
                            type="search"
                            id="orderBooksFilter"
                            class="form-control form-control-sm"
                            placeholder="Filter by book code or description"
                            data-order-books-filter
                        >
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <label for="orderBooksSort" class="form-label mb-1">Sort</label>
                        <select id="orderBooksSort" class="form-select form-select-sm" data-order-books-sort>
                            <option value="book_code_asc">Book code (A–Z)</option>
                            <option value="description_asc">Description (A–Z)</option>
                            <option value="qty_desc">Quantity (high to low)</option>
                            <option value="qty_asc">Quantity (low to high)</option>
                            <option value="updated_desc">Updated (newest first)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <h4 class="h6 mb-2">Visible order books</h4>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" data-order-books-table="visible">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Book Code</th>
                                    <th scope="col" style="width: 24%">Description</th>
                                    <th scope="col" style="width: 24%">Description 2</th>
                                    <th scope="col">Visibility</th>
                                    <th scope="col" class="text-end">Qty</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Updated</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="visibleOrderBooksBody" data-empty-message="No visible order books yet.">
                                <?php if (empty($visibleBooks)) : ?>
                                    <tr data-empty-state="true">
                                        <td colspan="8" class="text-center text-secondary">No visible order books yet.</td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($visibleBooks as $book) : ?>
                                        <?php renderOrderBookRow($book); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h4 class="h6 mb-2">Hidden order books</h4>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" data-order-books-table="hidden">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Book Code</th>
                                    <th scope="col" style="width: 24%">Description</th>
                                    <th scope="col" style="width: 24%">Description 2</th>
                                    <th scope="col">Visibility</th>
                                    <th scope="col" class="text-end">Qty</th>
                                    <th scope="col">Created</th>
                                    <th scope="col">Updated</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="hiddenOrderBooksBody" data-empty-message="No hidden order books. Mark a book as Hidden to move it here.">
                                <?php if (empty($hiddenBooks)) : ?>
                                    <tr data-empty-state="true">
                                        <td colspan="8" class="text-center text-secondary">No hidden order books. Mark a book as Hidden to move it here.</td>
                                    </tr>
                                <?php else : ?>
                                    <?php foreach ($hiddenBooks as $book) : ?>
                                        <?php renderOrderBookRow($book); ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
// Handle inline updates for order books while keeping the UI responsive.
(function () {
    const tables = document.querySelectorAll('[data-order-books-table]');
    const alertBox = document.getElementById('orderBookAlert');
    const tableBodies = {
        visible: document.getElementById('visibleOrderBooksBody'),
        hidden: document.getElementById('hiddenOrderBooksBody'),
    };
    const filterInput = document.querySelector('[data-order-books-filter]');
    const sortSelect = document.querySelector('[data-order-books-sort]');

    if (!tables.length) {
        return;
    }

    function showAlert(message, type = 'success') {
        if (!alertBox) {
            return;
        }

        alertBox.textContent = message;
        alertBox.className = `alert alert-${type}`;
        alertBox.classList.remove('d-none');
    }

    function clearPlaceholderRows(body) {
        if (!body) {
            return;
        }

        body.querySelectorAll('[data-empty-state]').forEach((row) => row.remove());
    }

    function ensurePlaceholderRow(body) {
        if (!body) {
            return;
        }

        const existingDataRows = body.querySelectorAll('tr[data-id]').length > 0;
        if (existingDataRows) {
            return;
        }

        const emptyMessage = body.dataset.emptyMessage || 'No order books available.';
        const placeholderRow = document.createElement('tr');
        placeholderRow.dataset.emptyState = 'true';

        const cell = document.createElement('td');
        cell.colSpan = 8;
        cell.className = 'text-center text-secondary';
        cell.textContent = emptyMessage;

        placeholderRow.appendChild(cell);
        body.appendChild(placeholderRow);
    }

    function moveRowToSection(row, isVisible) {
        const targetBody = isVisible ? tableBodies.visible : tableBodies.hidden;
        const sourceBody = row.closest('tbody');

        if (!targetBody || !sourceBody || targetBody === sourceBody) {
            return;
        }

        clearPlaceholderRows(targetBody);
        targetBody.appendChild(row);
        ensurePlaceholderRow(sourceBody);
        applyFilterAndSort();
    }

    function updateRowFields(row, book) {
        const descriptionInput = row.querySelector('input[name="description"]');
        const description2Input = row.querySelector('input[name="description_2"]');
        const visibilitySelect = row.querySelector('select[name="is_visible"]');
        const qtyInput = row.querySelector('input[name="qty"]');
        const updatedAtCell = row.querySelector('[data-field="updated_at"]');

        if (descriptionInput && typeof book.description === 'string') {
            descriptionInput.value = book.description;
        }

        if (description2Input && typeof book.description_2 === 'string') {
            description2Input.value = book.description_2;
        }

        if (visibilitySelect && typeof book.is_visible !== 'undefined') {
            visibilitySelect.value = String(book.is_visible);
        }

        if (qtyInput && typeof book.qty !== 'undefined' && book.qty !== null) {
            qtyInput.value = Number(book.qty);
        } else if (qtyInput) {
            qtyInput.value = '';
        }

        if (updatedAtCell && book.updated_at) {
            updatedAtCell.textContent = book.updated_at;
        }
    }

    function normalize(text) {
        return (text || '').toString().trim().toLowerCase();
    }

    function getSortValue(row, sortKey) {
        const code = normalize(row.dataset.bookCode);
        const description = normalize(row.querySelector('input[name="description"]')?.value);
        const qtyRaw = row.querySelector('input[name="qty"]')?.value;
        const qtyValue = qtyRaw === '' || qtyRaw === null ? null : Number(qtyRaw);
        const updatedText = row.querySelector('[data-field="updated_at"]')?.textContent || '';
        const updatedValue = updatedText ? Date.parse(updatedText) : 0;

        switch (sortKey) {
            case 'description_asc':
                return { key: description, direction: 1 };
            case 'qty_desc':
                return { key: qtyValue ?? -Infinity, direction: -1 };
            case 'qty_asc':
                return { key: qtyValue ?? Infinity, direction: 1 };
            case 'updated_desc':
                return { key: updatedValue, direction: -1 };
            case 'book_code_asc':
            default:
                return { key: code, direction: 1 };
        }
    }

    function applyFilterAndSort() {
        const filterTerm = normalize(filterInput ? filterInput.value : '');
        const sortSelection = sortSelect ? sortSelect.value : 'book_code_asc';

        Object.values(tableBodies).forEach((body) => {
            if (!body) {
                return;
            }

            const rows = Array.from(body.querySelectorAll('tr[data-id]'));
            const matches = [];

            rows.forEach((row) => {
                const code = normalize(row.dataset.bookCode);
                const description = normalize(row.querySelector('input[name="description"]')?.value);
                const description2 = normalize(row.querySelector('input[name="description_2"]')?.value);
                const qty = row.querySelector('input[name="qty"]')?.value || '';
                const combined = `${code} ${description} ${description2} ${qty}`;

                const isMatch = filterTerm === '' || combined.includes(filterTerm);
                row.classList.toggle('d-none', !isMatch);

                if (isMatch) {
                    matches.push(row);
                }
            });

            matches.sort((a, b) => {
                const aSort = getSortValue(a, sortSelection);
                const bSort = getSortValue(b, sortSelection);

                if (aSort.key === bSort.key) {
                    // Use book code as a deterministic tie-breaker.
                    return normalize(a.dataset.bookCode).localeCompare(normalize(b.dataset.bookCode)) * (aSort.direction || 1);
                }

                if (aSort.key > bSort.key) {
                    return 1 * (aSort.direction || 1);
                }

                return -1 * (aSort.direction || 1);
            });

            matches.forEach((row) => body.appendChild(row));
            ensurePlaceholderRow(body);
        });
    }

    tables.forEach((table) => {
        table.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-action="update-order-book"]');
            if (!button) {
                return;
            }

            const row = button.closest('tr');
            if (!row) {
                return;
            }

            const id = row.dataset.id;
            const descriptionInput = row.querySelector('input[name="description"]');
            const description2Input = row.querySelector('input[name="description_2"]');
            const visibilitySelect = row.querySelector('select[name="is_visible"]');
            const qtyInput = row.querySelector('input[name="qty"]');

            if (!id || !descriptionInput || !description2Input || !visibilitySelect || !qtyInput) {
                showAlert('Unable to update this row because required fields are missing.', 'danger');
                return;
            }

            const payload = new FormData();
            payload.append('id', id);
            payload.append('description', descriptionInput.value.trim());
            payload.append('description_2', description2Input.value.trim());
            payload.append('is_visible', visibilitySelect.value);
            payload.append('qty', qtyInput.value.trim());

            button.disabled = true;
            button.textContent = 'Saving...';

            try {
                const response = await fetch('order_books_update.php', {
                    method: 'POST',
                    body: payload,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    const message = data.message || 'Order book could not be updated.';
                    showAlert(message, 'danger');
                    return;
                }

                if (data.book) {
                    updateRowFields(row, data.book);
                    moveRowToSection(row, Number(data.book.is_visible) === 1);
                    applyFilterAndSort();
                }

                showAlert(data.message || 'Order book updated.', 'success');
            } catch (error) {
                showAlert('Unexpected error updating order book. Please try again.', 'danger');
            } finally {
                button.disabled = false;
                button.textContent = 'Update';
            }
        });
    });

    if (filterInput) {
        filterInput.addEventListener('input', applyFilterAndSort);
    }

    if (sortSelect) {
        sortSelect.addEventListener('change', applyFilterAndSort);
    }

    applyFilterAndSort();
})();
</script>
