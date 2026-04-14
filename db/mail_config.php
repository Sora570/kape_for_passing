<?php
// db/mail_config.php
// Reads mail configuration from environment variables or a local .env file (project root)

// Try to load a local .env if present (simple parser; only for local dev convenience)
$envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m)) continue;
        $key = $m[1];
        $val = $m[2];
        // strip quotes
        if ((strlen($val) >= 2) && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

return [
    'smtp_host' => getenv('SMTP_HOST') ?: '',
    'smtp_port' => getenv('SMTP_PORT') ?: 587,
    'smtp_user' => getenv('SMTP_USER') ?: '',
    'smtp_pass' => getenv('SMTP_PASS') ?: '',
    'smtp_secure' => getenv('SMTP_SECURE') ?: 'tls', // tls or ssl or empty
    'from_address' => getenv('SMTP_FROM_ADDRESS') ?: 'no-reply@localhost',
    'from_name' => getenv('SMTP_FROM_NAME') ?: 'Kape Timplado',
];
