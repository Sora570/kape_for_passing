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

$branchName = sanitize_text($_POST['branch_name'] ?? '', 100);
$address = sanitize_text($_POST['address'] ?? '', 255);
$status = strtolower(trim($_POST['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';

if ($branchName === '') {
    echo json_encode(['status' => 'error', 'message' => 'Branch name is required']);
    exit;
}

try {
    $checkStmt = $conn->prepare('SELECT branch_id FROM branches WHERE branch_name = ? LIMIT 1');
    $checkStmt->bind_param('s', $branchName);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        echo json_encode(['status' => 'error', 'message' => 'Branch name already exists']);
        exit;
    }
    $checkStmt->close();

    $stmt = $conn->prepare('INSERT INTO branches (branch_name, address, status) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $branchName, $address, $status);

    if ($stmt->execute()) {
        $branchId = $stmt->insert_id;
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'branch_add', "Branch: {$branchName} (#{$branchId})");
        }
        echo json_encode(['status' => 'success', 'branch_id' => $branchId, 'message' => 'Branch added successfully']);
    } else {
        throw new Exception('Failed to add branch');
    }
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
