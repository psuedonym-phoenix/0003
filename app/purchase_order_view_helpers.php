<?php
require_once __DIR__ . '/db.php';

/**
 * Fetch the available units of measurement from the catalogue table.
 * The view relies on these options to build the UOM dropdown with fuzzy matching.
 */
function fetch_unit_options(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT unit_label FROM units_of_measurement ORDER BY unit_label ASC');
        $units = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_filter(array_map(static function ($unit) {
            return trim((string) $unit);
        }, $units), static function ($unit) {
            return $unit !== '';
        }));
    } catch (Throwable $exception) {
        // If the table does not yet exist, fall back to an empty list so the view still renders.
        return [];
    }
}

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
    $originView = $input['origin_view'] ?? 'purchase_orders';

    if (!in_array($originView, ['purchase_orders', 'line_entry_enquiry'], true)) {
        $originView = 'purchase_orders';
    }

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

    // Ensure the VAT amount field is always available to the view, even if
    // older records or schemas did not populate it.
    if (!array_key_exists('vat_amount', $purchaseOrder)) {
        $purchaseOrder['vat_amount'] = null;
    }

    $vatPercent = (float) ($purchaseOrder['vat_percent'] ?? 0);

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
    // Line column count includes the running total column, VATable flag, and an actions column for editable lines.
    $lineColumnCount = $poType === 'transactional' ? 9 : 11;

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
        'origin_view' => $originView,
    ];

    if ($originView === 'line_entry_enquiry') {
        $lineReturnKeys = [
            'po_number',
            'order_book',
            'supplier',
            'query',
            'order_date_from',
            'order_date_to',
            'sort_by',
            'sort_dir',
            'page',
        ];

        foreach ($lineReturnKeys as $key) {
            $value = trim((string) ($input[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $sharedParams[$key] = $value;
        }
    } else {
        $sharedParams['order_book'] = $selectedBook;
        $sharedParams['supplier'] = $returnSupplier;

        if ($showHidden) {
            $sharedParams['show_hidden'] = '1';
        }

        if ($returnPage > 1) {
            $sharedParams['page'] = (string) $returnPage;
        }
    }

    $returnParams = ['view' => $originView] + $sharedParams;

    return [
        'purchaseOrder' => $purchaseOrder,
        'lineItems' => $lineItems,
        'poType' => $poType,
        'lineColumnCount' => $lineColumnCount,
        'lineSummary' => calculate_line_summary($lineItems, $poType, $vatPercent),
        'previousPo' => $previousPo,
        'nextPo' => $nextPo,
        'sharedParams' => $sharedParams,
        'returnParams' => $returnParams,
        'suppliers' => fetch_supplier_options($pdo),
        'supplierDetails' => fetch_supplier_details($pdo, $purchaseOrder),
        'unitOptions' => fetch_unit_options($pdo),
        'status' => 200,
    ];
}

/**
 * Provide a quick summary of the line items for header display.
 * We explicitly choose the numeric column based on the PO type so the roll-up
 * mirrors the running total shown in the table below (net price vs line total)
 * and applies VAT to standard lines when the VATable flag allows it.
 */
function calculate_line_summary(array $lineItems, string $poType, float $vatPercent): array
{
    $lineSum = 0.0;
    $vatRate = max(0.0, $vatPercent);

    foreach ($lineItems as $line) {
        if ($poType === 'transactional') {
            // Transactional rows already include VAT in the stored line total.
            $lineSum += round((float) ($line['line_total_amount'] ?? 0), 2);
            continue;
        }

        $lineNet = (float) ($line['net_price'] ?? 0);
        // If the VATable flag is missing, default to applying VAT using the PO VAT%.
        $lineIsVatable = !isset($line['is_vatable']) || (int) $line['is_vatable'] === 1;
        $lineVat = $lineIsVatable ? ($lineNet * $vatRate) : 0.0;

        $lineSum += round($lineNet + $lineVat, 2);
    }

    return [
        'count' => count($lineItems),
        'sum' => round($lineSum, 2),
    ];
}

/**
 * Load supplier names and codes to populate selection controls when editing a purchase order.
 */
function fetch_supplier_options(PDO $pdo): array
{
    try {
        $stmt = $pdo->query(
            'SELECT id, supplier_code, supplier_name FROM suppliers WHERE supplier_name IS NOT NULL AND supplier_name != "" ORDER BY supplier_name ASC'
        );

        return $stmt->fetchAll();
    } catch (Throwable $exception) {
        // If the suppliers table is unavailable we still want the purchase order view to render.
        return [];
    }
}

/**
 * Load supplier master data so the view can display accurate addresses and contact details.
 * Falls back gracefully when identifiers are missing or the suppliers table is unavailable.
 */
function fetch_supplier_details(PDO $pdo, array $purchaseOrder): array
{
    $supplierId = $purchaseOrder['supplier_id'] ?? null;
    $supplierCode = trim((string) ($purchaseOrder['supplier_code'] ?? ''));

    try {
        if ($supplierId !== null) {
            $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = :id LIMIT 1');
            $stmt->bindValue(':id', $supplierId, PDO::PARAM_INT);
        } elseif ($supplierCode !== '') {
            $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE supplier_code = :supplier_code LIMIT 1');
            $stmt->bindValue(':supplier_code', $supplierCode, PDO::PARAM_STR);
        } else {
            return [];
        }

        $stmt->execute();
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

        return $supplier ?: [];
    } catch (Throwable $exception) {
        // If the suppliers table cannot be queried we return an empty set so the view can fall back to PO data.
        return [];
    }
}
