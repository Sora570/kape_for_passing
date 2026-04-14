<?php
header('Content-Type: application/json');

// Include database connections 
require_once 'db_connect.php';
require_once __DIR__ . '/../lib/RecipeHelper.php';
require_once __DIR__ . '/inventory_usage_helpers.php';

$recipeHelper = new RecipeHelper($conn);
$branchIdForScope = null; // set in try block from session; branch-scoped when non-null

/**
 * Get top 5 products sold today from NEW sales schema
 */
function get_top_5_products(){
    try{
        global $conn, $branchIdForScope;
        $statusClause = "(s.status IS NULL OR LOWER(s.status) IN ('completed','delivered','paid'))";
        $branchClause = ($branchIdForScope !== null) ? " AND s.branch_id = ?" : "";

        $query = "
            SELECT 
                si.product_id,
                p.productName,
                SUM(si.quantity) AS total_quantity,
                COUNT(DISTINCT si.sale_id) AS order_count
            FROM sale_items si
            INNER JOIN sales s ON s.sale_id = si.sale_id
            LEFT JOIN products p ON p.productID = si.product_id
            WHERE DATE(s.sale_datetime) = CURDATE()
              AND {$statusClause}{$branchClause}
            GROUP BY si.product_id, p.productName
            ORDER BY total_quantity DESC, order_count DESC
            LIMIT 5
        ";

        $stmt = $conn->prepare($query);
        if ($stmt === false) {
            throw new Exception("Prepare failed: ".$conn->error);
        }
        if ($branchIdForScope !== null) {
            $stmt->bind_param('i', $branchIdForScope);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'name' => $row['productName'] ?? 'Unknown Product',
                'quantity' => (int)($row['total_quantity'] ?? 0),
                'count' => (int)($row['order_count'] ?? 0)
            ];
        }

        $stmt->close();
        return $products;

    } catch(Exception $e){
        error_log("Analytics Error: ".$e->getMessage());
        return [];
    }
}

/**
 * Get today's total sales from NEW sales table
 */
