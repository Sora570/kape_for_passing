<?php
header('Content-Type: application/json');
require_once __DIR__ . '/db_connect.php';

$sql = "
    SELECT 
        c.categoryID,
        c.categoryName,
        c.isActive,
        c.parent_id,
        p.categoryName AS parentName
    FROM categories c
    LEFT JOIN categories p ON p.categoryID = c.parent_id
    ORDER BY c.categoryName";
$res = $conn->query($sql);
$out = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $out[] = [
            'categoryID' => (int) $r['categoryID'],
            'categoryName' => $r['categoryName'],
            'isActive' => (int) $r['isActive'],
            'parent_id' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'parentName' => $r['parentName']
        ];
    }
}
echo json_encode($out);
$conn->close();
