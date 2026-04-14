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

$branchId = $_POST['branch_id'] ?? $_POST['branchID'] ?? null;
if (!validate_int($branchId, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Valid branch ID is required']);
    exit;
}
$branchId = (int) $branchId;

$fields = [];
$params = [];
$types = '';
$logDetail = null;
$logName = null;

if (array_key_exists('branch_name', $_POST)) {
    $branchName = sanitize_text($_POST['branch_name'], 100);
    if ($branchName === '') {
        echo json_encode(['status' => 'error', 'message' => 'Branch name cannot be empty']);
        exit;
    }

    $checkStmt = $conn->prepare('SELECT branch_id FROM branches WHERE branch_name = ? AND branch_id <> ? LIMIT 1');
    $checkStmt->bind_param('si', $branchName, $branchId);
    $checkStmt->execute();
    $checkStmt->store_result();
    if ($checkStmt->num_rows > 0) {
        $checkStmt->close();
        echo json_encode(['status' => 'error', 'message' => 'Branch name already exists']);
        exit;
    }
    $checkStmt->close();

    $fields[] = 'branch_name = ?';
    $params[] = $branchName;
    $types .= 's';
    $logName = $branchName;
}

if (array_key_exists('address', $_POST)) {
    $address = sanitize_text($_POST['address'], 255);
    $fields[] = 'address = ?';
    $params[] = $address;
    $types .= 's';
}

if (array_key_exists('status', $_POST)) {
    $status = strtolower(trim($_POST['status'])) === 'inactive' ? 'inactive' : 'active';
    $fields[] = 'status = ?';
    $params[] = $status;
    $types .= 's';
    $logDetail = $status === 'active' ? 'Activated' : 'Deactivated';
}

if (!$fields) {
    echo json_encode(['status' => 'error', 'message' => 'No updates were provided']);
    exit;
}

if ($logName === null) {
    $nameStmt = $conn->prepare('SELECT branch_name FROM branches WHERE branch_id = ?');
    $nameStmt->bind_param('i', $branchId);
    if ($nameStmt->execute()) {
        $nameRes = $nameStmt->get_result();
        if ($nameRes && $nameRow = $nameRes->fetch_assoc()) {
            $logName = $nameRow['branch_name'] ?? null;
        }
    }
    $nameStmt->close();
}

$params[] = $branchId;
$types .= 'i';

try {
    $stmt = $conn->prepare('UPDATE branches SET ' . implode(', ', $fields) . ' WHERE branch_id = ?');
    $bindParams = [];
    foreach ($params as $index => $value) {
        $bindParams[$index] = &$params[$index];
    }
    array_unshift($bindParams, $types);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    if ($stmt->execute()) {
        if (isset($_SESSION['userID'])) {
            $detail = $logName ? "Branch: {$logName}" : "Branch ID {$branchId}";
            if ($logDetail) {
                $detail .= " - {$logDetail}";
            }
            logSystemActivity($conn, $_SESSION['userID'], 'branch_update', $detail);
        }
        echo json_encode(['status' => 'success', 'message' => 'Branch updated successfully']);
    } else {
        throw new Exception('Failed to update branch');
    }
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
