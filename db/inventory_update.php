<?php
// db/inventory_update.php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';

$inventoryID = $_POST['inventoryID'] ?? 0;
$action = $_POST['action'] ?? '';
if (!validate_int($inventoryID,1)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid inventory ID']);
    exit;
}
$inventoryID = (int)$inventoryID;

if (!$inventoryID) {
    echo json_encode(['status' => 'error', 'message' => 'Missing inventory ID']);
    exit;
}

$conn->begin_transaction();

try {
    // Get current inventory data
    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    $getStmt = $conn->prepare("SELECT * FROM inventory WHERE inventoryID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : ""));
    if ($branchId !== null) {
        $getStmt->bind_param('ii', $inventoryID, $branchId);
    } else {
        $getStmt->bind_param('i', $inventoryID);
    }
    $getStmt->execute();
    $currentData = $getStmt->get_result()->fetch_assoc();

    if (!$currentData) {
        throw new Exception('Inventory entry not found');
    }

$updateFields = [];
$updateValues = [];
$types = '';

    switch ($action) {
        case 'update_stock':
            $newStock = floatval($_POST['Current Stock'] ?? 0);
            if ($newStock < 0) {
                throw new Exception('Stock cannot be negative');
            }

            $updateFields[] = '`Current_Stock` = ?';
            $updateValues[] = $newStock;
            $types .= 'd';

            $recomputedTotal = round($newStock * (float)$currentData['Cost_Price'], 2);
            $updateFields[] = '`Total_Value` = ?';
            $updateValues[] = $recomputedTotal;
            $types .= 'd';

            // No logging needed
            break;

        case 'update_cost_price':
            $newCostPrice = floatval($_POST['Cost Price'] ?? 0);
            if ($newCostPrice < 0) {
                throw new Exception('Cost price cannot be negative');
            }

            $updateFields[] = '`Cost_Price` = ?';
            $updateValues[] = $newCostPrice;
            $types .= 'd';

            // Recalculate total value
            $totalValue = round($newCostPrice * (float)$currentData['Current_Stock'], 2);

            $updateFields[] = '`Total_Value` = ?';
            $updateValues[] = $totalValue;
            $types .= 'd';
            break;

        case 'update_total_value':
            $newTotalValue = floatval($_POST['Total Value'] ?? 0);
            if ($newTotalValue < 0) {
                throw new Exception('Total value cannot be negative');
            }

            $updateFields[] = '`Total_Value` = ?';
            $updateValues[] = $newTotalValue;
            $types .= 'd';
            break;

        case 'update_status':
            $newStatus = trim($_POST['Status'] ?? '');
            if (!$newStatus) {
                throw new Exception('Status cannot be empty');
            }

            $updateFields[] = '`Status` = ?';
            $updateValues[] = $newStatus;
            $types .= 's';
            break;

        case 'update_inventory':
            // Update all fields for full inventory item update
            $inventoryName = trim($_POST['InventoryName'] ?? '');
            $sizeRaw = $_POST['Size'] ?? '';
            $unit = trim($_POST['Unit'] ?? '');
            $currentStock = floatval($_POST['Current_Stock'] ?? 0);
            $costPrice = floatval($_POST['Cost_Price'] ?? 0);
            $reorderPoint = floatval($_POST['reorder_point'] ?? 0);
            $qtyPerOrder = trim($_POST['qty_per_order'] ?? '');
            $reorderPoint = max(0, $reorderPoint);
            $totalValue = round($currentStock * $costPrice, 2);

            // Validate required fields
            if (!$inventoryName || !$unit) {
                throw new Exception('All required fields must be filled');
            }

            // Size is optional - fall back to previous value or default
            if ($sizeRaw === '' || $sizeRaw === null) {
                $size = isset($currentData['Size']) ? (string)$currentData['Size'] : '1';
            } else {
                if (!validate_float($sizeRaw)) {
                    throw new Exception('Size must be a numeric value');
                }
                $size = (string)floatval($sizeRaw);
            }

            if ($currentStock < 0 || $costPrice < 0 || $totalValue < 0) {
                throw new Exception('Numeric values cannot be negative');
            }

            $updateFields[] = '`InventoryName` = ?';
            $updateValues[] = $inventoryName;
            $types .= 's';

            $updateFields[] = '`Size` = ?';
            $updateValues[] = $size;
            $types .= 's';

            $updateFields[] = '`Unit` = ?';
            $updateValues[] = $unit;
            $types .= 's';

            $updateFields[] = '`Current_Stock` = ?';
            $updateValues[] = $currentStock;
            $types .= 'd';

            $updateFields[] = '`Cost_Price` = ?';
            $updateValues[] = $costPrice;
            $types .= 'd';

            $updateFields[] = '`Total_Value` = ?';
            $updateValues[] = $totalValue;
            $types .= 'd';

            $updateFields[] = '`reorder_point` = ?';
            $updateValues[] = $reorderPoint;
            $types .= 'd';

            // Add qty_per_order to update
            $qtyValue = $qtyPerOrder !== '' ? $qtyPerOrder : null;
            
            $updateFields[] = '`qty_per_order` = ?';
            $updateValues[] = $qtyValue;
            $types .= 's';
            
            break;

        default:
            throw new Exception('Invalid action');
    }
    
    if (empty($updateFields)) {
        throw new Exception('No fields to update');
    }
    
    // Add inventoryID to the values array
    $updateValues[] = $inventoryID;
    $types .= 'i';
    
    // Build and execute update query
    $updateSql = "UPDATE inventory SET " . implode(', ', $updateFields) . " WHERE inventoryID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : "");
    $updateStmt = $conn->prepare($updateSql);
    if ($branchId !== null) {
        $updateValues[] = $branchId;
        $types .= 'i';
    }
    $updateStmt->bind_param($types, ...$updateValues);
    
    if (!$updateStmt->execute()) {
        throw new Exception($updateStmt->error);
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Inventory updated successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
