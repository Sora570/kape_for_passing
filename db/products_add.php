<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ob_start();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Fatal error: ' . $error['message']]);
        exit;
    }
});

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
header('Content-Type: application/json');

try {
    ob_start();
    require_once __DIR__ . '/db_connect.php';
    ob_end_clean();
    if (!isset($conn) || !$conn || $conn->connect_error) {
        throw new Exception('Database connection failed');
    }
    require_once __DIR__ . '/audit_log.php';
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

$productName = sanitize_text($_POST['productName'] ?? '', 150);
$categoryID  = (int) ($_POST['categoryID'] ?? 0);
$has_flavors  = isset($_POST['has_flavors']) ? (int) $_POST['has_flavors'] : 0;
$has_addons   = isset($_POST['has_addons']) ? (int) $_POST['has_addons'] : 0;
$isActive     = (isset($_POST['isActive']) && $_POST['isActive'] != '0') ? 1 : 0;

$variantsJson = $_POST['variants'] ?? '';
$addonIdsJson = $_POST['addon_ids'] ?? '';
$flavorIdsJson = $_POST['flavor_ids'] ?? '';

if ($productName === '' || !validate_int($categoryID, 1)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Product name and valid category required']);
    exit;
}

$variants = [];
if ($variantsJson !== '') {
    $decoded = json_decode($variantsJson, true);
    if (is_array($decoded)) {
        $variants = $decoded;
    }
}
$addonIds = [];
if ($addonIdsJson !== '') {
    $decoded = json_decode($addonIdsJson, true);
    if (is_array($decoded)) {
        $addonIds = array_map('intval', $decoded);
    }
}
$flavorIds = [];
if ($flavorIdsJson !== '') {
    $decoded = json_decode($flavorIdsJson, true);
    if (is_array($decoded)) {
        $flavorIds = array_map('intval', $decoded);
    }
}

if (empty($variants)) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'At least one variant required']);
    exit;
}

$unit_type = 'piece';
$unit_value = 1.00;
$cost_price = 0.00;
$is_trackable = 1;

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$stmt = $conn->prepare("
    INSERT INTO products (productName, categoryID, isActive, has_variants, has_flavors, has_addons, unit_type, unit_value, cost_price, is_trackable, branch_id)
    VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
");
if (!$stmt) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Insert prepare failed: ' . $conn->error]);
    exit;
}
$stmt->bind_param("siiiisddii", $productName, $categoryID, $isActive, $has_flavors, $has_addons, $unit_type, $unit_value, $cost_price, $is_trackable, $branchId);
if (!$stmt->execute()) {
    ob_clean();
    echo json_encode(['status' => 'error', 'message' => 'Insert failed: ' . $stmt->error]);
    exit;
}
$productID = (int) $conn->insert_id;
$stmt->close();

foreach ($variants as $v) {
    $vname = isset($v['variant_name']) ? trim($v['variant_name']) : (isset($v['size_label']) ? trim($v['size_label']) : '');
    $slabel = isset($v['size_label']) ? trim($v['size_label']) : $vname;
    $price = isset($v['price']) ? (float) $v['price'] : 0;
    if ($vname === '') continue;
    $ins = $conn->prepare("INSERT INTO product_variants (product_id, variant_name, size_label, price, status) VALUES (?, ?, ?, ?, 'active')");
    if ($ins) {
        $ins->bind_param("issd", $productID, $vname, $slabel, $price);
        $ins->execute();
        $ins->close();
    }
}

foreach ($addonIds as $aid) {
    if ($aid <= 0) continue;
    $ins = $conn->prepare("INSERT IGNORE INTO product_addons (product_id, addon_id) VALUES (?, ?)");
    if ($ins) {
        $ins->bind_param("ii", $productID, $aid);
        $ins->execute();
        $ins->close();
    }
}

foreach ($flavorIds as $fid) {
    if ($fid <= 0) continue;
    $ins = $conn->prepare("INSERT IGNORE INTO food_flavors (product_id, flavor_id) VALUES (?, ?)");
    if ($ins) {
        $ins->bind_param("ii", $productID, $fid);
        $ins->execute();
        $ins->close();
    }
}

try {
    if (isset($_SESSION['userID'])) {
        logProductActivity($conn, $_SESSION['userID'], 'add', $productName, "Product ID: $productID, Category ID: $categoryID");
    }
} catch (Exception $e) {
    error_log('Audit log error: ' . $e->getMessage());
}

ob_clean();
echo json_encode(['status' => 'success', 'message' => 'Product added successfully', 'productID' => $productID]);
exit;
