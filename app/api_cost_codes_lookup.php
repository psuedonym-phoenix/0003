<?php
// api_cost_codes_lookup.php
// Provides cost code suggestions for the dashboard UI.

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only GET is supported']);
    exit;
}

$term = trim($_GET['term'] ?? '');

try {
    $pdo = get_db_connection();

    $sql = 'SELECT id, cost_code, description, accounting_allocation
            FROM cost_codes';

    $params = [];
    if ($term !== '') {
        $sql .= ' WHERE cost_code LIKE :term OR description LIKE :term_description';
        $likeTerm = '%' . $term . '%';
        $params[':term'] = $likeTerm;
        $params[':term_description'] = $likeTerm;
    }

    $sql .= ' ORDER BY cost_code ASC LIMIT 50';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to load cost codes',
    ]);
}
