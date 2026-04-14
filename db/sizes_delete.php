<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';

// Require admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$sizeID = $_POST['sizeID'] ?? 0;

if (!validate_int($sizeID,1)) {
    echo json_encode(['status' => 'error', 'message' => 'Size ID is required']);
    exit;
}
$sizeID = (int)$sizeID;

// Check if size has product prices
$checkStmt = $conn->prepare("SELECT COUNT(*) as priceCount FROM product_prices WHERE sizeID = ?");
$checkStmt->bind_param("i", $sizeID);
$checkStmt->execute();
$result = $checkStmt->get_result();
$row = $result->fetch_assoc();
$checkStmt->close();

if ($row['priceCount'] > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Cannot delete size with existing product prices']);
    exit;
}

$stmt = $conn->prepare("DELETE FROM sizes WHERE sizeID = ?");
$stmt->bind_param("i", $sizeID);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Size deleted successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete size']);
}

$stmt->close();
$conn->close();
?>