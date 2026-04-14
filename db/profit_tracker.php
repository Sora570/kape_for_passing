<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/../lib/RecipeHelper.php';
require_once __DIR__ . '/inventory_usage_helpers.php';

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

// Super admin (null branch_id) can filter via ?branch_filter=
if ($branchId === null && isset($_GET['branch_filter']) && $_GET['branch_filter'] !== '') {
    $bf = (int) $_GET['branch_filter'];
    if ($bf > 0) $branchId = $bf;
}

$recipeHelper = new RecipeHelper($conn, $branchId);

try {
    require_once __DIR__ . '/session_config.php';
    require_once __DIR__ . '/auth_check.php';
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception('Unauthorized access. Admin role required.');
    }

    $statusClause = "(s.status IS NULL OR LOWER(s.status) IN ('completed','delivered','paid')) AND LOWER(s.status) != 'cancelled'";
    $branchClause = ($branchId !== null) ? " AND s.branch_id = ?" : "";

    $currentMonth = new DateTimeImmutable('first day of this month 00:00:00');
    $monthBuckets = [];

    for ($i = 11; $i >= 0; $i--) {
        $month = $currentMonth->modify("-{$i} months");
        $key = $month->format('Y-m');
        $monthBuckets[$key] = [
            'key' => $key,
            'label' => $month->format('M Y'),
            'revenue' => 0.0,
            'ingredient_cost' => 0.0,
            'other_expenses' => 0.0,
            'profit' => 0.0
        ];
    }

    $currentWeekStart = new DateTimeImmutable('monday this week');
    $weekBuckets = [];

    for ($i = 3; $i >= 0; $i--) {
        $start = $currentWeekStart->modify("-{$i} weeks");
        $end = $start->modify('sunday this week 23:59:59');
        $key = $start->format('Y-m-d');
        $weekBuckets[$key] = [
            'key' => $key,
            'label' => $start->format('M d') . ' - ' . $end->format('M d'),
            'start_date' => $start->format('Y-m-d 00:00:00'),
            'end_date' => $end->format('Y-m-d H:i:s'),
            'revenue' => 0.0,
            'ingredient_cost' => 0.0,
            'other_expenses' => 0.0,
            'profit' => 0.0
        ];
    }

    // Last 7 days buckets (for Week tab: show all 7 days)
    $today = new DateTimeImmutable('today 00:00:00');
    $weekDayBuckets = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = $today->modify("-{$i} days");
        $k = $d->format('Y-m-d');
        $weekDayBuckets[$k] = [
            'key' => $k,
            'label' => $d->format('D M d'),
            'start_date' => $d->format('Y-m-d 00:00:00'),
            'end_date' => $d->format('Y-m-d 23:59:59'),
            'revenue' => 0.0,
            'ingredient_cost' => 0.0,
            'other_expenses' => 0.0,
            'profit' => 0.0
        ];
    }

    // Current month day-by-day buckets (for Month tab)
    $monthStart = new DateTimeImmutable('first day of this month 00:00:00');
    $daysInMonth = (int)$monthStart->format('t');
    $monthDayBuckets = [];
    for ($i = 0; $i < $daysInMonth; $i++) {
        $d = $monthStart->modify("+{$i} days");
        $k = $d->format('Y-m-d');
        $monthDayBuckets[$k] = [
            'key' => $k,
            'label' => $d->format('M d'),
            'start_date' => $d->format('Y-m-d 00:00:00'),
            'end_date' => $d->format('Y-m-d 23:59:59'),
            'revenue' => 0.0,
            'ingredient_cost' => 0.0,
            'other_expenses' => 0.0,
            'profit' => 0.0
        ];
    }

    // Today's totals
    $todayKey = $today->format('Y-m-d');
    $todayBucket = [
        'key' => $todayKey,
        'label' => $today->format('M d, Y'),
        'revenue' => 0.0,
        'ingredient_cost' => 0.0,
        'other_expenses' => 0.0,
        'profit' => 0.0
    ];

    $rangeStart = array_key_first($monthBuckets) . '-01 00:00:00';
    $rangeEnd = $currentMonth->modify('last day of this month 23:59:59')->format('Y-m-d H:i:s');

    $sql = "
        SELECT 
            s.sale_id,
            s.total_amount,
            s.ingredient_cost,
            s.sale_datetime
        FROM sales s
        WHERE s.sale_datetime BETWEEN ? AND ?
          AND {$statusClause}{$branchClause}
        ORDER BY s.sale_datetime ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Failed to prepare profit tracker query: ' . $conn->error);
    }
    if ($branchId !== null) {
        $stmt->bind_param('ssi', $rangeStart, $rangeEnd, $branchId);
    } else {
        $stmt->bind_param('ss', $rangeStart, $rangeEnd);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $usageByMonth = [];
    $usageByWeek = [];

    while ($row = $result->fetch_assoc()) {
        $createdAt = $row['sale_datetime'] ?? null;
        if (!$createdAt) {
            continue;
        }
        $monthKey = date('Y-m', strtotime($createdAt));
        if (!isset($monthBuckets[$monthKey])) {
            continue;
        }

        $orderRevenue = floatval($row['total_amount'] ?? 0);
        $monthBuckets[$monthKey]['revenue'] += $orderRevenue;

        $dateKey = date('Y-m-d', strtotime($createdAt));

        foreach ($weekBuckets as $weekKey => &$week) {
            if ($createdAt >= $week['start_date'] && $createdAt <= $week['end_date']) {
                $week['revenue'] += $orderRevenue;
                break;
            }
        }
        unset($week);

        if (isset($weekDayBuckets[$dateKey])) {
            $weekDayBuckets[$dateKey]['revenue'] += $orderRevenue;
        }
        if (isset($monthDayBuckets[$dateKey])) {
            $monthDayBuckets[$dateKey]['revenue'] += $orderRevenue;
        }
        if ($dateKey === $todayKey) {
            $todayBucket['revenue'] += $orderRevenue;
        }

        $finalIngredientCost = null;
        $ingredientCost = floatval($row['ingredient_cost'] ?? 0);
        if ($ingredientCost > 0) {
            $finalIngredientCost = $ingredientCost;
        } else {
            $saleId = (int)($row['sale_id'] ?? 0);
            $summaryItems = [];
            if ($saleId > 0) {
                $itemsStmt = $conn->prepare("SELECT product_id, variant_id, quantity FROM sale_items WHERE sale_id = ?");
                $itemsStmt->bind_param('i', $saleId);
                $itemsStmt->execute();
                $itemsRes = $itemsStmt->get_result();
                while ($itemRow = $itemsRes->fetch_assoc()) {
                    $summaryItems[] = [
                        'productID' => (int)($itemRow['product_id'] ?? 0),
                        'variantId' => $itemRow['variant_id'] ? (int)$itemRow['variant_id'] : null,
                        'quantity' => (int)($itemRow['quantity'] ?? 1)
                    ];
                }
                $itemsStmt->close();
            }
            if (!empty($summaryItems)) {
                // Recalculate cost with ice overrides
                $usage = $recipeHelper->aggregateUsage($summaryItems);
                if (!empty($usage)) {
                    $inventoryRows = $recipeHelper->fetchInventoryRows($conn, array_keys($usage), true);
                    $mapped = $recipeHelper->mapUsageToInventory($usage, $inventoryRows);

                    // Calculate base cost from recipes
                    $calculatedCost = 0.0;
                    if (!empty($mapped)) {
                        $calculatedCost = $recipeHelper->calculateCost($mapped);
                    }

                    // Load production cost overrides to apply ice costs
                    $allOverrides = load_production_cost_overrides_from_db($conn);
                    $ICE_INVENTORY_ID = 59;

                    // Calculate what ice cost was included in the base calculation (if any)
                    $calculatedIceCost = 0.0;
                    if (isset($mapped[$ICE_INVENTORY_ID])) {
                        $calculatedIceCost = $mapped[$ICE_INVENTORY_ID]['fraction'] * $mapped[$ICE_INVENTORY_ID]['cost_price'];
                    }

                    // Load all recipes once to check which products use ice
                    $productsWithIce = [];
                    $recipeCheckQuery = $conn->query("SELECT DISTINCT productID FROM recipes WHERE inventoryID = $ICE_INVENTORY_ID" . ($branchId !== null ? " AND productID IN (SELECT productID FROM products WHERE branch_id IS NULL OR branch_id = " . (int)$branchId . ")" : ""));
                    if ($recipeCheckQuery) {
                        while ($recipeRow = $recipeCheckQuery->fetch_assoc()) {
                            $productsWithIce[(int)$recipeRow['productID']] = true;
                        }
                        $recipeCheckQuery->free();
                    }

                    // Calculate total ice cost from overrides (per product quantity)
                    $totalIceCostFromOverrides = 0.0;
                    foreach ($summaryItems as $item) {
                        $productID = (int)($item['productID'] ?? 0);
                        $quantity = (int)($item['quantity'] ?? 1);

                        if ($productID <= 0) {
                            continue;
                        }

                        $iceCostPerProduct = 0.0;
                        $productUsesIce = false;

                        // Check if product uses ice (in recipe or override)
                        if (isset($productsWithIce[$productID])) {
                            $productUsesIce = true;
                        }

                        // Check if product has ice override
                        if (isset($allOverrides[$productID])) {
                            $overrides = $allOverrides[$productID];
                            foreach ($overrides as $override) {
                                $overrideInventoryID = (int)($override['inventoryID'] ?? 0);
                                // Check if this is ice (inventoryID 59)
                                if ($overrideInventoryID === $ICE_INVENTORY_ID) {
                                    // If ice is marked as removed, don't use it
                                    if (isset($override['removed']) && $override['removed']) {
                                        $productUsesIce = false;
                                        break;
                                    }
                                    // Product uses ice (override exists)
                                    $productUsesIce = true;
                                    // Use override cost if set and > 0, otherwise default to 2.00
                                    $iceCostPerProduct = (isset($override['ingredientCost']) && $override['ingredientCost'] !== null && (float)$override['ingredientCost'] > 0)
                                        ? (float)$override['ingredientCost']
                                        : 2.00;
                                    break; // Found ice override for this product
                                }
                            }
                        }

                        // If product uses ice but no override cost was set, use default 2.00
                        if ($productUsesIce && $iceCostPerProduct == 0.0) {
                            $iceCostPerProduct = 2.00;
                        }

                        // Apply ice cost for this product if it uses ice
                        if ($productUsesIce) {
                            $totalIceCostFromOverrides += $iceCostPerProduct * $quantity;
                        }
                    }

                    // Apply adjustment: use override ice cost instead of calculated
                    $iceCostAdjustment = $totalIceCostFromOverrides - $calculatedIceCost;

                    // Round to 2 decimal places for accuracy
                    $recalculatedCost = round($calculatedCost + $iceCostAdjustment, 2);

                    $finalIngredientCost = $recalculatedCost;
                }
            }
        }

        // Apply ingredient cost (if any was found or calculated)
        if ($finalIngredientCost !== null) {
            $monthBuckets[$monthKey]['ingredient_cost'] += $finalIngredientCost;

            foreach ($weekBuckets as $weekKey => &$week) {
                if ($createdAt >= $week['start_date'] && $createdAt <= $week['end_date']) {
                    $week['ingredient_cost'] += $finalIngredientCost;
                    break;
                }
            }
            unset($week);

            if (isset($weekDayBuckets[$dateKey])) {
                $weekDayBuckets[$dateKey]['ingredient_cost'] += $finalIngredientCost;
            }
            if (isset($monthDayBuckets[$dateKey])) {
                $monthDayBuckets[$dateKey]['ingredient_cost'] += $finalIngredientCost;
            }
            if ($dateKey === $todayKey) {
                $todayBucket['ingredient_cost'] += $finalIngredientCost;
            }
        }
    }

    $stmt->close();

    // Note: Costs are now calculated immediately per order (with ice overrides) 
    // instead of aggregating and calculating later, which ensures accuracy

    $totals = [
        'revenue' => 0.0,
        'ingredient_cost' => 0.0,
        'other_expenses' => 0.0,
        'profit' => 0.0
    ];

    foreach ($monthBuckets as &$bucket) {
        $bucket['revenue'] = round($bucket['revenue'], 2);
        $bucket['ingredient_cost'] = round($bucket['ingredient_cost'], 2);
        $bucket['other_expenses'] = round($bucket['other_expenses'], 2);
        $bucket['profit'] = round(
            $bucket['revenue'] - $bucket['ingredient_cost'] - $bucket['other_expenses'],
            2
        );

        $totals['revenue'] += $bucket['revenue'];
        $totals['ingredient_cost'] += $bucket['ingredient_cost'];
        $totals['other_expenses'] += $bucket['other_expenses'];
        $totals['profit'] += $bucket['profit'];
    }
    unset($bucket);

    foreach ($weekBuckets as &$week) {
        $week['revenue'] = round($week['revenue'], 2);
        $week['ingredient_cost'] = round($week['ingredient_cost'], 2);
        $week['other_expenses'] = round($week['other_expenses'], 2);
        $week['profit'] = round(
            $week['revenue'] - $week['ingredient_cost'] - $week['other_expenses'],
            2
        );
    }
    unset($week);

    // Round daily week buckets (last 7 days)
    foreach ($weekDayBuckets as &$d) {
        $d['revenue'] = round($d['revenue'], 2);
        $d['ingredient_cost'] = round($d['ingredient_cost'], 2);
        $d['other_expenses'] = round($d['other_expenses'], 2);
        $d['profit'] = round($d['revenue'] - $d['ingredient_cost'] - $d['other_expenses'], 2);
    }
    unset($d);

    // Round current month day buckets
    foreach ($monthDayBuckets as &$d) {
        $d['revenue'] = round($d['revenue'], 2);
        $d['ingredient_cost'] = round($d['ingredient_cost'], 2);
        $d['other_expenses'] = round($d['other_expenses'], 2);
        $d['profit'] = round($d['revenue'] - $d['ingredient_cost'] - $d['other_expenses'], 2);
    }
    unset($d);

    // Ensure today's totals are rounded
    $todayBucket['revenue'] = round($todayBucket['revenue'], 2);
    $todayBucket['ingredient_cost'] = round($todayBucket['ingredient_cost'], 2);
    $todayBucket['other_expenses'] = round($todayBucket['other_expenses'], 2);
    $todayBucket['profit'] = round($todayBucket['revenue'] - $todayBucket['ingredient_cost'] - $todayBucket['other_expenses'], 2);

    foreach ($totals as $key => $value) {
        $totals[$key] = round($value, 2);
    }

    echo json_encode([
        'months' => array_values($monthBuckets),
        'weeks' => array_values($weekBuckets),
        'week_days' => array_values($weekDayBuckets),
        'month_days' => array_values($monthDayBuckets),
        'today' => $todayBucket,
        'totals' => $totals,
        'generated_at' => date(DATE_ATOM)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
