<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$stmt = $conn->prepare("SELECT unit_id, unit_name, unit_symbol, conversion_factor, is_base_unit, created_at FROM product_units ORDER BY unit_name");
$stmt->execute();
$result = $stmt->get_result();

$units = [];
while ($row = $result->fetch_assoc()) {
    $units[] = [
        'unit_id' => (int) $row['unit_id'],
        'unit_name' => $row['unit_name'],
        'unit_symbol' => $row['unit_symbol'],
        'conversion_factor' => (float) $row['conversion_factor'],
        'is_base_unit' => (int) $row['is_base_unit'],
        'created_at' => $row['created_at']
    ];
}

echo json_encode($units);
$stmt->close();
$conn->close();
?>
