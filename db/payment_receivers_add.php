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

$provider = strtolower(trim($_POST['provider'] ?? ''));
$label = sanitize_text($_POST['label'] ?? '', 100);
$phoneNumber = sanitize_text($_POST['phone_number'] ?? $_POST['phoneNumber'] ?? '', 30);
$status = strtolower(trim($_POST['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active';

$allowedProviders = ['gcash', 'paymaya'];
if (!in_array($provider, $allowedProviders, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Provider must be gcash or paymaya']);
    exit;
}

if ($phoneNumber === '') {
    echo json_encode(['status' => 'error', 'message' => 'Phone number is required']);
    exit;
}

if (!preg_match('/^09\d{9}$/', $phoneNumber)) {
    echo json_encode(['status' => 'error', 'message' => 'Phone number must be 11 digits and start with 09']);
    exit;
}

try {
    $stmt = $conn->prepare('INSERT INTO payment_receivers (provider, label, phone_number, status) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $provider, $label, $phoneNumber, $status);

    if ($stmt->execute()) {
        $receiverId = $stmt->insert_id;
        if (isset($_SESSION['userID'])) {
            $detail = strtoupper($provider) . ' receiver ' . ($label ? "$label" : $phoneNumber);
            logSystemActivity($conn, $_SESSION['userID'], 'payment_receiver_add', $detail);
        }
        echo json_encode(['status' => 'success', 'receiver_id' => $receiverId]);
    } else {
        throw new Exception('Failed to add receiver');
    }
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
