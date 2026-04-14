<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log_function.php';

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$productIds = $_POST['product_ids'] ?? [];
$rawStatus = $_POST['isActive'] ?? null;

if (!is_array($productIds)) {
    $productIds = [$productIds];
}
$productIds = array_values(array_filter(array_map('intval', $productIds), fn($id) => $id > 0));

if (empty($productIds)) {
    echo json_encode(['status' => 'error', 'message' => 'No products selected']);
    exit;
}

if ($rawStatus === null || ($rawStatus !== '0' && $rawStatus !== '1' && $rawStatus !== 0 && $rawStatus !== 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
    exit;
}

$isActive = (int) $rawStatus === 1 ? 1 : 0;
$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$placeholders = implode(',', array_fill(0, count($productIds), '?'));
$types = str_repeat('i', count($productIds));
$params = $productIds;

$sql = "UPDATE products SET isActive = ? WHERE productID IN ($placeholders)";
$types = 'i' . $types;
array_unshift($params, $isActive);

if ($branchId !== null) {
    $sql .= " AND (branch_id IS NULL OR branch_id = ?)";
    $types .= 'i';
    $params[] = $branchId;
}

$stmt = $conn->prepare($sql);
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

$action = $isActive ? 'product_bulk_activate' : 'product_bulk_deactivate';
foreach ($productIds as $productId) {
    log_product_change($_SESSION['userID'] ?? 0, $action, $productId, 'Bulk update', "Status set to " . ($isActive ? 'active' : 'inactive'));
}

$updatedCount = $conn->affected_rows;

echo json_encode([
    'status' => 'success',
    'updated' => $updatedCount,
    'isActive' => $isActive
]);
