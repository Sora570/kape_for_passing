<?php
// db/login.php
require_once __DIR__ . '/session_config.php';
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log_function.php';
require_once __DIR__ . '/session_enforce.php';

$identifier = trim($_POST['identifier'] ?? $_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (!$identifier || !$password) {
    echo json_encode(['status'=>'error','message'=>'Missing credentials']);
    exit;
}

// Fetch user
// Check if users.password_changed_at and users.branch_id exist to avoid SQL errors when missing
$colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at'");
$includePwChanged = ($colRes && $colRes->num_rows > 0);
$colBranch = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'branch_id'");
$includeBranchId = ($colBranch && $colBranch->num_rows > 0);
$selectCols = 'userID, username, passwordHash, role' . ($includePwChanged ? ', password_changed_at' : '') . ($includeBranchId ? ', branch_id' : '');

$stmt = $conn->prepare("SELECT " . $selectCols . " FROM users WHERE username = ? OR employee_id = ? LIMIT 1");
$stmt->bind_param("ss", $identifier, $identifier);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
    // Use password_verify for hashed passwords
    if (password_verify($password, $row['passwordHash'])) {
        // successful login
        if (user_has_active_session($conn, (int) $row['userID'])) {
            echo json_encode(['status'=>'error','message'=>'Account already logged in on another session']);
            exit;
        }
        // Prevent session fixation
        session_regenerate_id(true);
        $_SESSION['userID'] = $row['userID'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role'] = $row['role'];
        // store password change timestamp to help invalidate old sessions after reset
        $_SESSION['password_changed_at'] = $row['password_changed_at'] ?? null;
        // store branch_id for branch-scoped data (null = global admin sees all branches)
        $_SESSION['branch_id'] = (isset($row['branch_id']) && $row['branch_id'] !== '' && $row['branch_id'] !== null) ? (int) $row['branch_id'] : null;
        // also set a userEmail for existing logAction usage if you want:
        $_SESSION['userEmail'] = $row['username'];

        // Bind simple client info to session
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['last_activity'] = time();

        // Register session as active
        register_user_session($conn, (int) $row['userID'], session_id());

        // Mark shift start for online status
        $shiftStmt = $conn->prepare("UPDATE users SET shift_start = NOW(), shift_end = NULL WHERE userID = ?");
        if ($shiftStmt) {
            $shiftStmt->bind_param('i', $row['userID']);
            $shiftStmt->execute();
            $shiftStmt->close();
        }

        // Log successful login
        log_login_attempt($row['userID'], $row['username'], true);

        // Return role information for client-side redirection
        echo json_encode(['status'=>'success','role'=>$row['role'],'user'=>$row['username']]);
        exit;
    } else {
        log_login_attempt($row['userID'], $row['username'], false, 'Password mismatch');
        echo json_encode(['status'=>'error','message'=>'Invalid username or password']);
        exit;
    }
} else {
    log_login_attempt(0, $identifier, false, 'Unknown username');
    echo json_encode(['status'=>'error','message'=>'Invalid username or password']);
    exit;
}
