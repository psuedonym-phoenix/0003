<?php
// api_purchase_order_lines.php
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/config/api_key_file.php';
header('Content-Type: application/json');

// 1) Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// 2) Decode JSON
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// 3) API key
if (!isset($data['api_key']) || $data['api_key'] !== EEMS_API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// 4) Helper
function get_field($arr, $key) {
    return isset($arr[$key]) ? trim((string)$arr[$key]) : '';
}

$po_number     = get_field($data, 'po_number');
$supplier_code = strtoupper(get_field($data, 'supplier_code'));
$lines         = isset($data['lines']) && is_array($data['lines']) ? $data['lines'] : [];

if ($po_number === '' || $supplier_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing po_number or supplier_code']);
    exit;
}

if (empty($lines)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No lines supplied']);
    exit;
}

try {
    $pdo = get_db_connection();

    // 6) Supplier lookup
    $stmt = $pdo->prepare("
        SELECT id
        FROM suppliers
        WHERE supplier_code = :supplier_code
    ");
    $stmt->execute([':supplier_code' => $supplier_code]);
    $supplier_id = $stmt->fetchColumn();

    if (!$supplier_id) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'error'      => 'Supplier not found',
            'error_code' => 'SUPPLIER_NOT_FOUND'
        ]);
        exit;
    }

    // 7) Latest purchase order header
    $stmt = $pdo->prepare("
        SELECT id, po_number, supplier_code, supplier_name
        FROM purchase_orders
        WHERE supplier_id = :supplier_id
          AND po_number   = :po_number
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':supplier_id' => $supplier_id,
        ':po_number'   => $po_number
    ]);

    $poHeader = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$poHeader) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'error'      => 'Purchase order header not found',
            'error_code' => 'PO_HEADER_NOT_FOUND'
        ]);
        exit;
    }

    $po_id        = (int)$poHeader['id'];
    $db_po_number = $poHeader['po_number'];
    $db_sup_code  = $poHeader['supplier_code'];
    $db_sup_name  = $poHeader['supplier_name'];

    // 8) Delete existing lines for this PO version
    $del = $pdo->prepare("DELETE FROM purchase_order_lines WHERE purchase_order_id = :po_id");
    $del->execute([':po_id' => $po_id]);

    // 9) Prepare INSERT with extended columns
    $insert = $pdo->prepare("
        INSERT INTO purchase_order_lines (
            purchase_order_id,
            po_number,
            supplier_code,
            supplier_name,
            line_no,
            line_type,
            line_date,
            item_code,
            description,
            quantity,
            unit,
            unit_price,
            deposit_amount,
            discount_percent,
            net_price,
            ex_vat_amount,
            line_vat_amount,
            line_total_amount,
            is_vatable
        ) VALUES (
            :purchase_order_id,
            :po_number,
            :supplier_code,
            :supplier_name,
            :line_no,
            :line_type,
            :line_date,
            :item_code,
            :description,
            :quantity,
            :unit,
            :unit_price,
            :deposit_amount,
            :discount_percent,
            :net_price,
            :ex_vat_amount,
            :line_vat_amount,
            :line_total_amount,
            :is_vatable
        )
    ");

    $count = 0;

    foreach ($lines as $line) {
        // Base fields
        $line_no    = isset($line['line_no']) ? (int)$line['line_no'] : 0;
        $item_code  = isset($line['item_code']) ? trim((string)$line['item_code']) : '';
        $description= isset($line['description']) ? trim((string)$line['description']) : '';
        $quantity   = isset($line['quantity']) ? (float)$line['quantity'] : 0;
        $unit       = isset($line['unit']) ? trim((string)$line['unit']) : '';
        $unit_price = isset($line['unit_price']) ? (float)$line['unit_price'] : 0;
        
        $disc_pct   = isset($line['discount_percent']) ? (float)$line['discount_percent'] : 0;
        $net_price  = isset($line['net_price']) ? (float)$line['net_price'] : 0;
        $deposit_amount = isset($line['deposit_amount']) ? (float)$line['deposit_amount'] : null;
        // Extended fields (optional)
        $line_type = isset($line['line_type']) && $line['line_type'] !== ''
            ? strtoupper($line['line_type'])
            : 'STANDARD';

        // line_date incoming format "YYYY/MM/DD" → convert to "YYYY-MM-DD"
        $line_date_raw = isset($line['line_date']) ? trim((string)$line['line_date']) : '';
        $line_date = null;
        if ($line_date_raw !== '') {
            // simple replacement; you can add stricter parsing if needed
            $line_date = str_replace('/', '-', $line_date_raw);
        }

        $ex_vat_amount     = isset($line['ex_vat_amount']) ? (float)$line['ex_vat_amount'] : null;
        $line_vat_amount   = isset($line['line_vat_amount']) ? (float)$line['line_vat_amount'] : null;
        $line_total_amount = isset($line['line_total_amount']) ? (float)$line['line_total_amount'] : null;

        $is_vatable = null;
        if (isset($line['is_vatable'])) {
            $is_vatable = (int)$line['is_vatable'];
        } elseif (!is_null($line_vat_amount)) {
            $is_vatable = ($line_vat_amount != 0) ? 1 : 0;
        }

        if ($line_no <= 0) {
            continue;
        }

        $insert->execute([
            ':purchase_order_id' => $po_id,
            ':po_number'         => $db_po_number,
            ':supplier_code'     => $db_sup_code,
            ':supplier_name'     => $db_sup_name,
            ':line_no'           => $line_no,
            ':line_type'         => $line_type,
            ':line_date'         => $line_date,
            ':item_code'         => $item_code,
            ':description'       => $description,
            ':quantity'          => $quantity,
            ':unit'              => $unit,
            ':unit_price'        => $unit_price,
            ':deposit_amount'    => $deposit_amount,
            ':discount_percent'  => $disc_pct,
            ':net_price'         => $net_price,
            ':ex_vat_amount'     => $ex_vat_amount,
            ':line_vat_amount'   => $line_vat_amount,
            ':line_total_amount' => $line_total_amount,
            ':is_vatable'        => $is_vatable
        ]);

        $count++;
    }

    echo json_encode([
        'success'            => true,
        'action'             => 'lines_replaced',
        'purchase_order_id'  => $po_id,
        'lines_inserted'     => $count
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error',
        'detail'  => $e->getMessage() // keep for debugging; remove later if you want
    ]);
}
