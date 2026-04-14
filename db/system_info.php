<?php
header('Content-Type: application/json');
require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

try {
    // Check admin access
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        throw new Exception("Access denied. Admin permissions required.");
    }
    
    // Get database information
    $dbStatus = 'Online';
    $tableCount = 0;
    $dbSize = '0 MB';
    
    // Check database connection
    if ($conn->connect_error) {
        $dbStatus = 'Offline';
    } else {
        // Get table count
        $result = $conn->query("SHOW TABLES");
        $tableCount = $result->num_rows;
        
        // Get database size
        $sizeResult = $conn->query("
            SELECT 
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'DB Size in MB'
            FROM information_schema.tables 
            WHERE table_schema = 'kape_db'
        ");
        
        if ($sizeResult && $row = $sizeResult->fetch_assoc()) {
            $dbSize = $row['DB Size in MB'] . ' MB';
        }
    }
    
    // Get system information
    $systemInfo = [
        'dbStatus' => $dbStatus,
        'tableCount' => $tableCount,
        'dbSize' => $dbSize,
        'phpVersion' => PHP_VERSION,
        'serverTime' => date('Y-m-d H:i:s'),
        'memoryUsage' => round(memory_get_usage()/1024/1024, 2) . ' MB',
        'maxMemory' => ini_get('memory_limit'),
        'uploadMaxFilesize' => ini_get('upload_max_filesize'),
        'postMaxSize' => ini_get('post_max_size')
    ];
    
    echo json_encode($systemInfo);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

$conn->close();
?>