function get_today_sales_total(){
    try{
        global $conn, $branchIdForScope;
        $branchClause = ($branchIdForScope !== null) ? " AND s.branch_id = ?" : "";

        $query = "
            SELECT 
                COALESCE(SUM(s.total_amount), 0) AS today_total
            FROM sales s
            WHERE DATE(s.sale_datetime) = CURDATE()
              AND (s.status IS NULL OR LOWER(s.status) IN ('completed','delivered','paid')){$branchClause}
        ";

        $stmt = $conn->prepare($query);
        if ($stmt === false) {
            throw new Exception("Prepare failed: ".$conn->error);
        }
        if ($branchIdForScope !== null) {
            $stmt->bind_param('i', $branchIdForScope);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        return round(floatval($data['today_total'] ?? 0), 2);

    } catch(Exception $e){
        error_log("Analytics Error: ".$e->getMessage());
        return 0;
    }
}

/**
 * Get today's order (sale) count from NEW sales table
 */
function get_today_orders_count(){
    try{
        global $conn, $branchIdForScope;
        $branchClause = ($branchIdForScope !== null) ? " AND s.branch_id = ?" : "";
        $query = "
            SELECT COUNT(*) AS orders_count
            FROM sales s
            WHERE DATE(s.sale_datetime) = CURDATE()
              AND (s.status IS NULL OR LOWER(s.status) IN ('completed','delivered','paid')){$branchClause}
        ";

        $stmt = $conn->prepare($query);
        if ($stmt === false) {
            throw new Exception("Prepare failed: ".$conn->error);
        }
        if ($branchIdForScope !== null) {
            $stmt->bind_param('i', $branchIdForScope);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();

        return intval($data['orders_count'] ?? 0);

    } catch(Exception $e){
        error_log("Analytics Error: ".$e->getMessage());
        return 0;
    }
}

/**
 * Get today's total expenses (ingredient cost) from NEW sales table
 */
function get_today_expenses_total(){
    try{
        global $conn, $recipeHelper, $branchIdForScope;
        $statusClause = "(s.status IS NULL OR LOWER(s.status) IN ('completed','delivered','paid'))";
        $branchClause = ($branchIdForScope !== null) ? " AND s.branch_id = ?" : "";

        $query = "
            SELECT 
                s.sale_id,
                s.ingredient_cost
            FROM sales s
            WHERE DATE(s.sale_datetime) = CURDATE()
              AND {$statusClause}{$branchClause}
        ";

        $stmt = $conn->prepare($query);
        if ($stmt === false) {
            throw new Exception("Prepare failed: ".$conn->error);
        }
        if ($branchIdForScope !== null) {
            $stmt->bind_param('i', $branchIdForScope);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $total = 0.0;
        $salesNeedingRecalculation = [];

        while ($row = $result->fetch_assoc()) {
            $cost = floatval($row['ingredient_cost'] ?? 0);
            if ($cost > 0) {
                $total += $cost;
            } else {
                // Need to recalculate - get items for this sale
                $salesNeedingRecalculation[] = (int)$row['sale_id'];
            }
        }
        $stmt->close();

        // Recalculate costs for sales missing ingredient_cost
        if (!empty($salesNeedingRecalculation)) {
            // Get all items for sales needing recalculation
            $saleIdsStr = implode(',', $salesNeedingRecalculation);
            $itemsQuery = "
                SELECT si.sale_id, si.product_id, si.variant_id, si.quantity
                FROM sale_items si
                WHERE si.sale_id IN ({$saleIdsStr})
            ";
            $itemsResult = $conn->query($itemsQuery);
            
            if ($itemsResult) {
                // Group items by sale
                $saleItems = [];
                while ($itemRow = $itemsResult->fetch_assoc()) {
                    $saleId = (int)$itemRow['sale_id'];
                    if (!isset($saleItems[$saleId])) {
                        $saleItems[$saleId] = [];
                    }
                    $saleItems[$saleId][] = [
                        'productID' => (int)$itemRow['product_id'],
                        'variantId' => $itemRow['variant_id'] ? (int)$itemRow['variant_id'] : null,
                        'quantity' => (int)$itemRow['quantity']
                    ];
                }
                $itemsResult->close();

                // Load production cost overrides
                $allOverrides = load_production_cost_overrides_from_db($conn);
                $ICE_INVENTORY_ID = 59;
                
                // Products with ice
                $productsWithIce = [];
                $recipeCheckQuery = $conn->query("SELECT DISTINCT productID FROM recipes WHERE inventoryID = $ICE_INVENTORY_ID");
                if ($recipeCheckQuery) {
                    while ($recipeRow = $recipeCheckQuery->fetch_assoc()) {
                        $productsWithIce[(int)$recipeRow['productID']] = true;
                    }
                    $recipeCheckQuery->free();
                }

                // Calculate cost for each sale
                foreach ($saleItems as $saleId => $items) {
                    $usage = $recipeHelper->aggregateUsage($items);
                    
                    if (!empty($usage)) {
                        $inventoryRows = $recipeHelper->fetchInventoryRows($conn, array_keys($usage));
                        $mapped = $recipeHelper->mapUsageToInventory($usage, $inventoryRows);
                        
                        $calculatedCost = 0.0;
                        if (!empty($mapped)) {
                            $calculatedCost = $recipeHelper->calculateCost($mapped);
                        }
                        
                        // Calculate ice adjustments
                        $calculatedIceCost = 0.0;
                        if (isset($mapped[$ICE_INVENTORY_ID])) {
                            $calculatedIceCost = $mapped[$ICE_INVENTORY_ID]['fraction'] * $mapped[$ICE_INVENTORY_ID]['cost_price'];
                        }
                        
                        $totalIceCostFromOverrides = 0.0;
                        foreach ($items as $item) {
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
                        $saleCost = round($calculatedCost + $iceCostAdjustment, 2);
                        $total += $saleCost;
                    }
                }
            }
        }

        return round($total, 2);
    } catch(Exception $e){
        error_log("Analytics Expense Error: ".$e->getMessage());
        return 0.0;
    }
}

/**
 * Get daily sales chart data for last 7 days from NEW sales schema
 */
function get_daily_sales_chart_data(){
    try{
        global $conn, $branchIdForScope;

        $statusClause = "(s.status IS NULL OR LOWER(s.status) IN ('completed','delivered','paid'))";
        $branchClause = ($branchIdForScope !== null) ? " AND s.branch_id = ?" : "";
        $startDate = date('Y-m-d', strtotime('-6 days'));

        $query = "
            SELECT 
                DATE(s.sale_datetime) AS sale_date,
                DATE_FORMAT(s.sale_datetime, '%b %d') AS date_label,
                s.total_amount AS sale_total,
                s.sale_id
            FROM sales s
            WHERE DATE(s.sale_datetime) >= ?
              AND {$statusClause}{$branchClause}
            ORDER BY sale_date ASC, s.sale_datetime ASC
        ";

        $stmt = $conn->prepare($query);
        if ($stmt === false) {
            throw new Exception("Prepare failed: ".$conn->error);
        }
        if ($branchIdForScope !== null) {
            $stmt->bind_param('si', $startDate, $branchIdForScope);
        } else {
            $stmt->bind_param('s', $startDate);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $saleIds = [];
        $aggregated = [];

        while ($row = $result->fetch_assoc()) {
            $dateKey = $row['sale_date'];
            $saleId = (int)$row['sale_id'];
            if (!$dateKey) continue;

            if (!isset($aggregated[$dateKey])) {
                $aggregated[$dateKey] = [
                    'date' => $dateKey,
                    'date_label' => $row['date_label'] ?? date('M d', strtotime($dateKey)),
                    'orders' => 0,
                    'revenue' => 0.0,
                    'daily_items_sold' => 0
                ];
            }

            $aggregated[$dateKey]['orders'] += 1;
            $aggregated[$dateKey]['revenue'] += floatval($row['sale_total'] ?? 0);
            $saleIds[$dateKey][] = $saleId;
        }
        $stmt->close();

        // Get item counts for each day
        foreach ($saleIds as $dateKey => $ids) {
            if (empty($ids)) continue;
            
            $idsStr = implode(',', $ids);
            $itemsQuery = "SELECT SUM(quantity) AS total_qty FROM sale_items WHERE sale_id IN ({$idsStr})";
            $itemsResult = $conn->query($itemsQuery);
            if ($itemsResult) {
                $itemRow = $itemsResult->fetch_assoc();
                $aggregated[$dateKey]['daily_items_sold'] = (int)($itemRow['total_qty'] ?? 0);
                $itemsResult->close();
            }
        }

        // Build chart data for last 7 days
        $chartData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            if (isset($aggregated[$date])) {
                $entry = $aggregated[$date];
                $entry['revenue'] = round($entry['revenue'], 2);
                $chartData[] = $entry;
            } else {
                $chartData[] = [
                    'date' => $date,
                    'date_label' => date('M d', strtotime($date)),
                    'orders' => 0,
                    'revenue' => 0.00,
                    'daily_items_sold' => 0
                ];
            }
        }

        return $chartData;

    } catch(Exception $e){
        error_log("Analytics Error: ".$e->getMessage());
        return array_map(function($i) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            return [
                'date' => $date,
                'date_label' => date('M d', strtotime($date)),
                'orders' => 0,
                'revenue' => 0.00,
                'daily_items_sold' => 0
            ];
        }, range(6,0));
    }
}

try {
    // #region agent log
    require_once __DIR__ . '/session_config.php';
    require_once __DIR__ . '/auth_check.php';
    // Ensure user is logged in and admin
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception("Unauthorized access. Admin role required.");
    }

    global $branchIdForScope;
    $branchIdForScope = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    // Super admin (null branch_id) can filter by a specific branch via ?branch_filter=
    if ($branchIdForScope === null && isset($_GET['branch_filter']) && $_GET['branch_filter'] !== '') {
        $bf = (int) $_GET['branch_filter'];
        if ($bf > 0) {
            $branchIdForScope = $bf;
        }
    }

    @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'dashboard_analytics.php', 'message' => 'entry', 'data' => ['action' => $_POST['action'] ?? $_GET['action'] ?? 'all'], 'timestamp' => time() * 1000, 'hypothesisId' => 'A']) . "\n", LOCK_EX | FILE_APPEND);

    $action = $_POST['action'] ?? $_GET['action'] ?? 'all';

    switch($action) {
        case 'top_products':
            echo json_encode(get_top_5_products());
            break;
            
        case 'daily_sales':
            echo json_encode(['daily_sales' => get_today_sales_total()]);
            break;
            
        case 'all':
        default:
            $dailySales = get_today_sales_total();
            $dailyExpenses = get_today_expenses_total();
            echo json_encode([
                'top_products' => get_top_5_products(),
                'daily_sales' => $dailySales,
                'daily_expenses' => $dailyExpenses,
                'daily_profit' => round($dailySales - $dailyExpenses, 2),
                'today_orders' => get_today_orders_count(),
                'chart_data' => get_daily_sales_chart_data()
            ]);
            break;
    }
    // #endregion
} catch (Throwable $e) {
    @file_put_contents(__DIR__ . '/../.cursor/debug.log', json_encode(['location' => 'dashboard_analytics.php', 'message' => 'exception', 'data' => ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()], 'timestamp' => time() * 1000, 'hypothesisId' => 'A']) . "\n", LOCK_EX | FILE_APPEND);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}
?>
