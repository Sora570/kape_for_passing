<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';

// Require admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$categoryID = $_POST['categoryID'] ?? $_POST['category_id'] ?? 0;
$categoryID = (int) $categoryID;

if (!validate_int($categoryID,1)) {
    echo json_encode(['status' => 'error', 'message' => 'Valid category ID is required']);
    exit;
}

$fields = [];
$params = [];
$types = '';
$logName = null;
$statusLogDetail = null;

if (array_key_exists('categoryName', $_POST) || array_key_exists('category_name', $_POST)) {
    $categoryName = sanitize_text($_POST['categoryName'] ?? $_POST['category_name'], 100);
    if ($categoryName === '') {
        echo json_encode(['status' => 'error', 'message' => 'Category name cannot be empty']);
        exit;
    }

    $checkStmt = $conn->prepare("SELECT categoryID FROM categories WHERE categoryName = ? AND categoryID <> ? LIMIT 1");
    $checkStmt->bind_param("si", $categoryName, $categoryID);
    $checkStmt->execute();
    $dupRes = $checkStmt->get_result();
    if ($dupRes && $dupRes->num_rows > 0) {
        $checkStmt->close();
        echo json_encode(['status' => 'error', 'message' => 'Category name already exists']);
        exit;
    }
    $checkStmt->close();

    $fields[] = "categoryName = ?";
    $params[] = $categoryName;
    $types .= "s";
    $logName = $categoryName;
}

if (array_key_exists('parent_id', $_POST) || array_key_exists('parentId', $_POST)) {
    $parentInput = $_POST['parent_id'] ?? $_POST['parentId'];
    if ($parentInput === '' || $parentInput === null) {
        $parentId = null;
    } else {
        if (!validate_int($parentInput, 1)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parent category']);
            exit;
        }
        $parentId = (int) $parentInput;
        if ($parentId === $categoryID) {
            echo json_encode(['status' => 'error', 'message' => 'Category cannot be its own parent']);
            exit;
        }
    }
    $fields[] = "parent_id = ?";
    $params[] = $parentId;
    $types .= "i";
}

if (array_key_exists('status', $_POST) || array_key_exists('isActive', $_POST)) {
    $statusInput = $_POST['status'] ?? $_POST['isActive'];
    if (is_string($statusInput)) {
        $statusValue = strtolower(trim($statusInput));
        if ($statusValue === 'inactive') {
            $isActive = 0;
        } elseif ($statusValue === 'active') {
            $isActive = 1;
        } else {
            $isActive = (int) $statusInput === 0 ? 0 : 1;
        }
    } else {
        $isActive = (int) $statusInput === 0 ? 0 : 1;
    }
    $fields[] = "isActive = ?";
    $params[] = $isActive;
    $types .= "i";
    $statusLogDetail = $isActive === 1 ? 'Activated' : 'Deactivated';
}

if (!$fields) {
    echo json_encode(['status' => 'error', 'message' => 'No updates were provided']);
    exit;
}

if ($logName === null) {
    $nameStmt = $conn->prepare('SELECT categoryName FROM categories WHERE categoryID = ?');
    $nameStmt->bind_param('i', $categoryID);
    if ($nameStmt->execute()) {
        $nameRes = $nameStmt->get_result();
        if ($nameRes && $nameRow = $nameRes->fetch_assoc()) {
            $logName = $nameRow['categoryName'] ?? null;
        }
    }
    $nameStmt->close();
}

$params[] = $categoryID;
$types .= "i";

$stmt = $conn->prepare("UPDATE categories SET " . implode(', ', $fields) . " WHERE categoryID = ?");
$bindParams = [];
foreach ($params as $index => $value) {
    $bindParams[$index] = &$params[$index];
}
array_unshift($bindParams, $types);
call_user_func_array([$stmt, 'bind_param'], $bindParams);

if ($stmt->execute()) {
    if (isset($_SESSION['userID'])) {
        $logLabel = $logName ?? "Category ID: $categoryID";
        $details = "Category ID: $categoryID";
        if ($statusLogDetail) {
            $details .= " - {$statusLogDetail}";
        }
        logCategoryActivity($conn, $_SESSION['userID'], 'update', $logLabel, $details);
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Category updated successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update category']);
}

$stmt->close();
$conn->close();
?>
