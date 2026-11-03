
<?php
session_start();

require_once __DIR__ . '/../config/database.php';

// Accept login requests only through POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../login.php');
    exit;
}

function loginFailed(): void
{
    $_SESSION['login_error'] =
        'Invalid username or password.';

    header('Location: ../login.php');
    exit;
}

function loginLocked(int $remainingMinutes = 15): void
{
    $remainingMinutes = max(1, $remainingMinutes);

    $_SESSION['login_error'] =
        'Your account is temporarily locked due to '
        . '5 consecutive failed login attempts. '
        . 'Please try again in approximately '
        . $remainingMinutes
        . ' minute(s).';

    $_SESSION['show_lockout_popup'] = true;

    header('Location: ../login.php');
    exit;
}

$loginIdentifier = trim($_POST['login_identifier'] ?? '');
$password = $_POST['password'] ?? '';

if ($loginIdentifier === '' || $password === '') {
    loginFailed();
}

try {
    // Find the account, including its MFA status.
    $sql = 'SELECT
                user_id,
                login_identifier,
                full_name,
                password_hash,
                role,
                failed_login_attempts,
                locked_until,
                is_active,
                mfa_enabled
            FROM users
            WHERE login_identifier = :identifier
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'identifier' => $loginIdentifier
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !(bool) $user['is_active']) {
        loginFailed();
    }

    // Check whether the account is still locked.
    if ($user['locked_until'] !== null) {
        $timeQuery = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(
                SECOND,
                NOW(),
                :locked_until
            )'
        );

        $timeQuery->execute([
            'locked_until' => $user['locked_until']
        ]);

        $remainingSeconds = (int) $timeQuery->fetchColumn();

        if ($remainingSeconds > 0) {
            loginLocked((int) ceil($remainingSeconds / 60));
        }

        // Reset an expired lockout.
        $resetLock = $pdo->prepare(
            'UPDATE users
             SET failed_login_attempts = 0,
                 locked_until = NULL
             WHERE user_id = :user_id'
        );

        $resetLock->execute([
            'user_id' => $user['user_id']
        ]);

        $user['failed_login_attempts'] = 0;
    }

    // Verify the password.
    if (!password_verify($password, $user['password_hash'])) {
        $failedAttempts =
            (int) $user['failed_login_attempts'] + 1;

        $update = $pdo->prepare(
            'UPDATE users
             SET failed_login_attempts = :attempts,
                 locked_until = CASE
                     WHEN :lock_attempts >= 5
                     THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                     ELSE NULL
                 END
             WHERE user_id = :user_id'
        );

        $update->execute([
            'attempts' => $failedAttempts,
            'lock_attempts' => $failedAttempts,
            'user_id' => $user['user_id']
        ]);

        if ($failedAttempts >= 5) {
            loginLocked(15);
        }

        loginFailed();
    }

    // Accept only student, lecturer, and admin accounts.
    if (!in_array($user['role'], ['student', 'lecturer', 'admin'], true)) {
        loginFailed();
    }

    // Reset failed attempts after a correct password.
    $reset = $pdo->prepare(
        'UPDATE users
         SET failed_login_attempts = 0,
             locked_until = NULL
         WHERE user_id = :user_id'
    );

    $reset->execute([
        'user_id' => $user['user_id']
    ]);

    // Start a fresh session, but do not authenticate fully yet.
    session_regenerate_id(true);

    // Remove any previous authentication or pending MFA state.
    unset(
        $_SESSION['user_id'],
        $_SESSION['login_identifier'],
        $_SESSION['full_name'],
        $_SESSION['role'],
        $_SESSION['auth_complete'],
        $_SESSION['pending_mfa_user_id'],
        $_SESSION['pending_mfa_role']
    );

    // Keep only the identity needed for the MFA stage.
    $_SESSION['pending_mfa_user_id'] = $user['user_id'];
    $_SESSION['pending_mfa_role'] = $user['role'];

    // Continue to MFA setup or verification.
    if ((int) $user['mfa_enabled'] === 1) {
        header('Location: ../verify_mfa.php');
        exit;
    }

    header('Location: ../setup_mfa.php');
    exit;

} catch (PDOException $e) {
    error_log($e->getMessage());

    http_response_code(500);
    exit('A system error occurred. Please try again later.');
}

