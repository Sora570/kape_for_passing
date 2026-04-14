<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

try {
    // Check admin access
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin' || !isset($_SESSION['userID'])) {
        throw new Exception("Access denied. Admin permissions required.");
    }
    
    $query = "
        SELECT 
            u.userID,
            u.username,
            u.role,
            u.created_at as createdAt,
            COALESCE(u.employee_id, 'N/A') as employee_id,
            COALESCE(u.shift_start, NULL) as shift_start,
            COALESCE(u.shift_end, NULL) as shift_end,
            COALESCE(
                (SELECT MAX(al.created_at) 
                 FROM audit_logs al 
                 WHERE al.userID = u.userID 
                 AND LOWER(al.action) = 'login'), 
                u.last_login
            ) as lastLogin,
            CASE 
                WHEN COALESCE(u.shift_start, '') != '' AND COALESCE(u.shift_end, '') = '' THEN 'Online'
                WHEN COALESCE(u.shift_start, '') != '' AND COALESCE(u.shift_end, '') != '' AND u.shift_end > u.shift_start THEN 'Offline'
                ELSE 'Not Started'
            END as status,
            COALESCE(u.first_name, '') as first_name,
            COALESCE(u.last_name, '') as last_name,
            COALESCE(u.email, '') as email,
            COALESCE(u.phone, '') as phone,
            COALESCE(u.address, '') as address,
            u.branch_id as branch_id
        FROM users u 
        WHERE u.role IN ('cashier', 'admin')
        AND ( ? IS NULL OR u.branch_id IS NULL OR u.branch_id = ? )
        ORDER BY u.created_at DESC
    ";
    
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param('ii', $branchId, $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
    $employees = [];
    
    while ($row = $result->fetch_assoc()) {
        $employees[] = [
            'userID' => intval($row['userID']),
            'username' => $row['username'],
            'role' => $row['role'],
            'createdAt' => $row['createdAt'],
            'lastLogin' => $row['lastLogin'],
            'status' => $row['status'],
            'employee_id' => $row['employee_id'],
            'shift_start' => $row['shift_start'],
            'shift_end' => $row['shift_end'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'address' => $row['address'],
            'branch_id' => $row['branch_id'] !== null ? (int)$row['branch_id'] : null
        ];
    }
    
    $stmt->close();
    echo json_encode($employees);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
