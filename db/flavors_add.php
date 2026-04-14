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

$name = sanitize_text($_POST['flavor_name'] ?? $_POST['flavorName'] ?? '', 100);
$inventoryIdRaw = $_POST['inventory_id'] ?? $_POST['inventoryId'] ?? null;
$amountRaw = $_POST['amount_per_serving'] ?? $_POST['amount'] ?? 0;
$status = strtolower(trim($_POST['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';

if ($name === '') {
    echo json_encode(['status' => 'error', 'message' => 'Flavor name is required']);
    exit;
}

$inventoryId = null;
if ($inventoryIdRaw !== null && $inventoryIdRaw !== '') {
    if (!validate_int($inventoryIdRaw, 1)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid inventory reference']);
        exit;
    }
    $inventoryId = (int) $inventoryIdRaw;
}

if (!is_numeric($amountRaw)) {
    echo json_encode(['status' => 'error', 'message' => 'Amount per serving must be numeric']);
    exit;
}
$amount = round((float) $amountRaw, 4);
if ($amount < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Amount per serving cannot be negative']);
    exit;
}

try {
    $stmt = $conn->prepare('INSERT INTO flavors (flavor_name, inventory_id, amount_per_serving, status) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('sids', $name, $inventoryId, $amount, $status);

    if ($stmt->execute()) {
        $flavorId = $stmt->insert_id;
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'flavor_add', "Flavor: {$name} (#{$flavorId})");
        }
        echo json_encode(['status' => 'success', 'flavor_id' => $flavorId, 'message' => 'Flavor added successfully']);
    } else {
        throw new Exception('Failed to add flavor');
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
