<?php
// api_enquiry_cost_codes.php
// Returns purchase orders filtered by cost code and optional supplier/date criteria.

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET is supported']);
    exit;
}

$params = $_GET;

$costCodeId = isset($params['cost_code_id']) ? (int) $params['cost_code_id'] : 0;
$costCode = trim($params['cost_code'] ?? '');
$costCodeDescription = trim($params['cost_code_description'] ?? '');
$supplierId = isset($params['supplier_id']) ? (int) $params['supplier_id'] : 0;
$supplierName = trim($params['supplier_name'] ?? '');
$startDate = trim($params['start_date'] ?? '');
$endDate = trim($params['end_date'] ?? '');

$hasAnyFilter = $costCodeId > 0
    || $costCode !== ''
    || $costCodeDescription !== ''
    || $supplierId > 0
    || $supplierName !== ''
    || $startDate !== ''
    || $endDate !== '';

// Avoid heavy queries when no filters are supplied.
if (!$hasAnyFilter) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $pdo = get_db_connection();

    $latestPoSubquery = 'SELECT po_number, MAX(id) AS latest_id FROM purchase_orders GROUP BY po_number';

    $sql = 'SELECT
                po.id,
                po.po_number,
                po.supplier_name,
                po.order_date,
                po.cost_code,
                po.cost_code_description,
                po.cost_code_id,
                po.total_amount,
                po.reference AS description
            FROM purchase_orders po
            INNER JOIN (' . $latestPoSubquery . ') latest
                ON latest.po_number = po.po_number AND latest.latest_id = po.id
            WHERE 1=1';

    $bindings = [];

    if ($costCodeId > 0) {
        $sql .= ' AND po.cost_code_id = :cost_code_id';
        $bindings[':cost_code_id'] = $costCodeId;
    } elseif ($costCode !== '') {
        $sql .= ' AND po.cost_code LIKE :cost_code';
        $bindings[':cost_code'] = '%' . $costCode . '%';
    } elseif ($costCodeDescription !== '') {
        $sql .= ' AND po.cost_code_description LIKE :cost_code_description';
        $bindings[':cost_code_description'] = '%' . $costCodeDescription . '%';
    }

    if ($supplierId > 0) {
        $sql .= ' AND po.supplier_id = :supplier_id';
        $bindings[':supplier_id'] = $supplierId;
    } elseif ($supplierName !== '') {
        $sql .= ' AND po.supplier_name LIKE :supplier_name';
        $bindings[':supplier_name'] = '%' . $supplierName . '%';
    }

    if ($startDate !== '') {
        $sql .= ' AND po.order_date >= :start_date';
        $bindings[':start_date'] = $startDate;
    }

    if ($endDate !== '') {
        $sql .= ' AND po.order_date <= :end_date';
        $bindings[':end_date'] = $endDate;
    }

    $sql .= ' ORDER BY po.order_date DESC, po.id DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindings);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => count($rows),
        'data' => $rows,
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load cost code enquiries',
    ]);
}
