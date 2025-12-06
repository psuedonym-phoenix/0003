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
$exclusiveRaw = $_POST['exclusive_amount'] ?? '';
$vatPercentRaw = $_POST['vat_percent'] ?? '';
$vatAmountRaw = $_POST['vat_amount'] ?? '';
$totalAmountRaw = $_POST['total_amount'] ?? '';

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

$exclusiveAmount = $exclusiveRaw === '' ? null : filter_var($exclusiveRaw, FILTER_VALIDATE_FLOAT);
$vatPercent = $vatPercentRaw === '' ? null : filter_var($vatPercentRaw, FILTER_VALIDATE_FLOAT);
$vatAmount = $vatAmountRaw === '' ? null : filter_var($vatAmountRaw, FILTER_VALIDATE_FLOAT);
$totalAmount = $totalAmountRaw === '' ? null : filter_var($totalAmountRaw, FILTER_VALIDATE_FLOAT);

if ($exclusiveAmount === false || $vatPercent === false || $vatAmount === false || $totalAmount === false) {
    respond(400, [
        'success' => false,
        'message' => 'Amounts must be valid numbers.',
    ]);
}

try {
    $pdo = get_db_connection();

    $poStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id LIMIT 1');
    $poStmt->execute([':id' => $purchaseOrderId]);
    $purchaseOrder = $poStmt->fetch();

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

    $updateParts = [];
    $params = [':id' => $purchaseOrderId];

    if (array_key_exists('supplier_name', $purchaseOrder)) {
        $updateParts[] = 'supplier_name = :supplier_name';
        $params[':supplier_name'] = $supplierName;
    }

    if (array_key_exists('supplier_code', $purchaseOrder)) {
        $updateParts[] = 'supplier_code = :supplier_code';
        $params[':supplier_code'] = $supplierCode;
    }

    if (array_key_exists('order_date', $purchaseOrder)) {
        $updateParts[] = 'order_date = :order_date';
        $params[':order_date'] = $orderDate;
    }

    if (array_key_exists('order_sheet_no', $purchaseOrder)) {
        $updateParts[] = 'order_sheet_no = :order_sheet_no';
        $params[':order_sheet_no'] = $orderSheet;
    }

    if (array_key_exists('reference', $purchaseOrder)) {
        $updateParts[] = 'reference = :reference';
        $params[':reference'] = $reference;
    }

    if (array_key_exists('subtotal', $purchaseOrder)) {
        $updateParts[] = 'subtotal = :subtotal';
        $params[':subtotal'] = $exclusiveAmount;
    }

    if (array_key_exists('exclusive_amount', $purchaseOrder)) {
        $updateParts[] = 'exclusive_amount = :exclusive_amount';
        $params[':exclusive_amount'] = $exclusiveAmount;
    }

    if (array_key_exists('vat_percent', $purchaseOrder)) {
        $updateParts[] = 'vat_percent = :vat_percent';
        $params[':vat_percent'] = $vatPercent;
    }

    if (array_key_exists('vat_amount', $purchaseOrder)) {
        $updateParts[] = 'vat_amount = :vat_amount';
        $params[':vat_amount'] = $vatAmount;
    }

    if (array_key_exists('total_amount', $purchaseOrder)) {
        $updateParts[] = 'total_amount = :total_amount';
        $params[':total_amount'] = $totalAmount;
    }

    if (empty($updateParts)) {
        respond(400, [
            'success' => false,
            'message' => 'No editable columns were detected for this purchase order.',
        ]);
    }

    $updateSql = 'UPDATE purchase_orders SET ' . implode(', ', $updateParts) . ' WHERE id = :id';
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute($params);

    $refreshStmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = :id LIMIT 1');
    $refreshStmt->execute([':id' => $purchaseOrderId]);
    $updatedOrder = $refreshStmt->fetch();

    respond(200, [
        'success' => true,
        'message' => 'Purchase order header updated successfully.',
        'purchaseOrder' => $updatedOrder,
    ]);
} catch (Throwable $exception) {
    respond(500, [
        'success' => false,
        'message' => 'Unexpected error while updating the purchase order. Please try again.',
    ]);
}
