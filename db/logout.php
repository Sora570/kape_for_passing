<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';
require_once __DIR__ . '/session_enforce.php';

// Log logout activity if user is logged in
if (isset($_SESSION['userID']) && isset($_SESSION['username'])) {
    try {
        logLogout($conn, $_SESSION['userID'], $_SESSION['username']);
    } catch (Exception $e) {
        // Don't fail logout if audit logging fails
        error_log("Audit logging on logout failed: " . $e->getMessage());
    }
}

// Clear all session data
if (session_id()) {
    deactivate_user_session($conn, session_id());
}
if (isset($_SESSION['userID'])) {
    $shiftStmt = $conn->prepare("UPDATE users SET shift_end = NOW() WHERE userID = ?");
    if ($shiftStmt) {
        $shiftStmt->bind_param('i', $_SESSION['userID']);
        $shiftStmt->execute();
        $shiftStmt->close();
    }
}
$_SESSION = array();

// Destroy the session cookie (support samesite when available)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], true);
    }
}

// Destroy the session
session_destroy();

// Strong cache-clearing headers to prevent browser from serving cached authenticated pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
// Clear-site-data is helpful on modern browsers over HTTPS; keep it as advisory
header('Clear-Site-Data: "cache", "cookies", "storage"');

// Return JSON response for better error handling
header('Content-Type: application/json');
echo json_encode(['status' => 'success', 'message' => 'Logged out successfully']);
?>