<?php
// includes/access.php - shared role guard and error pages.

/**
 * Render a small styled error page and stop. Nothing about the underlying
 * cause (missing record vs. not yours) is revealed to the visitor.
 */
function render_error(int $code, string $title, string $message): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    $role = $_SESSION['role'] ?? null;
    $authed = ($_SESSION['auth_complete'] ?? false) === true;
    if ($authed && $role === 'student') {
        $back = ['student_dashboard.php', 'Back to Dashboard'];
    } elseif ($authed && $role === 'lecturer') {
        $back = ['lecturer_dashboard.php', 'Back to Dashboard'];
    } else {
        $back = ['login.php', 'Go to Login'];
    }

    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= (int) $code ?> <?= $h($title) ?> - EduSphere</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f4f6fb; color: #263248; min-height: 100vh; display: flex; flex-direction: column; }
        .navbar { background: #243b64; color: #fff; padding: 18px 6%; font-size: 22px; font-weight: bold; }
        .box { margin: auto; background: #fff; padding: 40px 30px; border-radius: 12px; box-shadow: 0 3px 12px rgba(0,0,0,.05); max-width: 460px; width: calc(100% - 40px); text-align: center; }
        .code { font-size: 64px; font-weight: bold; color: #243b64; line-height: 1; }
        h1 { font-size: 22px; margin: 14px 0 10px; }
        p { color: #64748b; line-height: 1.6; margin-bottom: 24px; }
        a { display: inline-block; padding: 10px 18px; background: #243b64; color: #fff; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 14px; }
        a:hover { background: #1a2c4c; }
    </style>
</head>
<body>
    <div class="navbar">EduSphere</div>
    <div class="box">
        <div class="code"><?= (int) $code ?></div>
        <h1><?= $h($title) ?></h1>
        <p><?= $h($message) ?></p>
        <a href="<?= $h($back[0]) ?>"><?= $h($back[1]) ?></a>
    </div>
</body>
</html>
<?php
    exit;
}

function render_forbidden(): void
{
    render_error(403, 'Access Forbidden', 'You do not have permission to access this page or resource.');
}

/**
 * Is this student enrolled with the given lecturer (the creator of an assignment)?
 */
function student_is_enrolled(PDO $pdo, int $student_id, int $lecturer_id): bool
{
    $stmt = $pdo->prepare("
        SELECT 1 FROM enrollments
        WHERE student_id = :student_id AND lecturer_id = :lecturer_id
        LIMIT 1
    ");
    $stmt->execute([':student_id' => $student_id, ':lecturer_id' => $lecturer_id]);
    return (bool) $stmt->fetchColumn();
}

/** Record a blocked access attempt (never lets logging break the request). */
function audit_denied(PDO $pdo, int $user_id, string $details): void
{
    try {
        $log = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address)
            VALUES (:user_id, 'ACCESS_DENIED', :details, :ip_address)
        ");
        $log->execute([
            ':user_id'    => $user_id,
            ':details'    => substr($details, 0, 500),
            ':ip_address' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('audit_denied: ' . $e->getMessage());
    }
}

/**
 * Require a fully authenticated (MFA-verified) user with the given role.
 * - not logged in / MFA not finished -> redirect to login
 * - logged in with the WRONG role    -> 403 Forbidden
 */
function require_role(string $role): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (
        !isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['auth_complete']) ||
        $_SESSION['auth_complete'] !== true
    ) {
        header('Location: login.php');
        exit;
    }

    if ($_SESSION['role'] !== $role) {
        render_forbidden();
    }
}
