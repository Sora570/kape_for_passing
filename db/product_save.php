<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$productName = sanitize_text($_POST['productName'] ?? '', 150);
$categoryID = isset($_POST['categoryID']) ? (int) $_POST['categoryID'] : 0;
$isActive = isset($_POST['isActive']) && (int) $_POST['isActive'] === 0 ? 0 : 1;
$hasVariants = isset($_POST['has_variants']) ? (int) $_POST['has_variants'] : 0;
$hasFlavorsFlag = isset($_POST['has_flavors']) ? (int) $_POST['has_flavors'] : 0;
$hasAddonsFlag = isset($_POST['has_addons']) ? (int) $_POST['has_addons'] : 0;
$basePriceInput = isset($_POST['base_price']) ? (float) $_POST['base_price'] : 0.0;

$variantsPayload = json_decode($_POST['variants'] ?? '[]', true);
$addonIdsPayload = json_decode($_POST['addon_ids'] ?? '[]', true);
$flavorIdsPayload = json_decode($_POST['flavor_ids'] ?? '[]', true);

if ($productName === '' || $categoryID < 1) {
    echo json_encode(['status' => 'error', 'message' => 'Product name and category are required.']);
    exit;
}

if ($hasVariants && (!is_array($variantsPayload) || count($variantsPayload) === 0)) {
    echo json_encode(['status' => 'error', 'message' => 'Add at least one variant before saving.']);
    exit;
}

$variants = is_array($variantsPayload) ? $variantsPayload : [];
$addonIds = array_values(array_unique(array_filter(
    is_array($addonIdsPayload) ? array_map('intval', $addonIdsPayload) : [],
    fn($id) => $id > 0
)));
$flavorIds = array_values(array_unique(array_filter(
    is_array($flavorIdsPayload) ? array_map('intval', $flavorIdsPayload) : [],
    fn($id) => $id > 0
)));

$hasAddons = ($hasAddonsFlag && count($addonIds) > 0) ? 1 : 0;
$hasFlavors = ($hasFlavorsFlag && count($flavorIds) > 0) ? 1 : 0;
$basePrice = $hasVariants ? null : $basePriceInput;

try {
    $conn->begin_transaction();

    if ($basePrice === null) {
        $stmt = $conn->prepare("
            INSERT INTO products (productName, categoryID, isActive, has_variants, has_flavors, has_addons, base_price, createdAt)
            VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())
        ");
        $stmt->bind_param(
            'siiiii',
            $productName,
            $categoryID,
            $isActive,
            $hasVariants,
            $hasFlavors,
            $hasAddons
        );
    } else {
        $stmt = $conn->prepare("
            INSERT INTO products (productName, categoryID, isActive, has_variants, has_flavors, has_addons, base_price, createdAt)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param(
            'siiiiid',
            $productName,
            $categoryID,
            $isActive,
            $hasVariants,
            $hasFlavors,
            $hasAddons,
            $basePrice
        );
    }

    if (!$stmt->execute()) {
        throw new Exception('Failed to save product: ' . $stmt->error);
    }
    $stmt->close();

    $productID = $conn->insert_id;

    if ($hasVariants && count($variants) > 0) {
        $variantStmt = $conn->prepare("
            INSERT INTO product_variants (product_id, variant_name, size_label, price, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        if (!$variantStmt) {
            throw new Exception('Failed to prepare variant insert: ' . $conn->error);
        }

        $insertedVariant = false;
        foreach ($variants as $variant) {
            $variantName = sanitize_text($variant['variant_name'] ?? '', 100);
            $sizeLabel = sanitize_text($variant['size_label'] ?? $variantName, 50);
            $price = isset($variant['price']) ? (float) $variant['price'] : 0;
            $status = strtolower($variant['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

            if ($variantName === '' && $sizeLabel === '') {
                continue;
            }

            $variantStmt->bind_param(
                'issds',
                $productID,
                $variantName !== '' ? $variantName : $sizeLabel,
                $sizeLabel !== '' ? $sizeLabel : $variantName,
                $price,
                $status
            );

            if (!$variantStmt->execute()) {
                throw new Exception('Failed to insert variant: ' . $variantStmt->error);
            }
            $insertedVariant = true;
        }
        $variantStmt->close();

        if (!$insertedVariant) {
            throw new Exception('Provide at least one valid variant entry.');
        }
    }

    if ($hasAddons && count($addonIds) > 0) {
        $addonStmt = $conn->prepare("INSERT INTO product_addons (product_id, addon_id) VALUES (?, ?)");
        if (!$addonStmt) {
            throw new Exception('Failed to prepare add-on insert: ' . $conn->error);
        }
        foreach ($addonIds as $addonId) {
            $addonStmt->bind_param('ii', $productID, $addonId);
            if (!$addonStmt->execute()) {
                throw new Exception('Failed to link add-on: ' . $addonStmt->error);
            }
        }
        $addonStmt->close();
    }

    if ($hasFlavors && count($flavorIds) > 0) {
        $flavorStmt = $conn->prepare("INSERT INTO food_flavors (product_id, flavor_id) VALUES (?, ?)");
        if (!$flavorStmt) {
            throw new Exception('Failed to prepare flavor insert: ' . $conn->error);
        }
        foreach ($flavorIds as $flavorId) {
            $flavorStmt->bind_param('ii', $productID, $flavorId);
            if (!$flavorStmt->execute()) {
                throw new Exception('Failed to link flavor: ' . $flavorStmt->error);
            }
        }
        $flavorStmt->close();
    }

    $conn->commit();
    echo json_encode(['status' => 'success', 'productID' => $productID]);
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
