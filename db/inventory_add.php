<?php
// db/inventory_add.php
// Suppress error output to prevent HTML in JSON response
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

header('Content-Type: application/json');

// Centralized session/auth and validation
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';

try {
    require_once __DIR__ . '/db_connect.php';
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Failed to load database connection: ' . $e->getMessage()]);
    exit;
}

$inventoryName = sanitize_text($_POST['InventoryName'] ?? '', 150);
$sizeRaw = $_POST['Size'] ?? '';
$unit = sanitize_text($_POST['Unit'] ?? '', 50);

if ($sizeRaw === '' || $sizeRaw === null) {
    $size = '1';
} else {
    if (!validate_float($sizeRaw)) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Size must be a numeric value']);
        exit;
    }
    $size = (string)floatval($sizeRaw);
}
$currentStock = $_POST['Current_Stock'] ?? 0;
$costPrice = $_POST['Cost_Price'] ?? 0;
$reorderPoint = $_POST['reorder_point'] ?? 0;
$qtyPerOrder = trim($_POST['qty_per_order'] ?? '');
$status = trim($_POST['Status'] ?? 'In_Stock');

// Validate input
if ($inventoryName === '' || $unit === '') {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

if (!validate_float($currentStock) || $currentStock < 0) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Stock values must be numeric and non-negative']);
    exit;
}
$currentStock = (float)$currentStock;

if (!validate_float($costPrice) || $costPrice < 0) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Prices must be numeric and non-negative']);
    exit;
}
$costPrice = (float)$costPrice;

if (!validate_float($reorderPoint) || $reorderPoint < 0) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Reorder point must be numeric and non-negative']);
    exit;
}
$reorderPoint = (float)$reorderPoint;

// qty per order optional
if ($qtyPerOrder !== '') {
    if (!validate_int($qtyPerOrder, 0)) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'qty_per_order must be a non-negative integer']);
        exit;
    }
    $qtyPerOrder = (int)$qtyPerOrder;
} else {
    $qtyPerOrder = null;
}

// Normalize status
$allowedStatus = ['In_Stock', 'Out_of_Stock', 'Low_Stock'];
if (!in_array($status, $allowedStatus, true)) $status = 'In_Stock';

$totalValue = round($currentStock * $costPrice, 2);

$conn->begin_transaction();

try {
    // Check if inventory entry already exists
    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    $checkSql = "SELECT inventoryID FROM inventory WHERE `InventoryName` = ? AND `Size` = ? AND `Unit` = ?";
    if ($branchId !== null) {
        $checkSql .= " AND (branch_id IS NULL OR branch_id = ?)";
    }
    $checkStmt = $conn->prepare($checkSql);
    if ($branchId !== null) {
        $checkStmt->bind_param('sssi', $inventoryName, $size, $unit, $branchId);
    } else {
        $checkStmt->bind_param('sss', $inventoryName, $size, $unit);
    }
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();

    if ($existing) {
        ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'Inventory entry already exists for this item']);
        exit;
    }

    // Insert new inventory entry
    $stmt = $conn->prepare("INSERT INTO inventory (`InventoryName`, `Size`, `Unit`, `Current_Stock`, `Cost_Price`, `Total_Value`, `reorder_point`, `qty_per_order`, `Status`, `branch_id`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Prepare value for qty_per_order
    $qtyValue = $qtyPerOrder !== '' ? $qtyPerOrder : null;
    
    $stmt->bind_param(
        'sssddddisi',
        $inventoryName,
        $size,
        $unit,
        $currentStock,
        $costPrice,
        $totalValue,
        $reorderPoint,
        $qtyValue,
        $status,
        $branchId
    );

    if (!$stmt->execute()) {
        throw new Exception($stmt->error);
    }

    $inventoryID = $conn->insert_id;

    $conn->commit();
    
    // Clear any unexpected output before sending JSON
    ob_clean();
    echo json_encode(['status' => 'success', 'message' => 'Inventory entry added successfully', 'inventoryID' => $inventoryID]);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
} catch (Error $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'PHP Error: ' . $e->getMessage()]);
    exit;
}

?>
