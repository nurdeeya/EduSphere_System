<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';

// Only authenticated Admin users can reset MFA.
if (
    !isset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['auth_complete']) ||
    $_SESSION['auth_complete'] !== true ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

// Process MFA reset request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf = $_POST['csrf_token'] ?? '';
    $userId = filter_input(
        INPUT_POST,
        'user_id',
        FILTER_VALIDATE_INT
    );

    if (
        !is_string($csrf) ||
        !hash_equals($_SESSION['csrf_token'], $csrf)
    ) {
        $error = 'Invalid request.';
    } elseif (!$userId || $userId <= 0) {
        $error = 'Please select a valid account.';
    } else {
        try {
            // Reset MFA for Student or Lecturer only.
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET mfa_enabled = 0,
                     mfa_secret = NULL
                 WHERE user_id = :user_id
                   AND role IN ('student', 'lecturer')
                   AND is_active = 1
                   AND mfa_enabled = 1"
            );

            $stmt->execute([
                'user_id' => $userId
            ]);

            if ($stmt->rowCount() > 0) {
                audit_log(
                    $pdo,
                    (int) $_SESSION['user_id'],
                    'MFA_RESET',
                    'target_user_id=' . $userId
                );

                $message = 'MFA reset successfully. The user must configure MFA again.';
            } else {
                $error = 'No eligible MFA-enabled account was updated.';
            }

        } catch (PDOException $e) {
            error_log($e->getMessage());
            $error = 'A system error occurred.';
        }
    }
}

// Retrieve Student and Lecturer accounts.
try {
    $stmt = $pdo->query(
        "SELECT user_id, login_identifier, full_name, role, mfa_enabled
         FROM users
         WHERE role IN ('student', 'lecturer')
           AND is_active = 1
         ORDER BY role, login_identifier"
    );

    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log($e->getMessage());
    $accounts = [];
    $error = 'Unable to load user accounts.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset MFA - EduSphere</title>

    <link rel="stylesheet" href="css/reset_mfa_style.css">

</head>
<body>
    <main class="card">
        <h1>Reset MFA</h1>

        <p class="description">
            Select a Student or Lecturer account to reset
            its Multi-Factor Authentication (MFA).

            After the reset, the user must configure MFA
            again during the next login.
        </p>

        <?php if ($message !== ''): ?>
            <div class="message" role="status">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="reset_mfa.php"
              onsubmit="return confirm('Reset MFA for the selected test account? The account will need to set up MFA again.');">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <label for="user_id">Select test account</label>

            <select name="user_id" id="user_id" required>
                <option value="">-- Choose an account --</option>

                <?php foreach ($accounts as $account): ?>
                    <option value="<?= (int) $account['user_id'] ?>">
                        <?= htmlspecialchars(
                            $account['login_identifier'] . ' — ' .
                            $account['full_name'] . ' (' .
                            $account['role'] . ') — MFA: ' .
                            ((int) $account['mfa_enabled'] === 1
                                ? 'Enabled'
                                : 'Disabled'),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </option>
                <?php endforeach; ?>
            </select>

<button type="submit">Reset User MFA</button>
</form>

<!-- Back to Admin Dashboard -->
<div class="back-dashboard-container">
    <a href="admin_dashboard.php" class="back-dashboard-btn">
        ← Back to Dashboard
    </a>
</div>
        <br>

        <div class="warning">
            <strong>Warning:</strong> This page is intended for local
            development only. Do not deploy it as an unrestricted reset
            function on a production server.
        </div>
    </main>
</body>
</html>