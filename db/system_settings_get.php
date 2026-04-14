<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$settingName = isset($_GET['setting_name']) ? trim($_GET['setting_name']) : '';

try {
    if ($settingName !== '') {
        $stmt = $conn->prepare('SELECT setting_name, value, description, updated_at FROM system_settings WHERE setting_name = ? LIMIT 1');
        $stmt->bind_param('s', $settingName);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Setting not found']);
            exit;
        }

        echo json_encode(['status' => 'success', 'setting' => $row]);
        exit;
    }

    $result = $conn->query('SELECT setting_name, value, description, updated_at FROM system_settings ORDER BY setting_name');
    $settings = [];
    while ($row = $result->fetch_assoc()) {
        $settings[] = $row;
    }

    echo json_encode(['status' => 'success', 'settings' => $settings]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
