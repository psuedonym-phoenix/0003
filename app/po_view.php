<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Restrict access to authenticated admins only.
require_authentication();

// Helper to safely escape output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Keep the query string tidy while preserving meaningful context values.
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

$poNumber = trim($_GET['po_number'] ?? '');
$selectedBook = trim($_GET['order_book'] ?? '');
$showHidden = ($_GET['show_hidden'] ?? '0') === '1';
$returnSupplier = trim($_GET['supplier'] ?? '');
$returnPage = max(1, (int) ($_GET['page'] ?? 1));

if ($poNumber === '') {
    http_response_code(400);
    echo '<div class="container py-5"><div class="alert alert-danger">A PO Number is required to view a purchase order.</div></div>';
    exit;
}

$pdo = get_db_connection();

// Fetch the latest version of the requested purchase order, scoped to the selected order book when provided.
$orderSql = "
    SELECT po.*
    FROM purchase_orders po
    INNER JOIN (
        SELECT po_number, MAX(id) AS latest_id
        FROM purchase_orders
        GROUP BY po_number
    ) latest ON latest.po_number = po.po_number AND latest.latest_id = po.id
    WHERE po.po_number = :po_number
";

if ($selectedBook !== '') {
    $orderSql .= " AND po.order_book = :order_book";
}

$orderSql .= ' LIMIT 1';

$orderStmt = $pdo->prepare($orderSql);
$orderStmt->bindValue(':po_number', $poNumber, PDO::PARAM_STR);

if ($selectedBook !== '') {
    $orderStmt->bindValue(':order_book', $selectedBook, PDO::PARAM_STR);
}

$orderStmt->execute();
$purchaseOrder = $orderStmt->fetch();

if (!$purchaseOrder) {
    http_response_code(404);
    echo '<div class="container py-5"><div class="alert alert-warning">The requested purchase order could not be found.</div></div>';
    exit;
}

// Pull line items for this purchase order version ordered by their line number.
$linesStmt = $pdo->prepare("SELECT * FROM purchase_order_lines WHERE purchase_order_id = :purchase_order_id ORDER BY line_no ASC, id ASC");
$linesStmt->bindValue(':purchase_order_id', $purchaseOrder['id'], PDO::PARAM_INT);
$linesStmt->execute();
$lineItems = $linesStmt->fetchAll();

// Build navigation across the latest PO in the selected order book (or all books when none is chosen).
$navigationSql = "
    SELECT po.po_number
    FROM purchase_orders po
    INNER JOIN (
        SELECT po_number, MAX(id) AS latest_id
        FROM purchase_orders
        GROUP BY po_number
    ) latest ON latest.po_number = po.po_number AND latest.latest_id = po.id
";

if ($selectedBook !== '') {
    $navigationSql .= " WHERE po.order_book = :order_book";
}

$navigationSql .= ' ORDER BY po.po_number ASC';

$navigationStmt = $pdo->prepare($navigationSql);

if ($selectedBook !== '') {
    $navigationStmt->bindValue(':order_book', $selectedBook, PDO::PARAM_STR);
}

$navigationStmt->execute();
$poNumbers = $navigationStmt->fetchAll(PDO::FETCH_COLUMN);

$previousPo = null;
$nextPo = null;

foreach ($poNumbers as $index => $number) {
    if ($number !== $poNumber) {
        continue;
    }

    if ($index > 0) {
        $previousPo = $poNumbers[$index - 1];
    }

    if (isset($poNumbers[$index + 1])) {
        $nextPo = $poNumbers[$index + 1];
    }

    break;
}

// Prepare query string pieces shared between navigation links.
$sharedParams = [
    'order_book' => $selectedBook,
    'supplier' => $returnSupplier,
];

if ($showHidden) {
    $sharedParams['show_hidden'] = '1';
}

if ($returnPage > 1) {
    $sharedParams['page'] = (string) $returnPage;
}

$returnParams = array_merge(['view' => 'purchase_orders'], $sharedParams);
$returnUrl = 'index.php';
$returnQuery = build_query($returnParams);

if ($returnQuery !== '') {
    $returnUrl .= '?' . $returnQuery;
}

$previousUrl = null;
$nextUrl = null;

if ($previousPo !== null) {
    $previousUrl = 'po_view.php?' . build_query(array_merge($sharedParams, ['po_number' => $previousPo]));
}

if ($nextPo !== null) {
    $nextUrl = 'po_view.php?' . build_query(array_merge($sharedParams, ['po_number' => $nextPo]));
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order <?php echo e($purchaseOrder['po_number']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h1 class="h4 mb-1">Purchase Order <?php echo e($purchaseOrder['po_number']); ?></h1>
            <div class="text-secondary">Latest version with line items from purchase_order_lines.</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="<?php echo e($returnUrl); ?>">&larr; Back to Purchase Orders</a>
            <?php if ($previousUrl !== null) : ?>
                <a class="btn btn-outline-primary" href="<?php echo e($previousUrl); ?>">&larr; Previous</a>
            <?php endif; ?>
            <?php if ($nextUrl !== null) : ?>
                <a class="btn btn-outline-primary" href="<?php echo e($nextUrl); ?>">Next &rarr;</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-secondary small">Order Book</div>
                    <div class="fw-semibold"><?php echo e($purchaseOrder['order_book'] ?? 'Not set'); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-secondary small">Supplier</div>
                    <div class="fw-semibold"><?php echo e($purchaseOrder['supplier_name'] ?? ''); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-secondary small">Order Date</div>
                    <div class="fw-semibold"><?php echo e($purchaseOrder['order_date'] ?? ''); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-secondary small">Order Sheet</div>
                    <div class="fw-semibold"><?php echo e($purchaseOrder['order_sheet_no'] ?? ''); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-secondary small">Total Amount</div>
                    <div class="fw-semibold">R <?php echo number_format((float) ($purchaseOrder['total_amount'] ?? 0), 2); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-secondary small">Uploaded</div>
                    <div class="fw-semibold"><?php echo e($purchaseOrder['created_at'] ?? ''); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <h2 class="h6 mb-0">Line items</h2>
                    <small class="text-secondary">Showing the lines attached to this latest purchase order version.</small>
                </div>
                <span class="badge text-bg-light border">Total lines: <?php echo count($lineItems); ?></span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Line #</th>
                            <th scope="col">Type</th>
                            <th scope="col">Date</th>
                            <th scope="col">Item Code</th>
                            <th scope="col">Description</th>
                            <th scope="col" class="text-end">Quantity</th>
                            <th scope="col">Unit</th>
                            <th scope="col" class="text-end">Unit Price</th>
                            <th scope="col" class="text-end">Net Price</th>
                            <th scope="col" class="text-end">Ex VAT</th>
                            <th scope="col" class="text-end">VAT</th>
                            <th scope="col" class="text-end">Line Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lineItems)) : ?>
                            <tr>
                                <td colspan="12" class="text-secondary">No line items were captured for this purchase order version.</td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($lineItems as $line) : ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e((string) ($line['line_no'] ?? '')); ?></td>
                                    <td><?php echo e($line['line_type'] ?? ''); ?></td>
                                    <td><?php echo e($line['line_date'] ?? ''); ?></td>
                                    <td><?php echo e($line['item_code'] ?? ''); ?></td>
                                    <td><?php echo e($line['description'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($line['quantity'] ?? 0), 2); ?></td>
                                    <td><?php echo e($line['unit'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($line['unit_price'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($line['net_price'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($line['ex_vat_amount'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($line['line_vat_amount'] ?? 0), 2); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($line['line_total_amount'] ?? 0), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</body>
</html>
