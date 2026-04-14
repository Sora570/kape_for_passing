<?php
// db/session_alive.php
require_once __DIR__ . '/session_config.php';

// Respond with JSON 200 if session is active, otherwise 401
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

if (isset($_SESSION['userID'])) {
    echo json_encode(['status' => 'ok', 'userID' => $_SESSION['userID'], 'role' => $_SESSION['role'] ?? null]);
    exit;
}

http_response_code(401);
echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
exit;
