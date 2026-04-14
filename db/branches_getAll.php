<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

try {
    $result = $conn->query("SELECT branch_id, branch_name, address, status, created_at FROM branches ORDER BY branch_name");
    $branches = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $branches[] = [
                'branch_id' => (int) $row['branch_id'],
                'branch_name' => $row['branch_name'],
                'address' => $row['address'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            ];
        }
    }

    echo json_encode(['status' => 'success', 'branches' => $branches]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
