<?php
// api_purchase_order_import.php
header('Content-Type: application/json');

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/api_key.php';

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

// Helpers
function get_field($arr, $key, $default = '') {
    return isset($arr[$key]) ? trim((string)$arr[$key]) : $default;
}
function get_num($arr, $key) {
    if (!isset($arr[$key]) || $arr[$key] === '' || $arr[$key] === null) {
        return null;
    }
    return (float)$arr[$key];
}

// 4) Extract fields
$po_number        = get_field($data, 'po_number');
$supplier_id_raw  = get_field($data, 'supplier_id');     // from X1
$supplier_code_in = strtoupper(get_field($data, 'supplier_code')); // optional/info

$order_date_raw   = get_field($data, 'order_date');
$cost_code        = get_field($data, 'cost_code');
$cost_code_desc   = get_field($data, 'cost_code_description');
$terms            = get_field($data, 'terms');
$reference        = get_field($data, 'reference');
$order_book       = get_field($data, 'order_book');      // T2
$order_sheet_no   = get_field($data, 'order_sheet_no');  // T1

$subtotal       = get_num($data, 'subtotal');
$vat_percent    = get_num($data, 'vat_percent');
$vat_amount     = get_num($data, 'vat_amount');
$misc1_label    = get_field($data, 'misc1_label');
$misc1_amount   = get_num($data, 'misc1_amount');
$misc2_label    = get_field($data, 'misc2_label');
$misc2_amount   = get_num($data, 'misc2_amount');
$total_amount   = get_num($data, 'total_amount');

$created_by     = get_field($data, 'created_by');
$source_file    = get_field($data, 'source_filename');

// Basic validation
if ($po_number === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing po_number']);
    exit;
}

if ($supplier_id_raw === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing supplier_id']);
    exit;
}

$supplier_id = (int)$supplier_id_raw;
if ($supplier_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid supplier_id']);
    exit;
}

// Normalise date "YYYY/MM/DD" -> "YYYY-MM-DD"
$order_date = null;
if ($order_date_raw !== '') {
    $order_date = str_replace('/', '-', $order_date_raw);
}

// 5) DB connection
try {
    $pdo = get_db_connection();

    // 6) Confirm supplier exists by ID and get canonical code/name
    $stmt = $pdo->prepare("
        SELECT id, supplier_code, supplier_name
        FROM suppliers
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $supplier_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(400);
        echo json_encode([
            'success'    => false,
            'error'      => 'Supplier not found for given id',
            'error_code' => 'SUPPLIER_NOT_FOUND'
        ]);
        exit;
    }

    $supplier_id   = (int)$row['id'];            // trusted ID
    $supplier_code = $row['supplier_code'];      // canonical
    $supplier_name = $row['supplier_name'];

    // 7) Insert PO header (audit-friendly: always insert, never overwrite)
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
        'action'  => 'import_inserted',
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
