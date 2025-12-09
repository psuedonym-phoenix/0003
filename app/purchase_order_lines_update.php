<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Only authenticated users may update purchase order lines.
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
$vatPercentInput = filter_input(INPUT_POST, 'vat_percent', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$rawLines = $_POST['lines'] ?? '[]';

if (!$purchaseOrderId) {
    respond(400, [
        'success' => false,
        'message' => 'A valid purchase order ID is required.',
    ]);
}

$decodedLines = json_decode($rawLines, true);

if (!is_array($decodedLines)) {
    respond(400, [
        'success' => false,
        'message' => 'Line data must be valid JSON.',
    ]);
}

/**
 * Ensure numeric inputs are safely normalised to floats.
 */
function normalise_number($value): float
{
    if ($value === null) {
        return 0.0;
    }

    if (is_string($value)) {
        $value = preg_replace('/[,\s]/', '', $value);
    }

    return (float) $value;
}

/**
 * Store the provided unit of measurement in the catalogue table when it is missing.
 */
function ensure_unit_catalogued(PDO $pdo, string $unit): void
{
    $trimmedUnit = trim($unit);

    if ($trimmedUnit === '') {
        return;
    }

    $insertUnit = $pdo->prepare(
        'INSERT INTO units_of_measurement (unit_label, created_at) VALUES (:unit_label, NOW())
        ON DUPLICATE KEY UPDATE unit_label = VALUES(unit_label)'
    );
    $insertUnit->execute([':unit_label' => $trimmedUnit]);
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

    $poType = $purchaseOrder['po_type'] ?? $purchaseOrder['order_type'] ?? 'standard';

    if ($poType === 'transactional') {
        respond(400, [
            'success' => false,
            'message' => 'Transactional purchase orders cannot be edited via this screen.',
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

    $vatPercent = normalise_number($vatPercentInput);
    $vatRate = max(0.0, $vatPercent) / 100;

    $lines = [];
    $exclusiveAmount = 0.0;
    $vatAmount = 0.0;

    if (empty($decodedLines)) {
        respond(400, [
            'success' => false,
            'message' => 'Add at least one populated line before saving.',
        ]);
    }

    foreach ($decodedLines as $index => $line) {
        $lineNumber = (int) ($line['line_no'] ?? ($index + 1));
        $quantity = max(0.0, normalise_number($line['quantity'] ?? 0));
        $unitPrice = max(0.0, normalise_number($line['unit_price'] ?? 0));
        $discountPercent = max(0.0, normalise_number($line['discount_percent'] ?? 0));
        $discountMultiplier = 1 - $discountPercent / 100;
        $providedNetPrice = array_key_exists('net_price', $line) ? normalise_number($line['net_price']) : null;
        $netPrice = $providedNetPrice !== null
            ? max(0.0, $providedNetPrice)
            : max(0.0, $quantity * $unitPrice * $discountMultiplier);
        $isVatable = array_key_exists('is_vatable', $line) ? ((bool) $line['is_vatable']) : ($vatPercent > 0);

        $description = trim((string) ($line['description'] ?? ''));
        $itemCode = trim((string) ($line['item_code'] ?? ''));

        // Ignore placeholder rows that have no identifying data and no value attached.
        if ($itemCode === '' && $description === '' && $quantity <= 0 && $netPrice <= 0) {
            continue;
        }

        if ($itemCode === '' && $description === '') {
            respond(400, [
                'success' => false,
                'message' => 'Each line must include either an item code or a description.',
            ]);
        }

        // Renumber once blanks are removed so we avoid duplicate line numbers.
        $lineNumber = count($lines) + 1;

        $exclusiveAmount += $netPrice;
        $vatAmount += $isVatable ? ($netPrice * $vatRate) : 0.0;

        $lines[] = [
            'line_no' => $lineNumber,
            'item_code' => $itemCode,
            'description' => $description,
            'quantity' => $quantity,
            'unit' => trim((string) ($line['unit'] ?? '')),
            'unit_price' => $unitPrice,
            'discount_percent' => $discountPercent,
            'net_price' => $netPrice,
            'is_vatable' => $isVatable ? 1 : 0,
        ];
    }

    if (empty($lines)) {
        respond(400, [
            'success' => false,
            'message' => 'No valid line items were provided. Please fill in at least one line before saving.',
        ]);
    }

    $totalAmount = $exclusiveAmount + $vatAmount;

    $pdo->beginTransaction();

    // Prepare an updated header by cloning the current row and refreshing financial amounts.
    $updatedOrder = $purchaseOrder;
    //$updatedOrder['exclusive_amount'] = $exclusiveAmount;
    $updatedOrder['subtotal'] = $exclusiveAmount;
    $updatedOrder['vat_percent'] = $vatPercent;
    $updatedOrder['vat_amount'] = $vatAmount;
    $updatedOrder['total_amount'] = $totalAmount;
    $updatedOrder['created_at'] = date('Y-m-d H:i:s');

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

    // Store the updated line items against the new header version.
    $lineInsert = $pdo->prepare(
        'INSERT INTO purchase_order_lines (
            purchase_order_id, po_number, supplier_code, supplier_name, line_no, line_type,
            item_code, description, quantity, unit, unit_price, discount_percent, net_price, is_vatable
        ) VALUES (
            :purchase_order_id, :po_number, :supplier_code, :supplier_name, :line_no, :line_type,
            :item_code, :description, :quantity, :unit, :unit_price, :discount_percent, :net_price, :is_vatable
        )'
    );

    foreach ($lines as $line) {
        $lineInsert->execute([
            ':purchase_order_id' => $newPurchaseOrderId,
            ':po_number' => $purchaseOrder['po_number'] ?? null,
            ':supplier_code' => $purchaseOrder['supplier_code'] ?? null,
            ':supplier_name' => $purchaseOrder['supplier_name'] ?? null,
            ':line_no' => $line['line_no'],
            ':line_type' => $purchaseOrder['line_type'] ?? 'standard',
            ':item_code' => $line['item_code'],
            ':description' => $line['description'],
            ':quantity' => $line['quantity'],
            ':unit' => $line['unit'],
            ':unit_price' => $line['unit_price'],
            ':discount_percent' => $line['discount_percent'],
            ':net_price' => $line['net_price'],
            ':is_vatable' => $line['is_vatable'],
        ]);

        ensure_unit_catalogued($pdo, $line['unit']);
    }

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => 'Purchase order lines updated successfully.',
        'purchase_order_id' => $newPurchaseOrderId,
        'total_amount' => $totalAmount,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    respond(500, [
        'success' => false,
        'message' => 'An error occurred while updating purchase order lines.',
        'error' => $exception->getMessage(),
    ]);
}
