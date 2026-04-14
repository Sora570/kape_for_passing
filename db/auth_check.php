<?php
// db/auth_check.php
// Simple reusable auth check for protected pages and endpoints.
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/session_enforce.php';

// Prevent caching of authenticated pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Basic authentication check
if (!isset($_SESSION['userID'])) {
    // For regular requests redirect to login
    if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Location: /login');
    } else {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    }
    exit;
}

// Enforce single active session per user
$sessionId = session_id();
if (!validate_active_session($conn, (int) $_SESSION['userID'], $sessionId)) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Location: /login');
    } else {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Session invalidated']);
    }
    exit;
}

touch_user_session($conn, $sessionId);

// Optional: verify IP / UA binds if set
if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
    // Log or take action if desired
}

// Invalidate session if password was changed after this session was created
require_once __DIR__ . '/db_connect.php';
if (isset($_SESSION['userID'])) {
    // Check if the column exists to avoid SQL errors on older DB schemas
    $colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at'");
    $includePwChanged = ($colRes && $colRes->num_rows > 0);

    if ($includePwChanged) {
        $pwStmt = $conn->prepare("SELECT password_changed_at FROM users WHERE userID = ? LIMIT 1");
        $pwStmt->bind_param('i', $_SESSION['userID']);
        $pwStmt->execute();
        $pwRes = $pwStmt->get_result();
        if ($pwRow = $pwRes->fetch_assoc()) {
            $dbPwChanged = $pwRow['password_changed_at'] ?? null;
            $sessPwChanged = $_SESSION['password_changed_at'] ?? null;
            if ($dbPwChanged && (!$sessPwChanged || strtotime($dbPwChanged) > strtotime($sessPwChanged))) {
                // Password changed since this session; terminate session
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $params = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000,
                        $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                }
                session_destroy();
                // For normal requests redirect to login
                if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Location: /login');
                } else {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => 'error', 'message' => 'Session invalidated']);
                }
                exit;
            }
        }
        $pwStmt->close();
    }
}

?>
