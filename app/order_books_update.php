<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Only authenticated users may update order book metadata.
require_authentication();

header('Content-Type: application/json');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ]);
}

$orderBookId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$description = trim((string) ($_POST['description'] ?? ''));
$description2 = trim((string) ($_POST['description_2'] ?? ''));
$isVisibleRaw = $_POST['is_visible'] ?? '0';
$isVisible = $isVisibleRaw === '1' ? 1 : 0;
$qtyRaw = $_POST['qty'] ?? '';
$qty = $qtyRaw === '' ? null : filter_var($qtyRaw, FILTER_VALIDATE_FLOAT);

if (!$orderBookId) {
    respond(400, [
        'success' => false,
        'message' => 'A valid order book ID is required.',
    ]);
}

if ($description === '') {
    respond(400, [
        'success' => false,
        'message' => 'Description cannot be empty.',
    ]);
}

if ($qty === false) {
    respond(400, [
        'success' => false,
        'message' => 'Quantity must be a valid number.',
    ]);
}

if (!is_null($qty) && $qty < 0) {
    respond(400, [
        'success' => false,
        'message' => 'Quantity cannot be negative.',
    ]);
}

try {
    $pdo = get_db_connection();

    // Ensure the record exists before attempting to update it.
    $existsStmt = $pdo->prepare('SELECT id FROM order_books WHERE id = :id LIMIT 1');
    $existsStmt->execute([':id' => $orderBookId]);

    if (!$existsStmt->fetch()) {
        respond(404, [
            'success' => false,
            'message' => 'Order book not found.',
        ]);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE order_books
         SET description = :description,
             description_2 = :description2,
             qty = :qty,
             is_visible = :is_visible,
             updated_at = NOW()
         WHERE id = :id'
    );

    $updateStmt->execute([
        ':description' => $description,
        ':description2' => $description2,
        ':qty' => $qty,
        ':is_visible' => $isVisible,
        ':id' => $orderBookId,
    ]);

    $selectStmt = $pdo->prepare(
        'SELECT id, book_code, description, description_2, qty, is_visible, created_at, updated_at
         FROM order_books
         WHERE id = :id
         LIMIT 1'
    );
    $selectStmt->execute([':id' => $orderBookId]);
    $book = $selectStmt->fetch();

    if (!$book) {
        respond(404, [
            'success' => false,
            'message' => 'Order book not found after update.',
        ]);
    }

    respond(200, [
        'success' => true,
        'message' => 'Order book updated successfully.',
        'book' => $book,
    ]);
} catch (Throwable $exception) {
    respond(500, [
        'success' => false,
        'message' => 'Unexpected error while updating the order book. Please try again.',
    ]);
}
