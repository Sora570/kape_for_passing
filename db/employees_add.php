<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';

try {
    // Check admin access
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception("Access denied. Admin permissions required.");
    }
    
    // Basic Information
    $firstName = trim($_POST['firstName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // Validate basic fields
    if (!preg_match('/^[A-Za-z\s]+$/', $firstName)) {
        throw new Exception('First name must contain letters and spaces only');
    }
    if (!preg_match('/^[A-Za-z\s]+$/', $lastName)) {
        throw new Exception('Last name must contain letters and spaces only');
    }
    if (!validate_email($email)) {
        throw new Exception('Invalid email address');
    }
    if (!preg_match('/^[0-9]+$/', $phone)) {
        throw new Exception('Phone number must contain digits only');
    }
    $address = sanitize_text($address, 300);
    
    // Login Information
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $pin = $_POST['pin'] ?? '';
    $employeeId = trim($_POST['employeeId'] ?? '');
    $branchId = isset($_POST['branch_id']) && $_POST['branch_id'] !== '' ? (int) $_POST['branch_id'] : null;
    
    $requiredFields = [
        'First name' => $firstName,
        'Last name' => $lastName,
        'Email' => $email,
        'Phone number' => $phone,
        'Address' => $address,
        'Username' => $username,
        'Employee ID' => $employeeId
    ];
    
    foreach ($requiredFields as $label => $value) {
        if ($value === '') {
            throw new Exception("$label is required");
        }
    }
    
    if (!in_array($role, ['admin', 'cashier'], true)) {
        throw new Exception("Invalid role specified");
    }
    
    $credentialSecret = '';
    if ($role === 'admin') {
        if ($password === '') {
            throw new Exception("Password is required for admin accounts");
        }
        // Enforce a minimum password strength for admin accounts
        if (!is_strong_password($password)) {
            throw new Exception("Admin password must be at least 8 characters and include letters and numbers.");
        }
        $credentialSecret = $password;
    } else {
        if ($pin === '') {
            throw new Exception("PIN is required for cashier accounts");
        }
        if (!preg_match('/^[0-9]{4}$/', $pin)) {
            throw new Exception("PIN must be a 4-digit number");
        }
        if ($password !== '' && $password !== $pin) {
            throw new Exception("For cashier accounts, the password (if provided) must match the PIN.");
        }
        $credentialSecret = $pin;
    }
    
    $hashedPassword = password_hash($credentialSecret, PASSWORD_DEFAULT);
    
    // Normalize and sanitize before insert
    $firstName = sanitize_text($firstName, 60);
    $lastName = sanitize_text($lastName, 60);
    $email = strtolower(trim($email));
    $phone = preg_replace('/\D/', '', $phone);
    $address = sanitize_text($address, 300);
    
    // Check if username already exists
    $checkStmt = $conn->prepare("SELECT userID FROM users WHERE username = ?");
    $checkStmt->bind_param('s', $username);
    $checkStmt->execute();
    
    if ($checkStmt->get_result()->num_rows > 0) {
        throw new Exception("Username already exists");
    }
    $checkStmt->close();
    
    // Check if employee ID already exists
    $checkEmployeeId = $conn->prepare("SELECT userID FROM users WHERE employee_id = ?");
    $checkEmployeeId->bind_param('s', $employeeId);
    $checkEmployeeId->execute();
    
    if ($checkEmployeeId->get_result()->num_rows > 0) {
        throw new Exception("Employee ID already exists");
    }
    $checkEmployeeId->close();
    
    // Insert new employee (branch_id optional; use separate query when null for correct NULL binding)
    if ($branchId !== null) {
        $stmt = $conn->prepare("INSERT INTO users (username, passwordHash, role, employee_id, first_name, last_name, email, phone, address, branch_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('sssssssssi', $username, $hashedPassword, $role, $employeeId, $firstName, $lastName, $email, $phone, $address, $branchId);
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, passwordHash, role, employee_id, first_name, last_name, email, phone, address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('sssssssss', $username, $hashedPassword, $role, $employeeId, $firstName, $lastName, $email, $phone, $address);
    }
    
    if ($stmt->execute()) {
        $newUserID = $conn->insert_id;
        
        // Log the action
        $auditStmt = $conn->prepare("INSERT INTO audit_logs (userID, action, details, created_at) VALUES (?, ?, ?, NOW())");
        $auditAction = "employee_added";
        $auditDetails = "Admin added new employee: " . $username;
        $auditStmt->bind_param('iss', $_SESSION['userID'], $auditAction, $auditDetails);
        $auditStmt->execute();
        
        echo "success";
    } else {
        throw new Exception("Failed to create employee account");
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo $e->getMessage();
}
?>
