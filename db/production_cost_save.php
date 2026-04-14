<?php
// Start output buffering to catch any errors
ob_start();

// Suppress error output to prevent breaking JSON response
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';

try {
    require_once __DIR__ . '/db_connect.php';
} catch (Exception $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

// Admin required
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$productID = $_POST['productID'] ?? 0;
$variantID = isset($_POST['variantID']) ? (int)$_POST['variantID'] : null;
if ($variantID !== null && $variantID <= 0) {
    $variantID = null;
}
$action = $_POST['action'] ?? '';

// #region agent log
if ($variantID !== null) {
    @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_save.php', 'message' => 'entry with variantID', 'data' => ['productID' => $productID, 'variantID' => $variantID, 'action' => $action], 'timestamp' => time() * 1000, 'hypothesisId' => 'C']) . "\n", LOCK_EX | FILE_APPEND);
}
// #endregion

if (!validate_int($productID,1) || !is_string($action) || $action === '') {
    echo json_encode(['status' => 'error', 'message' => 'ProductID and action are required']);
    exit;
}
$productID = (int)$productID;

require_once __DIR__ . '/inventory_usage_helpers.php';

/**
 * Normalize unit to match recipe format (ml, g, or pc)
 */
function normalizeUnitForRecipe(string $unit): string {
    $unit = strtolower(trim($unit));
    return match ($unit) {
        'milliliter', 'millilitre', 'ml', 'l', 'liter', 'litre' => 'ml',
        'gram', 'grams', 'g', 'kg', 'kilogram' => 'g',
        'piece', 'pieces', 'pc', 'pcs', 'unit', 'units', 'ounce', 'ounces', 'oz' => 'pc',
        default => $unit ?: 'ml',
    };
}

/**
 * Update or insert recipe in recipes table
 */
function updateRecipeInTable(mysqli $conn, int $productID, ?int $variantID, int $inventoryID, float $amount, string $unit): void {
    $normalizedUnit = normalizeUnitForRecipe($unit);
    
    // Check if recipe already exists
    if ($variantID !== null) {
        $checkStmt = $conn->prepare("SELECT recipeID, display_order FROM recipes WHERE productID = ? AND variant_id = ? AND inventoryID = ?");
        $checkStmt->bind_param('iii', $productID, $variantID, $inventoryID);
    } else {
        $checkStmt = $conn->prepare("SELECT recipeID, display_order FROM recipes WHERE productID = ? AND variant_id IS NULL AND inventoryID = ?");
        $checkStmt->bind_param('ii', $productID, $inventoryID);
    }
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $existing = $checkResult->fetch_assoc();
    $checkStmt->close();
    
    if ($existing) {
        // Update existing recipe (preserve display_order)
        $updateStmt = $conn->prepare("
            UPDATE recipes 
            SET amount = ?, unit = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE recipeID = ?
        ");
        $updateStmt->bind_param('dsi', $amount, $normalizedUnit, $existing['recipeID']);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        // Get current max display_order for this product (only for new recipes)
        if ($variantID !== null) {
            $orderStmt = $conn->prepare("SELECT MAX(display_order) as max_order FROM recipes WHERE productID = ? AND variant_id = ?");
            $orderStmt->bind_param('ii', $productID, $variantID);
        } else {
            $orderStmt = $conn->prepare("SELECT MAX(display_order) as max_order FROM recipes WHERE productID = ? AND variant_id IS NULL");
            $orderStmt->bind_param('i', $productID);
        }
        $orderStmt->execute();
        $orderResult = $orderStmt->get_result();
        $orderRow = $orderResult->fetch_assoc();
        $displayOrder = ($orderRow['max_order'] ?? -1) + 1;
        $orderStmt->close();
        
        // Insert new recipe
        if ($variantID !== null) {
            $insertStmt = $conn->prepare("
                INSERT INTO recipes (productID, variant_id, inventoryID, amount, unit, display_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->bind_param('iiidsi', $productID, $variantID, $inventoryID, $amount, $normalizedUnit, $displayOrder);
        } else {
            $insertStmt = $conn->prepare("
                INSERT INTO recipes (productID, inventoryID, amount, unit, display_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insertStmt->bind_param('iidsi', $productID, $inventoryID, $amount, $normalizedUnit, $displayOrder);
        }
        $insertStmt->execute();
        $insertStmt->close();
    }
}

/**
 * Remove recipe from recipes table
 */
function removeRecipeFromTable(mysqli $conn, int $productID, ?int $variantID, int $inventoryID): void {
    if ($variantID !== null) {
        $deleteStmt = $conn->prepare("DELETE FROM recipes WHERE productID = ? AND variant_id = ? AND inventoryID = ?");
        $deleteStmt->bind_param('iii', $productID, $variantID, $inventoryID);
    } else {
        $deleteStmt = $conn->prepare("DELETE FROM recipes WHERE productID = ? AND variant_id IS NULL AND inventoryID = ?");
        $deleteStmt->bind_param('ii', $productID, $inventoryID);
    }
    $deleteStmt->execute();
    $deleteStmt->close();
}

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
$productMetaStmt = $conn->prepare("SELECT has_variants FROM products WHERE productID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : ""));
if ($branchId !== null) {
    $productMetaStmt->bind_param('ii', $productID, $branchId);
} else {
    $productMetaStmt->bind_param('i', $productID);
}
$productMetaStmt->execute();
$productMeta = $productMetaStmt->get_result()->fetch_assoc();
$productMetaStmt->close();

if ($productMeta && (int)$productMeta['has_variants'] === 1 && $variantID === null) {
    echo json_encode(['status' => 'error', 'message' => 'VariantID is required for this product']);
    exit;
}

try {
    $conn->begin_transaction();
    $responsePayload = ['status' => 'success'];

    switch ($action) {
        case 'update_ingredient_cost':
            // Update ingredient cost (for wildcards or overrides)
            $inventoryID = intval($_POST['inventoryID'] ?? 0);
            $ingredientCost = floatval($_POST['ingredientCost'] ?? 0);
            
            if (!$inventoryID || $ingredientCost < 0) {
                throw new Exception('Invalid parameters');
            }
            
            // Check if override exists and is removed
            $existingOverrides = get_production_cost_overrides_for_product($conn, $productID, $variantID);
            $isRemoved = false;
            foreach ($existingOverrides as $ov) {
                if ($ov['inventoryID'] === $inventoryID && $ov['removed']) {
                    // Remove the "removed" marker first
                    remove_production_cost_override_from_db($conn, $productID, $inventoryID, $variantID);
                    break;
                }
            }
            
            // Save or update override
            persist_production_cost_override_to_db($conn, $productID, $variantID, $inventoryID, null, $ingredientCost, false);
            break;
            
        case 'add_ingredient':
            // Add a new ingredient to the product
            $inventoryID = intval($_POST['inventoryID'] ?? 0);
            $neededPerCup = floatval($_POST['neededPerCup'] ?? 0);
            $ingredientCost = floatval($_POST['ingredientCost'] ?? 0);
            
            if (!$inventoryID || $neededPerCup < 0 || $ingredientCost < 0) {
                throw new Exception('Invalid parameters');
            }
            
            // Get inventory item to get unit
            $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
            $invStmt = $conn->prepare("SELECT Unit FROM inventory WHERE inventoryID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : ""));
            if ($branchId !== null) {
                $invStmt->bind_param('ii', $inventoryID, $branchId);
            } else {
                $invStmt->bind_param('i', $inventoryID);
            }
            $invStmt->execute();
            $invResult = $invStmt->get_result();
            $inv = $invResult->fetch_assoc();
            $invStmt->close();
            
            if (!$inv) {
                throw new Exception('Inventory item not found');
            }
            
            $unit = $inv['Unit'] ?? 'ml';
            
            // Check if already exists (but allow re-adding if it was previously removed)
            $existingOverrides = get_production_cost_overrides_for_product($conn, $productID, $variantID);
            $exists = false;
            $isRemoved = false;
            foreach ($existingOverrides as $ov) {
                if ($ov['inventoryID'] === $inventoryID) {
                    if ($ov['removed']) {
                        // It's a removed marker - we can re-add it
                        $isRemoved = true;
                    } else {
                        // It's an active ingredient - cannot add duplicate
                        $exists = true;
                    }
                    break;
                }
            }
            
            // Check if it exists in recipes table
            if ($variantID !== null) {
                $recipeCheckStmt = $conn->prepare("SELECT recipeID FROM recipes WHERE productID = ? AND variant_id = ? AND inventoryID = ?");
                $recipeCheckStmt->bind_param('iii', $productID, $variantID, $inventoryID);
            } else {
                $recipeCheckStmt = $conn->prepare("SELECT recipeID FROM recipes WHERE productID = ? AND variant_id IS NULL AND inventoryID = ?");
                $recipeCheckStmt->bind_param('ii', $productID, $inventoryID);
            }
            $recipeCheckStmt->execute();
            $recipeCheckResult = $recipeCheckStmt->get_result();
            $recipeExists = $recipeCheckResult->fetch_assoc() !== null;
            $recipeCheckStmt->close();
            
            if ($exists || $recipeExists) {
                throw new Exception('Ingredient already exists in this product');
            }
            
            // If it was previously removed, remove the "removed" marker first
            if ($isRemoved) {
                remove_production_cost_override_from_db($conn, $productID, $inventoryID, $variantID);
            }
            
            // Add to recipes table
            updateRecipeInTable($conn, $productID, $variantID, $inventoryID, $neededPerCup, $unit);
            
            // Add new override
            persist_production_cost_override_to_db($conn, $productID, $variantID, $inventoryID, $neededPerCup, $ingredientCost, false);
            break;

        case 'copy_variant_recipe':
            $rawSourceVariantID = $_POST['sourceVariantID'] ?? '';
            $isBaseSource = ($rawSourceVariantID === 'base');
            $sourceVariantID = $isBaseSource ? null : (int)$rawSourceVariantID;

            if ($variantID === null || $variantID <= 0) {
                throw new Exception('Target variant is required for copying.');
            }
            if (!$isBaseSource && ($sourceVariantID === null || $sourceVariantID <= 0)) {
                throw new Exception('Please select a variant to copy from.');
            }
            if (!$isBaseSource && $sourceVariantID === $variantID) {
                throw new Exception('Source and target variants must be different.');
            }

            // Ensure the target variant exists for this product
            $targetCheckStmt = $conn->prepare("SELECT variant_id FROM product_variants WHERE product_id = ? AND variant_id = ? LIMIT 1");
            $targetCheckStmt->bind_param('ii', $productID, $variantID);
            $targetCheckStmt->execute();
            $targetResult = $targetCheckStmt->get_result()->fetch_assoc();
            $targetCheckStmt->close();
            if (!$targetResult) {
                throw new Exception('Selected target variant does not belong to this product.');
            }

            if (!$isBaseSource) {
                // Ensure the source variant belongs to this product
                $sourceCheckStmt = $conn->prepare("SELECT variant_id FROM product_variants WHERE product_id = ? AND variant_id = ? LIMIT 1");
                $sourceCheckStmt->bind_param('ii', $productID, $sourceVariantID);
                $sourceCheckStmt->execute();
                $sourceResult = $sourceCheckStmt->get_result()->fetch_assoc();
                $sourceCheckStmt->close();
                if (!$sourceResult) {
                    throw new Exception('Selected source variant does not belong to this product.');
                }
            }

            // Ensure the source (variant or base) actually has a recipe to copy
            if ($isBaseSource) {
                $sourceRecipeCountStmt = $conn->prepare("
                    SELECT COUNT(*) as total 
                    FROM recipes 
                    WHERE productID = ? AND variant_id IS NULL
                ");
                $sourceRecipeCountStmt->bind_param('i', $productID);
            } else {
                $sourceRecipeCountStmt = $conn->prepare("
                    SELECT COUNT(*) as total 
                    FROM recipes 
                    WHERE productID = ? AND variant_id = ?
                ");
                $sourceRecipeCountStmt->bind_param('ii', $productID, $sourceVariantID);
            }
            $sourceRecipeCountStmt->execute();
            $sourceRecipeCount = $sourceRecipeCountStmt->get_result()->fetch_assoc();
            $sourceRecipeCountStmt->close();
            if (($sourceRecipeCount['total'] ?? 0) < 1) {
                throw new Exception('Selected recipe has no ingredients to copy.');
            }

            // Remove current recipe entries for the target variant
            $deleteRecipeStmt = $conn->prepare("DELETE FROM recipes WHERE productID = ? AND variant_id = ?");
            $deleteRecipeStmt->bind_param('ii', $productID, $variantID);
            $deleteRecipeStmt->execute();
            $deleteRecipeStmt->close();

            // Copy recipes (preserve order)
            if ($isBaseSource) {
                $copyRecipeStmt = $conn->prepare("
                    INSERT INTO recipes (productID, variant_id, inventoryID, amount, unit, display_order, created_at, updated_at)
                    SELECT productID, ?, inventoryID, amount, unit, display_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM recipes
                    WHERE productID = ? AND variant_id IS NULL
                ");
                $copyRecipeStmt->bind_param('ii', $variantID, $productID);
            } else {
                $copyRecipeStmt = $conn->prepare("
                    INSERT INTO recipes (productID, variant_id, inventoryID, amount, unit, display_order, created_at, updated_at)
                    SELECT productID, ?, inventoryID, amount, unit, display_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                    FROM recipes
                    WHERE productID = ? AND variant_id = ?
                ");
                $copyRecipeStmt->bind_param('iii', $variantID, $productID, $sourceVariantID);
            }
            $copyRecipeStmt->execute();
            $copyRecipeStmt->close();

            // Copy variant-specific overrides if the schema supports it
            if (_production_cost_has_variant_id($conn)) {
                $deleteOverrideStmt = $conn->prepare("DELETE FROM production_cost_overrides WHERE productID = ? AND variant_id = ?");
                $deleteOverrideStmt->bind_param('ii', $productID, $variantID);
                $deleteOverrideStmt->execute();
                $deleteOverrideStmt->close();

                if ($isBaseSource) {
                    $copyOverrideStmt = $conn->prepare("
                        INSERT INTO production_cost_overrides (productID, variant_id, inventoryID, needed_per_cup, ingredient_cost, is_removed, is_active, created_at, updated_at)
                        SELECT productID, ?, inventoryID, needed_per_cup, ingredient_cost, is_removed, is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                        FROM production_cost_overrides
                        WHERE productID = ? AND variant_id IS NULL
                    ");
                    $copyOverrideStmt->bind_param('ii', $variantID, $productID);
                } else {
                    $copyOverrideStmt = $conn->prepare("
                        INSERT INTO production_cost_overrides (productID, variant_id, inventoryID, needed_per_cup, ingredient_cost, is_removed, is_active, created_at, updated_at)
                        SELECT productID, ?, inventoryID, needed_per_cup, ingredient_cost, is_removed, is_active, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                        FROM production_cost_overrides
                        WHERE productID = ? AND variant_id = ?
                    ");
                    $copyOverrideStmt->bind_param('iii', $variantID, $productID, $sourceVariantID);
                }
                $copyOverrideStmt->execute();
                $copyOverrideStmt->close();
            }

            $responsePayload['message'] = 'Recipe copied successfully.';
            break;
            
        case 'update_needed_per_cup':
            // Update needed per cup and recalculate ingredient cost
            $inventoryID = intval($_POST['inventoryID'] ?? 0);
            $neededPerCup = floatval($_POST['neededPerCup'] ?? 0);
            
            if (!$inventoryID || $neededPerCup < 0) {
                throw new Exception('Invalid parameters');
            }
            
            // Get inventory item to calculate price per unit and get unit
            $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
            $invStmt = $conn->prepare("SELECT Size, Unit, Cost_Price FROM inventory WHERE inventoryID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : ""));
            if ($branchId !== null) {
                $invStmt->bind_param('ii', $inventoryID, $branchId);
            } else {
                $invStmt->bind_param('i', $inventoryID);
            }
            $invStmt->execute();
            $invResult = $invStmt->get_result();
            $inv = $invResult->fetch_assoc();
            $invStmt->close();
            
            if (!$inv) {
                throw new Exception('Inventory item not found');
            }
            
            $unit = $inv['Unit'] ?? 'ml';
            
            // Calculate price per unit
            $packSize = $inv['Size'] ?? '';
            $packPrice = (float)($inv['Cost_Price'] ?? 0);
            $sizeValue = floatval(preg_replace('/[^0-9.]/', '', $packSize)) ?: 1;
            $pricePerUnit = $sizeValue > 0 ? ($packPrice / $sizeValue) : 0;
            $ingredientCost = $pricePerUnit * $neededPerCup;
            
            // Update recipes table
            updateRecipeInTable($conn, $productID, $variantID, $inventoryID, $neededPerCup, $unit);
            
            // Update or create override
            persist_production_cost_override_to_db($conn, $productID, $variantID, $inventoryID, $neededPerCup, $ingredientCost, false);
            break;
            
        case 'remove_ingredient':
            // Remove an ingredient - can now remove base recipe ingredients too
            $inventoryID = intval($_POST['inventoryID'] ?? 0);
            
            if (!$inventoryID) {
                throw new Exception('Invalid inventory ID');
            }
            
            // Remove from recipes table
            removeRecipeFromTable($conn, $productID, $variantID, $inventoryID);
            
            // Remove existing override if it exists
            remove_production_cost_override_from_db($conn, $productID, $inventoryID, $variantID);
            
            // Add a "removed" marker for base recipe ingredients (in case it was in base recipe)
            // This tells production_cost_get.php to skip this ingredient even if it's in base recipe
            persist_production_cost_override_to_db($conn, $productID, $variantID, $inventoryID, null, null, true);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
    // Commit transaction
    $conn->commit();
    
    // Clear output buffer and send JSON
    ob_end_clean();
    echo json_encode($responsePayload);
    
} catch (Exception $e) {
    // #region agent log
    @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_save.php', 'message' => 'Exception', 'data' => ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'timestamp' => time() * 1000, 'hypothesisId' => 'C']) . "\n", LOCK_EX | FILE_APPEND);
    // #endregion
    if (isset($conn)) {
        $conn->rollback();
    }
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (Error $e) {
    // #region agent log
    @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'production_cost_save.php', 'message' => 'Error', 'data' => ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'timestamp' => time() * 1000, 'hypothesisId' => 'C']) . "\n", LOCK_EX | FILE_APPEND);
    // #endregion
    if (isset($conn)) {
        $conn->rollback();
    }
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => 'An error occurred: ' . $e->getMessage()]);
}
