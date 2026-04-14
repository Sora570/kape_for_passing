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

$productID = $_POST['productID'] ?? 0;
if (!validate_int($productID, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Product ID required']);
    exit;
}
$productID = (int)$productID;


try {
    // Get product name for audit log
    $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

    $productQuery = $conn->prepare("SELECT productName FROM products WHERE productID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : ""));
    if ($branchId !== null) {
        $productQuery->bind_param("ii", $productID, $branchId);
    } else {
        $productQuery->bind_param("i", $productID);
    }
    $productQuery->execute();
    $productResult = $productQuery->get_result();
    $product = $productResult->fetch_assoc();
    $productName = $product ? $product['productName'] : 'Unknown';

    // Delete product
    $stmt = $conn->prepare("DELETE FROM products WHERE productID = ?" . ($branchId !== null ? " AND (branch_id IS NULL OR branch_id = ?)" : ""));
    if ($branchId !== null) {
        $stmt->bind_param("ii", $productID, $branchId);
    } else {
        $stmt->bind_param("i", $productID);
    }
    
    if ($stmt->execute()) {
        // Clean up production cost overrides from database
        // Note: If foreign keys are set up with CASCADE, this will happen automatically
        $deleteOverridesStmt = $conn->prepare("DELETE FROM production_cost_overrides WHERE productID = ?");
        $deleteOverridesStmt->bind_param("i", $productID);
        $deleteOverridesStmt->execute();
        $deleteOverridesStmt->close();
        
        // Log audit activity
        if (isset($_SESSION['userID'])) {
            logProductActivity($conn, $_SESSION['userID'], 'delete', $productName, "Product ID: $productID");
        }
        
        echo json_encode(['status' => 'success', 'message' => 'Product deleted successfully']);
    } else {
        echo json_encode(['status' => 'error', 'message' => $stmt->error]);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>