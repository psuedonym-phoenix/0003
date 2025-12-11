<?php
// api_enquiry_cost_codes.php
header('Content-Type: application/json');

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/db.php';

// This endpoint is driven by the internal UI (Enquiry page).
// Ideally we should check session auth here if it's strictly internal, 
// but the architecture seems to mix root APIs (key-based) and app scripts.
// We'll rely on the standard DB connection.

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get params (support both GET and POST for flexibility)
$p = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

// If a JSON body was sent (common in some fetch setups), try valid json
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($p)) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input)) {
        $p = $input;
    }
}

$costCodeId = isset($p['cost_code_id']) ? (int) $p['cost_code_id'] : 0;
$supplierId = isset($p['supplier_id']) ? (int) $p['supplier_id'] : 0;
$supplierName = isset($p['supplier_name']) ? trim($p['supplier_name']) : '';
$startDate = isset($p['start_date']) ? trim($p['start_date']) : '';
$endDate = isset($p['end_date']) ? trim($p['end_date']) : '';

// Validation: At least one filter should probably be active? 
// Or default to recent if nothing? Let's default to nothing if no filters to avoid slamming DB.
if ($costCodeId === 0 && empty($supplierName) && $supplierId === 0 && empty($startDate) && empty($endDate)) {
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

try {
    $pdo = get_db_connection();

    // Base Query
    // We want details from purchase_orders.
    // Note: purchase_orders has `cost_code_id`.
    $sql = "SELECT 
                id,
                po_number,
                supplier_name,
                order_date,
                cost_code,
                cost_code_description,
                reference AS description, -- Wait, usually description is on lines, but PO has `cost_code_description`. 
                             -- Let's check if there's a main description or if we just show the header info.
                             -- The PO table has `reference` or `misc1_label`. 
                             -- Actually PO table has `cost_code` and `cost_code_description` stored as cache/snapshot?
                             -- DESCRIBE said: cost_code, cost_code_description, cost_code_id.
                total_amount,
                status -- Assuming there might be a status, or just use what we have.
            FROM purchase_orders
            WHERE 1=1
    ";

    $params = [];

    // Filter: Cost Code (ID or Description)
    if ($costCodeId > 0) {
        $sql .= " AND cost_code_id = :cc_id";
        $params[':cc_id'] = $costCodeId;
    } elseif (!empty($p['cost_code_description'])) {
        // Fallback to description match if no ID (fuzzy match or exact?) 
        // User asked for "fuzzy logic" in UI, but for the search, if they selected a description, we should filter by it.
        // Let's use exact match if it came from a selection, or LIKE if typed? 
        // Safety: LIKE is better for "fuzzy".
        $sql .= " AND cost_code_description LIKE :cc_desc";
        $params[':cc_desc'] = '%' . trim($p['cost_code_description']) . '%';
    }

    // Filter: Supplier (ID or Name match)
    if ($supplierId > 0) {
        $sql .= " AND supplier_id = :sup_id";
        $params[':sup_id'] = $supplierId;
    } elseif (!empty($supplierName)) {
        $sql .= " AND supplier_name LIKE :sup_name";
        $params[':sup_name'] = '%' . $supplierName . '%';
    }

    // Filter: Date Range
    if (!empty($startDate)) {
        $sql .= " AND order_date >= :start_date";
        $params[':start_date'] = $startDate;
    }
    if (!empty($endDate)) {
        $sql .= " AND order_date <= :end_date";
        $params[':end_date'] = $endDate;
    }

    $sql .= " ORDER BY order_date DESC, id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'count' => count($rows),
        'data' => $rows
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
