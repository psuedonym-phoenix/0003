<?php
// api_purchase_order.php
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

// Helper
function get_field($arr, $key, $default = '') {
    return isset($arr[$key]) ? trim((string)$arr[$key]) : $default;
}

function get_num($arr, $key) {
    if (!isset($arr[$key]) || $arr[$key] === '' || $arr[$key] === null) {
        return null;
    }
    return (float)$arr[$key];
}

// 4) Extract main fields
$po_number      = get_field($data, 'po_number');
$supplier_code  = strtoupper(get_field($data, 'supplier_code'));
$order_date_raw = get_field($data, 'order_date');
$cost_code      = get_field($data, 'cost_code');
$cost_code_desc = get_field($data, 'cost_code_description');
$terms          = get_field($data, 'terms');
$reference      = get_field($data, 'reference');
$created_by     = get_field($data, 'created_by');
$source_file    = get_field($data, 'source_filename');

// NEW: order type (for legacy clients this will default to "standard")
$order_type_raw = strtolower(get_field($data, 'order_type', 'standard'));
if ($order_type_raw !== 'standard' && $order_type_raw !== 'transactional') {
    // Any unknown / invalid value falls back to standard
    $order_type = 'standard';
} else {
    $order_type = $order_type_raw;
}

// NEW: order book + sheet no
$order_book     = get_field($data, 'order_book');      // from T2
$order_sheet_no = get_field($data, 'order_sheet_no');  // from T1

// Totals
$subtotal       = get_num($data, 'subtotal');
$vat_percent    = get_num($data, 'vat_percent');
$vat_amount     = get_num($data, 'vat_amount');
$misc1_label    = get_field($data, 'misc1_label');
$misc1_amount   = get_num($data, 'misc1_amount');
$misc2_label    = get_field($data, 'misc2_label');
$misc2_amount   = get_num($data, 'misc2_amount');
$total_amount   = get_num($data, 'total_amount');

// Basic validation
if ($po_number === '' || $supplier_code === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing po_number or supplier_code']);
    exit;
}

// Normalise date "YYYY/MM/DD" → "YYYY-MM-DD" if needed
$order_date = null;
if ($order_date_raw !== '') {
    $order_date = str_replace('/', '-', $order_date_raw);
}

try {
    $pdo = get_db_connection();

    // 6) Lookup supplier id
    $stmt = $pdo->prepare("
        SELECT id, supplier_name
        FROM suppliers
        WHERE supplier_code = :supplier_code
    ");
    $stmt->execute([':supplier_code' => $supplier_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'error'      => 'Supplier not found',
            'error_code' => 'SUPPLIER_NOT_FOUND'
        ]);
        exit;
    }

    $supplier_id   = (int)$row['id'];
    $supplier_name = $row['supplier_name'];

    // 7) Insert new PO version (audit)
    $insert = $pdo->prepare("
        INSERT INTO purchase_orders (
            po_number,
            order_book,
            order_sheet_no,
            supplier_id,
            supplier_code,
            supplier_name,
            order_date,
            cost_code,
            cost_code_description,
            terms,
            reference,
            order_type,
            subtotal,
            vat_percent,
            vat_amount,
            misc1_label,
            misc1_amount,
            misc2_label,
            misc2_amount,
            total_amount,
            created_by,
            source_filename,
            created_at
        ) VALUES (
            :po_number,
            :order_book,
            :order_sheet_no,
            :supplier_id,
            :supplier_code,
            :supplier_name,
            :order_date,
            :cost_code,
            :cost_code_description,
            :terms,
            :reference,
            :order_type,
            :subtotal,
            :vat_percent,
            :vat_amount,
            :misc1_label,
            :misc1_amount,
            :misc2_label,
            :misc2_amount,
            :total_amount,
            :created_by,
            :source_filename,
            NOW()
        )
    ");

    $insert->execute([
        ':po_number'             => $po_number,
        ':order_book'            => $order_book,
        ':order_sheet_no'        => $order_sheet_no,
        ':supplier_id'           => $supplier_id,
        ':supplier_code'         => $supplier_code,
        ':supplier_name'         => $supplier_name,
        ':order_date'            => $order_date,
        ':cost_code'             => $cost_code,
        ':cost_code_description' => $cost_code_desc,
        ':terms'                 => $terms,
        ':reference'             => $reference,
        ':order_type'            => $order_type,
        ':subtotal'              => $subtotal,
        ':vat_percent'           => $vat_percent,
        ':vat_amount'            => $vat_amount,
        ':misc1_label'           => $misc1_label,
        ':misc1_amount'          => $misc1_amount,
        ':misc2_label'           => $misc2_label,
        ':misc2_amount'          => $misc2_amount,
        ':total_amount'          => $total_amount,
        ':created_by'            => $created_by,
        ':source_filename'       => $source_file
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'action'  => 'inserted',
        'id'      => $newId
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error',
        'detail'  => $e->getMessage()
    ]);
}
