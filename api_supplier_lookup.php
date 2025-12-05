<?php
header('Content-Type: application/json');

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

const API_KEY = '%h68zOewi6lqsb7aaB4!VW4bF5^fsyGCGv%mGI6QSaD5!u0FDLjLp82MIQ61VO4J'; // must match VBA

if (!isset($data['api_key']) || $data['api_key'] !== API_KEY) {
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

// DB connect
$dbHost = 'cp53.domains.co.za';
$dbName = 'filiades_eems';
$dbUser = 'filiades_eemsdbuser';
$dbPass = 'hV&2w6JfW6@Pi3q1'; 

$dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

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
