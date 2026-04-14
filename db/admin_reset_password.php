<?php
// db/admin_reset_password.php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/audit_log_function.php';

try {
    $token = trim($_POST['token'] ?? '');
    $newPassword = $_POST['newPassword'] ?? '';

    if ($token === '' || $newPassword === '') {
        throw new Exception('Invalid request');
    }

    if (!is_strong_password($newPassword)) {
        throw new Exception('Password must be at least 8 characters and include letters and numbers');
    }

    $tokenHash = hash('sha256', $token);

    // Ensure table exists (safe to run)
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userID INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used TINYINT(1) DEFAULT 0,
        request_ip VARCHAR(45) DEFAULT NULL,
        request_agent TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (userID),
        INDEX (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure users.password_changed_at exists (add when missing)
    $colCheck = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at'");
    if ($colCheck && $colCheck->num_rows === 0) {
        // Best-effort: add column
        @$conn->query("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL");
    }

    $stmt = $conn->prepare("SELECT id, userID, expires_at, used FROM password_resets WHERE token_hash = ? LIMIT 1");
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!($row = $res->fetch_assoc())) {
        throw new Exception('Invalid or expired token');
    }

    if ((int)$row['used'] === 1) {
        throw new Exception('Invalid or expired token');
    }

    $expires = strtotime($row['expires_at']);
    if ($expires < time()) {
        throw new Exception('Invalid or expired token');
    }

    $userID = (int)$row['userID'];

    // Update password and mark reset used
    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

    $conn->begin_transaction();

    $update = $conn->prepare("UPDATE users SET passwordHash = ?, password_changed_at = NOW() WHERE userID = ?");
    $update->bind_param('si', $hashed, $userID);
    if (!$update->execute()) {
        $conn->rollback();
        throw new Exception('Failed to update password');
    }

    $mark = $conn->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
    $mark->bind_param('i', $row['id']);
    $mark->execute();

    // Audit log
    $auditStmt = $conn->prepare("INSERT INTO audit_logs (userID, action, details, created_at) VALUES (?, ?, ?, NOW())");
    $action = 'admin_password_reset';
    $details = 'Admin password reset via token by userID ' . $userID;
    $auditStmt->bind_param('iss', $userID, $action, $details);
    $auditStmt->execute();

    $conn->commit();

    // Send confirmation email if available
    $emailStmt = $conn->prepare("SELECT email FROM users WHERE userID = ? LIMIT 1");
    $emailStmt->bind_param('i', $userID);
    $emailStmt->execute();
    $emailRes = $emailStmt->get_result();
    if ($emailRow = $emailRes->fetch_assoc()) {
        $email = $emailRow['email'];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__ . '/mail_helper.php';
            $confirmHtml = '<p>Your admin account password was changed.</p><p>If this was not you, contact another admin immediately.</p>';
            $sent = send_reset_mail($email, 'Admin password changed', $confirmHtml, true);
            if (!$sent) {
                // fallback to audit log so there's a trace
                $auditStmt2 = $conn->prepare("INSERT INTO audit_logs (userID, action, details, created_at) VALUES (?, ?, ?, NOW())");
                $action2 = 'admin_password_change_email_failed';
                $details2 = 'Could not send confirmation email to: ' . $email;
                $auditStmt2->bind_param('iss', $userID, $action2, $details2);
                $auditStmt2->execute();
                $auditStmt2->close();
            }
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Password reset successful']);
    exit;

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}
