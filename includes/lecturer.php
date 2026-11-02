<?php
// includes/lecturer.php - shared helpers for the Lecturer Module (FR2-FR5).
// Every page that includes this has already called require_role('lecturer').

require_once __DIR__ . '/../vendor/autoload.php';

use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;

// ---- Input bounds (Buffer Overflow prevention: every field has a hard maximum,
//      checked server-side in characters, matching the DB column sizes).
const LEC_TITLE_MAX          = 100;   // assignments.title VARCHAR(100)
const LEC_DESCRIPTION_MAX    = 2000;  // assignments.description TEXT
const LEC_MATERIAL_TITLE_MAX = 150;   // learning_materials.title VARCHAR(150)
const LEC_MATERIAL_DESC_MAX  = 1000;
const LEC_SEARCH_MAX         = 50;
const LEC_MAX_DEADLINE_DAYS  = 365;   // semantic rule: deadline within one year
const LEC_REAUTH_MAX_FAILS   = 5;     // deadline-change confirmation attempts
const LEC_REAUTH_LOCK_SECS   = 900;   // 15 minutes, same as login lockout

/** Read a POST/GET field as a string; arrays and other tricks become ''. */
function lec_str($value): string
{
    return is_string($value) ? trim($value) : '';
}

/**
 * Syntactic check for free text: within length and no control characters
 * (newlines/tabs are allowed only when $multiline is true).
 */
function lec_text_ok(string $value, int $max, bool $multiline = false): bool
{
    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max) {
        return false;
    }
    $pattern = $multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';
    return preg_match($pattern, $value) !== 1;
}

/**
 * Semantic check for a deadline (syntax is checked by validate_datetime()):
 * must be in the future and no more than a year ahead, measured by the
 * DATABASE clock - the same clock the Student Module uses for Late status.
 * Returns '' when OK, otherwise an error message.
 */
function lec_deadline_problem(PDO $pdo, string $deadline): string
{
    $stmt = $pdo->prepare("
        SELECT (:d1 > NOW()) AS is_future,
               (:d2 <= DATE_ADD(NOW(), INTERVAL " . LEC_MAX_DEADLINE_DAYS . " DAY)) AS within_limit
    ");
    $mysql = str_replace('T', ' ', $deadline) . ':00';
    $stmt->execute([':d1' => $mysql, ':d2' => $mysql]);
    $row = $stmt->fetch();

    if (!$row || (int) $row['is_future'] !== 1) {
        return 'The deadline must be a date and time in the future.';
    }
    if ((int) $row['within_limit'] !== 1) {
        return 'The deadline cannot be more than one year from now.';
    }
    return '';
}

/** Write an audit record. Logging failures never break the page. */
function lec_audit(PDO $pdo, int $user_id, string $action, string $details): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, details, ip_address)
            VALUES (:user_id, :action, :details, :ip_address)
        ");
        $stmt->execute([
            ':user_id'    => $user_id,
            ':action'     => substr($action, 0, 100),
            ':details'    => substr($details, 0, 500),
            ':ip_address' => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
        ]);
    } catch (Throwable $e) {
        error_log('lec_audit: ' . $e->getMessage());
    }
}

/** One-time message carried across a POST/redirect/GET. */
function lec_flash(string $type, string $text): void
{
    $_SESSION['lecturer_flash'] = ['type' => $type, 'text' => $text];
}

function lec_take_flash(): array
{
    $flash = $_SESSION['lecturer_flash'] ?? null;
    unset($_SESSION['lecturer_flash']);
    return is_array($flash) ? $flash : ['type' => '', 'text' => ''];
}

/** A request body bigger than post_max_size arrives with empty $_POST and $_FILES. */
function lec_post_too_large(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST'
        && empty($_POST) && empty($_FILES)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/**
 * Verify a 6-digit authenticator code against the lecturer's stored MFA secret
 * (same RobThree TOTP library the login MFA uses).
 */
function lec_verify_mfa_code(PDO $pdo, int $user_id, string $code): bool
{
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $stmt = $pdo->prepare("
        SELECT mfa_secret FROM users
        WHERE user_id = :user_id AND role = 'lecturer' AND is_active = 1 AND mfa_enabled = 1
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $user_id]);
    $secret = $stmt->fetchColumn();
    if (!is_string($secret) || $secret === '') {
        return false;
    }
    $tfa = new TwoFactorAuth(new BaconQrCodeProvider(format: 'svg'), 'EduSphere');
    return $tfa->verifyCode($secret, $code);
}

/** Navigation shared by every lecturer page. */
function lec_nav(string $active): void
{
    $links = [
        'dashboard'   => ['lecturer_dashboard.php', 'Dashboard'],
        'assignments' => ['lecturer_create_assignment.php', 'Assignments'],
        'materials'   => ['lecturer_materials.php', 'Learning Materials'],
        'students'    => ['lecturer_students.php', 'Students'],
    ];
    echo '<nav class="lec-subnav">';
    foreach ($links as $key => [$href, $label]) {
        $cls = $key === $active ? ' class="active"' : '';
        echo '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $cls . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
    }
    echo '</nav>';
}
