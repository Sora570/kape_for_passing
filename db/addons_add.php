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

$name = sanitize_text($_POST['addon_name'] ?? $_POST['addonName'] ?? '', 100);
$priceRaw = $_POST['price'] ?? '0';
$status = strtolower(trim($_POST['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';

if ($name === '') {
    echo json_encode(['status' => 'error', 'message' => 'Add-on name is required']);
    exit;
}

if (!is_numeric($priceRaw)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid price value']);
    exit;
}
$price = round((float) $priceRaw, 2);
if ($price < 0) {
    echo json_encode(['status' => 'error', 'message' => 'Price cannot be negative']);
    exit;
}

try {
    $stmt = $conn->prepare('INSERT INTO addons (addon_name, price, status) VALUES (?, ?, ?)');
    $stmt->bind_param('sds', $name, $price, $status);

    if ($stmt->execute()) {
        $addonId = $stmt->insert_id;
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'addon_add', "Add-on: {$name} (#{$addonId})");
        }
        echo json_encode(['status' => 'success', 'addon_id' => $addonId, 'message' => 'Add-on added successfully']);
    } else {
        throw new Exception('Failed to add add-on');
    }

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
?>
