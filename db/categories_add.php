<?php
// Start output buffering to catch any unexpected output
ob_start();

// Use centralized session and validation
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';

// Clear any output that might have been generated
ob_clean();

// Set JSON header
header('Content-Type: application/json');

try {
    $cat = sanitize_text($_POST['categoryName'] ?? $_POST['category_name'] ?? '', 100);
    if ($cat === '') {
        echo json_encode(['status'=>'error','message'=>'Category name required']);
        exit;
    }

    $parentInput = $_POST['parent_id'] ?? $_POST['parentId'] ?? '';
    if ($parentInput === '') {
        $parentId = null;
    } else {
        if (!validate_int($parentInput, 1)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid parent category']);
            exit;
        }
        $parentId = (int) $parentInput;
    }

    $statusInput = $_POST['status'] ?? $_POST['isActive'] ?? 1;
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

    // Ensure admin
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
        exit;
    }

    // Check if category already exists
    $checkStmt = $conn->prepare("SELECT categoryID FROM categories WHERE categoryName = ?");
    $checkStmt->bind_param("s", $cat);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    if ($result->num_rows > 0) {
        $checkStmt->close();
        echo json_encode(['status'=>'error','message'=>'Category name already exists']);
        exit;
    }
    $checkStmt->close();

    $stmt = $conn->prepare("INSERT INTO categories (categoryName, parent_id, isActive) VALUES (?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Database prepare failed: ' . $conn->error);
    }
    
    $stmt->bind_param("sii", $cat, $parentId, $isActive);

    if ($stmt->execute()) {
        $categoryID = $conn->insert_id;
        
        // Log audit activity
        if (isset($_SESSION['userID'])) {
            logCategoryActivity($conn, $_SESSION['userID'], 'add', $cat, "Category ID: $categoryID");
        }
        
        echo json_encode(['status'=>'success','categoryID'=>$categoryID]);
    } else {
        // Check for duplicate entry error
        if ($conn->errno == 1062) {
            echo json_encode(['status'=>'error','message'=>'Category name already exists']);
        } else {
            echo json_encode(['status'=>'error','message'=>$stmt->error]);
        }
    }
    
    $stmt->close();
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
