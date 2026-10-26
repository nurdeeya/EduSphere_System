<?php
session_start();
require_once 'config/security.php';
require_once 'config/database.php';
require_once __DIR__ . '/includes/access.php';

// ---- Authentication: any fully authenticated (MFA-verified) student or lecturer.
if (
    !isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['auth_complete']) ||
    $_SESSION['auth_complete'] !== true ||
    !in_array($_SESSION['role'], ['student', 'lecturer'], true)
) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role    = $_SESSION['role'];

// Release the session lock so a large download doesn't block other requests.
session_write_close();

function deny(PDO $pdo, int $user_id, string $details): void
{
    try {
        $log = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address)
            VALUES (:user_id, 'DOWNLOAD_DENIED', :details, :ip_address)
        ");
        $log->execute([
            ':user_id'    => $user_id,
            ':details'    => substr($details, 0, 500),
            ':ip_address' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('download deny log: ' . $e->getMessage());
    }
    // One uniform 403 for "missing" and "not yours" so ids can't be enumerated.
    render_forbidden();
}

function safe_name_part(string $value, string $fallback): string
{
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
    $value = trim((string) $value, '_');
    return $value === '' ? $fallback : substr($value, 0, 60);
}

// ---- Parameters (type + numeric record id only; the filename is NEVER taken from the request).
$type = $_GET['type'] ?? '';
$id   = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!is_string($type) || !in_array($type, ['material', 'submission'], true) || $id === false || $id === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Bad request.');
}

try {
    if ($type === 'material') {
        // Lecturer material: enrolled students and the lecturer who created it.
        $stmt = $pdo->prepare("
            SELECT file_path, title, created_by
            FROM assignments
            WHERE id = :id AND file_path IS NOT NULL AND file_path <> ''
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            deny($pdo, $user_id, "material id=$id not found");
        }
        // SR2: students must be enrolled with the creator; only the creating lecturer may fetch it.
        $allowed =
            ($role === 'student'  && student_is_enrolled($pdo, $user_id, (int) $row['created_by'])) ||
            ($role === 'lecturer' && (int) $row['created_by'] === $user_id);

        if (!$allowed) {
            deny($pdo, $user_id, "material id=$id forbidden for role=$role");
        }
        $stored_name   = $row['file_path'];
        $download_base = safe_name_part($row['title'], 'assignment_' . $id) . '_material';
    } else {
        // Student submission: the owning student, or the lecturer who created that assignment.
        $stmt = $pdo->prepare("
            SELECT s.file_path, s.student_id, a.created_by, a.title, u.login_identifier
            FROM submissions s
            JOIN assignments a ON a.id = s.assignment_id
            JOIN users u       ON u.user_id = s.student_id
            WHERE s.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            deny($pdo, $user_id, "submission id=$id not found");
        }

        $allowed =
            ($role === 'student'  && (int) $row['student_id'] === $user_id) ||
            ($role === 'lecturer' && (int) $row['created_by'] === $user_id);

        if (!$allowed) {
            deny($pdo, $user_id, "submission id=$id forbidden for role=$role");
        }
        $stored_name   = $row['file_path'];
        $download_base = safe_name_part($row['login_identifier'], 'student') . '_'
                       . safe_name_part($row['title'], 'assignment') . '_submission';
    }
} catch (PDOException $e) {
    error_log('download.php: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('A system error occurred. Please try again later.');
}

// ---- Path-traversal defence: stored name must be a bare "<32 hex>.<ext>" and resolve inside uploads/.
if ($stored_name !== basename($stored_name) || !preg_match('/^[a-f0-9]{32}\.[A-Za-z0-9]{1,10}$/', $stored_name)) {
    deny($pdo, $user_id, "$type id=$id has malformed stored filename");
}

$upload_root = realpath(__DIR__ . '/uploads');
$full_path   = $upload_root !== false ? realpath($upload_root . DIRECTORY_SEPARATOR . $stored_name) : false;

if (
    $upload_root === false || $full_path === false ||
    strpos($full_path, $upload_root . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($full_path) || !is_readable($full_path)
) {
    deny($pdo, $user_id, "$type id=$id file missing on disk");
}

$ext           = strtolower(pathinfo($stored_name, PATHINFO_EXTENSION));
$download_name = $download_base . '.' . $ext;
$size          = filesize($full_path);

// ---- Stream as a forced download.
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');

readfile($full_path);
exit;
