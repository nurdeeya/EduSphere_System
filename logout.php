<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';

// Record the logout for fully authenticated users.
if (($_SESSION['auth_complete'] ?? false) === true && isset($_SESSION['user_id'])) {
    audit_log($pdo, (int) $_SESSION['user_id'], 'LOGOUT', 'role=' . ($_SESSION['role'] ?? ''));
}

//Remove all session data.
$_SESSION = [];

//Delete thye session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

//Destroy the session
session_destroy();

//Return to login page
header('Location: login.php');
exit;