<?php
// db/client_error_log.php
// Receives client-side error reports from the browser and writes them to a log file.

require_once __DIR__ . '/session_config.php';

header('Content-Type: application/json; charset=utf-8');

// Read raw JSON body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit;
}

// Basic sanitization
$timestamp   = isset($data['timestamp']) ? substr($data['timestamp'], 0, 64) : date('c');
$level       = isset($data['level']) ? substr($data['level'], 0, 16) : 'error';
$message     = isset($data['message']) ? substr($data['message'], 0, 2048) : '';
$stack       = isset($data['stack']) ? substr($data['stack'], 0, 4096) : '';
$url         = isset($data['url']) ? substr($data['url'], 0, 1024) : '';
$userAgent   = isset($data['userAgent']) ? substr($data['userAgent'], 0, 1024) : '';
$userRole    = isset($data['userRole']) ? substr($data['userRole'], 0, 64) : '';
$sessionId   = isset($data['sessionId']) ? substr($data['sessionId'], 0, 128) : '';
$context     = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];

$userId = isset($_SESSION['userID']) ? (int)$_SESSION['userID'] : 0;

// Build log line
$entry = [
    'time'      => $timestamp,
    'level'     => $level,
    'user_id'   => $userId,
    'user_role' => $userRole,
    'session'   => $sessionId,
    'url'       => $url,
    'message'   => $message,
    'stack'     => $stack,
    'context'   => $context,
    'userAgent' => $userAgent,
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
];

// Append to log file
$logDir  = __DIR__;
$logFile = $logDir . DIRECTORY_SEPARATOR . 'client_error.log';

try {
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
} catch (Throwable $e) {
    // If logging fails, do not break the app – just respond with generic OK
}

echo json_encode(['status' => 'ok']);
