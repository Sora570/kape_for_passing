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

$flavorId = $_POST['flavor_id'] ?? $_POST['flavorId'] ?? null;
if (!validate_int($flavorId, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Valid flavor ID is required']);
    exit;
}
$flavorId = (int) $flavorId;

$fields = [];
$params = [];
$types = '';
$logDetail = null;
$logName = null;

if (array_key_exists('flavor_name', $_POST) || array_key_exists('flavorName', $_POST)) {
    $name = sanitize_text($_POST['flavor_name'] ?? $_POST['flavorName'], 100);
    if ($name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Flavor name cannot be empty']);
        exit;
    }
    $fields[] = 'flavor_name = ?';
    $params[] = $name;
    $types .= 's';
    $logName = $name;
}

if (array_key_exists('inventory_id', $_POST) || array_key_exists('inventoryId', $_POST)) {
    $raw = $_POST['inventory_id'] ?? $_POST['inventoryId'];
    if ($raw === '' || $raw === null) {
        $inventoryParam = null;
    } else {
        if (!validate_int($raw, 1)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid inventory reference']);
            exit;
        }
        $inventoryParam = (int) $raw;
    }
    $fields[] = 'inventory_id = ?';
    $params[] = $inventoryParam;
    $types .= 'i';
}

if (array_key_exists('amount_per_serving', $_POST) || array_key_exists('amount', $_POST)) {
    $amountRaw = $_POST['amount_per_serving'] ?? $_POST['amount'];
    if (!is_numeric($amountRaw)) {
        echo json_encode(['status' => 'error', 'message' => 'Amount per serving must be numeric']);
        exit;
    }
    $amount = round((float) $amountRaw, 4);
    if ($amount < 0) {
        echo json_encode(['status' => 'error', 'message' => 'Amount per serving cannot be negative']);
        exit;
    }
    $fields[] = 'amount_per_serving = ?';
    $params[] = $amount;
    $types .= 'd';
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
    $nameStmt = $conn->prepare('SELECT flavor_name FROM flavors WHERE flavor_id = ?');
    $nameStmt->bind_param('i', $flavorId);
    if ($nameStmt->execute()) {
        $nameRes = $nameStmt->get_result();
        if ($nameRes && $nameRow = $nameRes->fetch_assoc()) {
            $logName = $nameRow['flavor_name'] ?? null;
        }
    }
    $nameStmt->close();
}

$params[] = $flavorId;
$types .= 'i';

try {
    $stmt = $conn->prepare('UPDATE flavors SET ' . implode(', ', $fields) . ' WHERE flavor_id = ?');
    $bindParams = [];
    foreach ($params as $index => $value) {
        $bindParams[$index] = &$params[$index];
    }
    array_unshift($bindParams, $types);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    if ($stmt->execute()) {
        if (isset($_SESSION['userID'])) {
            $detail = $logName ? "Flavor: {$logName}" : "Flavor ID {$flavorId}";
            if ($logDetail) {
                $detail .= " - {$logDetail}";
            }
            logSystemActivity($conn, $_SESSION['userID'], 'flavor_update', $detail);
        }
        echo json_encode(['status' => 'success', 'message' => 'Flavor updated successfully']);
    } else {
        throw new Exception('Failed to update flavor');
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
