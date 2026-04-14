<?php
// db/download_employee_file.php
// Secure download endpoint for employee files

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/db_connect.php';

// Check authentication - employees should be able to download files sent to them
if (!isset($_SESSION['userID']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Unauthorized - Please log in to download files';
    exit;
}

// Get filename from query parameter
$fileName = isset($_GET['file']) ? basename($_GET['file']) : '';

if (empty($fileName)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'No file specified';
    exit;
}

// Validate filename format (should be timestamp_filename.ext)
if (!preg_match('/^\d+_[a-zA-Z0-9._-]+$/', $fileName)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid file name format';
    exit;
}

// Construct file path
$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'employee_files';
$filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

// Check if file exists
if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'File not found';
    exit;
}

// Get original filename from stored filename (remove timestamp prefix)
$originalFileName = preg_replace('/^\d+_/', '', $fileName);

// Determine content type based on file extension
$extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
$contentTypes = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'txt' => 'text/plain',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png'
];

$contentType = $contentTypes[$extension] ?? 'application/octet-stream';

// Set headers for file download
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . addslashes($originalFileName) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Output file
readfile($filePath);
exit;
