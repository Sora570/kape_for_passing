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

$settingName = sanitize_text($_POST['setting_name'] ?? '', 100);
$value = trim($_POST['value'] ?? '');

if ($settingName === '') {
    echo json_encode(['status' => 'error', 'message' => 'Setting name is required']);
    exit;
}

if (in_array($settingName, ['void_notification_email', 'void_notification_emails'], true) && $value !== '') {
    $emails = array_filter(array_map('trim', explode(',', $value)));
    foreach ($emails as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid email address: ' . $email]);
            exit;
        }
    }
}

try {
    $stmt = $conn->prepare('SELECT value FROM system_settings WHERE setting_name = ? LIMIT 1');
    $stmt->bind_param('s', $settingName);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        echo json_encode(['status' => 'error', 'message' => 'Setting not found']);
        exit;
    }

    $updateStmt = $conn->prepare('UPDATE system_settings SET value = ?, updated_at = NOW() WHERE setting_name = ?');
    $updateStmt->bind_param('ss', $value, $settingName);

    if ($updateStmt->execute()) {
        if (isset($_SESSION['userID'])) {
            logSettingsChange($conn, $_SESSION['userID'], 'settings_update', $settingName, $existing['value'] ?? '', $value);
        }
        echo json_encode(['status' => 'success']);
    } else {
        throw new Exception('Failed to update setting');
    }
    $updateStmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

$conn->close();
