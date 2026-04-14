<?php
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/audit_log.php';

// Require admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Access denied. Admin only.']);
    exit;
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

// Validate required fields
if (!isset($_POST['productID']) || !isset($_FILES['productImage'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields.']);
    exit;
}

// Validate productID
$productID = $_POST['productID'] ?? 0;
if (!validate_int($productID, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID.']);
    exit;
}
$productID = (int)$productID;

// Validate image file (use finfo for MIME detection)
$imageFile = $_FILES['productImage'];
$allowedTypes = ['image/jpeg','image/png','image/gif'];
$maxSize = 5 * 1024 * 1024; // 5MB
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $imageFile['tmp_name']);
finfo_close($finfo);
if (!in_array($mime, $allowedTypes, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.']);
    exit;
}
if ($imageFile['size'] > $maxSize) {
    echo json_encode(['status' => 'error', 'message' => 'File too large. Maximum size is 5MB.']);
    exit;
}
if ($imageFile['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'File upload error.']);
    exit;
}

$productID = intval($_POST['productID']);
$imageFile = $_FILES['productImage'];

// Validate product ID
if ($productID <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID.']);
    exit;
}

// Validate image file
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!in_array($imageFile['type'], $allowedTypes)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Only JPG, PNG, and GIF are allowed.']);
    exit;
}

if ($imageFile['size'] > $maxSize) {
    echo json_encode(['status' => 'error', 'message' => 'File too large. Maximum size is 5MB.']);
    exit;
}

if ($imageFile['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'File upload error.']);
    exit;
}

try {
    // Check if product exists
    $stmt = $conn->prepare("SELECT productName FROM products WHERE productID = ?");
    $stmt->bind_param("i", $productID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Product not found.']);
        exit;
    }
    
    $product = $result->fetch_assoc();
    $productName = $product['productName'];
    
    // Create uploads directory if it doesn't exist
    $uploadDir = '../assest/image/products/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($imageFile['name'], PATHINFO_EXTENSION);
    $filename = 'product_' . $productID . '_' . time() . '.' . $fileExtension;
    $filepath = $uploadDir . $filename;
    
    // Move uploaded file
    if (move_uploaded_file($imageFile['tmp_name'], $filepath)) {
        // Update product with image URL
        $imageUrl = 'assest/image/products/' . $filename;
        $stmt = $conn->prepare("UPDATE products SET image_url = ? WHERE productID = ?");
        $stmt->bind_param("si", $imageUrl, $productID);
        
        if ($stmt->execute()) {
            // Log the activity
            logProductActivity($conn, $_SESSION['userID'], 'image_upload', $productName, "Image uploaded for product: $productName");
            
            echo json_encode([
                'status' => 'success', 
                'message' => 'Image uploaded successfully!',
                'productID' => $productID,
                'imageUrl' => $imageUrl
            ]);
        } else {
            // Delete uploaded file if database update failed
            unlink($filepath);
            echo json_encode(['status' => 'error', 'message' => 'Failed to update product with image.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file.']);
    }
    
} catch (Exception $e) {
    error_log("Image upload error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An error occurred while uploading the image.']);
}

$conn->close();
?>
