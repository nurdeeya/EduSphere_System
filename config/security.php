<?php
// config/security.php
function escape($data) {
    return htmlspecialchars($data ?? '', ENT_QUOTES, 'UTF-8');
}

function validate_datetime($datetime) {
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $datetime);
    return $d && $d->format('Y-m-d\TH:i') === $datetime;
}

function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>