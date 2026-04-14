<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$branchId = isset($_SESSION['branch_id']) ? (int) $_SESSION['branch_id'] : null;

$sql = "
    SELECT i.inventoryID, i.InventoryName, COALESCE(b.branch_name, 'Unassigned') AS branch_name
    FROM inventory i
    LEFT JOIN branches b ON b.branch_id = i.branch_id
    " . ($branchId !== null ? "WHERE (i.branch_id IS NULL OR i.branch_id = ?)" : "") . "
    ORDER BY i.InventoryName";

if ($branchId !== null) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $branchId);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

$options = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $options[] = [
            'inventoryID' => (int) $row['inventoryID'],
            'name' => $row['InventoryName'],
            'branch_name' => $row['branch_name']
        ];
    }
}

echo json_encode(['status' => 'success', 'items' => $options]);
$conn->close();
?>
