<?php
header('Content-Type: application/json');

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/db.php';
require_once __DIR__ . '/app/api_key.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

if (!isset($data['api_key']) || $data['api_key'] !== EEMS_API_KEY) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

function field($arr,$key){ return isset($arr[$key]) ? trim($arr[$key]) : ''; }

$supplier_name = field($data,'supplier_name');

if ($supplier_name === '') {
    echo json_encode(['success'=>false,'error'=>'Missing supplier_name']);
    exit;
}

try {
    $pdo = get_db_connection();

    $stmt = $pdo->prepare("
        SELECT id, supplier_code, supplier_name
        FROM suppliers
        WHERE supplier_name = :name
        LIMIT 1
    ");
    $stmt->execute([':name' => $supplier_name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success'=>false,'found'=>false]);
        exit;
    }

    echo json_encode([
        'success'=>true,
        'found'=>true,
        'supplier_id'=>$row['id'],
        'supplier_code'=>$row['supplier_code'],
        'supplier_name'=>$row['supplier_name']
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success'=>false,
        'error'=>'Database error',
        'detail'=>$e->getMessage()
    ]);
}
