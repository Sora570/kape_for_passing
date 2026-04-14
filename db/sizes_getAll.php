<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

$sql = "SELECT sizeID, sizeName, defaultPrice, isActive
        FROM sizes
        ORDER BY sizeID ASC";

$res = $conn->query($sql);
$out = [];

while ($row = $res->fetch_assoc()) {
    $out[] = $row;
}

echo json_encode($out);
