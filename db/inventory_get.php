<?php
// db/inventory_get.php
// Start output buffering to catch any errors
ob_start();

// Suppress error output to prevent breaking JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json; charset=utf-8');

// Set error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => 'Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
        ]);
        exit;
    }
});

try {
    // Include db_connect.php - capture any output it might produce
    ob_start();
    require_once __DIR__ . '/db_connect.php';
    $dbConnectOutput = ob_get_clean();
    
    // Check if connection was established
    if (!isset($conn) || !$conn) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed: Connection variable not set']);
        exit;
    }
    
    if ($conn->connect_error) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Database connection error: ' . $conn->connect_error]);
        exit;
    }
    
    // Include RecipeHelper
    if (!file_exists(__DIR__ . '/../lib/RecipeHelper.php')) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'RecipeHelper file not found']);
        exit;
    }
    require_once __DIR__ . '/../lib/RecipeHelper.php';
    require_once __DIR__ . '/session_config.php';
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Failed to load required files: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'PHP Error: ' . $e->getMessage()]);
    exit;
}

try {
    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    // Super admin (null branch_id) can filter via ?branch_filter=
    if ($branchId === null && isset($_GET['branch_filter']) && $_GET['branch_filter'] !== '') {
        $bf = (int) $_GET['branch_filter'];
        if ($bf > 0) $branchId = $bf;
    }

    $sql = "SELECT
                i.inventoryID,
                i.`InventoryName`,
                i.`Size`,
                i.`Unit`,
                i.`Current_Stock` AS `Current Stock`,
                i.`Cost_Price` AS `Cost Price`,
                i.`Total_Value` AS `Total Value`,
                i.`reorder_point` AS `Reorder Point`,
                i.`qty_per_order`,
                i.`Status`
            FROM inventory i
            " . ($branchId !== null ? "WHERE (i.branch_id IS NULL OR i.branch_id = ?)" : "") . "
            ORDER BY i.`InventoryName`";

    if ($branchId !== null) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database query failed: ' . $conn->error);
        }
        $stmt->bind_param('i', $branchId);
        $stmt->execute();
        $res = $stmt->get_result();
    } else {
        $res = $conn->query($sql);
    }
    $out = [];
    
    if (!$res) {
        throw new Exception('Database query failed: ' . $conn->error);
    }

    while ($row = $res->fetch_assoc()) {
        $currentStock = (float)($row['Current Stock'] ?? 0);
        $costPrice = (float)($row['Cost Price'] ?? 0);
        $row['Total Value'] = round($currentStock * $costPrice, 2);
        $out[] = $row;
    }
    $res->free();

    $recipeHelper = new RecipeHelper();
    $costMap = $recipeHelper->calculateCostPerOrderMap($out);
    $usageMap = $recipeHelper->getAverageUsageMap();
    $usageProducts = $recipeHelper->getIngredientProductMap($conn);

    foreach ($out as &$row) {
        $inventoryID = (int)($row['inventoryID'] ?? 0);
        
        // Always use calculated cost per order (never from database)
        $perOrderCost = isset($costMap[$inventoryID]) ? round($costMap[$inventoryID], 2) : 0;
        $qtyPerOrder = $usageMap[$inventoryID] ?? '';
        
        // Override qty_per_order from inventory table if it exists
        if (!empty($row['qty_per_order'])) {
            $qtyPerOrder = $row['qty_per_order'];
        }
        
        // Cost per order is always calculated, never from database
        $row['Cost Per Order'] = $perOrderCost;
        $row['Qty Per Order'] = $qtyPerOrder;
        $row['Usage per Product'] = $qtyPerOrder;

        // Default for cups if still empty
        if (empty($row['Qty Per Order']) && stripos($row['InventoryName'] ?? '', 'cup') !== false) {
            $row['Qty Per Order'] = '1 pc';
            $row['Usage per Product'] = '1 pc';
        }
        $row['Used In'] = $usageProducts[$inventoryID] ?? '';
        $effectivePerOrderCost = (float)($row['Cost Per Order'] ?? 0);
        $row['Final Cost'] = round($effectivePerOrderCost, 2);
    }
    unset($row);
    
    // Clear any output buffer and send JSON
    ob_end_clean();
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Error loading inventory: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'PHP Error: ' . $e->getMessage()]);
    exit;
}
?>
