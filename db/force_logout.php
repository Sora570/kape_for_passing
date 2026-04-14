<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/session_enforce.php';

try {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['userID'])) {
        throw new Exception('Access denied. Admin permissions required.');
    }

    $targetUserId = isset($_POST['userID']) ? (int) $_POST['userID'] : 0;
    if ($targetUserId <= 0) {
        throw new Exception('Invalid user identifier.');
    }

    ensure_user_sessions_table($conn);
    $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_id = ?");
    $stmt->bind_param('i', $targetUserId);
    $stmt->execute();
    $stmt->close();

    $shiftStmt = $conn->prepare("UPDATE users SET shift_end = NOW() WHERE userID = ?");
    if ($shiftStmt) {
        $shiftStmt->bind_param('i', $targetUserId);
        $shiftStmt->execute();
        $shiftStmt->close();
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

?>
