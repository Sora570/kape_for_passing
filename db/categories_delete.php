<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';

// Require admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$categoryID = $_POST['categoryID'] ?? 0;

if (!validate_int($categoryID,1)) {
    echo json_encode(['status' => 'error', 'message' => 'Category ID is required']);
    exit;
}
$categoryID = (int)$categoryID;

// Check if category has products
$checkStmt = $conn->prepare("SELECT COUNT(*) as productCount FROM products WHERE categoryID = ?");
$checkStmt->bind_param("i", $categoryID);
$checkStmt->execute();
$result = $checkStmt->get_result();
$row = $result->fetch_assoc();
$checkStmt->close();

if ($row['productCount'] > 0) {
    echo json_encode(['status' => 'error', 'message' => 'Cannot delete category with existing products']);
    exit;
}

// Get category name before deletion for audit log
$getCategoryStmt = $conn->prepare("SELECT categoryName FROM categories WHERE categoryID = ?");
$getCategoryStmt->bind_param("i", $categoryID);
$getCategoryStmt->execute();
$categoryResult = $getCategoryStmt->get_result();
$categoryName = $categoryResult->fetch_assoc()['categoryName'] ?? 'Unknown';
$getCategoryStmt->close();

$stmt = $conn->prepare("DELETE FROM categories WHERE categoryID = ?");
$stmt->bind_param("i", $categoryID);

if ($stmt->execute()) {
    // Log audit activity
    if (isset($_SESSION['userID'])) {
        logCategoryActivity($conn, $_SESSION['userID'], 'delete', $categoryName, "Category ID: $categoryID");
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Category deleted successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to delete category']);
}

$stmt->close();
$conn->close();
?>
