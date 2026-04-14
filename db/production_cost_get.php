<?php
// Start output buffering to catch any errors
ob_start();

// Suppress error output to prevent breaking JSON response
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

if (!file_exists(__DIR__ . '/db_connect.php')) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database configuration file not found']);
    exit;
}

$dbConnectOutput = '';
ob_start();
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';
$dbConnectOutput = ob_get_clean();

if (!isset($conn) || !$conn) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . (!empty($dbConnectOutput) ? $dbConnectOutput : 'Connection variable not set')]);
    exit;
}

if ($conn->connect_error) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database connection error: ' . $conn->connect_error]);
    exit;
}

$productID = intval($_GET['productID'] ?? 0);
$variantID = intval($_GET['variantID'] ?? 0);
if ($variantID <= 0) {
    $variantID = null;
}

// #region agent log
@file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_get.php', 'message' => 'entry', 'data' => ['productID' => $productID, 'variantID' => $variantID], 'timestamp' => time() * 1000, 'hypothesisId' => 'B']) . "\n", LOCK_EX | FILE_APPEND);
// #endregion

if (!$productID) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'ProductID is required']);
    exit;
}

try {
    ob_clean();

    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
    $productStmt = $conn->prepare("SELECT productName, has_variants FROM products WHERE productID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : ""));
    if ($branchId !== null) {
        $productStmt->bind_param('ii', $productID, $branchId);
    } else {
        $productStmt->bind_param('i', $productID);
    }
    $productStmt->execute();
    $productResult = $productStmt->get_result();
    $product = $productResult->fetch_assoc();
    $productStmt->close();

    if (!$product) {
        echo json_encode(['status' => 'error', 'message' => 'Product not found']);
        exit;
    }

    $productName = $product['productName'] ?: "Product #$productID";
    $hasVariants = (int)($product['has_variants'] ?? 0) === 1;

    if ($hasVariants && $variantID === null) {
        echo json_encode(['status' => 'error', 'message' => 'VariantID is required for this product']);
        exit;
    }

    $variantLabel = '';
    if ($variantID !== null) {
        $variantStmt = $conn->prepare("SELECT variant_name, size_label FROM product_variants WHERE variant_id = ? AND product_id = ?");
        $variantStmt->bind_param('ii', $variantID, $productID);
        $variantStmt->execute();
        $variantResult = $variantStmt->get_result();
        $variant = $variantResult->fetch_assoc();
        $variantStmt->close();

        if (!$variant) {
            echo json_encode(['status' => 'error', 'message' => 'Variant not found for this product']);
            exit;
        }
        $variantLabel = $variant['variant_name'] ?: ($variant['size_label'] ?? '');
    }

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

    require_once __DIR__ . '/inventory_usage_helpers.php';

    // #region agent log
    try {
        $overrides = get_production_cost_overrides_for_product($conn, $productID, $variantID);
    } catch (Throwable $e) {
        @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_get.php', 'message' => 'get_production_cost_overrides_for_product failed', 'data' => ['error' => $e->getMessage(), 'productID' => $productID, 'variantID' => $variantID], 'timestamp' => time() * 1000, 'hypothesisId' => 'B']) . "\n", LOCK_EX | FILE_APPEND);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Failed to load overrides: ' . $e->getMessage()]);
        exit;
    }

    if ($variantID !== null) {
        $recipeStmt = $conn->prepare("
            SELECT inventoryID, amount, unit, display_order
            FROM recipes
            WHERE productID = ? AND variant_id = ?
            ORDER BY display_order
        ");
        if (!$recipeStmt) {
            $err = $conn->error;
            @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_get.php', 'message' => 'recipes prepare failed', 'data' => ['error' => $err, 'productID' => $productID, 'variantID' => $variantID], 'timestamp' => time() * 1000, 'hypothesisId' => 'B']) . "\n", LOCK_EX | FILE_APPEND);
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $err]);
            exit;
        }
        $recipeStmt->bind_param('ii', $productID, $variantID);
    } else {
        $recipeStmt = $conn->prepare("
            SELECT inventoryID, amount, unit, display_order
            FROM recipes
            WHERE productID = ? AND variant_id IS NULL
            ORDER BY display_order
        ");
        if (!$recipeStmt) {
            $err = $conn->error;
            @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_get.php', 'message' => 'recipes prepare (null variant) failed', 'data' => ['error' => $err], 'timestamp' => time() * 1000, 'hypothesisId' => 'B']) . "\n", LOCK_EX | FILE_APPEND);
            ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $err]);
            exit;
        }
        $recipeStmt->bind_param('i', $productID);
    }

    $recipeStmt->execute();
    if ($recipeStmt->error) {
        @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_get.php', 'message' => 'recipes execute failed', 'data' => ['error' => $recipeStmt->error, 'productID' => $productID, 'variantID' => $variantID], 'timestamp' => time() * 1000, 'hypothesisId' => 'B']) . "\n", LOCK_EX | FILE_APPEND);
        ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $recipeStmt->error]);
        exit;
    }
    $recipeResult = $recipeStmt->get_result();
    $recipes = [];
    if ($recipeResult) {
        while ($row = $recipeResult->fetch_assoc()) {
            $recipes[] = [
                'inventoryID' => (int)($row['inventoryID'] ?? 0),
                'amount' => (float)($row['amount'] ?? 0),
                'unit' => $row['unit']
            ];
        }
        $recipeStmt->close();
    }
    // #endregion

    $result = [];
    $itemID = 1;

    if (!empty($recipes)) {
        foreach ($recipes as $ingredient) {
            $inventoryID = (int)($ingredient['inventoryID'] ?? 0);
            $amount = (float)($ingredient['amount'] ?? 0);
            $unit = $ingredient['unit'] ?? '';

            if (!$inventoryID || !isset($inventoryMap[$inventoryID])) {
                continue;
            }

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
            $override = null;
            foreach ($overrides as $ov) {
                if ((int)($ov['inventoryID'] ?? 0) === $inventoryID && !isset($ov['removed'])) {
                    $override = $ov;
                    break;
                }
            }

            if ($isWildcard) {
                $ingredientCost = $override ? (float)($override['ingredientCost'] ?? 0) : 2.00;
                $result[] = [
                    'productID' => $productID,
                    'itemID' => $itemID++,
                    'inventoryID' => $inventoryID,
                    'InventoryName' => $invName,
                    'packSize' => '',
                    'unit' => '',
                    'packPrice' => 0,
                    'neededPerCup' => '',
                    'pricePerUnit' => 0,
                    'ingredientCost' => $ingredientCost,
                    'isWildcard' => true,
                    'isFromBaseRecipe' => false
                ];
            } else {
                $packSize = $inv['Size'] ?? '';
                $packPrice = (float)($inv['Cost_Price'] ?? 0);
                $sizeValue = floatval(preg_replace('/[^0-9.]/', '', $packSize)) ?: 1;
                $pricePerUnit = $sizeValue > 0 ? ($packPrice / $sizeValue) : 0;
                $neededPerCup = $override ? (float)($override['neededPerCup'] ?? $amount) : $amount;
                $ingredientCost = $pricePerUnit * $neededPerCup;

                $result[] = [
                    'productID' => $productID,
                    'itemID' => $itemID++,
                    'inventoryID' => $inventoryID,
                    'InventoryName' => $invName,
                    'packSize' => $packSize,
                    'unit' => $inv['Unit'] ?? '',
                    'packPrice' => $packPrice,
                    'neededPerCup' => $neededPerCup,
                    'pricePerUnit' => $pricePerUnit,
                    'ingredientCost' => $ingredientCost,
                    'isWildcard' => false,
                    'isFromBaseRecipe' => false
                ];
            }
        }
    }

    foreach ($overrides as $ov) {
        if (isset($ov['removed']) && $ov['removed']) {
            continue;
        }

        $inventoryID = (int)($ov['inventoryID'] ?? 0);
        if (!$inventoryID || !isset($inventoryMap[$inventoryID])) {
            continue;
        }

        $found = false;
        foreach ($result as $r) {
            if ((int)($r['inventoryID'] ?? 0) === $inventoryID) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $inv = $inventoryMap[$inventoryID];
            $invName = $inv['InventoryName'] ?? '';
            $isWildcard = stripos($invName, 'ice') !== false;

            if ($isWildcard) {
                $ingredientCost = (float)($ov['ingredientCost'] ?? 2.00);
                $result[] = [
                    'productID' => $productID,
                    'itemID' => $itemID++,
                    'inventoryID' => $inventoryID,
                    'InventoryName' => $invName,
                    'packSize' => '',
                    'unit' => '',
                    'packPrice' => 0,
                    'neededPerCup' => '',
                    'pricePerUnit' => 0,
                    'ingredientCost' => $ingredientCost,
                    'isWildcard' => true,
                    'isFromBaseRecipe' => false
                ];
            } else {
                $packSize = $inv['Size'] ?? '';
                $packPrice = (float)($inv['Cost_Price'] ?? 0);
                $sizeValue = floatval(preg_replace('/[^0-9.]/', '', $packSize)) ?: 1;
                $pricePerUnit = $sizeValue > 0 ? ($packPrice / $sizeValue) : 0;
                $neededPerCup = (float)($ov['neededPerCup'] ?? 0);
                $ingredientCost = $pricePerUnit * $neededPerCup;

                $result[] = [
                    'productID' => $productID,
                    'itemID' => $itemID++,
                    'inventoryID' => $inventoryID,
                    'InventoryName' => $invName,
                    'packSize' => $packSize,
                    'unit' => $inv['Unit'] ?? '',
                    'packPrice' => $packPrice,
                    'neededPerCup' => $neededPerCup,
                    'pricePerUnit' => $pricePerUnit,
                    'ingredientCost' => $ingredientCost,
                    'isWildcard' => false,
                    'isFromBaseRecipe' => false
                ];
            }
        }
    }

    $totalCost = 0.0;
    foreach ($result as $item) {
        $totalCost += (float)($item['ingredientCost'] ?? 0);
    }

    ob_end_clean();
    echo json_encode([
        'status' => 'success',
        'data' => $result,
        'productName' => $productName,
        'variantLabel' => $variantLabel,
        'totalCost' => round($totalCost, 2)
    ]);

} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Error $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()]);
}
