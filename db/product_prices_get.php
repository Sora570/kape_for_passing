<?php
/**
 * Get product prices by productID
 * Returns pricing information with size_label instead of sizeID
 */

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json');

$productID = isset($_GET['productID']) ? intval($_GET['productID']) : 0;

if ($productID <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            pp.productID,
            pp.size_label,
            pp.unit_id,
            pp.price,
            pu.unit_name,
            pu.unit_symbol
        FROM product_prices pp
        LEFT JOIN product_units pu ON pp.unit_id = pu.unit_id
        WHERE pp.productID = ?
        ORDER BY pp.size_label, pp.unit_id
    ");
    
    $stmt->bind_param("i", $productID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $prices = [];
    while ($row = $result->fetch_assoc()) {
        $prices[] = [
            'productID' => (int)$row['productID'],
            'size_label' => $row['size_label'],
            'unit_id' => (int)$row['unit_id'],
            'unit_name' => $row['unit_name'],
            'unit_symbol' => $row['unit_symbol'],
            'price' => (float)$row['price']
        ];
    }
    
    echo json_encode($prices);
    
} catch (Exception $e) {
    error_log("Error in product_prices_get.php: " . $e->getMessage());
    echo json_encode([]);
}

$conn->close();
?>
