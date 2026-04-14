<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

$sql = "SELECT categoryID, categoryName, isActive
        FROM categories
        WHERE isActive = 1
        ORDER BY categoryID ASC";

$res = $conn->query($sql);
$out = [];

while ($row = $res->fetch_assoc()) {
    $out[] = $row;
}

echo json_encode($out);