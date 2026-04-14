<?php
// Prevent caching to ensure fresh data
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../lib/RecipeHelper.php';

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$recipeHelper = new RecipeHelper($conn);

try {

if (!function_exists('normalize_size_label')) {
    function normalize_size_label($label) {
        $value = strtolower(trim((string)$label));
        if ($value === '') {
            return '';
        }
        if (strpos($value, 'oz') === false) {
            $value .= 'oz';
        }
        return $value;
    }
}

// Manual cost overrides are no longer used - production costs are calculated from recipes
$manualCostMap = [];

// Load inventory cost info - fetch fresh data each time to ensure we have latest prices
$inventoryRows = [];
// Use a fresh query to ensure we get the latest Cost_Price values
$invSql = "SELECT inventoryID, InventoryName, `Size`, `Unit`, `Cost_Price` FROM inventory";
if ($branchId !== null) {
    $invSql .= " WHERE (branch_id IS NULL OR branch_id = ?)";
}
$invStmt = $conn->prepare($invSql);
if ($branchId !== null) {
    $invStmt->bind_param('i', $branchId);
}
$invStmt->execute();
$invResult = $invStmt->get_result();
if ($invResult) {
    while ($row = $invResult->fetch_assoc()) {
        $inventoryRows[(int)$row['inventoryID']] = $row;
    }
    $invResult->free();
}
$invStmt->close();

if (empty($inventoryRows)) {
    echo json_encode([]);
    exit;
}

// Get production costs from database (user-editable)
// Use the same calculation as production_cost_get.php to ensure consistency
$productionCosts = [];
require_once __DIR__ . '/inventory_usage_helpers.php';

$hasRecipeVariants = (function () use ($conn) {
    $r = @$conn->query("SHOW COLUMNS FROM recipes LIKE 'variant_id'");
    return $r && $r->num_rows > 0;
})();

$allOverrides = [];
if ($hasRecipeVariants) {
    $overrideResult = $conn->query("SELECT productID, variant_id, inventoryID, needed_per_cup, ingredient_cost, is_removed FROM production_cost_overrides WHERE is_active = 1");
    if ($overrideResult) {
        while ($row = $overrideResult->fetch_assoc()) {
            $productID = (int)$row['productID'];
            $inventoryID = (int)$row['inventoryID'];
            if ($productID <= 0 || $inventoryID <= 0) {
                continue;
            }
            $variantKey = $row['variant_id'] === null ? 'base' : (string)(int)$row['variant_id'];
            if (!isset($allOverrides[$productID])) {
                $allOverrides[$productID] = [];
            }
            if (!isset($allOverrides[$productID][$variantKey])) {
                $allOverrides[$productID][$variantKey] = [];
            }
            $allOverrides[$productID][$variantKey][] = [
                'inventoryID' => $inventoryID,
                'neededPerCup' => $row['needed_per_cup'] !== null ? (float)$row['needed_per_cup'] : null,
                'ingredientCost' => $row['ingredient_cost'] !== null ? (float)$row['ingredient_cost'] : null,
                'removed' => (bool)$row['is_removed']
            ];
        }
        $overrideResult->free();
    }
} else {
    $allOverrides = load_production_cost_overrides_from_db($conn);
}

// Load recipes from database
$recipes = [];
if ($hasRecipeVariants) {
    $recipeResult = $conn->query("
        SELECT productID, variant_id, inventoryID, amount, unit, display_order 
        FROM recipes 
        ORDER BY productID, variant_id, display_order
    ");
    if ($recipeResult) {
        while ($row = $recipeResult->fetch_assoc()) {
            $productID = (int)$row['productID'];
            $variantKey = $row['variant_id'] === null ? 'base' : (string)(int)$row['variant_id'];
            if (!isset($recipes[$productID])) {
                $recipes[$productID] = [];
            }
            if (!isset($recipes[$productID][$variantKey])) {
                $recipes[$productID][$variantKey] = [];
            }
            $recipes[$productID][$variantKey][] = [
                'inventoryID' => (int)$row['inventoryID'],
                'amount' => (float)$row['amount'],
                'unit' => $row['unit']
            ];
        }
        $recipeResult->free();
    }
} else {
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
}

// Refresh inventory data right before calculation to ensure we have the latest prices
// This is critical to ensure costs are calculated with the most recent inventory prices
$invSql = "SELECT inventoryID, InventoryName, `Size`, `Unit`, `Cost_Price` FROM inventory";
if ($branchId !== null) {
    $invSql .= " WHERE (branch_id IS NULL OR branch_id = ?)";
}
$invStmt = $conn->prepare($invSql);
if ($branchId !== null) {
    $invStmt->bind_param('i', $branchId);
}
$invStmt->execute();
$invResult = $invStmt->get_result();
if ($invResult) {
    $inventoryRows = [];
    while ($row = $invResult->fetch_assoc()) {
        $inventoryRows[(int)$row['inventoryID']] = $row;
    }
    $invResult->free();
}
$invStmt->close();

// Calculate production cost for ALL products with recipes (not just those with overrides)
// This ensures products without overrides still show up in the costing table
foreach ($recipes as $productID => $recipeGroup) {
    $productID = (int)$productID;
    if ($productID <= 0) continue;

    $variantsToProcess = $hasRecipeVariants ? $recipeGroup : ['base' => $recipeGroup];

    foreach ($variantsToProcess as $variantKey => $recipeIngredients) {
        $normalizedVariantKey = $variantKey === 'base' ? 'base' : (string)(int)$variantKey;
        $variantId = $normalizedVariantKey === 'base' ? null : (int)$normalizedVariantKey;

        // Get overrides for this product/variant (if any)
        if ($hasRecipeVariants) {
            $overrides = $allOverrides[$productID][$normalizedVariantKey] ?? [];
        } else {
            $overrides = $allOverrides[$productID] ?? [];
        }

        $totalCost = 0.0;
        $iceCost = 0.0; // Track ice cost separately

        // Process base recipe ingredients - same logic as production_cost_get.php
        foreach ($recipeIngredients as $ingredient) {
            $inventoryID = (int)($ingredient['inventoryID'] ?? 0);
            $amount = (float)($ingredient['amount'] ?? 0);

            if (!$inventoryID || !isset($inventoryRows[$inventoryID])) continue;

            // Check if removed
            $isRemoved = false;
            foreach ($overrides as $ov) {
                if ((int)($ov['inventoryID'] ?? 0) === $inventoryID && isset($ov['removed']) && $ov['removed']) {
                    $isRemoved = true;
                    break;
                }
            }
            if ($isRemoved) continue;

            $inv = $inventoryRows[$inventoryID];
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
                // Wildcard (Ice) - use fixed cost from override (same logic as production_cost_get.php)
                $ingredientCost = $override ? (float)($override['ingredientCost'] ?? 0) : 2.00;
                $iceCost += $ingredientCost; // Track ice separately
                $totalCost += $ingredientCost;
            } else {
                // Always recalculate from current inventory prices (same as production_cost_get.php)
                $packSize = $inv['Size'] ?? '';
                $packPrice = (float)($inv['Cost_Price'] ?? 0);
                $sizeValue = floatval(preg_replace('/[^0-9.]/', '', $packSize)) ?: 1;
                $pricePerUnit = $sizeValue > 0 ? ($packPrice / $sizeValue) : 0;
                $neededPerCup = $override ? (float)($override['neededPerCup'] ?? $amount) : $amount;
                $ingredientCost = $pricePerUnit * $neededPerCup;
                $totalCost += $ingredientCost;
            }
        }

        // Add manually added ingredients (from overrides) - same logic as production_cost_get.php
        foreach ($overrides as $ov) {
            if (isset($ov['removed']) && $ov['removed']) continue;
            $inventoryID = (int)($ov['inventoryID'] ?? 0);
            if (!$inventoryID || !isset($inventoryRows[$inventoryID])) continue;

            // Check if already processed in base recipe
            $found = false;
            foreach ($recipeIngredients as $ingredient) {
                if ((int)($ingredient['inventoryID'] ?? 0) === $inventoryID) {
                    $found = true;
                    break;
                }
            }

            // If not found in base recipe, it's a manually added ingredient
            if (!$found) {
                $inv = $inventoryRows[$inventoryID];
                $invName = $inv['InventoryName'] ?? '';
                $isWildcard = stripos($invName, 'ice') !== false;

                if ($isWildcard) {
                    // Wildcard (Ice) - use fixed cost from override (same logic as production_cost_get.php)
                    $ingredientCost = isset($ov['ingredientCost']) ? (float)$ov['ingredientCost'] : 2.00;
                    $iceCost += $ingredientCost; // Track ice separately
                    $totalCost += $ingredientCost;
                } else {
                    // Always recalculate from current inventory prices (same as production_cost_get.php)
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

        // Round to 2 decimal places, same as production_cost_get.php
        // Store both total cost and ice cost separately for multiplier calculation
        $costEntry = [
            'total' => round($totalCost, 2),
            'ice' => round($iceCost, 2)
        ];

        if ($variantId === null) {
            $productionCosts[$productID]['base'] = $costEntry;
        } else {
            if (!isset($productionCosts[$productID])) {
                $productionCosts[$productID] = [];
            }
            if (!isset($productionCosts[$productID]['variants'])) {
                $productionCosts[$productID]['variants'] = [];
            }
            $productionCosts[$productID]['variants'][$variantId] = $costEntry;
        }
    }
}

// Fall back to base recipe costs for products without production cost data
$baseCosts = $recipeHelper->calculateBaseProductCosts($inventoryRows);
// Product metadata
$productMap = [];
$categoryMap = [];
$variantLabels = [];
$productSql = "SELECT p.productID, p.productName, p.categoryID, c.categoryName FROM products p LEFT JOIN categories c ON c.categoryID = p.categoryID";
if ($branchId !== null) {
    $productSql .= " WHERE (p.branch_id IS NULL OR p.branch_id = ?)";
}
$productStmt = $conn->prepare($productSql);
if ($branchId !== null) {
    $productStmt->bind_param('i', $branchId);
}
$productStmt->execute();
$productResult = $productStmt->get_result();
if ($productResult) {
    while ($row = $productResult->fetch_assoc()) {
        $productMap[(int)$row['productID']] = $row['productName'] ?? ('Product #' . $row['productID']);
        if ($row['categoryID']) {
            $categoryMap[(int)$row['productID']] = $row['categoryName'] ?? 'Uncategorized';
        }
    }
    $productResult->free();
    $productStmt->close();
}

if ($hasRecipeVariants) {
    $variantResult = $conn->query("SELECT product_id, variant_id, variant_name, size_label FROM product_variants");
    if ($variantResult) {
        while ($row = $variantResult->fetch_assoc()) {
            $productID = (int)$row['product_id'];
            $variantID = (int)$row['variant_id'];
            if (!isset($variantLabels[$productID])) {
                $variantLabels[$productID] = [];
            }
            $variantLabels[$productID][$variantID] = $row['size_label'] ?: ($row['variant_name'] ?? '');
        }
        $variantResult->free();
    }
}

$sizeMap = [];
$sizesTableExists = (function () use ($conn) {
    $tableCheck = @$conn->query("SHOW TABLES LIKE 'sizes'");
    return $tableCheck && $tableCheck->num_rows > 0;
})();
if ($sizesTableExists) {
    $sizeResult = $conn->query("SELECT sizeID, sizeName FROM sizes");
    if ($sizeResult) {
        while ($row = $sizeResult->fetch_assoc()) {
            $sizeMap[(int)$row['sizeID']] = $row['sizeName'];
        }
        $sizeResult->free();
    }
}
// If sizes table does not exist (overhaul schema), $sizeMap stays empty; labels fall back to 'Size #id' or size_label

$output = [];
// Detect schema: overhaul uses size_label, legacy uses sizeID
$useSizeLabel = (function () use ($conn) {
    $r = @$conn->query("SHOW COLUMNS FROM product_prices LIKE 'size_label'");
    return $r && $r->num_rows > 0;
})();

// Get prices - prefer unit_id = 2 (oz) but fall back to highest price if oz not available
try {
    $priceMap = [];
    if ($useSizeLabel) {
        $priceStmt = $conn->query("SELECT productID, size_label, price, unit_id FROM product_prices WHERE unit_id = 2 AND price > 0");
        if ($priceStmt) {
            while ($row = $priceStmt->fetch_assoc()) {
                $productID = (int)$row['productID'];
                $sl = (string)($row['size_label'] ?? '');
                $key = $productID . '_' . $sl;
                $priceMap[$key] = [
                    'productID' => $productID,
                    'sizeID' => 0,
                    'size_label' => $sl,
                    'price' => (float)$row['price'],
                    'unit_id' => 2
                ];
            }
            $priceStmt->free();
        }
        $priceStmt2 = $conn->query("SELECT productID, size_label, price, unit_id FROM product_prices WHERE price > 0 ORDER BY productID, size_label, price DESC");
        if ($priceStmt2) {
            while ($row = $priceStmt2->fetch_assoc()) {
                $productID = (int)$row['productID'];
                $sl = (string)($row['size_label'] ?? '');
                $key = $productID . '_' . $sl;
                if (!isset($priceMap[$key])) {
                    $priceMap[$key] = [
                        'productID' => $productID,
                        'sizeID' => 0,
                        'size_label' => $sl,
                        'price' => (float)$row['price'],
                        'unit_id' => (int)$row['unit_id']
                    ];
                }
            }
            $priceStmt2->free();
        }
    } else {
        $priceStmt = $conn->query("SELECT productID, sizeID, price, unit_id FROM product_prices WHERE unit_id = 2 AND price > 0");
        if (!$priceStmt) {
            throw new Exception('Failed to query product prices: ' . $conn->error);
        }
        while ($row = $priceStmt->fetch_assoc()) {
            $productID = (int)$row['productID'];
            $sizeID = (int)$row['sizeID'];
            $key = "{$productID}_{$sizeID}";
            $priceMap[$key] = [
                'productID' => $productID,
                'sizeID' => $sizeID,
                'size_label' => $sizeMap[$sizeID] ?? ('Size #' . $sizeID),
                'price' => (float)$row['price'],
                'unit_id' => 2
            ];
        }
        $priceStmt->free();
        $priceStmt2 = $conn->query("SELECT productID, sizeID, price, unit_id FROM product_prices WHERE price > 0 ORDER BY productID, sizeID, price DESC");
        if ($priceStmt2) {
            while ($row = $priceStmt2->fetch_assoc()) {
                $productID = (int)$row['productID'];
                $sizeID = (int)$row['sizeID'];
                $key = "{$productID}_{$sizeID}";
                if (!isset($priceMap[$key])) {
                    $priceMap[$key] = [
                        'productID' => $productID,
                        'sizeID' => $sizeID,
                        'size_label' => $sizeMap[$sizeID] ?? ('Size #' . $sizeID),
                        'price' => (float)$row['price'],
                        'unit_id' => (int)$row['unit_id']
                    ];
                }
            }
            $priceStmt2->free();
        }
    }
    
    // Include prices from product variants if product_prices is empty or missing entries
    $variantTableExists = (function () use ($conn) {
        $variantCheck = @$conn->query("SHOW COLUMNS FROM product_variants LIKE 'variant_id'");
        return $variantCheck && $variantCheck->num_rows > 0;
    })();

    if ($variantTableExists) {
        $statusColumnExists = (function () use ($conn) {
            $statusCheck = @$conn->query("SHOW COLUMNS FROM product_variants LIKE 'status'");
            return $statusCheck && $statusCheck->num_rows > 0;
        })();
        $variantSql = $statusColumnExists
            ? "SELECT product_id, variant_name, size_label, price FROM product_variants WHERE status = 'active' AND price > 0"
            : "SELECT product_id, variant_name, size_label, price FROM product_variants WHERE price > 0";
        $variantResult = $conn->query($variantSql);
        if ($variantResult) {
            while ($row = $variantResult->fetch_assoc()) {
                $productID = (int) $row['product_id'];
                $sizeLabel = trim((string)($row['size_label'] ?? $row['variant_name'] ?? ''));
                $key = $productID . '_' . $sizeLabel;
                if (!isset($priceMap[$key])) {
                    $priceMap[$key] = [
                        'productID' => $productID,
                        'sizeID' => 0,
                        'size_label' => $sizeLabel,
                        'price' => (float) $row['price'],
                        'unit_id' => null
                    ];
                }
            }
            $variantResult->free();
        }
    }

    // Include base_price for non-variant products
    $basePriceSql = "SELECT productID, base_price FROM products WHERE (has_variants = 0 OR has_variants IS NULL) AND base_price > 0";
if ($branchId !== null) {
    $basePriceSql .= " AND (branch_id IS NULL OR branch_id = ?)";
}
$basePriceStmt = $conn->prepare($basePriceSql);
if ($branchId !== null) {
    $basePriceStmt->bind_param('i', $branchId);
}
$basePriceStmt->execute();
$basePriceResult = $basePriceStmt->get_result();
    if ($basePriceResult) {
        while ($row = $basePriceResult->fetch_assoc()) {
            $productID = (int) $row['productID'];
            $key = $productID . '_single';
            if (!isset($priceMap[$key])) {
                $priceMap[$key] = [
                    'productID' => $productID,
                    'sizeID' => 0,
                    'size_label' => 'Single',
                    'price' => (float) $row['base_price'],
                    'unit_id' => null
                ];
            }
        }
        $basePriceResult->free();
        $basePriceStmt->close();
    }

    // Process the price map
    foreach ($priceMap as $entry) {
        $productID = $entry['productID'];
        $sizeID = (int)($entry['sizeID'] ?? 0);
        $sizeLabel = $entry['size_label'] ?? ('Size #' . $sizeID);
        $menuPrice = $entry['price'];
        $manualKey = strtolower($productMap[$productID] ?? ('Product #' . $productID)) . '|' . normalize_size_label($sizeLabel);
        $manual = $manualCostMap[$manualKey] ?? null;
        $multiplier = 1.0;

        $hasBaseCost = isset($baseCosts[$productID]);
        if (!$hasBaseCost && !$manual) {
            if ($menuPrice <= 0) {
                continue;
            }
        } elseif ($menuPrice <= 0 && (!isset($manual['SellingPrice']) || $manual['SellingPrice'] <= 0)) {
            continue;
        }

        if ($menuPrice <= 0 && isset($manual['SellingPrice'])) {
            $menuPrice = (float)$manual['SellingPrice'];
        }

        $multiplier = 1.0;
        $cost = 0.0;
        $profit = 0.0;
        $margin = 0.0;

        $productionCostEntry = null;
        if ($hasRecipeVariants) {
            $normalizedVariantKey = normalize_size_label($sizeLabel);
            $variantId = null;
            if (isset($productionCosts[$productID]['variants'])) {
                foreach ($productionCosts[$productID]['variants'] as $variantKey => $variantCost) {
                    $variantLabel = $variantLabels[$productID][$variantKey] ?? '';
                    if ($variantLabel !== '' && normalize_size_label($variantLabel) === $normalizedVariantKey) {
                        $variantId = (int)$variantKey;
                        $productionCostEntry = $variantCost;
                        break;
                    }
                }
            }
            if ($productionCostEntry === null && isset($productionCosts[$productID]['base'])) {
                $productionCostEntry = $productionCosts[$productID]['base'];
            }
            if ($productionCostEntry === null && isset($productionCosts[$productID]) && is_array($productionCosts[$productID])) {
                $productionCostEntry = $productionCosts[$productID];
            }
        } else if (isset($productionCosts[$productID])) {
            $productionCostEntry = $productionCosts[$productID];
        }

        // Priority: Production cost > Manual cost > Base cost
        // Use production cost if available (this is the most accurate)
        if ($productionCostEntry !== null) {
            // Handle both old format (single value) and new format (array with total and ice)
            if (is_array($productionCostEntry)) {
                $baseCost = $productionCostEntry['total'] ?? 0;
                $iceCost = $productionCostEntry['ice'] ?? 0;
                $nonIceCost = $baseCost - $iceCost;
                // Apply multiplier to non-ice cost, then add ice cost separately (ice cost doesn't scale with size)
                $cost = round(($nonIceCost * $multiplier) + $iceCost, 2);
            } else {
                // Backward compatibility: if it's a single value, use old calculation
                $cost = round($productionCostEntry * $multiplier, 2);
            }
            $profit = round($menuPrice - $cost, 2);
            $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
        } else if ($manual && isset($manual['TotalCost'])) {
            // Use manual cost only if production cost is not available
            // Only use manual SellingPrice if database price is missing or invalid
            if (isset($manual['SellingPrice']) && $menuPrice <= 0) {
                $menuPrice = (float)$manual['SellingPrice'];
            }
            // Apply size multiplier to manual cost (manual costs are typically for base 16oz size)
            // If manual cost is for a different size, it should be adjusted accordingly
            $cost = round((float)$manual['TotalCost'] * $multiplier, 2);
            $profit = round($menuPrice - $cost, 2);
            if (isset($manual['Profit'])) {
                $profit = round((float)$manual['Profit'], 2);
            }
            if (isset($manual['Margin'])) {
                $margin = round((float)$manual['Margin'], 2);
            } else {
                $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
            }
        } else if ($hasBaseCost) {
            // Fall back to base cost calculation
            $cost = round($baseCosts[$productID] * $multiplier, 2);
            $profit = round($menuPrice - $cost, 2);
            $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
        } else {
            // No cost calculation available - still show product but with cost = 0
            // This allows products without recipes to still appear in the table
            $profit = round($menuPrice - $cost, 2);
            $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
        }

        // Apply manual overrides for menu price, profit, and margin if manual exists
        // But don't override cost if production cost was used
        // Only use manual SellingPrice if database price is missing or invalid
        if ($manual) {
            if (isset($manual['SellingPrice']) && $menuPrice <= 0) {
                $menuPrice = (float)$manual['SellingPrice'];
                // Recalculate profit and margin if menu price changed
                if (isset($productionCosts[$productID])) {
                    // Recalculate based on production cost (cost already calculated above with multiplier logic)
                    $profit = round($menuPrice - $cost, 2);
                    $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
                }
            }
            // Only override profit/margin if not using production cost
            if (!isset($productionCosts[$productID])) {
                if (isset($manual['Profit'])) {
                    $profit = round((float)$manual['Profit'], 2);
                }
                if (isset($manual['Margin'])) {
                    $margin = round((float)$manual['Margin'], 2);
                } else if ($menuPrice > 0) {
                    $margin = round(($profit / $menuPrice) * 100, 2);
                }
            }
        }

        $output[] = [
            'Category' => $categoryMap[$productID] ?? 'Uncategorized',
            'Product' => $productMap[$productID] ?? ('Product #' . $productID),
            'Variant' => $sizeLabel,
            'Selling Price' => $menuPrice,
            'Cost Price' => $cost,
            'Profit' => $profit,
            'Margin' => $margin
        ];
    }
} catch (Exception $e) {
    // If there's an error, fall back to the original query with unit_id = 2
    error_log('Error in inventory_costing.php price query: ' . $e->getMessage());
    $priceStmt = $conn->query("SELECT productID, sizeID, price FROM product_prices WHERE unit_id = 2 AND price >= 0");
    if ($priceStmt) {
        while ($row = $priceStmt->fetch_assoc()) {
            $productID = (int)$row['productID'];
            $sizeID = (int)$row['sizeID'];
            $menuPrice = (float)$row['price'];
            
            $sizeLabel = $sizeMap[$sizeID] ?? ('Size #' . $sizeID);
            $manualKey = strtolower($productMap[$productID] ?? ('Product #' . $productID)) . '|' . normalize_size_label($sizeLabel);
            $manual = $manualCostMap[$manualKey] ?? null;

            $hasBaseCost = isset($baseCosts[$productID]);
            if (!$hasBaseCost && !$manual) {
                if ($menuPrice <= 0) {
                    continue;
                }
            } elseif ($menuPrice <= 0 && (!isset($manual['SellingPrice']) || $manual['SellingPrice'] <= 0)) {
                continue;
            }

            if ($menuPrice <= 0 && isset($manual['SellingPrice'])) {
                $menuPrice = (float)$manual['SellingPrice'];
            }

            // Ensure sizeID is an integer for proper array lookup
            $sizeID = (int)$sizeID;
            $multiplier = 1.0;
            $cost = 0.0;
            $profit = 0.0;
            $margin = 0.0;

            // Priority: Production cost > Manual cost > Base cost
            if (isset($productionCosts[$productID])) {
                if (is_array($productionCosts[$productID])) {
                    $baseCost = $productionCosts[$productID]['total'];
                    $iceCost = $productionCosts[$productID]['ice'];
                    $nonIceCost = $baseCost - $iceCost;
                    $cost = round(($nonIceCost * $multiplier) + $iceCost, 2);
                } else {
                    $cost = round($productionCosts[$productID] * $multiplier, 2);
                }
                $profit = round($menuPrice - $cost, 2);
                $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
            } else if ($manual && isset($manual['TotalCost'])) {
                // Only use manual SellingPrice if database price is missing or invalid
                if (isset($manual['SellingPrice']) && $menuPrice <= 0) {
                    $menuPrice = (float)$manual['SellingPrice'];
                }
                // Apply size multiplier to manual cost (manual costs are typically for base 16oz size)
                $cost = round((float)$manual['TotalCost'] * $multiplier, 2);
                $profit = round($menuPrice - $cost, 2);
                if (isset($manual['Profit'])) {
                    $profit = round((float)$manual['Profit'], 2);
                }
                if (isset($manual['Margin'])) {
                    $margin = round((float)$manual['Margin'], 2);
                } else {
                    $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
                }
            } else if ($hasBaseCost) {
                $cost = round($baseCosts[$productID] * $multiplier, 2);
                $profit = round($menuPrice - $cost, 2);
                $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
            }

            if ($manual) {
                // Only use manual SellingPrice if database price is missing or invalid
                if (isset($manual['SellingPrice']) && $menuPrice <= 0) {
                    $menuPrice = (float)$manual['SellingPrice'];
                    if (isset($productionCosts[$productID])) {
                        $profit = round($menuPrice - $cost, 2);
                        $margin = $menuPrice > 0 ? round(($profit / $menuPrice) * 100, 2) : 0;
                    }
                }
                if (!isset($productionCosts[$productID])) {
                    if (isset($manual['Profit'])) {
                        $profit = round((float)$manual['Profit'], 2);
                    }
                    if (isset($manual['Margin'])) {
                        $margin = round((float)$manual['Margin'], 2);
                    } else if ($menuPrice > 0) {
                        $margin = round(($profit / $menuPrice) * 100, 2);
                    }
                }
            }

            $output[] = [
                'Category' => $categoryMap[$productID] ?? 'Uncategorized',
                'Product' => $productMap[$productID] ?? ('Product #' . $productID),
                'Variant' => $sizeLabel,
                'Selling Price' => $menuPrice,
                'Cost Price' => $cost,
                'Profit' => $profit,
                'Margin' => $margin
            ];
        }
        $priceStmt->free();
    }
}

usort($output, function ($a, $b) {
    return strcmp($a['Category'] . $a['Product'], $b['Category'] . $b['Product']);
});

echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

} catch (Throwable $e) {
    error_log('inventory_costing.php: ' . $e->getMessage());
    echo json_encode([]);
}
?>
