<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db_connect.php';

// New schema: addon_id, addon_name, price, status (fallback to old column names if present)
$sql = "SELECT addon_id, addon_name, price, status FROM addons ORDER BY addon_id ASC";
$res = @$conn->query($sql);
if (!$res) {
    $sql = "SELECT addonID AS addon_id, addonName AS addon_name, 0 AS price FROM addons ORDER BY addonID ASC";
    $res = $conn->query($sql);
}
$out = [];
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'addon_id' => (int) ($row['addon_id'] ?? $row['addonID'] ?? 0),
            'addon_name' => $row['addon_name'] ?? $row['addonName'] ?? '',
            'price' => isset($row['price']) ? (float) $row['price'] : 0,
            'status' => $row['status'] ?? 'active'
        ];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
