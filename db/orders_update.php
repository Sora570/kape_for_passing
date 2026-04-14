<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';

if (!isset($_SESSION['userID'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not logged in']);
    exit;
}

$orderId = isset($_POST['orderID']) ? (int)$_POST['orderID'] : (isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0);
$status = strtolower(trim($_POST['status'] ?? ''));

if ($orderId <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Valid order ID is required']);
    exit;
}

if (!in_array($status, ['completed', 'pending', 'pending_void'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid status']);
    exit;
}

try {
    $branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;

    $stmt = $conn->prepare('SELECT sale_id, status, total_amount, branch_id FROM sales WHERE sale_id = ?');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $sale = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sale) {
        echo json_encode(['status' => 'error', 'message' => 'Order not found']);
        exit;
    }

    if ($branchId !== null && (int)$sale['branch_id'] !== $branchId) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized for this branch']);
        exit;
    }

    $updateStmt = $conn->prepare('UPDATE sales SET status = ? WHERE sale_id = ?');
    $updateStmt->bind_param('si', $status, $orderId);
    if (!$updateStmt->execute()) {
        throw new Exception('Failed to update order status');
    }
    $updateStmt->close();

    $actionMap = [
        'completed' => 'order_completed',
        'pending' => 'order_pending',
        'pending_void' => 'order_pending_void'
    ];
    $action = $actionMap[$status] ?? 'order_updated';
    logOrderActivity($conn, (int)$_SESSION['userID'], $action, "Sale ID: {$orderId}");

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
