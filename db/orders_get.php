<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

// Check if user is logged in
if (!isset($_SESSION['userID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

/**
 * Build machine-readable items array for a sale (for close-out aggregation)
 * Returns array of { productID, productName, quantity, unitPrice, sizeLabel, variant_id, flavor_name, addons: [{ addon_name, price }] }
 */
function getSaleItemsArray($saleId, $conn) {
    $sql = "
        SELECT 
            si.sale_item_id,
            si.product_id AS productID,
            si.quantity,
            si.price AS unitPrice,
            si.variant_id,
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
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $saleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $addons = [];
        $addonStmt = $conn->prepare("SELECT a.addon_name, sa.price FROM sale_addons sa LEFT JOIN addons a ON a.addon_id = sa.addon_id WHERE sa.sale_item_id = ?");
        $addonStmt->bind_param('i', $row['sale_item_id']);
        $addonStmt->execute();
        $addonRes = $addonStmt->get_result();
        while ($ar = $addonRes->fetch_assoc()) {
            $addons[] = [
                'addon_name' => $ar['addon_name'] ?? '',
                'price' => (float)($ar['price'] ?? 0)
            ];
        }
        $addonStmt->close();
        $sizeLabel = $row['size_label'] ?? $row['variant_name'] ?? '';
        $items[] = [
            'productID' => (int)($row['productID'] ?? 0),
            'productName' => $row['productName'] ?? 'Unknown Product',
            'quantity' => (int)($row['quantity'] ?? 1),
            'unitPrice' => (float)($row['unitPrice'] ?? 0),
            'sizeLabel' => $sizeLabel,
            'sizeID' => $row['variant_id'] ? (int)$row['variant_id'] : null,
            'variant_id' => $row['variant_id'] ? (int)$row['variant_id'] : null,
            'flavor_name' => $row['flavor_name'] ?? '',
            'addons' => $addons
        ];
    }
    $stmt->close();
    return $items;
}

/**
 * Format sale items into a readable HTML string
 */
function formatSaleItems($saleId, $conn) {
    $sql = "
        SELECT 
            si.quantity,
            si.price,
            p.productName,
            pv.variant_name,
            pv.size_label,
            f.flavor_name,
            si.sale_item_id
        FROM sale_items si
        LEFT JOIN products p ON p.productID = si.product_id
        LEFT JOIN product_variants pv ON pv.variant_id = si.variant_id
        LEFT JOIN flavors f ON f.flavor_id = si.flavor_id
        WHERE si.sale_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $saleId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $lines = [];
    while ($row = $result->fetch_assoc()) {
        $qty = (int)$row['quantity'];
        $price = number_format((float)$row['price'], 2);
        $productName = $row['productName'] ?? 'Unknown Product';
        
        $line = "{$qty}x {$productName}";
        
        // Add size/variant
        if ($row['size_label'] || $row['variant_name']) {
            $size = $row['size_label'] ?? $row['variant_name'];
            $line .= " ({$size})";
        }
        
        // Add flavor
        if ($row['flavor_name']) {
            $line .= " [{$row['flavor_name']}]";
        }
        
        $line .= " @ P{$price}";
        
        // Get add-ons
        $addonSql = "SELECT a.addon_name FROM sale_addons sa 
                     LEFT JOIN addons a ON a.addon_id = sa.addon_id 
                     WHERE sa.sale_item_id = ?";
        $addonStmt = $conn->prepare($addonSql);
        $addonStmt->bind_param('i', $row['sale_item_id']);
        $addonStmt->execute();
        $addonResult = $addonStmt->get_result();
        
        $addons = [];
        while ($addonRow = $addonResult->fetch_assoc()) {
            if ($addonRow['addon_name']) {
                $addons[] = $addonRow['addon_name'];
            }
        }
        $addonStmt->close();
        
        if (!empty($addons)) {
            $line .= ' +' . implode(', +', $addons);
        }
        
        $lines[] = $line;
    }
    $stmt->close();
    
    return empty($lines) ? 'No items found.' : implode("<br>", $lines);
}

try {
    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    // Query from NEW sales table (scope by branch when user has branch_id)
    $query = "SELECT
                s.sale_id,
                s.reference_number,
                s.payment_method,
                s.cash_tendered,
                s.change_amount,
                s.total_amount,
                s.status,
                s.sale_datetime AS created_at,
                s.ingredient_cost,
                s.voided_at,
                s.void_reason,
                s.voided_by,
                b.branch_name
            FROM
                sales s
            LEFT JOIN branches b ON b.branch_id = s.branch_id
            " . ($branchId !== null ? "WHERE s.branch_id = ?" : "") . "
            ORDER BY
                s.sale_datetime DESC";

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception('SQL Prepare Failed: ' . $conn->error);
    }

    if ($branchId !== null) {
        $stmt->bind_param('i', $branchId);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception('SQL Execution Failed: ' . $stmt->error);
    }

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $saleId = (int)$row['sale_id'];
        
        $orders[] = [
            'orderID' => $saleId,  // Backward compatibility
            'sale_id' => $saleId,
            'items' => formatSaleItems($saleId, $conn),
            'items_array' => getSaleItemsArray($saleId, $conn),
            'totalAmount' => floatval($row['total_amount'] ?? 0),
            'status' => $row['status'] ?? 'completed',
            'created_at' => $row['created_at'],
            'referenceNumber' => $row['reference_number'] ?? '',
            'paymentMethod' => $row['payment_method'] ?? '',
            'cash_tendered' => isset($row['cash_tendered']) ? (float)$row['cash_tendered'] : null,
            'change_amount' => isset($row['change_amount']) ? (float)$row['change_amount'] : null,
            'branch_name' => $row['branch_name'] ?? 'Main Branch',
            'ingredient_cost' => floatval($row['ingredient_cost'] ?? 0),
            'voided_at' => $row['voided_at'] ?? null,
            'void_reason' => $row['void_reason'] ?? null,
            'voided_by' => $row['voided_by'] ? (int)$row['voided_by'] : null
        ];
    }
    $stmt->close();

    // Return with status filtering for backward compatibility
    echo json_encode([
        'status' => 'success',
        'pending' => array_values(array_filter($orders, fn($o) => strtolower($o['status']) === 'pending')),
        'pending_void' => array_values(array_filter($orders, fn($o) => strtolower($o['status']) === 'pending_void')),
        'completed' => array_values(array_filter($orders, fn($o) => strtolower($o['status']) === 'completed')),
        'cancelled' => array_values(array_filter($orders, fn($o) => strtolower($o['status']) === 'cancelled'))
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Server Error: ' . $e->getMessage()]);
}

if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
