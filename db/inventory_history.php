<?php
// db/inventory_history.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
$inventoryID = intval($_GET['inventoryID'] ?? 0);

if (!$inventoryID) {
    echo json_encode(['status' => 'error', 'message' => 'Missing inventory ID']);
    exit;
}

$sql = "SELECT 
            ih.historyID,
            ih.changeType,
            ih.previousStock,
            ih.newStock,
            ih.changeAmount,
            ih.reason,
            ih.created_at,
            p.productName,
            s.sizeName
        FROM inventory_history ih
        JOIN inventory i ON ih.inventoryID = i.inventoryID
        JOIN products p ON i.productID = p.productID
        JOIN sizes s ON i.sizeID = s.sizeID
        WHERE ih.inventoryID = ?" . ($branchId !== null ? " AND (i.branch_id IS NULL OR i.branch_id = ?)" : "") . "
        ORDER BY ih.created_at DESC
        LIMIT 50";

$stmt = $conn->prepare($sql);
if ($branchId !== null) {
    $stmt->bind_param('ii', $inventoryID, $branchId);
} else {
    $stmt->bind_param('i', $inventoryID);
}
$stmt->execute();
$result = $stmt->get_result();

$history = [];
while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}

echo json_encode($history, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
?>
