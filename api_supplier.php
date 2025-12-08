<?php
// api_supplier.php
header('Content-Type: application/json');

// Load shared configuration, DB connector, and API key so every endpoint uses the same credentials.
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/api_key.php';

// 1) Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// 2) Read JSON input
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

// 3) API key check (shared via /app/api_key.php)
if (!isset($data['api_key']) || $data['api_key'] !== ApiKey) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

// 4) Helper to safely get fields
function get_field($arr, $key) {
    return isset($arr[$key]) ? trim((string)$arr[$key]) : '';
}

// 5) Extract supplier fields (including address_line4)
$supplier_code      = strtoupper(get_field($data, 'supplier_code'));
$supplier_name      = get_field($data, 'supplier_name');
$address_line1      = get_field($data, 'address_line1');
$address_line2      = get_field($data, 'address_line2');
$address_line3      = get_field($data, 'address_line3');
$address_line4      = get_field($data, 'address_line4');  // NEW
$telephone_no       = get_field($data, 'telephone_no');
$fax_no             = get_field($data, 'fax_no');
$contact_person     = get_field($data, 'contact_person');
$contact_person_no  = get_field($data, 'contact_person_no');
$contact_email      = get_field($data, 'contact_email');

// Basic validation
if ($supplier_code === '' || $supplier_name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing supplier_code or supplier_name']);
    exit;
}

// 6) DB connection (shared helper keeps credentials in one place)
try {
    $pdo = get_db_connection();

    // 7) Check if supplier exists
    $stmt = $pdo->prepare("
        SELECT id
        FROM suppliers
        WHERE supplier_code = :supplier_code
    ");
    $stmt->execute([':supplier_code' => $supplier_code]);
    $existingId = $stmt->fetchColumn();

    if ($existingId) {
        // 8) UPDATE existing supplier
        $update = $pdo->prepare("
            UPDATE suppliers
            SET
                supplier_name     = :supplier_name,
                address_line1     = :address_line1,
                address_line2     = :address_line2,
                address_line3     = :address_line3,
                address_line4     = :address_line4,
                telephone_no      = :telephone_no,
                fax_no            = :fax_no,
                contact_person    = :contact_person,
                contact_person_no = :contact_person_no,
                contact_email     = :contact_email,
                updated_at        = NOW()
            WHERE supplier_code = :supplier_code
        ");

        $update->execute([
            ':supplier_name'     => $supplier_name,
            ':address_line1'     => $address_line1,
            ':address_line2'     => $address_line2,
            ':address_line3'     => $address_line3,
            ':address_line4'     => $address_line4,
            ':telephone_no'      => $telephone_no,
            ':fax_no'            => $fax_no,
            ':contact_person'    => $contact_person,
            ':contact_person_no' => $contact_person_no,
            ':contact_email'     => $contact_email,
            ':supplier_code'     => $supplier_code
        ]);

        echo json_encode([
            'success' => true,
            'action'  => 'updated',
            'id'      => (int)$existingId
        ]);
    } else {
        // 9) INSERT new supplier
        $insert = $pdo->prepare("
            INSERT INTO suppliers (
                supplier_code,
                supplier_name,
                address_line1,
                address_line2,
                address_line3,
                address_line4,
                telephone_no,
                fax_no,
                contact_person,
                contact_person_no,
                contact_email,
                created_at,
                updated_at
            ) VALUES (
                :supplier_code,
                :supplier_name,
                :address_line1,
                :address_line2,
                :address_line3,
                :address_line4,
                :telephone_no,
                :fax_no,
                :contact_person,
                :contact_person_no,
                :contact_email,
                NOW(),
                NOW()
            )
        ");

        $insert->execute([
            ':supplier_code'     => $supplier_code,
            ':supplier_name'     => $supplier_name,
            ':address_line1'     => $address_line1,
            ':address_line2'     => $address_line2,
            ':address_line3'     => $address_line3,
            ':address_line4'     => $address_line4,
            ':telephone_no'      => $telephone_no,
            ':fax_no'            => $fax_no,
            ':contact_person'    => $contact_person,
            ':contact_person_no' => $contact_person_no,
            ':contact_email'     => $contact_email
        ]);

        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'action'  => 'inserted',
            'id'      => $newId
        ]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error',
        'detail'  => $e->getMessage()  // keep this while debugging; remove later if you want
    ]);
}
