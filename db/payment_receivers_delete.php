<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$receiverId = $_POST['receiver_id'] ?? $_POST['receiverId'] ?? null;
if (!validate_int($receiverId, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Valid receiver ID is required']);
    exit;
}
$receiverId = (int) $receiverId;

try {
    $stmt = $conn->prepare('UPDATE payment_receivers SET status = "inactive" WHERE receiver_id = ?');
    $stmt->bind_param('i', $receiverId);
    if ($stmt->execute()) {
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'payment_receiver_deactivate', "Receiver ID {$receiverId} deactivated");
        }
        echo json_encode(['status' => 'success']);
    } else {
        throw new Exception('Failed to deactivate receiver');
    }
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
