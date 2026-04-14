<?php
// ---------------------------------------------------------------
// Database credentials
// For Hostinger: update these four values to match your hosting
// control panel → Databases → MySQL Databases details.
// ---------------------------------------------------------------
$host   = getenv('DB_HOST')   ?: "127.0.0.1";   // Hostinger: often "localhost" or a specific IP
$user   = getenv('DB_USER')   ?: "root";          // Hostinger: your MySQL username (e.g. u123456789_kape)
$pass   = getenv('DB_PASS')   ?: "";              // Hostinger: your MySQL password
$dbname = getenv('DB_NAME')   ?: "kape_db";       // Hostinger: your database name (e.g. u123456789_kape_db)
$port   = (int)(getenv('DB_PORT') ?: 3306);

try {
    $conn = new mysqli($host, $user, $pass, $dbname, $port);

    if ($conn->connect_error) {
        // Fallback: try the XAMPP macOS socket (local dev only)
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