<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';

// Require admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Admin permissions required']);
    exit;
}

$sizeID = $_POST['sizeID'] ?? 0;
$sizeName = sanitize_text($_POST['sizeName'] ?? '', 50);
$price = $_POST['price'] ?? 0;

if (!validate_int($sizeID,1) || $sizeName === '' || !validate_float($price) || $price < 0) {
    echo json_encode(['status'=>'error','message'=>'Invalid input']);
    exit;
}
$sizeID = (int)$sizeID;
$price = (float)$price;

$stmt = $conn->prepare("UPDATE sizes SET sizeName = ?, defaultPrice = ? WHERE sizeID = ?");
$stmt->bind_param("sdi", $sizeName, $price, $sizeID);

if ($stmt->execute()) {
    echo json_encode(['status' => 'success', 'message' => 'Size updated successfully']);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update size']);
}

$stmt->close();
$conn->close();
?>