<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

try {
    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    // Super admin (null branch_id) can filter via ?branch_filter=
    if ($branchId === null && isset($_GET['branch_filter']) && $_GET['branch_filter'] !== '') {
        $bf = (int) $_GET['branch_filter'];
        if ($bf > 0) $branchId = $bf;
    }

    // FILTER/SORT parameters from query
    $filterType = isset($_GET['type']) ? trim($_GET['type']) : '';
    $filterDate = isset($_GET['date']) ? trim($_GET['date']) : '';
    $startDate = $_GET['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? null;
    $limit = intval($_GET['limit'] ?? 50);

    // Build WHERE conditions
    $where = [];
    $params = [];

    if ($branchId !== null) {
        $where[] = 's.branch_id = ?';
        $params[] = $branchId;
    }
    
    if ($filterType !== '' && strtolower($filterType) !== 'all') {
        $where[] = 's.status = ?';
        $params[] = $filterType;
    }
    
    // Date range filter
    $date_field = 's.sale_datetime';
    if ($startDate || $endDate) {
        if ($startDate && $endDate) {
            $where[] = "DATE($date_field) BETWEEN ? AND ?";
            $params[] = $startDate;
            $params[] = $endDate;
        } else {
            $where_cond = ($startDate) ?
                "DATE($date_field) >= ?" :
                "DATE($date_field) <= ?";
            $where[] = $where_cond;
            $params[] = $startDate ?? $endDate;
        }
    } elseif ($filterDate !== '') {
        $where[] = 'DATE(s.sale_datetime) = ?';
        $params[] = $filterDate;
    }

    $where_clause = implode(' AND ', $where);

    // Query from NEW sales table
    $query_sql = "
        SELECT
            s.sale_id,
            s.total_amount,
            s.payment_method,
            s.cash_tendered,
            s.change_amount,
            s.status,
            s.sale_datetime as order_date,
            s.reference_number,
            s.ingredient_cost,
            s.voided_at,
            s.void_reason,
            s.voided_by,
            u.employee_id as cashier_id,
            u.username as cashier_name,
            b.branch_name
        FROM sales s
        LEFT JOIN users u ON u.userID = s.user_id
        LEFT JOIN branches b ON b.branch_id = s.branch_id
        " . ($where_clause ? "WHERE $where_clause" : '') . "
        ORDER BY s.sale_datetime DESC
        LIMIT ?
    ";
    $params[] = $limit;
    
    $stmt = $conn->prepare($query_sql);
    if ($stmt === false) throw new Exception("Prepare failed: " . $conn->error);
    
    $types = str_repeat('s', count($params) - 1) . 'i';
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $transactions = [];
    
    while ($row = $result->fetch_assoc()) {
        $saleId = (int)$row['sale_id'];
        
        // Get sale items for this sale
        $itemsSql = "
            SELECT 
                si.sale_item_id,
                si.quantity,
                si.price,
                si.subtotal,
                p.productName,
                pv.variant_name,
                pv.size_label,
                f.flavor_name
            FROM sale_items si
            LEFT JOIN products p ON p.productID = si.product_id
            LEFT JOIN product_variants pv ON pv.variant_id = si.variant_id
            LEFT JOIN flavors f ON f.flavor_id = si.flavor_id
            WHERE si.sale_id = ?
        ";
        $itemsStmt = $conn->prepare($itemsSql);
        $itemsStmt->bind_param('i', $saleId);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        
        $itemLines = [];
        while ($itemRow = $itemsResult->fetch_assoc()) {
            $qty = (int)$itemRow['quantity'];
            $productName = $itemRow['productName'] ?? 'Unknown Product';
            $line = "{$qty}x {$productName}";
            
            // Add variant/size info
            if ($itemRow['variant_name'] || $itemRow['size_label']) {
                $sizeInfo = $itemRow['size_label'] ?? $itemRow['variant_name'];
                $line .= " ({$sizeInfo})";
            }
            
            // Add flavor info
            if ($itemRow['flavor_name']) {
                $line .= " [{$itemRow['flavor_name']}]";
            }
            
            // Get add-ons for this item
            $addonSql = "
                SELECT a.addon_name, sa.price
                FROM sale_addons sa
                LEFT JOIN addons a ON a.addon_id = sa.addon_id
                WHERE sa.sale_item_id = ?
            ";
            $addonStmt = $conn->prepare($addonSql);
            $addonStmt->bind_param('i', $itemRow['sale_item_id']);
            $addonStmt->execute();
            $addonResult = $addonStmt->get_result();
            
            $addonNames = [];
            while ($addonRow = $addonResult->fetch_assoc()) {
                if ($addonRow['addon_name']) {
                    $addonNames[] = $addonRow['addon_name'];
                }
            }
            $addonStmt->close();
            
            if (!empty($addonNames)) {
                $line .= ' [+ ' . implode(', ', $addonNames) . ']';
            }
            
            $itemLines[] = $line;
        }
        $itemsStmt->close();

        if (empty($itemLines)) {
            $itemLines[] = 'No items';
        }

        $transactions[] = [
            'orderID' => $saleId,  // For backward compatibility
            'sale_id' => $saleId,
            'totalAmount' => floatval($row['total_amount'] ?? 0),
            'order_date' => substr($row['order_date'] ?? '', 0, 19),
            'status' => $row['status'] ?? '',
            'payment_method' => $row['payment_method'] ?? '',
            'cash_tendered' => isset($row['cash_tendered']) ? (float)$row['cash_tendered'] : null,
            'change_amount' => isset($row['change_amount']) ? (float)$row['change_amount'] : null,
            'cashier_id' => $row['cashier_id'] ?? 'manual',
            'cashier_name' => $row['cashier_name'] ?? '',
            'referenceNumber' => $row['reference_number'] ?? '',
            'branch_name' => $row['branch_name'] ?? 'Main Branch',
            'ingredient_cost' => floatval($row['ingredient_cost'] ?? 0),
            'voided_at' => $row['voided_at'] ?? null,
            'void_reason' => $row['void_reason'] ?? null,
            'voided_by' => $row['voided_by'] ? (int)$row['voided_by'] : null,
            'items' => implode("\n", $itemLines),
            'items_list' => $itemLines
        ];
    }
    $stmt->close();

    // Summary data (exclude cancelled/voided sales from totals)
    $summary_params = array_slice($params, 0, -1);
    $summary_where = $where;
    $summary_where[] = "s.status NOT IN ('cancelled', 'pending_void')";
    $summary_clause = implode(' AND ', $summary_where);
    $summary_q = "
        SELECT
            COUNT(s.sale_id) as transaction_count,
            COALESCE(SUM(s.total_amount), 0) as total_revenue
        FROM sales s
        " . ($summary_clause ? "WHERE $summary_clause" : '') . "
    ";
    $summary_stmt = $conn->prepare($summary_q);
    if ($summary_stmt === false) throw new Exception("Summary prepare failed: " . $conn->error);
    
    if (!empty($summary_params)) {
        $param_types = str_repeat('s', count($summary_params));
        $summary_stmt->bind_param($param_types, ...$summary_params);
    }
    $summary_stmt->execute();
    $summary = $summary_stmt->get_result()->fetch_array();
    $summary_stmt->close();
    
    echo json_encode([
        'status' => 'ok',
        'transactions' => $transactions,
        'count' => intval($summary[0] ?? 0),
        'total_revenue' => floatval($summary[1] ?? 0)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
