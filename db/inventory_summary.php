<?php
// db/inventory_summary.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$sql = "SELECT 
            COUNT(*) as totalItems,
            SUM(CASE WHEN i.currentStock = 0 THEN 1 ELSE 0 END) as outOfStockItems,
            SUM(CASE WHEN i.currentStock > 0 AND i.currentStock <= i.minStock THEN 1 ELSE 0 END) as lowStockItems,
            SUM(CASE WHEN i.currentStock > i.minStock THEN 1 ELSE 0 END) as inStockItems,
            SUM(i.currentStock) as totalStockValue
        FROM inventory i
        JOIN products p ON i.productID = p.productID
        WHERE p.isActive = 1" . ($branchId !== null ? " AND (i.branch_id IS NULL OR i.branch_id = ?)" : "") . "
";

$stmt = $conn->prepare($sql);
if ($branchId !== null) {
    $stmt->bind_param('i', $branchId);
}
$stmt->execute();
$result = $stmt->get_result();
$summary = $result->fetch_assoc();
$stmt->close();

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
?>
