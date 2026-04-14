<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';

try {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['userID'])) {
        throw new Exception('Access denied. Admin permissions required.');
    }

    $userID = isset($_POST['userID']) ? (int)$_POST['userID'] : 0;
    if ($userID <= 0) {
        throw new Exception('Invalid employee identifier.');
    }

    $firstName = trim($_POST['firstName'] ?? '');
    $lastName  = trim($_POST['lastName'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $address   = trim($_POST['address'] ?? '');
    $role      = trim($_POST['role'] ?? '');
    $employeeId = trim($_POST['employeeId'] ?? '');
    $newPassword = trim($_POST['password'] ?? '');
    $newPin      = trim($_POST['pin'] ?? '');
    $branchInput = isset($_POST['branch_id']) ? trim($_POST['branch_id']) : '';
    $branchId = null;
    if ($branchInput !== '') {
        if (!ctype_digit($branchInput)) {
            throw new Exception('Invalid branch specified.');
        }
        $branchId = (int) $branchInput;
    }

    if ($firstName === '' || $lastName === '' || $email === '' || $phone === '' || $address === '' || $role === '' || $employeeId === '') {
        throw new Exception('Please complete all required fields.');
    }

    if (!in_array($role, ['admin', 'cashier'], true)) {
        throw new Exception('Invalid role specified.');
    }

    // Ensure employee ID uniqueness (allow same for current user)
    $checkEmployee = $conn->prepare('SELECT userID FROM users WHERE employee_id = ? AND userID != ?');
    $checkEmployee->bind_param('si', $employeeId, $userID);
    $checkEmployee->execute();
    if ($checkEmployee->get_result()->num_rows > 0) {
        throw new Exception('Employee ID is already in use.');
    }
    $checkEmployee->close();

    $fields = [
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $email,
        'phone'      => $phone,
        'address'    => $address,
        'role'       => $role,
        'employee_id'=> $employeeId,
        'branch_id'  => $branchId
    ];

    $currentRole = null;
    $roleStmt = $conn->prepare('SELECT role FROM users WHERE userID = ? LIMIT 1');
    $roleStmt->bind_param('i', $userID);
    $roleStmt->execute();
    $roleResult = $roleStmt->get_result();
    if ($row = $roleResult->fetch_assoc()) {
        $currentRole = $row['role'];
    } else {
        throw new Exception('Employee record not found.');
    }
    $roleStmt->close();

    $types = '';
    $values = [];
    $setClauses = [];
    $credentialHash = null;
    
    if ($role === 'admin') {
        if ($newPin !== '') {
            throw new Exception('Admin accounts use passwords. Leave the PIN field blank.');
        }
        if ($newPassword !== '') {
            if (!is_strong_password($newPassword)) {
                throw new Exception('Admin password must be at least 8 characters and include letters and numbers.');
            }
            $credentialHash = password_hash($newPassword, PASSWORD_DEFAULT);
        } elseif ($currentRole !== 'admin') {
            throw new Exception('Provide a password when changing the role to admin.');
        }
    } else {
        if ($newPin !== '') {
            if (!preg_match('/^[0-9]{4}$/', $newPin)) {
                throw new Exception('PIN must be a 4-digit number.');
            }
            $credentialHash = password_hash($newPin, PASSWORD_DEFAULT);
        } elseif ($newPassword !== '') {
            if (!preg_match('/^[0-9]{4}$/', $newPassword)) {
                throw new Exception('Cashier PIN must be a 4-digit number.');
            }
            $credentialHash = password_hash($newPassword, PASSWORD_DEFAULT);
        } elseif ($currentRole !== 'cashier') {
            throw new Exception('Provide a PIN when changing the role to cashier.');
        }
    }
    
    foreach ($fields as $column => $value) {
        if ($column === 'branch_id') {
            $types .= 'i';
        } else {
            $types .= 's';
        }
        $setClauses[] = "{$column} = ?";
        $values[] = $value;
    }

    if ($credentialHash !== null) {
        $setClauses[] = 'passwordHash = ?';
        $types .= 's';
        $values[] = $credentialHash;
    }

    if (empty($setClauses)) {
        throw new Exception('No changes detected.');
    }

    $types .= 'i';
    $values[] = $userID;

    $sql = 'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE userID = ?';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Failed to prepare update statement: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        throw new Exception('Failed to update employee record.');
    }
    $stmt->close();

    // Log audit entry
    $auditStmt = $conn->prepare("INSERT INTO audit_logs (userID, action, details, created_at) VALUES (?, 'employee_updated', ?, NOW())");
    if ($auditStmt) {
        $details = "Updated employee: {$employeeId} ({$firstName} {$lastName})";
        $auditStmt->bind_param('is', $_SESSION['userID'], $details);
        $auditStmt->execute();
        $auditStmt->close();
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
