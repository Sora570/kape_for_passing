<?php
/**
 * Centralized Audit Logging System
 * Records all employee and admin activities in the system
 */

function logAuditActivity($conn, $userID, $action, $details = '', $ipAddress = null, $userAgent = null) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    try {
        // Get IP address if not provided
        if (!$ipAddress) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        }
        
        // Get user agent if not provided
        if (!$userAgent) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        }
        
        $branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;
        $stmt = $conn->prepare("
            INSERT INTO audit_logs (userID, action, details, ip_address, user_agent, created_at, branch_id) 
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
        ");
        
        $stmt->bind_param("issssi", $userID, $action, $details, $ipAddress, $userAgent, $branchId);
        $result = $stmt->execute();
        $stmt->close();
        
        return $result;
    } catch (Exception $e) {
        error_log("Audit logging failed: " . $e->getMessage());
        return false;
    }
}

function logLogin($conn, $userID, $username, $success = true) {
    $action = $success ? 'login' : 'failed_login';
    $details = $success ? "Login successful for user: $username" : "Failed login attempt for user: $username";
    
    return logAuditActivity($conn, $userID, $action, $details);
}

function logLogout($conn, $userID, $username) {
    $details = "Logout for user: $username";
    return logAuditActivity($conn, $userID, 'logout', $details);
}

function logOrderActivity($conn, $userID, $action, $details = '') {
    $actionMap = [
        'create' => 'order_created',
        'complete' => 'order_completed',
        'cancel' => 'order_cancelled',
        'update' => 'order_updated'
    ];
    
    $auditAction = $actionMap[$action] ?? $action;
    return logAuditActivity($conn, $userID, $auditAction, $details);
}

function logProductActivity($conn, $userID, $action, $productName, $details = '') {
    $actionMap = [
        'add' => 'product_add',
        'update' => 'product_update',
        'delete' => 'product_delete'
    ];
    
    $auditAction = $actionMap[$action] ?? 'product_activity';
    $fullDetails = "Product: $productName" . ($details ? " - $details" : '');
    
    return logAuditActivity($conn, $userID, $auditAction, $fullDetails);
}

function logCategoryActivity($conn, $userID, $action, $categoryName, $details = '') {
    $actionMap = [
        'add' => 'category_add',
        'update' => 'category_update',
        'delete' => 'category_delete'
    ];
    
    $auditAction = $actionMap[$action] ?? 'category_activity';
    $fullDetails = "Category: $categoryName" . ($details ? " - $details" : '');
    
    return logAuditActivity($conn, $userID, $auditAction, $fullDetails);
}

function logSizeActivity($conn, $userID, $action, $sizeName, $details = '') {
    $actionMap = [
        'add' => 'size_add',
        'update' => 'size_update',
        'delete' => 'size_delete'
    ];
    
    $auditAction = $actionMap[$action] ?? 'size_activity';
    $fullDetails = "Size: $sizeName" . ($details ? " - $details" : '');
    
    return logAuditActivity($conn, $userID, $auditAction, $fullDetails);
}

function logInventoryActivity($conn, $userID, $action, $productName, $quantity, $details = '') {
    $fullDetails = "Product: $productName, Quantity: $quantity" . ($details ? " - $details" : '');
    return logAuditActivity($conn, $userID, 'inventory_update', $fullDetails);
}


function logTransactionActivity($conn, $userID, $action, $transactionID, $amount, $details = '') {
    $fullDetails = "Transaction ID: $transactionID, Amount: $amount" . ($details ? " - $details" : '');
    return logAuditActivity($conn, $userID, 'transaction_created', $fullDetails);
}

function logEmployeeActivity($conn, $userID, $action, $employeeName, $details = '') {
    $actionMap = [
        'add' => 'employee_add',
        'update' => 'employee_update',
        'delete' => 'employee_delete',
        'activate' => 'employee_activate',
        'deactivate' => 'employee_deactivate'
    ];
    
    $auditAction = $actionMap[$action] ?? 'employee_activity';
    $fullDetails = "Employee: $employeeName" . ($details ? " - $details" : '');
    
    return logAuditActivity($conn, $userID, $auditAction, $fullDetails);
}

function logSystemActivity($conn, $userID, $action, $details = '') {
    return logAuditActivity($conn, $userID, $action, $details);
}

function logSettingsChange($conn, $userID, $action, $settingName, $oldValue = null, $newValue = null, $details = '') {
    $fullDetails = "Setting: $settingName";
    if ($oldValue !== null && $newValue !== null) {
        $fullDetails .= " - Changed from '$oldValue' to '$newValue'";
    }
    if ($details) {
        $fullDetails .= " - $details";
    }
    return logAuditActivity($conn, $userID, $action, $fullDetails);
}
?>
