<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$provider = isset($_GET['provider']) ? strtolower(trim($_GET['provider'])) : '';
$includeInactive = isset($_GET['includeInactive']) || isset($_GET['include_inactive']);

if ($provider !== '' && !in_array($provider, ['gcash', 'paymaya'], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid provider']);
    exit;
}

$where = [];
$params = [];
$types = '';

if ($provider !== '') {
    $where[] = 'provider = ?';
    $params[] = $provider;
    $types .= 's';
}

if (!$includeInactive) {
    $where[] = "status = 'active'";
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $sql = "SELECT receiver_id, provider, label, phone_number, status, created_at, updated_at FROM payment_receivers {$whereClause} ORDER BY provider, label, phone_number";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Failed to prepare receiver query');
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $receivers = [];
    while ($row = $result->fetch_assoc()) {
        $receivers[] = [
            'receiver_id' => (int) $row['receiver_id'],
            'provider' => $row['provider'],
            'label' => $row['label'],
            'phone_number' => $row['phone_number'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
    $stmt->close();

    echo json_encode(['status' => 'success', 'receivers' => $receivers]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
