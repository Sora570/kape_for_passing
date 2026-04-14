<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../lib/RecipeHelper.php';
require_once __DIR__ . '/inventory_usage_helpers.php';

// Check if user is logged in
if (!isset($_SESSION['userID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$cartItems = json_decode($_POST['cartItems'] ?? '[]', true);
if (!is_array($cartItems) || empty($cartItems)) {
    echo json_encode(['status' => 'error', 'message' => 'No items in cart']);
    exit;
}

$checkoutToken = trim((string)($_POST['checkoutToken'] ?? ''));
if ($checkoutToken === '') {
    echo json_encode(['status' => 'error', 'message' => 'Missing checkout token']);
    exit;
}

if (!isset($_SESSION['checkout_tokens']) || !is_array($_SESSION['checkout_tokens'])) {
    $_SESSION['checkout_tokens'] = [];
}

// Remove tokens older than 10 minutes
$now = time();
$_SESSION['checkout_tokens'] = array_filter(
    $_SESSION['checkout_tokens'],
    fn($timestamp) => is_int($timestamp) && ($now - $timestamp) <= 600
);

if (array_key_exists($checkoutToken, $_SESSION['checkout_tokens'])) {
    echo json_encode(['status' => 'error', 'message' => 'Duplicate checkout detected']);
    exit;
}

$_SESSION['checkout_tokens'][$checkoutToken] = $now;

$paymentMethod = strtolower(trim((string)($_POST['paymentMethod'] ?? 'cash')));
$allowedPayments = ['cash', 'gcash', 'paymaya'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    $paymentMethod = 'cash';
}

$paymentProvider = $paymentMethod;
$receiverNumber = sanitize_text($_POST['receiverNumber'] ?? $_POST['receiver_number'] ?? '', 30);
$payerLast4 = preg_replace('/\D/', '', (string)($_POST['payerLast4'] ?? $_POST['payer_last4'] ?? ''));
if ($payerLast4 !== '') {
    $payerLast4 = substr($payerLast4, -4);
}

$cashTenderedRaw = $_POST['cashTendered'] ?? $_POST['cashReceived'] ?? 0;
if (!validate_float($cashTenderedRaw) || $cashTenderedRaw < 0) {
    $cashTenderedRaw = 0.0;
}
$cashTendered = (float)$cashTenderedRaw;

// Get user's branch_id (null means global/admin)
$userBranchId = $_SESSION['branch_id'] ?? null;

// Look up the branch name for inclusion in the receipt
$branchName = 'All Branches';
if ($userBranchId !== null) {
    $brNameStmt = $conn->prepare('SELECT branch_name FROM branches WHERE branch_id = ? LIMIT 1');
    if ($brNameStmt) {
        $brNameStmt->bind_param('i', $userBranchId);
        $brNameStmt->execute();
        $brNameRow = $brNameStmt->get_result()->fetch_assoc();
        if ($brNameRow) {
            $branchName = $brNameRow['branch_name'];
        }
        $brNameStmt->close();
    }
}

$recipeHelper = new RecipeHelper();
$ingredientUsage = $recipeHelper->aggregateUsage($cartItems);
$orderIngredientCost = 0.0;
$totalProfit = 0.0;

$conn->begin_transaction();

try {
    // Calculate totals
    $subtotal = 0;
    foreach ($cartItems as $item) {
        $subtotal += ($item['unitPrice'] ?? 0) * ($item['quantity'] ?? 0);
    }
    $totalAmount = $subtotal;
    $orderIngredientCost = 0.0;

    if ($paymentMethod === 'cash' && $cashTendered < $totalAmount) {
        throw new Exception('Cash tendered must be at least the total amount.');
    }

    if ($paymentMethod !== 'cash') {
        if ($receiverNumber === '' || $payerLast4 === '' || strlen($payerLast4) !== 4) {
            throw new Exception('Receiver number and last 4 digits are required for GCash/PayMaya payments.');
        }
    } else {
        $receiverNumber = '';
        $payerLast4 = '';
    }

    // Generate reference number placeholder (will update after insert)
    $referenceNumber = 'SAL000001';

    // ============================================================
    // NEW SCHEMA: Insert into `sales` table instead of `orders`
    // ============================================================
    $cashTenderedValue = null;
    $changeAmountValue = null;
    if ($paymentMethod === 'cash') {
        $cashTenderedValue = $cashTendered;
        $changeAmountValue = max(0, $cashTendered - $totalAmount);
    }

    $saleQuery = "INSERT INTO sales (branch_id, user_id, sale_datetime, total_amount, payment_method, cash_tendered, change_amount, reference_number, payment_provider, receiver_number, payer_last4, status, ingredient_cost, completed_at)
                  VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, 'completed', 0, NOW())";

    $stmt = $conn->prepare($saleQuery);
    $currentUserId = (int)($_SESSION['userID'] ?? 1);
    $stmt->bind_param('iidsddssss', $userBranchId, $currentUserId, $totalAmount, $paymentMethod, $cashTenderedValue, $changeAmountValue, $referenceNumber, $paymentProvider, $receiverNumber, $payerLast4);
    $stmt->execute();
    $saleId = $conn->insert_id;

    // Update reference number with actual sale_id
    $refBase = 'R' . str_pad($saleId, 4, '0', STR_PAD_LEFT);
    $actualRefNumber = $paymentMethod === 'cash'
        ? ''
        : $refBase . '-' . $payerLast4;
    $updateRefQuery = "UPDATE sales SET reference_number = ? WHERE sale_id = ?";
    $updateStmt = $conn->prepare($updateRefQuery);
    $updateStmt->bind_param('si', $actualRefNumber, $saleId);
    $updateStmt->execute();

    // ============================================================
    // Insert each cart item into `sale_items` table
    // ============================================================
    $saleItemStmt = $conn->prepare(
        "INSERT INTO sale_items (sale_id, product_id, variant_id, flavor_id, price, quantity, subtotal, branch_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    // Prepare addon statement
    $saleAddonStmt = $conn->prepare(
        "INSERT INTO sale_addons (sale_item_id, addon_id, price, branch_id)
         VALUES (?, ?, ?, ?)"
    );

    $variantGroups = []; // For inventory deduction by variant

    foreach ($cartItems as $item) {
        $productId = (int)($item['productID'] ?? 0);
        
        // Handle variant_id - can come as 'variantId', 'variant_id', 'sizeID' (legacy)
        $variantId = null;
        if (isset($item['variantId']) && $item['variantId']) {
            $variantId = (int)$item['variantId'];
        } elseif (isset($item['variant_id']) && $item['variant_id']) {
            $variantId = (int)$item['variant_id'];
        } elseif (isset($item['sizeID']) && $item['sizeID']) {
            // Legacy: map sizeID to variant_id via product_variants
            $sizeId = (int)$item['sizeID'];
            $variantLookup = $conn->prepare(
                "SELECT variant_id FROM product_variants WHERE product_id = ? AND size_label = (SELECT sizeName FROM sizes WHERE sizeID = ? LIMIT 1) LIMIT 1"
            );
            $variantLookup->bind_param('ii', $productId, $sizeId);
            $variantLookup->execute();
            $variantResult = $variantLookup->get_result()->fetch_assoc();
            if ($variantResult) {
                $variantId = (int)$variantResult['variant_id'];
            }
            $variantLookup->close();
        }

        // Handle flavor_id
        $flavorId = null;
        if (isset($item['flavorId']) && $item['flavorId']) {
            $flavorId = (int)$item['flavorId'];
        } elseif (isset($item['flavor_id']) && $item['flavor_id']) {
            $flavorId = (int)$item['flavor_id'];
        }

        $price = (float)($item['unitPrice'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 1);
        $itemSubtotal = $price * $quantity;

        // Insert sale item
        $saleItemStmt->bind_param('iiiddidi', $saleId, $productId, $variantId, $flavorId, $price, $quantity, $itemSubtotal, $userBranchId);
        $saleItemStmt->execute();
        $saleItemId = $conn->insert_id;

        // Track variants for inventory deduction
        if ($variantId) {
            if (!isset($variantGroups[$variantId])) {
                $variantGroups[$variantId] = ['qty' => 0, 'productId' => $productId];
            }
            $variantGroups[$variantId]['qty'] += $quantity;
        }

        // Insert add-ons for this item
        if (!empty($item['addons']) && is_array($item['addons'])) {
            foreach ($item['addons'] as $addon) {
                $addonId = null;
                $addonPrice = 0.0;

                if (is_array($addon)) {
                    $addonId = (int)($addon['addon_id'] ?? $addon['addonId'] ?? $addon['id'] ?? 0);
                    $addonPrice = (float)($addon['price'] ?? 0);
                } elseif (is_numeric($addon)) {
                    $addonId = (int)$addon;
                    // Lookup addon price
                    $addonLookup = $conn->prepare("SELECT price FROM addons WHERE addon_id = ?");
                    $addonLookup->bind_param('i', $addonId);
                    $addonLookup->execute();
                    $addonResult = $addonLookup->get_result()->fetch_assoc();
                    $addonPrice = (float)($addonResult['price'] ?? 0);
                    $addonLookup->close();
                }

                if ($addonId > 0) {
                    $saleAddonStmt->bind_param('iidi', $saleItemId, $addonId, $addonPrice, $userBranchId);
                    $saleAddonStmt->execute();
                }
            }
        }
    }

    $saleItemStmt->close();
    $saleAddonStmt->close();

    // ============================================================
    // Inventory deduction (using variant-aware logic)
    // ============================================================
    
    // Cup deduction based on variants
    foreach ($variantGroups as $variantId => $group) {
        // Get variant size_label to find cup inventory
        $variantStmt = $conn->prepare("SELECT size_label FROM product_variants WHERE variant_id = ?");
        $variantStmt->bind_param('i', $variantId);
        $variantStmt->execute();
        $variantResult = $variantStmt->get_result()->fetch_assoc();
        $variantStmt->close();

        if ($variantResult && $variantResult['size_label']) {
            $sizeLabel = $variantResult['size_label'];
            // Extract numeric size (e.g., "16oz" -> 16)
            preg_match('/(\d+)/', $sizeLabel, $matches);
            if (!empty($matches[1])) {
                $cupName = "{$matches[1]}oz Cup";
                $invStmt = $conn->prepare("SELECT inventoryID, `Current_Stock`, `Cost_Price` FROM inventory WHERE `InventoryName` = ?" . ($userBranchId !== null ? " AND branch_id = ?" : "") . " FOR UPDATE");
                if ($userBranchId !== null) {
                    $invStmt->bind_param('si', $cupName, $userBranchId);
                } else {
                    $invStmt->bind_param('s', $cupName);
                }
                $invStmt->execute();
                $invResult = $invStmt->get_result()->fetch_assoc();
                $invStmt->close();

                if ($invResult) {
                    $currentStock = (float)$invResult['Current_Stock'];
                    if ($currentStock < $group['qty']) {
                        throw new Exception("Insufficient inventory for {$cupName}: need {$group['qty']}, have {$currentStock}");
                    }
                    $newStock = $currentStock - $group['qty'];
                    $newValue = $newStock * (float)$invResult['Cost_Price'];
                    $updateStmt = $conn->prepare("UPDATE inventory SET `Current_Stock` = ?, `Total_Value` = ? WHERE inventoryID = ?");
                    $updateStmt->bind_param('ddi', $newStock, $newValue, $invResult['inventoryID']);
                    $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    error_log("Warning: Cup inventory '{$cupName}' not found for branch {$userBranchId}");
                }
            }
        }
    }

    // Ingredient deductions and cost calculation
    if (!empty($ingredientUsage)) {
        $inventoryRows = $recipeHelper->fetchInventoryRows($conn, array_keys($ingredientUsage), true);
        $mappedUsage = $recipeHelper->mapUsageToInventory($ingredientUsage, $inventoryRows);

        // Calculate base ingredient cost
        $calculatedCost = 0.0;
        if (!empty($mappedUsage)) {
            $calculatedCost = $recipeHelper->calculateCost($mappedUsage);
        }

        // Load production cost overrides
        $allOverrides = load_production_cost_overrides_from_db($conn);
        $ICE_INVENTORY_ID = 59;

        $calculatedIceCost = 0.0;
        if (isset($mappedUsage[$ICE_INVENTORY_ID])) {
            $calculatedIceCost = $mappedUsage[$ICE_INVENTORY_ID]['fraction'] * $mappedUsage[$ICE_INVENTORY_ID]['cost_price'];
        }

        // Check which products use ice
        $productsWithIce = [];
        $recipeCheckQuery = $conn->query("SELECT DISTINCT productID FROM recipes WHERE inventoryID = $ICE_INVENTORY_ID");
        if ($recipeCheckQuery) {
            while ($row = $recipeCheckQuery->fetch_assoc()) {
                $productsWithIce[(int)$row['productID']] = true;
            }
            $recipeCheckQuery->free();
        }

        // Calculate total ice cost from overrides
        $totalIceCostFromOverrides = 0.0;
        foreach ($cartItems as $item) {
            $productID = (int)($item['productID'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 1);
            if ($productID <= 0) continue;

            $iceCostPerProduct = 0.0;
            $productUsesIce = isset($productsWithIce[$productID]);

            if (isset($allOverrides[$productID])) {
                foreach ($allOverrides[$productID] as $override) {
                    $overrideInventoryID = (int)($override['inventoryID'] ?? 0);
                    if ($overrideInventoryID === $ICE_INVENTORY_ID) {
                        if (isset($override['removed']) && $override['removed']) {
                            $productUsesIce = false;
                            break;
                        }
                        $productUsesIce = true;
                        $iceCostPerProduct = (isset($override['ingredientCost']) && (float)$override['ingredientCost'] > 0)
                            ? (float)$override['ingredientCost'] : 2.00;
                        break;
                    }
                }
            }

            if ($productUsesIce && $iceCostPerProduct == 0.0) {
                $iceCostPerProduct = 2.00;
            }

            if ($productUsesIce) {
                $totalIceCostFromOverrides += $iceCostPerProduct * $quantity;
            }
        }

        $iceCostAdjustment = $totalIceCostFromOverrides - $calculatedIceCost;
        $orderIngredientCost = round($calculatedCost + $iceCostAdjustment, 2);

        // Log missing ingredients (but don't fail checkout)
        $missingIngredients = [];
        foreach ($ingredientUsage as $inventoryID => $_) {
            if (!isset($mappedUsage[$inventoryID])) {
                $missingIngredients[] = $inventoryID;
            }
        }
        if (!empty($missingIngredients)) {
            error_log("Warning: Ingredients not in inventory: " . implode(', ', $missingIngredients));
        }

        // Deduct stock for ingredients that exist
        foreach ($mappedUsage as $inventoryID => $data) {
            $fractionNeeded = $data['fraction'] ?? 0;
            if ($fractionNeeded <= 0) continue;

            $currentStock = $data['current_stock'] ?? 0;
            if ($currentStock + 1e-6 < $fractionNeeded) {
                throw new Exception("Insufficient stock for inventory ID {$inventoryID} (needs {$fractionNeeded}, has {$currentStock})");
            }

            $newStock = $currentStock - $fractionNeeded;
            $costPrice = $data['cost_price'] ?? 0;
            $newValue = $newStock * $costPrice;

            $updateStmt = $conn->prepare("UPDATE inventory SET `Current_Stock` = ?, `Total_Value` = ? WHERE inventoryID = ?");
            $updateStmt->bind_param('ddi', $newStock, $newValue, $inventoryID);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }

    // Update sale with ingredient cost
    $totalProfit = $totalAmount - $orderIngredientCost;
    $costStmt = $conn->prepare("UPDATE sales SET ingredient_cost = ? WHERE sale_id = ?");
    $roundedCost = round($orderIngredientCost, 2);
    $costStmt->bind_param('di', $roundedCost, $saleId);
    $costStmt->execute();
    $costStmt->close();

    // Log audit activity
    if (file_exists(__DIR__ . '/audit_log.php')) {
        require_once __DIR__ . '/audit_log.php';
        logOrderActivity($conn, $_SESSION['userID'], 'sale_completed', "Sale ID: $saleId, Total: $totalAmount");
    }

    $conn->commit();

    echo json_encode([
        'status' => 'success',
        'saleId' => $saleId,
        'orderID' => $saleId, // For backward compatibility with frontend
        'totalAmount' => $totalAmount,
        'totalProfit' => round($totalProfit, 2),
        'cashTendered' => $cashTenderedValue,
        'changeAmount' => $changeAmountValue,
        'receipt' => [
            'saleId' => $saleId,
            'orderID' => $saleId,
            'items' => $cartItems,
            'subtotal' => $subtotal,
            'totalAmount' => $totalAmount,
            'totalProfit' => round($totalProfit, 2),
            'paymentMethod' => $paymentMethod,
            'cashTendered' => $cashTenderedValue,
            'changeAmount' => $changeAmountValue,
            'referenceNumber' => $actualRefNumber,
            'paymentProvider' => $paymentProvider,
            'receiverNumber' => $receiverNumber,
            'payerLast4' => $payerLast4,
            'timestamp'  => date('Y-m-d H:i:s'),
            'branchName' => $branchName
        ]
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
