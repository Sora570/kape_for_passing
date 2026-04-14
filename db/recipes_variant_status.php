<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';

$productID = intval($_GET['productID'] ?? 0);
if (!$productID) {
    echo json_encode(['status' => 'error', 'message' => 'ProductID is required']);
    exit;
}

try {
    $productStmt = $conn->prepare("SELECT has_variants FROM products WHERE productID = ?");
    $productStmt->bind_param('i', $productID);
    $productStmt->execute();
    $productResult = $productStmt->get_result();
    $product = $productResult->fetch_assoc();
    $productStmt->close();

    if (!$product) {
        echo json_encode(['status' => 'error', 'message' => 'Product not found']);
        exit;
    }

    $variantStmt = $conn->prepare("
        SELECT variant_id, variant_name, size_label, price
        FROM product_variants
        WHERE product_id = ?
        ORDER BY variant_id
    ");
    $variantStmt->bind_param('i', $productID);
    $variantStmt->execute();
    $variantResult = $variantStmt->get_result();
    $variants = [];
    while ($row = $variantResult->fetch_assoc()) {
        $variants[] = [
            'variant_id' => (int)$row['variant_id'],
            'variant_name' => $row['variant_name'],
            'size_label' => $row['size_label'],
            'price' => isset($row['price']) ? (float)$row['price'] : null,
            'hasRecipe' => false
        ];
    }
    $variantStmt->close();

    if (!count($variants)) {
        echo json_encode([
            'status' => 'success',
            'productID' => $productID,
            'variants' => []
        ]);
        exit;
    }

    $recipeStmt = $conn->prepare("
        SELECT variant_id, COUNT(*) as total
        FROM recipes
        WHERE productID = ?
        GROUP BY variant_id
    ");
    $recipeStmt->bind_param('i', $productID);
    $recipeStmt->execute();
    $recipeResult = $recipeStmt->get_result();
    $recipeMap = [];
    while ($row = $recipeResult->fetch_assoc()) {
        $key = $row['variant_id'] === null ? 'base' : (string)$row['variant_id'];
        $recipeMap[$key] = (int)$row['total'];
    }
    $recipeStmt->close();

    $baseHasRecipe = !empty($recipeMap['base']);

    foreach ($variants as &$variant) {
        $variantKey = (string)$variant['variant_id'];
        $variant['hasRecipe'] = !empty($recipeMap[$variantKey]);
    }

    echo json_encode([
        'status' => 'success',
        'productID' => $productID,
        'baseHasRecipe' => $baseHasRecipe,
        'variants' => $variants
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
