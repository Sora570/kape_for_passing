<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../lib/RecipeHelper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=inventory_' . date('Y-m-d') . '.csv');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Inventory Name',
    'Size',
    'Unit',
    'Current Stock',
    'Cost Price',
    'Qty Per Order',
    'Cost Per Order',
    'Final Cost',
    'Total Value',
    'Status'
]);

$rows = [];
$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$sql = "SELECT
            inventoryID,
            `InventoryName` AS inventory_name,
            `Size` AS size_value,
            `Unit` AS unit,
            `Current_Stock` AS current_stock,
            `Cost_Price` AS cost_price,
            `Total_Value` AS total_value,
            `qty_per_order`,
            `Status` AS status
        FROM inventory";
if ($branchId !== null) {
    $sql .= " WHERE (branch_id IS NULL OR branch_id = ?)";
}
$sql .= " ORDER BY `InventoryName`";

$stmt = $conn->prepare($sql);
if ($branchId !== null) {
    $stmt->bind_param('i', $branchId);
}
$stmt->execute();
if ($result = $stmt->get_result()) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();
}
$stmt->close();

$recipeHelper = new RecipeHelper($conn, $branchId);
$costMap = $recipeHelper->calculateCostPerOrderMap($rows);
$usageMap = $recipeHelper->getAverageUsageMap();

foreach ($rows as $row) {
    $inventoryID = (int)($row['inventoryID'] ?? 0);
    
    // Always use calculated values
    $perOrderCost = $costMap[$inventoryID] ?? 0;
    $qtyPerOrder = $usageMap[$inventoryID] ?? '';
    
    // Override qty_per_order from inventory table if it exists
    if (!empty($row['qty_per_order'])) {
        $qtyPerOrder = $row['qty_per_order'];
    }
    
    // Cost per order is always calculated, never from database
    $finalCost = round((float)$perOrderCost, 2);

    fputcsv($output, [
        $row['inventory_name'] ?? '',
        $row['size_value'] ?? '',
        $row['unit'] ?? '',
        number_format((float)($row['current_stock'] ?? 0), 4, '.', ''),
        number_format((float)($row['cost_price'] ?? 0), 2, '.', ''),
        $qtyPerOrder,
        number_format((float)$perOrderCost, 2, '.', ''),
        number_format($finalCost, 2, '.', ''),
        number_format((float)($row['total_value'] ?? 0), 2, '.', ''),
        $row['status'] ?? ''
    ]);
}

fclose($output);
exit;

