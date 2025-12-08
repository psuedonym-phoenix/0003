<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Only authenticated users may update purchase orders.
require_authentication();

header('Content-Type: application/json');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ]);
}

$purchaseOrderId = filter_input(INPUT_POST, 'purchase_order_id', FILTER_VALIDATE_INT);
$supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
$supplierCode = trim((string) ($_POST['supplier_code'] ?? ''));
$orderSheet = trim((string) ($_POST['order_sheet_no'] ?? ''));
$reference = trim((string) ($_POST['reference'] ?? ''));
$orderDateInput = trim((string) ($_POST['order_date'] ?? ''));

/**
 * Normalise a numeric form field by stripping spaces/commas so formatted values like
 * "1 234.50" or "1,234.50" can be safely parsed into floats.
 */
function parse_optional_float($rawValue)
{
    if ($rawValue === null) {
        return null;
    }

    $trimmed = trim((string) $rawValue);

    if ($trimmed === '') {
        return null;
    }

    // Allow thousands separators while leaving the decimal point intact.
    $normalised = preg_replace('/[\s,]/', '', $trimmed);

    return filter_var($normalised, FILTER_VALIDATE_FLOAT);
}

$exclusiveAmount = parse_optional_float($_POST['exclusive_amount'] ?? null);
$vatPercent = parse_optional_float($_POST['vat_percent'] ?? null);
$vatAmount = parse_optional_float($_POST['vat_amount'] ?? null);
$totalAmount = parse_optional_float($_POST['total_amount'] ?? null);

if (!$purchaseOrderId) {
    respond(400, [
        'success' => false,
        'message' => 'A valid purchase order ID is required.',
    ]);
}

if ($supplierName === '') {
    respond(400, [
        'success' => false,
        'message' => 'Supplier name cannot be empty.',
    ]);
}

