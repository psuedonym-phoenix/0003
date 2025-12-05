<?php
require_once __DIR__ . '/db.php';

/**
 * Build a URL query string that omits empty values.
 */
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

/**
 * Fetch the latest purchase order header, lines, and navigation context.
 * Returns an array containing either the view data or an error message.
 */
function fetch_purchase_order_view(array $input): array
{
    $poNumber = trim($input['po_number'] ?? '');
    $selectedBook = trim($input['order_book'] ?? '');
    $showHidden = ($input['show_hidden'] ?? '0') === '1';
    $returnSupplier = trim($input['supplier'] ?? '');
    $returnPage = max(1, (int) ($input['page'] ?? 1));

    if ($poNumber === '') {
        return [
            'error' => 'A PO Number is required to view a purchase order.',
            'status' => 400,
        ];
    }

    $pdo = get_db_connection();

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
        return [
            'error' => 'The requested purchase order could not be found.',
            'status' => 404,
        ];
    }

    $linesStmt = $pdo->prepare(
        'SELECT * FROM purchase_order_lines WHERE purchase_order_id = :purchase_order_id ORDER BY line_no ASC, id ASC'
    );
    $linesStmt->bindValue(':purchase_order_id', $purchaseOrder['id'], PDO::PARAM_INT);
    $linesStmt->execute();
    $lineItems = $linesStmt->fetchAll();

    $rawPoType = strtolower((string) ($purchaseOrder['po_type'] ?? $purchaseOrder['order_type'] ?? $purchaseOrder['line_type'] ?? ''));

    if ($rawPoType === '' && !empty($lineItems)) {
        $lineTypes = array_unique(array_filter(array_map(static function ($line) {
            return strtolower((string) ($line['line_type'] ?? ''));
        }, $lineItems)));

        if (in_array('transactional', $lineTypes, true) || in_array('txn', $lineTypes, true)) {
            $rawPoType = 'transactional';
        }
    }

    $poType = $rawPoType === 'transactional' || $rawPoType === 'txn' ? 'transactional' : 'standard';
    $lineColumnCount = $poType === 'transactional' ? 7 : 8;

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

    return [
        'purchaseOrder' => $purchaseOrder,
        'lineItems' => $lineItems,
        'poType' => $poType,
        'lineColumnCount' => $lineColumnCount,
        'previousPo' => $previousPo,
        'nextPo' => $nextPo,
        'sharedParams' => $sharedParams,
        'returnParams' => array_merge(['view' => 'purchase_orders'], $sharedParams),
        'status' => 200,
    ];
}
