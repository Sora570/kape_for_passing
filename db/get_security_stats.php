<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

// Check admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Admin only.']);
    exit;
}

try {
    // Get failed logins in last 24 hours
    $failedLoginsQuery = "SELECT COUNT(*) as count FROM audit_logs 
                         WHERE action = 'failed_login' 
                         AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    $failedResult = $conn->query($failedLoginsQuery);
    $failedLogins = $failedResult->fetch_assoc()['count'];

    // Get active sessions (users who logged in within last 30 minutes)
    $activeSessionsQuery = "SELECT COUNT(DISTINCT user_id) as count FROM audit_logs 
                           WHERE action = 'login' 
                           AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)";
    $activeResult = $conn->query($activeSessionsQuery);
    $activeSessions = $activeResult->fetch_assoc()['count'];

    // Get total logins today
    $totalLoginsQuery = "SELECT COUNT(*) as count FROM audit_logs 
                        WHERE action = 'login' 
                        AND DATE(timestamp) = CURDATE()";
    $totalResult = $conn->query($totalLoginsQuery);
    $totalLogins = $totalResult->fetch_assoc()['count'];

    // Get last activity
    $lastActivityQuery = "SELECT timestamp FROM audit_logs 
                         ORDER BY timestamp DESC LIMIT 1";
    $lastResult = $conn->query($lastActivityQuery);
    $lastActivity = $lastResult->fetch_assoc()['timestamp'] ?? null;

    // Format last activity
    if ($lastActivity) {
        $lastActivity = date('M j, Y g:i A', strtotime($lastActivity));
    } else {
        $lastActivity = 'No activity recorded';
    }

    echo json_encode([
        'status' => 'success',
        'stats' => [
            'failedLogins' => (int)$failedLogins,
            'activeSessions' => (int)$activeSessions,
            'totalLogins' => (int)$totalLogins,
            'lastActivity' => $lastActivity
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Failed to load security statistics: ' . $e->getMessage()
    ]);
}

$conn->close();
?>