$orderDate = null;
if ($orderDateInput !== '') {
    $date = DateTime::createFromFormat('Y-m-d', $orderDateInput);
    $dateErrors = DateTime::getLastErrors();

    if (!$date || ($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0) {
        respond(400, [
            'success' => false,
            'message' => 'Order date must be in YYYY-MM-DD format.',
        ]);
    }

    $orderDate = $date->format('Y-m-d');
}

if ($exclusiveAmount === false || $vatPercent === false || $vatAmount === false || $totalAmount === false) {
    respond(400, [
        'success' => false,
        'message' => 'Amounts must be valid numbers. Remove spaces or commas if necessary.',
    ]);
}

try {
    $pdo = get_db_connection();

    $poStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id LIMIT 1');
    $poStmt->execute([':id' => $purchaseOrderId]);
    $purchaseOrder = $poStmt->fetch(PDO::FETCH_ASSOC);

    if (!$purchaseOrder) {
        respond(404, [
            'success' => false,
            'message' => 'The requested purchase order could not be found.',
        ]);
    }

    // Only the latest version of a purchase order may be edited to preserve history.
    $latestStmt = $pdo->prepare('SELECT MAX(id) FROM purchase_orders WHERE po_number = :po_number');
    $latestStmt->execute([':po_number' => $purchaseOrder['po_number']]);
    $latestId = (int) $latestStmt->fetchColumn();

    if ($latestId !== (int) $purchaseOrder['id']) {
        respond(409, [
            'success' => false,
            'message' => 'Only the latest version of a purchase order can be edited.',
        ]);
    }

    // Begin versioned update: insert a new header row and duplicate existing line items
    // so history remains intact and the latest version is fully populated.
    $pdo->beginTransaction();

    $updatedOrder = $purchaseOrder;

    if (array_key_exists('supplier_name', $updatedOrder)) {
        $updatedOrder['supplier_name'] = $supplierName;
    }

    if (array_key_exists('supplier_code', $updatedOrder)) {
        $updatedOrder['supplier_code'] = $supplierCode;
    }

    if (array_key_exists('order_date', $updatedOrder)) {
        $updatedOrder['order_date'] = $orderDate;
    }

    if (array_key_exists('order_sheet_no', $updatedOrder)) {
        $updatedOrder['order_sheet_no'] = $orderSheet;
    }

    if (array_key_exists('reference', $updatedOrder)) {
        $updatedOrder['reference'] = $reference;
    }

    if (array_key_exists('subtotal', $updatedOrder)) {
        $updatedOrder['subtotal'] = $exclusiveAmount;
    }

    if (array_key_exists('exclusive_amount', $updatedOrder)) {
        $updatedOrder['exclusive_amount'] = $exclusiveAmount;
    }

    if (array_key_exists('vat_percent', $updatedOrder)) {
        $updatedOrder['vat_percent'] = $vatPercent;
    }

    if (array_key_exists('vat_amount', $updatedOrder)) {
        $updatedOrder['vat_amount'] = $vatAmount;
    }

    if (array_key_exists('total_amount', $updatedOrder)) {
        $updatedOrder['total_amount'] = $totalAmount;
    }

    // Preserve the original order type/line type information regardless of input.
    foreach (['po_type', 'order_type', 'line_type'] as $typeField) {
        if (array_key_exists($typeField, $purchaseOrder)) {
            $updatedOrder[$typeField] = $purchaseOrder[$typeField];
        }
    }

    // Refresh the uploaded timestamp so users can see when the latest version was saved.
    $updatedOrder['created_at'] = date('Y-m-d H:i:s');

    // Remove the primary key before inserting the new version row.
    unset($updatedOrder['id']);

    $columns = array_keys($updatedOrder);
    $placeholders = array_map(static function ($column) {
        return ':' . $column;
    }, $columns);

    $insertSql = 'INSERT INTO purchase_orders (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $insertStmt = $pdo->prepare($insertSql);

    $insertParams = [];
    foreach ($columns as $column) {
        $insertParams[':' . $column] = $updatedOrder[$column];
    }

    $insertStmt->execute($insertParams);
    $newPurchaseOrderId = (int) $pdo->lastInsertId();

    // Duplicate the existing lines so the new header version carries the same detail rows.
    $linesStmt = $pdo->prepare(
        'SELECT * FROM purchase_order_lines WHERE purchase_order_id = :purchase_order_id ORDER BY line_no ASC, id ASC'
    );
    $linesStmt->execute([':purchase_order_id' => $purchaseOrderId]);
    $existingLines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($existingLines)) {
        $lineColumns = array_keys($existingLines[0]);
        $lineColumns = array_values(array_filter($lineColumns, static function ($column) {
            return $column !== 'id';
        }));

        $linePlaceholders = array_map(static function ($column) {
            return ':' . $column;
        }, $lineColumns);

        $lineInsertSql = 'INSERT INTO purchase_order_lines (' . implode(', ', $lineColumns) . ')
            VALUES (' . implode(', ', $linePlaceholders) . ')';
        $lineInsertStmt = $pdo->prepare($lineInsertSql);

        foreach ($existingLines as $line) {
            $line['purchase_order_id'] = $newPurchaseOrderId;

            if (array_key_exists('supplier_name', $line)) {
                $line['supplier_name'] = $supplierName;
            }

            if (array_key_exists('supplier_code', $line)) {
                $line['supplier_code'] = $supplierCode;
            }

            $lineParams = [];
            foreach ($lineColumns as $column) {
                $lineParams[':' . $column] = $line[$column] ?? null;
            }

            $lineInsertStmt->execute($lineParams);
        }
    }

    $pdo->commit();

    $refreshStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id LIMIT 1');
    $refreshStmt->execute([':id' => $newPurchaseOrderId]);
    $latestOrder = $refreshStmt->fetch(PDO::FETCH_ASSOC);

    respond(200, [
        'success' => true,
        'message' => 'Purchase order header updated successfully. Line items were copied to the latest version.',
        'purchaseOrder' => $latestOrder,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'Unexpected error while updating the purchase order. Please try again.',
    ]);
}
