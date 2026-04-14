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

$name = sanitize_text($_POST['unit_name'] ?? $_POST['unitName'] ?? '', 50);
$symbol = sanitize_text($_POST['unit_symbol'] ?? $_POST['unitSymbol'] ?? '', 10);
$conversionRaw = $_POST['conversion_factor'] ?? $_POST['conversionFactor'] ?? 1;
$isBase = !empty($_POST['is_base_unit']) ? 1 : 0;

if ($name === '' || $symbol === '') {
    echo json_encode(['status' => 'error', 'message' => 'Unit name and symbol are required']);
    exit;
}

if (!is_numeric($conversionRaw)) {
    echo json_encode(['status' => 'error', 'message' => 'Conversion factor must be numeric']);
    exit;
}
$conversion = round((float) $conversionRaw, 4);
if ($conversion <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Conversion factor must be greater than zero']);
    exit;
}

try {
    $stmt = $conn->prepare('INSERT INTO product_units (unit_name, unit_symbol, conversion_factor, is_base_unit) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssdi', $name, $symbol, $conversion, $isBase);

    if ($stmt->execute()) {
        $unitId = $stmt->insert_id;
        if ($isBase) {
            $update = $conn->prepare('UPDATE product_units SET is_base_unit = 0 WHERE unit_id <> ?');
            $update->bind_param('i', $unitId);
            $update->execute();
            $update->close();
        }
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'unit_add', "Unit: {$name} (#{$unitId})");
        }
        echo json_encode(['status' => 'success', 'unit_id' => $unitId, 'message' => 'Unit added successfully']);
    } else {
        throw new Exception('Failed to add unit');
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
