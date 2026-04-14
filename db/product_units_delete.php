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

try {
    $infoStmt = $conn->prepare('SELECT unit_name, is_base_unit FROM product_units WHERE unit_id = ? LIMIT 1');
    $infoStmt->bind_param('i', $unitId);
    $infoStmt->execute();
    $infoResult = $infoStmt->get_result();
    $unitInfo = $infoResult->fetch_assoc();
    $infoStmt->close();

    if (!$unitInfo) {
        echo json_encode(['status' => 'error', 'message' => 'Unit not found']);
        exit;
    }

    if ((int) $unitInfo['is_base_unit'] === 1) {
        echo json_encode(['status' => 'error', 'message' => 'The base unit cannot be deleted']);
        exit;
    }

    $usageStmt = $conn->prepare('SELECT COUNT(*) AS usage_count FROM product_prices WHERE unit_id = ?');
    $usageStmt->bind_param('i', $unitId);
    $usageStmt->execute();
    $usageRow = $usageStmt->get_result()->fetch_assoc();
    $usageStmt->close();

    if (($usageRow['usage_count'] ?? 0) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Unit is currently in use by product pricing']);
        exit;
    }

    $convStmt = $conn->prepare('SELECT COUNT(*) AS conv_count FROM unit_conversions WHERE from_unit_id = ? OR to_unit_id = ?');
    $convStmt->bind_param('ii', $unitId, $unitId);
    $convStmt->execute();
    $convRow = $convStmt->get_result()->fetch_assoc();
    $convStmt->close();

    if (($convRow['conv_count'] ?? 0) > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Unit is referenced in conversion rules']);
        exit;
    }

    $deleteStmt = $conn->prepare('DELETE FROM product_units WHERE unit_id = ?');
    $deleteStmt->bind_param('i', $unitId);
    if ($deleteStmt->execute()) {
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'unit_delete', "Unit: {$unitInfo['unit_name']} (#{$unitId}) removed");
        }
        echo json_encode(['status' => 'success', 'message' => 'Unit deleted successfully']);
    } else {
        throw new Exception('Failed to delete unit');
    }
    $deleteStmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
