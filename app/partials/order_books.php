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
?>
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
                <span class="badge text-bg-light border">Total books: <?php echo count($orderBooks); ?></span>
            </div>

            <?php if (empty($orderBooks)) : ?>
                <div class="alert alert-info mb-0" role="alert">
                    No order books found. Upload purchase orders or run order_books.sql to seed metadata entries.
                </div>
            <?php else : ?>
                <div id="orderBookAlert" class="alert d-none" role="alert"></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" id="orderBooksTable">
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
                        <tbody>
                            <?php foreach ($orderBooks as $book) : ?>
                                <tr data-id="<?php echo (int) $book['id']; ?>">
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
                                    <td class="text-end align-middle"><?php echo is_null($book['qty']) ? '—' : number_format((float) $book['qty']); ?></td>
                                    <td class="align-middle"><?php echo $book['created_at'] ? e($book['created_at']) : '—'; ?></td>
                                    <td class="align-middle" data-field="updated_at"><?php echo $book['updated_at'] ? e($book['updated_at']) : '—'; ?></td>
                                    <td class="text-end align-middle">
                                        <button type="button" class="btn btn-primary btn-sm" data-action="update-order-book">
                                            Update
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
// Handle inline updates for order books while keeping the UI responsive.
(function () {
    const table = document.getElementById('orderBooksTable');
    const alertBox = document.getElementById('orderBookAlert');

    if (!table) {
        return;
    }

    function showAlert(message, type = 'success') {
        if (!alertBox) {
            return;
        }

        alertBox.textContent = message;
        alertBox.className = `alert alert-${type}`;
    }

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
        const updatedAtCell = row.querySelector('[data-field="updated_at"]');

        if (!id || !descriptionInput || !description2Input || !visibilitySelect) {
            showAlert('Unable to update this row because required fields are missing.', 'danger');
            return;
        }

        const payload = new FormData();
        payload.append('id', id);
        payload.append('description', descriptionInput.value.trim());
        payload.append('description_2', description2Input.value.trim());
        payload.append('is_visible', visibilitySelect.value);

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

            if (updatedAtCell && data.book && data.book.updated_at) {
                updatedAtCell.textContent = data.book.updated_at;
            }

            showAlert(data.message || 'Order book updated.', 'success');
        } catch (error) {
            showAlert('Unexpected error updating order book. Please try again.', 'danger');
        } finally {
            button.disabled = false;
            button.textContent = 'Update';
        }
    });
})();
</script>
