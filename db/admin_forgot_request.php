<?php
// db/admin_forgot_request.php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/audit_log_function.php';

try {
    $identifier = trim($_POST['identifier'] ?? '');
    if ($identifier === '') {
        throw new Exception('If an account exists, an email will be sent');
    }

    // Create password_resets table if not exists
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

    // Lookup admin user by username OR email
    $stmt = $conn->prepare("SELECT userID, username, email, role FROM users WHERE (username = ? OR email = ?) AND role = 'admin' LIMIT 1");
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $res = $stmt->get_result();

    // Always return generic response to avoid enumeration
    $generic = ['status' => 'ok', 'message' => 'If an account exists, an email will be sent with reset instructions'];

    if ($row = $res->fetch_assoc()) {
        $userID = (int)$row['userID'];
        $email = trim($row['email']);

        // Rate limiting: count recent requests for this user/IP in last 30 minutes
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $rateStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM password_resets WHERE (userID = ? OR request_ip = ?) AND created_at > (NOW() - INTERVAL 30 MINUTE)");
        $rateStmt->bind_param('is', $userID, $ip);
        $rateStmt->execute();
        $rateRes = $rateStmt->get_result();
        $rowr = $rateRes->fetch_assoc();
        $countRecent = intval($rowr['cnt'] ?? 0);
        $rateStmt->close();

        if ($countRecent >= 5) {
            // Too many requests; still respond generic
            echo json_encode($generic);
            exit;
        }

        // Generate token and store only its hash
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + 60 * 30); // 30 minutes

        $insert = $conn->prepare("INSERT INTO password_resets (userID, token_hash, expires_at, request_ip, request_agent) VALUES (?, ?, ?, ?, ?)");
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $insert->bind_param('issss', $userID, $tokenHash, $expiresAt, $ip, $ua);
        $insert->execute();
        $insert->close();

        // Build reset URL (respect application base path so deployed in a subdirectory works)
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
        // Determine script directory (e.g. /yourapp/db) and remove trailing /db to get base path
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // e.g., /kapetimplados-main-final/db
        $base = preg_replace('#/db$#', '', $scriptDir); // e.g., /kapetimplados-main-final
        $resetUrl = $scheme . '://' . $host . $base . '/admin_reset.php?token=' . urlencode($token);

        // Send email (best-effort) using the helper. Send HTML email for nicer appearance.
        $subject = 'Admin password reset request';
        $emailBodyHtml = '<p>A password reset was requested for your admin account.</p>' .
                         '<p>If you requested this, click the link below within 30 minutes to reset your password:</p>' .
                         '<p><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES) . '">Reset your password</a></p>' .
                         '<p>If you did not request this, ignore this message.</p>';

        $mailSent = false;
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            require_once __DIR__ . '/mail_helper.php';
            $mailSent = send_reset_mail($email, $subject, $emailBodyHtml, true);
        }

        // Fallback logging & audit
        if (!$mailSent) {
            // audit log - link created
            $auditAction = 'admin_password_reset_link_generated';
            $auditDetails = 'Reset link created for userID ' . $userID . ' (email: ' . $email . ') Link: ' . $resetUrl;
            $auditStmt = $conn->prepare("INSERT INTO audit_logs (userID, action, details, created_at) VALUES (?, ?, ?, NOW())");
            $auditStmt->bind_param('iss', $userID, $auditAction, $auditDetails);
            $auditStmt->execute();
            $auditStmt->close();

            // write to local file for admins to retrieve if mail fails (best-effort)
            @file_put_contents(__DIR__ . '/reset_links.log', date('c') . " - userID=$userID - link=$resetUrl\n", FILE_APPEND | LOCK_EX);
        } else {
            // record audit that email was sent
            $auditAction = 'admin_password_reset_email_sent';
            $auditDetails = 'Reset email sent to ' . $email;
            $auditStmt = $conn->prepare("INSERT INTO audit_logs (userID, action, details, created_at) VALUES (?, ?, ?, NOW())");
            $auditStmt->bind_param('iss', $userID, $auditAction, $auditDetails);
            $auditStmt->execute();
            $auditStmt->close();
        }

        echo json_encode($generic);
        exit;
    }

    echo json_encode($generic);
    exit;

} catch (Exception $e) {
    // Always return generic response
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'If an account exists, an email will be sent with reset instructions']);
    exit;
}
