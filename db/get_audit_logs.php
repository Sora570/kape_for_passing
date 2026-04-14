<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

try {
    // Get filter parameters
    $action = $_GET['action'] ?? null;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

    // Build query
    $sql = "SELECT
                al.logID,
                al.userID,
                al.action,
                al.details,
                al.ip_address,
                al.user_agent,
                al.created_at,
                u.username,
                u.role
            FROM audit_logs al
            LEFT JOIN users u ON al.userID = u.userID";

    $where = [];
    $params = [];
    $types = "";

    if ($action) {
        $where[] = "al.action = ?";
        $params[] = $action;
        $types .= "s";
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $audit_logs = [];
    while ($row = $result->fetch_assoc()) {
        $audit_logs[] = [
            'logID' => $row['logID'],
            'userID' => $row['userID'],
            'username' => $row['username'] ?? 'System',
            'role' => $row['role'] ?? 'System',
            'action' => $row['action'],
            'details' => $row['details'],
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'created_at' => $row['created_at']
        ];
    }

    $stmt->close();

    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) as total FROM audit_logs";
    if ($action) {
        $count_sql .= " WHERE action = ?";
        $count_stmt = $conn->prepare($count_sql);
        $count_stmt->bind_param("s", $action);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $count_stmt->close();
    } else {
        $count_result = $conn->query($count_sql);
    }

    $total = $count_result->fetch_assoc()['total'];

    echo json_encode([
        'status' => 'success',
        'audit_logs' => $audit_logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>