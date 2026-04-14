<?php
// db/validation.php
// Small collection of server-side validation helpers used across endpoints.

function validate_int($value, $min = null, $max = null) {
    if (filter_var($value, FILTER_VALIDATE_INT) === false) return false;
    $int = (int)$value;
    if ($min !== null && $int < $min) return false;
    if ($max !== null && $int > $max) return false;
    return true;
}

function validate_float($value) {
    return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validate_date($dateStr, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $dateStr);
    return $d && $d->format($format) === $dateStr;
}

function validate_time($timeStr, $format = 'H:i:s') {
    $d = DateTime::createFromFormat($format, $timeStr);
    return $d && $d->format($format) === $timeStr;
}

function sanitize_text($s, $maxLen = 255) {
    $s = trim((string)$s);
    if ($maxLen !== null) {
        $s = mb_substr($s, 0, $maxLen);
    }
    return $s;
}

function escape_html($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_strong_password($pw) {
    // Minimum 8 characters, at least one letter and one number
    if (!is_string($pw) || strlen($pw) < 8) return false;
    if (!preg_match('/[0-9]/', $pw)) return false;
    if (!preg_match('/[A-Za-z]/', $pw)) return false;
    return true;
}

?>
