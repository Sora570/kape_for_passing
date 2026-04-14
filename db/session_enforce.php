<?php
// db/session_enforce.php
// Enforce single active session per user (block new logins when active session exists).

require_once __DIR__ . '/db_connect.php';

/**
 * Ensure the user_sessions table exists.
 */
function ensure_user_sessions_table(mysqli $conn): void
{
    $sql = "CREATE TABLE IF NOT EXISTS user_sessions (
        session_id VARCHAR(128) PRIMARY KEY,
        user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP NULL DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        INDEX idx_user_active (user_id, is_active),
        INDEX idx_last_seen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $conn->query($sql);
}

/**
 * Check if a user has any other active session.
 */
function user_has_active_session(mysqli $conn, int $userId, int $timeoutMinutes = 15): bool
{
    ensure_user_sessions_table($conn);
    $timeoutMinutes = max(1, $timeoutMinutes);
    $stmt = $conn->prepare(
        "UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1 AND last_seen IS NOT NULL AND last_seen < (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('ii', $userId, $timeoutMinutes);
    $stmt->execute();
    $stmt->close();

    $check = $conn->prepare("SELECT session_id FROM user_sessions WHERE user_id = ? AND is_active = 1 LIMIT 1");
    $check->bind_param('i', $userId);
    $check->execute();
    $res = $check->get_result();
    $has = $res && $res->num_rows > 0;
    $check->close();
    return $has;
}

/**
 * Register a session as active.
 */
function register_user_session(mysqli $conn, int $userId, string $sessionId): void
{
    ensure_user_sessions_table($conn);
    $stmt = $conn->prepare("INSERT INTO user_sessions (session_id, user_id, last_seen, is_active) VALUES (?, ?, NOW(), 1)");
    $stmt->bind_param('si', $sessionId, $userId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Mark a session as inactive.
 */
function deactivate_user_session(mysqli $conn, string $sessionId): void
{
    ensure_user_sessions_table($conn);
    $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_id = ?");
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Update last_seen for a session.
 */
function touch_user_session(mysqli $conn, string $sessionId): void
{
    ensure_user_sessions_table($conn);
    $stmt = $conn->prepare("UPDATE user_sessions SET last_seen = NOW() WHERE session_id = ? AND is_active = 1");
    $stmt->bind_param('s', $sessionId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Validate that the current session matches an active session for the user.
 */
function validate_active_session(mysqli $conn, int $userId, string $sessionId, int $timeoutMinutes = 15): bool
{
    ensure_user_sessions_table($conn);
    $timeoutMinutes = max(1, $timeoutMinutes);
    $stmt = $conn->prepare(
        "UPDATE user_sessions SET is_active = 0 WHERE user_id = ? AND is_active = 1 AND last_seen IS NOT NULL AND last_seen < (NOW() - INTERVAL ? MINUTE)"
    );
    $stmt->bind_param('ii', $userId, $timeoutMinutes);
    $stmt->execute();
    $stmt->close();

    $check = $conn->prepare("SELECT session_id FROM user_sessions WHERE user_id = ? AND session_id = ? AND is_active = 1 LIMIT 1");
    $check->bind_param('is', $userId, $sessionId);
    $check->execute();
    $res = $check->get_result();
    $valid = $res && $res->num_rows > 0;
    $check->close();
    return $valid;
}

?>
