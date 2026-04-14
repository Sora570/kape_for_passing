<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$productID   = (int) ($_POST['productID'] ?? 0);
$productName = sanitize_text($_POST['productName'] ?? '', 150);
$categoryID  = (int) ($_POST['categoryID'] ?? 0);
$isActive    = isset($_POST['isActive']) && $_POST['isActive'] !== '0' ? 1 : 0;
$has_flavors = isset($_POST['has_flavors']) ? (int) $_POST['has_flavors'] : null;
$has_addons = isset($_POST['has_addons']) ? (int) $_POST['has_addons'] : null;

if ($productID < 1 || $productName === '' || $categoryID < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid fields']);
    exit;
}

$variantsJson = $_POST['variants'] ?? '';
$addonIdsJson = $_POST['addon_ids'] ?? '';
$flavorIdsJson = $_POST['flavor_ids'] ?? '';

$variants = is_array(json_decode($variantsJson, true)) ? json_decode($variantsJson, true) : [];
$addonIds = is_array(json_decode($addonIdsJson, true)) ? array_map('intval', json_decode($addonIdsJson, true)) : [];
$flavorIds = is_array(json_decode($flavorIdsJson, true)) ? array_map('intval', json_decode($flavorIdsJson, true)) : [];

$sets = ["productName = ?", "categoryID = ?", "isActive = ?", "has_variants = 1"];
$params = [$productName, $categoryID, $isActive];
$types = "sii";

if ($has_flavors !== null) {
    $sets[] = "has_flavors = ?";
    $params[] = $has_flavors;
    $types .= "i";
}
if ($has_addons !== null) {
    $sets[] = "has_addons = ?";
    $params[] = $has_addons;
    $types .= "i";
}

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$params[] = $productID;
$types .= "i";

$sql = "UPDATE products SET " . implode(", ", $sets) . " WHERE productID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : "");
$stmt = $conn->prepare($sql);
if ($branchId !== null) {
    $params[] = $branchId;
    $types .= "i";
}
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => $conn->error]);
    exit;
}
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    echo json_encode(['status' => 'error', 'message' => $stmt->error]);
    exit;
}
$stmt->close();

if ($variantsJson !== '' && is_array($variants) && count($variants) > 0) {
    $del = $conn->prepare("DELETE FROM product_variants WHERE product_id = ?");
    if ($del) {
        $del->bind_param("i", $productID);
        $del->execute();
        $del->close();
    }
    foreach ($variants as $v) {
        $vname = isset($v['variant_name']) ? trim($v['variant_name']) : (isset($v['size_label']) ? trim($v['size_label']) : '');
        $slabel = isset($v['size_label']) ? trim($v['size_label']) : $vname;
        $price = isset($v['price']) ? (float) $v['price'] : 0;
        $status = isset($v['status']) && strtolower($v['status']) === 'inactive' ? 'inactive' : 'active';
        if ($vname === '') continue;
        $ins = $conn->prepare("INSERT INTO product_variants (product_id, variant_name, size_label, price, status) VALUES (?, ?, ?, ?, ?)");
        if ($ins) {
            $ins->bind_param("issds", $productID, $vname, $slabel, $price, $status);
            $ins->execute();
            $ins->close();
        }
    }
}

foreach ($_POST as $key => $value) {
    if (strpos($key, 'size_') === 0 && is_numeric($value)) {
        $sizeOrVariantId = intval(str_replace('size_', '', $key));
        $price = (float) $value;
        $up = $conn->prepare("UPDATE product_variants SET price = ? WHERE variant_id = ? AND product_id = ?");
        if ($up) {
            $up->bind_param("dii", $price, $sizeOrVariantId, $productID);
            $up->execute();
            $up->close();
        }
    }
}

if ($addonIdsJson !== '') {
    $conn->query("DELETE FROM product_addons WHERE product_id = " . (int) $productID);
    foreach ($addonIds as $aid) {
        if ($aid <= 0) continue;
        $ins = $conn->prepare("INSERT IGNORE INTO product_addons (product_id, addon_id) VALUES (?, ?)");
        if ($ins) {
            $ins->bind_param("ii", $productID, $aid);
            $ins->execute();
            $ins->close();
        }
    }
}

if ($flavorIdsJson !== '') {
    $conn->query("DELETE FROM food_flavors WHERE product_id = " . (int) $productID);
    foreach ($flavorIds as $fid) {
        if ($fid <= 0) continue;
        $ins = $conn->prepare("INSERT IGNORE INTO food_flavors (product_id, flavor_id) VALUES (?, ?)");
        if ($ins) {
            $ins->bind_param("ii", $productID, $fid);
            $ins->execute();
            $ins->close();
        }
    }
}

echo json_encode(['status' => 'success']);
exit;
