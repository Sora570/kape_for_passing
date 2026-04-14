<?php
// db/auth_check_ajax.php
// Lightweight JSON endpoint to verify whether the current session is authenticated.
// Used by the bfcache pageshow listener on protected pages (Safari/Mac logout fix).

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/session_enforce.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Not authenticated — no session user
if (!isset($_SESSION['userID'])) {
    echo json_encode(['authenticated' => false]);
    exit;
}

// Validate that the session is still registered as active in the DB
$sessionId = session_id();
if (!validate_active_session($conn, (int) $_SESSION['userID'], $sessionId)) {
    echo json_encode(['authenticated' => false]);
    exit;
}

echo json_encode(['authenticated' => true, 'role' => $_SESSION['role'] ?? 'unknown']);
?>
