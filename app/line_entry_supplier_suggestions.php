<?php
// Return supplier suggestions for the line enquiry view based on the provided description text.
// This keeps the supplier filter aligned with the description a user is investigating.

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Only GET is supported']);
    exit;
}

$description = trim($_GET['description'] ?? '');
$orderBook = trim($_GET['order_book'] ?? '');
$poNumber = trim($_GET['po_number'] ?? '');
$loadAllSuppliers = isset($_GET['all_suppliers']) && $_GET['all_suppliers'] === '1';

$hasMeaningfulDescription = mb_strlen($description) >= 2;
$hasFilters = $hasMeaningfulDescription || $orderBook !== '' || $poNumber !== '';

try {
    $pdo = get_db_connection();

    // When no filters are active, return the full supplier list to help pre-populate the field.
    if ($loadAllSuppliers && !$hasFilters) {
        $allSuppliersSql = '
            SELECT supplier_name
            FROM suppliers
            WHERE supplier_name IS NOT NULL
              AND supplier_name != ""
            ORDER BY supplier_name ASC
        ';

        $allSuppliersStmt = $pdo->query($allSuppliersSql);

        $allSuggestions = [];

        while ($row = $allSuppliersStmt->fetch(PDO::FETCH_ASSOC)) {
            $allSuggestions[] = $row['supplier_name'];
        }

        echo json_encode(['suggestions' => $allSuggestions]);
        exit;
    }

    // Avoid expensive scans unless the user has provided a meaningful description fragment.
    if (!$hasMeaningfulDescription) {
        echo json_encode(['suggestions' => []]);
        exit;
    }

    $conditions = ['pol.description LIKE :description'];
    $params = [
        ':description' => '%' . $description . '%',
    ];

    if ($orderBook !== '') {
        $conditions[] = 'po.order_book = :orderBook';
        $params[':orderBook'] = $orderBook;
    }

    if ($poNumber !== '') {
        $conditions[] = 'po.po_number LIKE :poNumber';
        $params[':poNumber'] = '%' . $poNumber . '%';
    }

    $latestPerPoSubquery = 'SELECT po_number, MAX(id) AS latest_id FROM purchase_orders GROUP BY po_number';

    $sql = '
        SELECT DISTINCT po.supplier_name
        FROM purchase_order_lines pol
        INNER JOIN purchase_orders po ON po.id = pol.purchase_order_id
        INNER JOIN (' . $latestPerPoSubquery . ') latest ON latest.po_number = po.po_number AND latest.latest_id = po.id
        WHERE ' . implode(' AND ', $conditions) . '
          AND po.supplier_name IS NOT NULL
          AND po.supplier_name != ""
        ORDER BY po.supplier_name ASC
        LIMIT 25
    ';

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->execute();

    $suggestions = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $suggestions[] = $row['supplier_name'];
    }

    echo json_encode(['suggestions' => $suggestions]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load supplier suggestions']);
}
