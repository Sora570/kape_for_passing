<?php
// db/products_getOne.php - New schema: product_variants, base_price, addons, flavors (no sizes table)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$pid = (int) ($_POST['productID'] ?? $_GET['productID'] ?? 0);
if ($pid <= 0) {
    echo json_encode(null);
    exit;
}

// Select product with new schema columns (no reference to sizes table)
$sql = "
    SELECT productID, productName, categoryID, isActive,
           COALESCE(has_variants, 0) AS has_variants,
           COALESCE(has_flavors, 0) AS has_flavors,
           COALESCE(has_addons, 0) AS has_addons,
           base_price, unit_type, cost_price, is_trackable
    FROM products
    WHERE productID = ?";
if ($branchId !== null) {
    $sql .= " AND (branch_id IS NULL OR branch_id = ?)";
}
$sql .= " LIMIT 1";
$stmt = $conn->prepare($sql);
if ($branchId !== null) {
    $stmt->bind_param("ii", $pid, $branchId);
} else {
    $stmt->bind_param("i", $pid);
}
$stmt->execute();
$res = $stmt->get_result();
$p = $res->fetch_assoc();
$stmt->close();

if (!$p) {
    echo json_encode(null);
    exit;
}

// Normalize types for JSON
$p['productID'] = (int) $p['productID'];
$p['categoryID'] = (int) $p['categoryID'];
$p['isActive'] = (int) $p['isActive'];
$p['has_variants'] = (int) $p['has_variants'];
$p['has_flavors'] = (int) $p['has_flavors'];
$p['has_addons'] = (int) $p['has_addons'];
$p['base_price'] = $p['base_price'] !== null ? (float) $p['base_price'] : null;
$p['cost_price'] = isset($p['cost_price']) ? (float) $p['cost_price'] : 0;
$p['is_trackable'] = isset($p['is_trackable']) ? (int) $p['is_trackable'] : 1;

$p['variants'] = [];
$p['sizes'] = []; // backward compatibility for JS that expects product.sizes

if (!empty($p['has_variants'])) {
    // Get variants from product_variants (no sizes table)
    $vstmt = $conn->prepare("
        SELECT variant_id, variant_name, size_label, price, status
        FROM product_variants
        WHERE product_id = ?
        ORDER BY variant_id
    ");
    $vstmt->bind_param("i", $pid);
    $vstmt->execute();
    $vres = $vstmt->get_result();
    while ($row = $vres->fetch_assoc()) {
        $p['variants'][] = [
            'variant_id' => (int) $row['variant_id'],
            'variant_name' => $row['variant_name'],
            'size_label' => $row['size_label'] ?? $row['variant_name'],
            'price' => (float) $row['price'],
            'status' => $row['status']
        ];
        // Backward compatibility: expose as sizes for existing edit modal JS
        $p['sizes'][] = [
            'sizeID' => (int) $row['variant_id'],
            'sizeName' => $row['size_label'] ?? $row['variant_name'],
            'price' => (float) $row['price']
        ];
    }
    $vstmt->close();
} else {
    // No variants: use base_price; one synthetic "size" for compatibility
    $bp = $p['base_price'] !== null ? (float) $p['base_price'] : 0;
    $p['sizes'][] = [
        'sizeID' => 0,
        'sizeName' => 'Single',
        'price' => $bp
    ];
}

// Addons: only when has_addons
$p['addons'] = [];
if (!empty($p['has_addons'])) {
    $astmt = $conn->prepare("
        SELECT a.addon_id, a.addon_name, a.price
        FROM addons a
        INNER JOIN product_addons pa ON pa.addon_id = a.addon_id AND pa.product_id = ?
        WHERE a.status = 'active'
        ORDER BY a.addon_id
    ");
    $astmt->bind_param("i", $pid);
    $astmt->execute();
    $ares = $astmt->get_result();
    while ($row = $ares->fetch_assoc()) {
        $p['addons'][] = [
            'addon_id' => (int) $row['addon_id'],
            'addon_name' => $row['addon_name'],
            'price' => (float) $row['price'],
            'selected' => true
        ];
    }
    $astmt->close();
}

// Flavors: only when has_flavors (food)
$p['flavors'] = [];
if (!empty($p['has_flavors'])) {
    $fstmt = $conn->prepare("
        SELECT f.flavor_id, f.flavor_name
        FROM flavors f
        INNER JOIN food_flavors ff ON ff.flavor_id = f.flavor_id AND ff.product_id = ?
        ORDER BY f.flavor_name
    ");
    $fstmt->bind_param("i", $pid);
    $fstmt->execute();
    $fres = $fstmt->get_result();
    while ($row = $fres->fetch_assoc()) {
        $p['flavors'][] = [
            'flavor_id' => (int) $row['flavor_id'],
            'flavor_name' => $row['flavor_name'],
            'selected' => true
        ];
    }
    $fstmt->close();
}

echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
