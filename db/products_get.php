<?php
require_once 'db_connect.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json');

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$sql = 'SELECT
    p.productID,
    p.productName,
    p.categoryID,
    p.unit_type,
    p.isActive,
    c.categoryName,
    pp.sizeID,
    s.sizeName,
    TRIM(s.sizeName) AS sizeLabel,
    pp.price
FROM products p
LEFT JOIN categories c ON p.categoryID = c.categoryID
LEFT JOIN (
    SELECT productID, sizeID, MAX(price) AS price
    FROM product_prices
    WHERE price > 0
    GROUP BY productID, sizeID
) pp ON p.productID = pp.productID
LEFT JOIN sizes s ON pp.sizeID = s.sizeID
WHERE p.isActive = 1' . ($branchId !== null ? ' AND (p.branch_id IS NULL OR p.branch_id = ?)' : '') . '
ORDER BY p.productID, COALESCE(pp.sizeID, 0)';

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(["error" => "SQL prepare failed: " . $conn->error]);
    exit;
}
if ($branchId !== null) {
    $stmt->bind_param('i', $branchId);
}
$stmt->execute();
$res = $stmt->get_result();
 
$products = [];

while ($row = $res->fetch_assoc()) {
    $id = $row['productID'];
    
    if (!isset($products[$id])) {
        $products[$id] = [
            'productID' => $row['productID'],
            'name' => $row['productName'],
            'productName' => $row['productName'],
            'categoryID' => $row['categoryID'],
            'categoryName' => $row['categoryName'],
            'unit_type' => $row['unit_type'] ?? 'piece',
            'isActive' => (int)($row['isActive'] ?? 1),
            'image_url' => 'assest/image/no-image.png',
            'sizes' => [],
            'sizes_map' => []
        ];
    }

    if ($row['sizeID'] !== null && $row['sizeName'] !== null && $row['price'] !== null) {
        $sizeID = (int)$row['sizeID'];
        $products[$id]['sizes_map'][$sizeID] = [
            'sizeID' => $sizeID,
            'size_label' => $row['sizeLabel'] ?? $row['sizeName'],
            'sizeName' => $row['sizeName'],
            'price' => floatval($row['price'])
        ];
    }
}

foreach ($products as &$product) {
    if (isset($product['sizes_map'])) {
        ksort($product['sizes_map']);
        $product['sizes'] = array_values($product['sizes_map']);
        unset($product['sizes_map']);
    }
}
unset($product);

$arr = array_values($products);

echo json_encode(["products" => $arr, "status" => "ok"]);
$conn->close(); 
?>
