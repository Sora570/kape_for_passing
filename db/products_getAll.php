<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

try {
    $includeInactive = !empty($_GET['includeInactive']);
    $includeUnits = !empty($_GET['includeUnits']);
    $statusFilter = isset($_GET['status']) ? strtolower(trim($_GET['status'])) : '';
    $format = isset($_GET['format']) ? $_GET['format'] : 'payload';

    if (in_array($statusFilter, ['active', 'inactive'], true)) {
        $includeInactive = true;
    }


    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    // Query products with new has_* columns
    $sql = "SELECT
                p.productID,
                p.productName,
                p.categoryID,
                p.has_variants,
                p.has_flavors,
                p.has_addons,
                p.base_price,
                c.categoryName,
                p.isActive,
                p.createdAt AS created_at
            FROM products p
            JOIN categories c ON p.categoryID = c.categoryID";

    $conditions = [];
    if (!$includeInactive) {
        $conditions[] = "p.isActive = 1";
    }

    if ($statusFilter === 'active') {
        $conditions[] = "p.isActive = 1";
    } elseif ($statusFilter === 'inactive') {
        $conditions[] = "p.isActive = 0";
    }
    if ($branchId !== null) {
        $conditions[] = "(p.branch_id IS NULL OR p.branch_id = " . (int)$branchId . ")";
    }
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }

    $sql .= " ORDER BY p.categoryID, p.productName";

    $result = $conn->query($sql);
    $products = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $productID = (int) $row['productID'];
            $normalizedSizes = [];

            $variantsSql = "
                SELECT variant_id, variant_name, size_label, price, status
                FROM product_variants
                WHERE product_id = ? AND status = 'active'
                ORDER BY variant_id
            ";
            $stmt = $conn->prepare($variantsSql);
            $stmt->bind_param('i', $productID);
            $stmt->execute();
            $variantsResult = $stmt->get_result();

            while ($variantRow = $variantsResult->fetch_assoc()) {
                $normalizedSizes[] = [
                    'id' => (int) $variantRow['variant_id'],
                    'variant_id' => (int) $variantRow['variant_id'],
                    'name' => trim($variantRow['variant_name'] ?? $variantRow['size_label'] ?? ''),
                    'size_label' => trim($variantRow['size_label'] ?? ''),
                    'price' => (float) $variantRow['price']
                ];
            }
            $stmt->close();

            // Get available add-ons for this product
            $addons = [];
            if ($row['has_addons']) {
                $addonsSql = "
                    SELECT a.addon_id, a.addon_name, a.price
                    FROM product_addons pa
                    JOIN addons a ON a.addon_id = pa.addon_id
                    WHERE pa.product_id = ? AND a.status = 'active'
                ";
                $addonStmt = $conn->prepare($addonsSql);
                $addonStmt->bind_param('i', $productID);
                $addonStmt->execute();
                $addonResult = $addonStmt->get_result();
                while ($addonRow = $addonResult->fetch_assoc()) {
                    $addons[] = [
                        'id' => (int) $addonRow['addon_id'],
                        'name' => $addonRow['addon_name'],
                        'price' => (float) $addonRow['price']
                    ];
                }
                $addonStmt->close();
            }

            // Get available flavors for this product
            $flavors = [];
            if ($row['has_flavors']) {
                $flavorsSql = "
                    SELECT f.flavor_id, f.flavor_name
                    FROM food_flavors ff
                    JOIN flavors f ON f.flavor_id = ff.flavor_id
                    WHERE ff.product_id = ? AND f.status = 'active'
                ";
                $flavorStmt = $conn->prepare($flavorsSql);
                $flavorStmt->bind_param('i', $productID);
                $flavorStmt->execute();
                $flavorResult = $flavorStmt->get_result();
                while ($flavorRow = $flavorResult->fetch_assoc()) {
                    $flavors[] = [
                        'id' => (int) $flavorRow['flavor_id'],
                        'name' => $flavorRow['flavor_name']
                    ];
                }
                $flavorStmt->close();
            }

            $product = [
                'id' => $productID,
                'name' => $row['productName'],
                'productName' => $row['productName'],
                'productID' => $productID,
                'categoryID' => (int) $row['categoryID'],
                'categoryName' => $row['categoryName'],
                'category' => [
                    'id' => (int) $row['categoryID'],
                    'name' => $row['categoryName']
                ],
                'image' => '',
                'isActive' => (bool) $row['isActive'],
                'createdAt' => $row['created_at'],
                'has_variants' => true,
                'has_flavors' => (bool) $row['has_flavors'],
                'has_addons' => (bool) $row['has_addons'],
                'base_price' => null,
                'sizes' => $normalizedSizes,      // Backward compatibility
                'variants' => $normalizedSizes,   // New schema naming
                'addons' => $addons,
                'flavors' => $flavors
            ];

            if ($includeUnits) {
                $unitSql = "SELECT unit_id, unit_name, unit_symbol FROM product_units ORDER BY unit_name";
                $unitRes = $conn->query($unitSql);
                $units = [];
                if ($unitRes) {
                    while ($unitRow = $unitRes->fetch_assoc()) {
                        $units[] = [
                            'id' => (int) $unitRow['unit_id'],
                            'name' => $unitRow['unit_name'],
                            'symbol' => $unitRow['unit_symbol']
                        ];
                    }
                }
                $product['units'] = $units;
            }

            $products[] = $product;
        }
    }

    $payload = [
        'status' => 'success',
        'count' => count($products),
        'products' => $products
    ];

    if ($format === 'payload') {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    } else {
        echo json_encode($products, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
