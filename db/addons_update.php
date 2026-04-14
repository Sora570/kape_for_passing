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

$addonId = $_POST['addon_id'] ?? $_POST['addonId'] ?? null;
if (!validate_int($addonId, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Valid add-on ID is required']);
    exit;
}
$addonId = (int) $addonId;

$fields = [];
$params = [];
$types = '';
$logDetail = null;
$logName = null;

if (array_key_exists('addon_name', $_POST) || array_key_exists('addonName', $_POST)) {
    $name = sanitize_text($_POST['addon_name'] ?? $_POST['addonName'], 100);
    if ($name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Add-on name cannot be empty']);
        exit;
    }
    $fields[] = 'addon_name = ?';
    $params[] = $name;
    $types .= 's';
    $logName = $name;
}

if (array_key_exists('price', $_POST)) {
    $priceRaw = $_POST['price'];
    if (!is_numeric($priceRaw)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid price']);
        exit;
    }
    $price = round((float) $priceRaw, 2);
    if ($price < 0) {
        echo json_encode(['status' => 'error', 'message' => 'Price cannot be negative']);
        exit;
    }
    $fields[] = 'price = ?';
    $params[] = $price;
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
    $nameStmt = $conn->prepare('SELECT addon_name FROM addons WHERE addon_id = ?');
    $nameStmt->bind_param('i', $addonId);
    if ($nameStmt->execute()) {
        $nameRes = $nameStmt->get_result();
        if ($nameRes && $nameRow = $nameRes->fetch_assoc()) {
            $logName = $nameRow['addon_name'] ?? null;
        }
    }
    $nameStmt->close();
}

$params[] = $addonId;
$types .= 'i';

try {
    $stmt = $conn->prepare('UPDATE addons SET ' . implode(', ', $fields) . ' WHERE addon_id = ?');
    $bindParams = [];
    foreach ($params as $index => $value) {
        $bindParams[$index] = &$params[$index];
    }
    array_unshift($bindParams, $types);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    if ($stmt->execute()) {
        if (isset($_SESSION['userID'])) {
            $detail = $logName ? "Add-on: {$logName}" : "Add-on ID {$addonId}";
            if ($logDetail) {
                $detail .= " - {$logDetail}";
            }
            logSystemActivity($conn, $_SESSION['userID'], 'addon_update', $detail);
        }
        echo json_encode(['status' => 'success', 'message' => 'Add-on updated successfully']);
    } else {
        throw new Exception('Failed to update add-on');
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
