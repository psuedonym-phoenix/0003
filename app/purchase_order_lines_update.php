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
$updateCurrentHeader = ($_POST['update_current_header'] ?? '') === '1';

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

    $poType = strtolower((string) ($purchaseOrder['po_type'] ?? $purchaseOrder['order_type'] ?? 'standard'));
    $isTransactional = $poType === 'transactional';

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
    $totalAmount = 0.0;

    if (empty($decodedLines)) {
        respond(400, [
            'success' => false,
            'message' => 'Add at least one populated line before saving.',
        ]);
    }

    if ($isTransactional) {
        foreach ($decodedLines as $index => $line) {
            $description = trim((string) ($line['description'] ?? ''));
            $depositAmount = normalise_number($line['deposit_amount'] ?? 0);
            $exVatAmount = normalise_number($line['ex_vat_amount'] ?? 0);
            $lineVatAmount = normalise_number($line['line_vat_amount'] ?? 0);
            $lineTotalAmount = normalise_number($line['line_total_amount'] ?? ($exVatAmount + $lineVatAmount));
            $lineDate = trim((string) ($line['line_date'] ?? ''));
            $isVatable = array_key_exists('is_vatable', $line) ? ((bool) $line['is_vatable']) : true;

            if ($description === '' && $depositAmount === 0.0 && $exVatAmount === 0.0 && $lineVatAmount === 0.0 && $lineTotalAmount === 0.0) {
                continue;
            }

            if ($description === '') {
                respond(400, [
                    'success' => false,
                    'message' => 'Each line must include a description.',
                ]);
            }

            $lineNumber = count($lines) + 1;

            $exclusiveAmount += $exVatAmount;
            $vatAmount += $lineVatAmount;
            $totalAmount += $lineTotalAmount;

            $lines[] = [
                'line_no' => $lineNumber,
                'line_date' => $lineDate,
                'description' => $description,
                'deposit_amount' => $depositAmount,
                'ex_vat_amount' => $exVatAmount,
                'line_vat_amount' => $lineVatAmount,
                'line_total_amount' => $lineTotalAmount,
                'is_vatable' => $isVatable ? 1 : 0,
            ];
        }
    } else {
        foreach ($decodedLines as $index => $line) {
            $lineNumber = (int) ($line['line_no'] ?? ($index + 1));
            $quantity = max(0.0, normalise_number($line['quantity'] ?? 0));
            // Permit negative unit prices so credits/adjustments are preserved instead of being zeroed out.
            $unitPrice = normalise_number($line['unit_price'] ?? 0);
            $discountPercent = max(0.0, normalise_number($line['discount_percent'] ?? 0));
            $discountMultiplier = 1 - $discountPercent / 100;
            $providedNetPrice = array_key_exists('net_price', $line) ? normalise_number($line['net_price']) : null;
            $netPrice = $providedNetPrice !== null
                ? $providedNetPrice
                : $quantity * $unitPrice * $discountMultiplier;
            $isVatable = array_key_exists('is_vatable', $line) ? ((bool) $line['is_vatable']) : ($vatPercent > 0);

            $description = trim((string) ($line['description'] ?? ''));
            $itemCode = trim((string) ($line['item_code'] ?? ''));

            if ($itemCode === '' && $description === '' && $quantity <= 0 && $netPrice <= 0) {
                continue;
            }

            if ($itemCode === '' && $description === '') {
                respond(400, [
                    'success' => false,
                    'message' => 'Each line must include either an item code or a description.',
                ]);
            }

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
    }

    if (empty($lines)) {
        respond(400, [
            'success' => false,
            'message' => 'No valid line items were provided. Please fill in at least one line before saving.',
        ]);
    }

    if (!$isTransactional) {
        $totalAmount = $exclusiveAmount + $vatAmount;
    }

    $pdo->beginTransaction();

    // Decide whether to update the current header or create a new version.
    $targetPurchaseOrderId = $purchaseOrderId;

    if ($updateCurrentHeader === false) {
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
        $targetPurchaseOrderId = (int) $pdo->lastInsertId();
    } else {
        // Refresh the financial amounts on the existing latest header so the header save
        // can be followed by a single lines save without creating a duplicate version.
        $updateHeader = $pdo->prepare(
            'UPDATE purchase_orders
             SET subtotal = :subtotal,
                 vat_percent = :vat_percent,
                 vat_amount = :vat_amount,
                 total_amount = :total_amount,
                 created_at = NOW()
             WHERE id = :id'
        );

        $updateHeader->execute([
            ':subtotal' => $exclusiveAmount,
            ':vat_percent' => $vatPercent,
            ':vat_amount' => $vatAmount,
            ':total_amount' => $totalAmount,
            ':id' => $purchaseOrderId,
        ]);

        // Clear any existing lines on the target header before re-inserting the updated set.
        $deleteLines = $pdo->prepare('DELETE FROM purchase_order_lines WHERE purchase_order_id = :purchase_order_id');
        $deleteLines->execute([':purchase_order_id' => $purchaseOrderId]);
    }

    // Store the updated line items against the chosen header version.
    if ($isTransactional) {
        $lineInsert = $pdo->prepare(
            'INSERT INTO purchase_order_lines (
                purchase_order_id, po_number, supplier_code, supplier_name, line_no, line_type,
                line_date, description, deposit_amount, ex_vat_amount, line_vat_amount, line_total_amount, is_vatable
            ) VALUES (
                :purchase_order_id, :po_number, :supplier_code, :supplier_name, :line_no, :line_type,
                :line_date, :description, :deposit_amount, :ex_vat_amount, :line_vat_amount, :line_total_amount, :is_vatable
            )'
        );
    } else {
        $lineInsert = $pdo->prepare(
            'INSERT INTO purchase_order_lines (
                purchase_order_id, po_number, supplier_code, supplier_name, line_no, line_type,
                item_code, description, quantity, unit, unit_price, discount_percent, net_price, is_vatable
            ) VALUES (
                :purchase_order_id, :po_number, :supplier_code, :supplier_name, :line_no, :line_type,
                :item_code, :description, :quantity, :unit, :unit_price, :discount_percent, :net_price, :is_vatable
            )'
        );
    }

    foreach ($lines as $line) {
        if ($isTransactional) {
            $lineInsert->execute([
                ':purchase_order_id' => $targetPurchaseOrderId,
                ':po_number' => $purchaseOrder['po_number'] ?? null,
                ':supplier_code' => $purchaseOrder['supplier_code'] ?? null,
                ':supplier_name' => $purchaseOrder['supplier_name'] ?? null,
                ':line_no' => $line['line_no'],
                ':line_type' => $poType,
                ':line_date' => $line['line_date'],
                ':description' => $line['description'],
                ':deposit_amount' => $line['deposit_amount'],
                ':ex_vat_amount' => $line['ex_vat_amount'],
                ':line_vat_amount' => $line['line_vat_amount'],
                ':line_total_amount' => $line['line_total_amount'],
                ':is_vatable' => $line['is_vatable'],
            ]);
        } else {
            $lineInsert->execute([
                ':purchase_order_id' => $targetPurchaseOrderId,
                ':po_number' => $purchaseOrder['po_number'] ?? null,
                ':supplier_code' => $purchaseOrder['supplier_code'] ?? null,
                ':supplier_name' => $purchaseOrder['supplier_name'] ?? null,
                ':line_no' => $line['line_no'],
                ':line_type' => $poType,
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
    }

    $pdo->commit();

    respond(200, [
        'success' => true,
        'message' => 'Purchase order lines updated successfully.',
        'purchase_order_id' => $targetPurchaseOrderId,
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
