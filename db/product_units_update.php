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

$unitId = $_POST['unit_id'] ?? $_POST['unitId'] ?? null;
if (!validate_int($unitId, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Valid unit ID is required']);
    exit;
}
$unitId = (int) $unitId;

$fields = [];
$params = [];
$types = '';
$setBase = null;

if (array_key_exists('unit_name', $_POST) || array_key_exists('unitName', $_POST)) {
    $name = sanitize_text($_POST['unit_name'] ?? $_POST['unitName'], 50);
    if ($name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Unit name cannot be empty']);
        exit;
    }
    $fields[] = 'unit_name = ?';
    $params[] = $name;
    $types .= 's';
}

if (array_key_exists('unit_symbol', $_POST) || array_key_exists('unitSymbol', $_POST)) {
    $symbol = sanitize_text($_POST['unit_symbol'] ?? $_POST['unitSymbol'], 10);
    if ($symbol === '') {
        echo json_encode(['status' => 'error', 'message' => 'Unit symbol cannot be empty']);
        exit;
    }
    $fields[] = 'unit_symbol = ?';
    $params[] = $symbol;
    $types .= 's';
}

if (array_key_exists('conversion_factor', $_POST) || array_key_exists('conversionFactor', $_POST)) {
    $raw = $_POST['conversion_factor'] ?? $_POST['conversionFactor'];
    if (!is_numeric($raw)) {
        echo json_encode(['status' => 'error', 'message' => 'Conversion factor must be numeric']);
        exit;
    }
    $conversion = round((float) $raw, 4);
    if ($conversion <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Conversion factor must be greater than zero']);
        exit;
    }
    $fields[] = 'conversion_factor = ?';
    $params[] = $conversion;
    $types .= 'd';
}

if (array_key_exists('is_base_unit', $_POST)) {
    $setBase = !empty($_POST['is_base_unit']) ? 1 : 0;
    $fields[] = 'is_base_unit = ?';
    $params[] = $setBase;
    $types .= 'i';
}

if (!$fields) {
    echo json_encode(['status' => 'error', 'message' => 'No updates were provided']);
    exit;
}

$params[] = $unitId;
$types .= 'i';

try {
    $stmt = $conn->prepare('UPDATE product_units SET ' . implode(', ', $fields) . ' WHERE unit_id = ?');
    $bindParams = [];
    foreach ($params as $index => $value) {
        $bindParams[$index] = &$params[$index];
    }
    array_unshift($bindParams, $types);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    if ($stmt->execute()) {
        if ($setBase === 1) {
            $update = $conn->prepare('UPDATE product_units SET is_base_unit = 0 WHERE unit_id <> ?');
            $update->bind_param('i', $unitId);
            $update->execute();
            $update->close();
        }
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'unit_update', "Unit ID {$unitId} updated");
        }
        echo json_encode(['status' => 'success', 'message' => 'Unit updated successfully']);
    } else {
        throw new Exception('Failed to update unit');
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
