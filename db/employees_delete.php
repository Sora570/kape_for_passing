<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

try {
    // Check admin access
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception("Access denied. Admin permissions required.");
    }
    
    $userID = intval($_POST['userID'] ?? 0);
    
    if ($userID <= 0) {
        throw new Exception("Invalid employee ID");
    }
    
    // Prevent deleting yourself
    if ($userID == $_SESSION['userID']) {
        throw new Exception("Cannot delete your own account");
    }
    
    // Get employee info for logging
    $infoQuery = $conn->prepare("SELECT username FROM users WHERE userID = ?");
    $infoQuery->bind_param('i', $userID);
    $infoQuery->execute();
    $result = $infoQuery->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Employee not found");
    }
    
    $employeeInfo = $result->fetch_assoc();
    $employeeUsername = $employeeInfo['username'];
    $infoQuery->close();
    
    // Delete from users table
    $deleteStmt = $conn->prepare("DELETE FROM users WHERE userID = ?");
    $deleteStmt->bind_param('i', $userID);
    
    if ($deleteStmt->execute()) {
        // Log the action
        $auditStmt = $conn->prepare("INSERT INTO audit_logs (userID, action, details, created_at) VALUES (?, ?, ?, NOW())");
        $auditAction = "employee_deleted";
        $auditDetails = "Admin deleted employee: " . $employeeUsername;
        $_SESSION['userID'] = $_SESSION['userID'] ?? 1; // Make sure we have a user ID
        $auditStmt->bind_param('iss', $_SESSION['userID'], $auditAction, $auditDetails);
        $auditStmt->execute();
        
        echo "success";
    } else {
        throw new Exception("Failed to delete employee");
    }
    
    $deleteStmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}
?>
