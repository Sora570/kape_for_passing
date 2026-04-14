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

$receiverId = $_POST['receiver_id'] ?? $_POST['receiverId'] ?? null;
if (!validate_int($receiverId, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Valid receiver ID is required']);
    exit;
}
$receiverId = (int) $receiverId;

$fields = [];
$params = [];
$types = '';

if (array_key_exists('provider', $_POST)) {
    $provider = strtolower(trim($_POST['provider']));
    if (!in_array($provider, ['gcash', 'paymaya'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Provider must be gcash or paymaya']);
        exit;
    }
    $fields[] = 'provider = ?';
    $params[] = $provider;
    $types .= 's';
}

if (array_key_exists('label', $_POST)) {
    $label = sanitize_text($_POST['label'], 100);
    $fields[] = 'label = ?';
    $params[] = $label;
    $types .= 's';
}

if (array_key_exists('phone_number', $_POST) || array_key_exists('phoneNumber', $_POST)) {
    $phoneNumber = sanitize_text($_POST['phone_number'] ?? $_POST['phoneNumber'], 30);
    if ($phoneNumber === '') {
        echo json_encode(['status' => 'error', 'message' => 'Phone number is required']);
        exit;
    }
    if (!preg_match('/^09\d{9}$/', $phoneNumber)) {
        echo json_encode(['status' => 'error', 'message' => 'Phone number must be 11 digits and start with 09']);
        exit;
    }
    $fields[] = 'phone_number = ?';
    $params[] = $phoneNumber;
    $types .= 's';
}

if (array_key_exists('status', $_POST)) {
    $status = strtolower(trim($_POST['status'])) === 'inactive' ? 'inactive' : 'active';
    $fields[] = 'status = ?';
    $params[] = $status;
    $types .= 's';
}

if (!$fields) {
    echo json_encode(['status' => 'error', 'message' => 'No updates were provided']);
    exit;
}

$params[] = $receiverId;
$types .= 'i';

try {
    $stmt = $conn->prepare('UPDATE payment_receivers SET ' . implode(', ', $fields) . ' WHERE receiver_id = ?');
    $bindParams = [];
    foreach ($params as $index => $value) {
        $bindParams[$index] = &$params[$index];
    }
    array_unshift($bindParams, $types);
    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    if ($stmt->execute()) {
        if (isset($_SESSION['userID'])) {
            logSystemActivity($conn, $_SESSION['userID'], 'payment_receiver_update', "Receiver ID {$receiverId} updated");
        }
        echo json_encode(['status' => 'success']);
    } else {
        throw new Exception('Failed to update receiver');
    }
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
