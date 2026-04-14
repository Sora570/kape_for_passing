<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

$includeInactive = isset($_GET['includeInactive']) || isset($_GET['include_inactive']);
$sql = $includeInactive
    ? "SELECT flavor_id, flavor_name, inventory_id, amount_per_serving, status, created_at, updated_at FROM flavors ORDER BY flavor_name"
    : "SELECT flavor_id, flavor_name, inventory_id, amount_per_serving, status, created_at, updated_at FROM flavors WHERE status = 'active' ORDER BY flavor_name";
$res = $conn->query($sql);
$out = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'flavor_id' => (int) $row['flavor_id'],
            'flavor_name' => $row['flavor_name'],
            'inventory_id' => $row['inventory_id'] !== null ? (int) $row['inventory_id'] : null,
            'amount_per_serving' => (float) $row['amount_per_serving'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
$conn->close();
