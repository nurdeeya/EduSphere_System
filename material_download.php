<?php
// material_download.php - secure download for FR4 learning materials.
// Mirrors the protections in download.php (kept separate so download.php is untouched):
//   - MFA-verified student or lecturer only
//   - lecturer: own materials only; student: only materials of a lecturer they are enrolled with
//   - filename is NEVER taken from the request; stored name must be "<32 hex>.<ext>" inside the private storage folder
//   - one uniform 403 for "missing" and "not allowed" (no id enumeration), denial is audit-logged
session_start();
require_once 'config/security.php';
require_once 'config/database.php';
require_once __DIR__ . '/config/upload.php';
require_once __DIR__ . '/includes/access.php';

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

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    render_error(400, 'Bad Request', 'No valid material was selected.');
}

function material_deny(PDO $pdo, int $user_id, string $details): void
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
        error_log('material_download deny log: ' . $e->getMessage());
    }
    render_forbidden();
}

try {
    $stmt = $pdo->prepare("SELECT file_path, title, lecturer_id FROM learning_materials WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        material_deny($pdo, $user_id, "learning_material id=$id not found");
    }

    $allowed =
        ($role === 'lecturer' && (int) $row['lecturer_id'] === $user_id) ||
        ($role === 'student'  && student_is_enrolled($pdo, $user_id, (int) $row['lecturer_id']));

    if (!$allowed) {
        material_deny($pdo, $user_id, "learning_material id=$id forbidden for role=$role");
    }
} catch (PDOException $e) {
    error_log('material_download: ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}

session_write_close();

$stored_name = $row['file_path'];
if ($stored_name !== basename($stored_name) || !preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,10}$/', $stored_name)) {
    material_deny($pdo, $user_id, "learning_material id=$id malformed stored filename");
}

$upload_root = realpath(upload_storage_dir());
$full_path   = $upload_root !== false ? realpath($upload_root . DIRECTORY_SEPARATOR . $stored_name) : false;
if (
    $upload_root === false || $full_path === false ||
    strpos($full_path, $upload_root . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($full_path) || !is_readable($full_path)
) {
    material_deny($pdo, $user_id, "learning_material id=$id file missing on disk");
}

$base = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $row['title']);
$base = trim((string) $base, '_');
$base = $base === '' ? 'material_' . $id : substr($base, 0, 60);
$download_name = $base . '.' . strtolower(pathinfo($stored_name, PATHINFO_EXTENSION));

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($full_path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');

readfile($full_path);
exit;
