<?php
// Get total production cost for a product (base 16oz size)
// This is used by the costing table to get the actual production cost
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../lib/RecipeHelper.php';

$productID = intval($_GET['productID'] ?? 0);

if (!$productID) {
    echo json_encode(['status' => 'error', 'message' => 'ProductID is required', 'totalCost' => 0]);
    exit;
}

try {
    // Load recipes from database
    $recipes = [];
    $recipeResult = $conn->query("
        SELECT productID, inventoryID, amount, unit, display_order 
        FROM recipes 
        ORDER BY productID, display_order
    ");
    if ($recipeResult) {
        while ($row = $recipeResult->fetch_assoc()) {
            $productID = (int)$row['productID'];
            if (!isset($recipes[$productID])) {
                $recipes[$productID] = [];
            }
            $recipes[$productID][] = [
                'inventoryID' => (int)$row['inventoryID'],
                'amount' => (float)$row['amount'],
                'unit' => $row['unit']
            ];
        }
        $recipeResult->free();
    }
    
    if (!isset($recipes[$productID])) {
        echo json_encode(['status' => 'success', 'totalCost' => 0]);
        exit;
    }
    
    // Get inventory data
    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
$inventoryStmt = $conn->prepare("SELECT inventoryID, InventoryName, Size, Unit, Cost_Price FROM inventory" . ($branchId !== null ? " WHERE (branch_id IS NULL OR branch_id = ?)" : ""));
if ($branchId !== null) {
    $inventoryStmt->bind_param('i', $branchId);
}
$inventoryStmt->execute();
$inventoryResult = $inventoryStmt->get_result();
$inventoryMap = [];
if ($inventoryResult) {
    while ($row = $inventoryResult->fetch_assoc()) {
        $inventoryMap[(int)$row['inventoryID']] = $row;
    }
    $inventoryResult->free();
}
$inventoryStmt->close();
        
    // Load production cost overrides from database
    require_once __DIR__ . '/inventory_usage_helpers.php';
    $overrides = get_production_cost_overrides_for_product($conn, $productID);
    
    $totalCost = 0.0;
    
    // Process base recipe ingredients
    foreach ($recipes[$productID] as $ingredient) {
        $inventoryID = (int)($ingredient['inventoryID'] ?? 0);
        $amount = (float)($ingredient['amount'] ?? 0);
        
        if (!$inventoryID || !isset($inventoryMap[$inventoryID])) {
            continue;
        }
        
        // Check if removed
        $isRemoved = false;
        foreach ($overrides as $ov) {
            if ((int)($ov['inventoryID'] ?? 0) === $inventoryID && isset($ov['removed']) && $ov['removed']) {
                $isRemoved = true;
                break;
            }
        }
        if ($isRemoved) {
            continue;
        }
        
        $inv = $inventoryMap[$inventoryID];
        $invName = $inv['InventoryName'] ?? '';
        $isWildcard = stripos($invName, 'ice') !== false;
        
        // Find override
        $override = null;
        foreach ($overrides as $ov) {
            if ((int)($ov['inventoryID'] ?? 0) === $inventoryID && !isset($ov['removed'])) {
                $override = $ov;
                break;
            }
        }
        
        if ($isWildcard) {
            // Wildcard (Ice) - use fixed cost
            $ingredientCost = $override ? (float)($override['ingredientCost'] ?? 0) : 2.00;
            $totalCost += $ingredientCost;
        } else if ($override && isset($override['ingredientCost'])) {
            // Has fixed override cost
            $totalCost += (float)($override['ingredientCost'] ?? 0);
        } else {
            // Calculate from inventory
            $packSize = $inv['Size'] ?? '';
            $packPrice = (float)($inv['Cost_Price'] ?? 0);
            $sizeValue = floatval(preg_replace('/[^0-9.]/', '', $packSize)) ?: 1;
            $pricePerUnit = $sizeValue > 0 ? ($packPrice / $sizeValue) : 0;
            $neededPerCup = $override ? (float)($override['neededPerCup'] ?? $amount) : $amount;
            $ingredientCost = $pricePerUnit * $neededPerCup;
            $totalCost += $ingredientCost;
        }
    }
    
    // Add manually added ingredients (only in overrides)
    foreach ($overrides as $ov) {
        if (isset($ov['removed']) && $ov['removed']) {
            continue;
        }
        
        $inventoryID = (int)($ov['inventoryID'] ?? 0);
        if (!$inventoryID || !isset($inventoryMap[$inventoryID])) {
            continue;
        }
        
        // Check if already processed in base recipe
        $found = false;
        foreach ($recipes[$productID] as $ingredient) {
            if ((int)($ingredient['inventoryID'] ?? 0) === $inventoryID) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $inv = $inventoryMap[$inventoryID];
            $invName = $inv['InventoryName'] ?? '';
            $isWildcard = stripos($invName, 'ice') !== false;
            
            if ($isWildcard || isset($ov['ingredientCost'])) {
                $ingredientCost = (float)($ov['ingredientCost'] ?? ($isWildcard ? 2.00 : 0));
                $totalCost += $ingredientCost;
            } else {
                $packSize = $inv['Size'] ?? '';
                $packPrice = (float)($inv['Cost_Price'] ?? 0);
                $sizeValue = floatval(preg_replace('/[^0-9.]/', '', $packSize)) ?: 1;
                $pricePerUnit = $sizeValue > 0 ? ($packPrice / $sizeValue) : 0;
                $neededPerCup = (float)($ov['neededPerCup'] ?? 0);
                $ingredientCost = $pricePerUnit * $neededPerCup;
                $totalCost += $ingredientCost;
            }
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'totalCost' => round($totalCost, 2)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'totalCost' => 0]);
}

