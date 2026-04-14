<?php
// db/session_config.php
// Centralized session configuration to improve security and consistency
// Should be required before any session usage

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

// Ensure samesite and secure flags where supported
$cookieParams = session_get_cookie_params();
// Set cookie params only if session not already active
if (session_status() === PHP_SESSION_NONE) {
    // Use explicit params array when supported (PHP 7.3+)
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        // Fallback for older PHP
        session_set_cookie_params(0, $cookieParams['path'] ?? '/', $cookieParams['domain'] ?? '', $secure, true);
    }

    session_start();
}

// Optional: simple inactivity timeout (30 minutes)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    // last request was more than 30 minutes ago
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    session_start();
}

$_SESSION['last_activity'] = time();

// Small helper to validate that the session seems to originate from the same client
if (!isset($_SESSION['client_ua'])) {
    $_SESSION['client_ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}
else {
    // Optional: make sure user agent hasn't changed drastically
    if (isset($_SERVER['HTTP_USER_AGENT']) && $_SESSION['client_ua'] !== $_SERVER['HTTP_USER_AGENT']) {
        // Don't force a logout automatically here; application can detect mismatch if desired
    }
}

?>
