<?php
/**
 * Compatibility wrappers around the centralized audit logging helpers.
 * Older codepaths still include this file, so we forward everything to
 * the newer implementations in audit_log.php to avoid drift.
 */
require_once __DIR__ . '/audit_log.php';

function log_audit_trail($userID, $action, $details = '', $ip_address = null, $user_agent = null) {
    global $conn;
    if (!isset($conn)) {
        return false;
    }

    return logAuditActivity($conn, $userID, $action, $details, $ip_address, $user_agent);
}

function log_product_change($userID, $action, $productID, $productName, $details = '') {
    $fullDetails = "Product: $productName (ID: $productID)";
    if ($details) {
        $fullDetails .= " - $details";
    }
    return log_audit_trail($userID, $action, $fullDetails);
}

function log_transaction_change($userID, $action, $transactionID, $amount = null, $details = '') {
    $fullDetails = "Transaction ID: $transactionID";
    if ($amount !== null) {
        $fullDetails .= " - Amount: ?$amount";
    }
    if ($details) {
        $fullDetails .= " - $details";
    }
    return log_audit_trail($userID, $action, $fullDetails);
}

function log_user_change($userID, $action, $targetUserID, $targetUsername, $details = '') {
    $fullDetails = "Target User: $targetUsername (ID: $targetUserID)";
    if ($details) {
        $fullDetails .= " - $details";
    }
    return log_audit_trail($userID, $action, $fullDetails);
}

function log_system_change($userID, $action, $details = '') {
    return log_audit_trail($userID, $action, $details);
}

function log_login_attempt($userID, $username, $success, $details = '') {
    $action = $success ? 'login' : 'failed_login';
    $fullDetails = "Username: $username";
    if ($details) {
        $fullDetails .= " - $details";
    }
    return log_audit_trail($userID, $action, $fullDetails);
}

function log_logout($userID, $username) {
    return log_audit_trail($userID, 'logout', "Username: $username");
}

function log_inventory_change($userID, $action, $productID, $productName, $quantity, $details = '') {
    $fullDetails = "Product: $productName (ID: $productID) - Quantity: $quantity";
    if ($details) {
        $fullDetails .= " - $details";
    }
    return log_audit_trail($userID, $action, $fullDetails);
}

function log_order_change($userID, $action, $orderID, $amount = null, $details = '') {
    $fullDetails = "Order ID: $orderID";
    if ($amount !== null) {
        $fullDetails .= " - Amount: ?$amount";
    }
    if ($details) {
        $fullDetails .= " - $details";
    }
    return log_audit_trail($userID, $action, $fullDetails);
}

function log_settings_change($userID, $action, $settingName, $oldValue = null, $newValue = null, $details = '') {
    $fullDetails = "Setting: $settingName";
    if ($oldValue !== null && $newValue !== null) {
        $fullDetails .= " - Changed from '$oldValue' to '$newValue'";
    }
    if ($details) {
        $fullDetails .= " - $details";
    }
    return log_audit_trail($userID, $action, $fullDetails);
}
