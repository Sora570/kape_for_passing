<?php
$host   = getenv('DB_HOST')   ?: "127.0.0.1";   
$user   = getenv('DB_USER')   ?: "root";          
$pass   = getenv('DB_PASS')   ?: "";              
$dbname = getenv('DB_NAME')   ?: "kape_db";       
$port   = (int)(getenv('DB_PORT') ?: 3306);

try {
    $conn = new mysqli($host, $user, $pass, $dbname, $port);

    if ($conn->connect_error) {
        $socket = "/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock";
        if (file_exists($socket)) {
            $conn = new mysqli($host, $user, $pass, $dbname, $port, $socket);
        }
    }

    if ($conn->connect_error) {
        throw new Exception($conn->connect_error);
    }

    $conn->set_charset("utf8mb4");

} catch (Exception $e) {
    // Always respond with JSON so API callers don't receive garbled HTML/text
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed. Please try again later.'
    ]);
    exit;
}
