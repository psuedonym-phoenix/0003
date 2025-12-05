<?php
require_once __DIR__ . '/../db.php';

// Helper to safely output HTML.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pdo = get_db_connection();

// Try to pull curated order book metadata; fall back to distinct book codes from purchase_orders if the table is missing.
$orderBooks = [];
try {
    $booksStmt = $pdo->query(
        'SELECT id, book_code, description, description_2, qty, is_visible, created_at
         FROM order_books
         ORDER BY book_code ASC'
    );
    $orderBooks = $booksStmt->fetchAll();
} catch (Throwable $exception) {
    // If the admin has not created the metadata table yet we still want the page to render.
    $orderBooks = [];
}

// Fallback mode uses distinct order_book values from purchase_orders.
if (empty($orderBooks)) {
    $fallbackStmt = $pdo->query(
        'SELECT DISTINCT order_book AS book_code
         FROM purchase_orders
         WHERE order_book IS NOT NULL AND order_book != ""
         ORDER BY order_book ASC'
    );

    while ($row = $fallbackStmt->fetch()) {
        $orderBooks[] = [
            'id' => null,
            'book_code' => $row['book_code'],
            'description' => 'Derived from purchase_orders',
            'description_2' => '',
            'qty' => null,
            'is_visible' => 1,
            'created_at' => null,
        ];
    }
}

// Map book codes to a quick lookup for order counts.
$bookCodes = array_column($orderBooks, 'book_code');
$orderCounts = [];
if (!empty($bookCodes)) {
    $placeholders = implode(',', array_fill(0, count($bookCodes), '?'));
    $countStmt = $pdo->prepare(
        "SELECT order_book, COUNT(*) AS total_orders
         FROM purchase_orders
         WHERE order_book IN ($placeholders)
         GROUP BY order_book"
    );
    $countStmt->execute($bookCodes);

    foreach ($countStmt->fetchAll() as $countRow) {
        $orderCounts[$countRow['order_book']] = (int) $countRow['total_orders'];
    }
}

// Determine which book is selected for the detailed order list.
$selectedBook = $_GET['order_book'] ?? ($bookCodes[0] ?? '');

$orders = [];
if ($selectedBook !== '') {
    $ordersStmt = $pdo->prepare(
        'SELECT id, po_number, order_book, order_sheet_no, supplier_name, order_date, total_amount, created_at
         FROM purchase_orders
         WHERE order_book = :book
         ORDER BY created_at DESC'
    );
    $ordersStmt->execute([':book' => $selectedBook]);
    $orders = $ordersStmt->fetchAll();
}
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
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-3">
            <div>
                <h3 class="h6 mb-1">Order Books</h3>
                <small class="text-secondary">Each entry represents a unique order book with its own metadata.</small>
            </div>
            <div class="d-flex align-items-center gap-2 mt-3 mt-lg-0">
                <label for="orderBookSelect" class="form-label mb-0">Filter orders by book:</label>
                <select id="orderBookSelect" class="form-select form-select-sm" <?php echo empty($bookCodes) ? 'disabled' : ''; ?>>
                    <?php if (empty($bookCodes)) : ?>
                        <option value="">No order books available</option>
                    <?php else : ?>
                        <?php foreach ($bookCodes as $bookCode) : ?>
                            <option value="<?php echo e($bookCode); ?>" <?php echo $bookCode === $selectedBook ? 'selected' : ''; ?>>
                                <?php echo e($bookCode); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Order Book</th>
                        <th scope="col">Description</th>
                        <th scope="col">Description 2</th>
                        <th scope="col">Visible</th>
                        <th scope="col" class="text-end">Qty</th>
                        <th scope="col" class="text-end">Orders</th>
                        <th scope="col">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orderBooks)) : ?>
                        <tr>
                            <td colspan="7" class="text-secondary">No order book metadata found. The list above is populated from existing purchase orders.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($orderBooks as $book) : ?>
                            <tr>
                                <td class="fw-semibold"><?php echo e($book['book_code']); ?></td>
                                <td><?php echo e($book['description']); ?></td>
                                <td><?php echo $book['description_2'] !== '' ? e($book['description_2']) : '—'; ?></td>
                                <td>
                                    <?php if ((int) $book['is_visible'] === 1) : ?>
                                        <span class="badge text-bg-success">Shown</span>
                                    <?php else : ?>
                                        <span class="badge text-bg-secondary">Hidden</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?php echo is_null($book['qty']) ? '—' : number_format((float) $book['qty']); ?></td>
                                <td class="text-end">
                                    <?php echo isset($orderCounts[$book['book_code']]) ? number_format($orderCounts[$book['book_code']]) : '0'; ?>
                                </td>
                                <td>
                                    <?php echo $book['created_at'] ? e($book['created_at']) : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="h6 mb-1">Orders in book: <?php echo $selectedBook !== '' ? e($selectedBook) : 'None selected'; ?></h3>
                <small class="text-secondary">Showing all purchase orders that belong to the selected order book.</small>
            </div>
            <span class="badge text-bg-light border">Total orders: <?php echo count($orders); ?></span>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th scope="col">PO Number</th>
                        <th scope="col">Order Sheet</th>
                        <th scope="col">Supplier</th>
                        <th scope="col">Order Date</th>
                        <th scope="col" class="text-end">Total Amount</th>
                        <th scope="col">Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($selectedBook === '') : ?>
                        <tr>
                            <td colspan="6" class="text-secondary">Select an order book to view its purchase orders.</td>
                        </tr>
                    <?php elseif (empty($orders)) : ?>
                        <tr>
                            <td colspan="6" class="text-secondary">No purchase orders were found for this order book.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($orders as $order) : ?>
                            <tr>
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
    const selector = document.getElementById('orderBookSelect');
    const contentArea = document.getElementById('contentArea');

    if (!selector || !contentArea) {
        return;
    }

    selector.addEventListener('change', async (event) => {
        const params = new URLSearchParams({ view: 'purchase_orders', order_book: event.target.value });

        try {
            const response = await fetch(`content.php?${params.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error('Unable to load order book');
            }

            const html = await response.text();
            contentArea.innerHTML = html;
        } catch (error) {
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger mt-3';
            alert.textContent = 'There was a problem loading the selected order book. Please try again.';
            contentArea.prepend(alert);
        }
    });
})();
</script>
