<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/db_connect.php';

$pid = $_POST['productID'] ?? 0;
if (!validate_int($pid, 1)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid product ID']);
    exit;
}
$pid = (int)$pid; // safe cast

try {
    // Prepare statement for upsert (insert or update)
    $stmt = $conn->prepare("INSERT INTO product_prices (productID, sizeID, unit_id, price) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");

    // Loop through all posted price keys
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'price_') === 0 && $value !== "") {
            // Extract sizeID and unit_id from key like "price_1_2"
            $parts = explode('_', $key);
            if (count($parts) === 3) {
                $sizeID = $parts[1];
                $unit_id = $parts[2];
                $price = $value;

                if (!validate_int($sizeID, 1) || !validate_int($unit_id, 1) || !validate_float($price) || $price < 0) {
                    // skip invalid price entry
                    continue;
                }
                $sizeID = (int)$sizeID;
                $unit_id = (int)$unit_id;
                $price = (float)$price;

                $stmt->bind_param("iiid", $pid, $sizeID, $unit_id, $price);
                $stmt->execute();
            }
        }
    }

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
