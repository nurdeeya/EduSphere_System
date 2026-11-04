
<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/vendor/autoload.php';

use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;

// Require a valid pending login.
if (
    !isset(
        $_SESSION['pending_mfa_user_id'],
        $_SESSION['pending_mfa_role']
    ) ||
    !in_array(
        $_SESSION['pending_mfa_role'],
        ['student', 'lecturer', 'admin'],
        true
    )
) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';
$qrDataUri = null;

try {
    $stmt = $pdo->prepare(
        'SELECT user_id, login_identifier, mfa_enabled
         FROM users
         WHERE user_id = :user_id
           AND role = :role
           AND is_active = 1
         LIMIT 1'
    );

    $stmt->execute([
        'user_id' => $_SESSION['pending_mfa_user_id'],
        'role' => $_SESSION['pending_mfa_role']
    ]);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // This page is only for accounts that have not enabled MFA.
    if ((int) $user['mfa_enabled'] === 1) {
        header('Location: verify_mfa.php');
        exit;
    }

    $tfa = new TwoFactorAuth(
        new BaconQrCodeProvider(format: 'svg'),
        'EduSphere'
    );

    if (
        empty($_SESSION['pending_mfa_secret']) ||
        !is_string($_SESSION['pending_mfa_secret'])
    ) {
        $_SESSION['pending_mfa_secret'] = $tfa->createSecret();
    }

    $secret = $_SESSION['pending_mfa_secret'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = $_POST['csrf_token'] ?? '';
        $code = trim($_POST['verification_code'] ?? '');

        if (
            !is_string($csrf) ||
            !hash_equals($_SESSION['csrf_token'], $csrf)
        ) {
            $error = 'Invalid request. Please try again.';
        } elseif (!preg_match('/^\d{6}$/', $code)) {
            $error = 'Enter the six-digit code from your authenticator app.';
        } elseif (!$tfa->verifyCode($secret, $code)) {
            $error = 'Invalid code. Check your authenticator app and try again.';
        } else {
            $save = $pdo->prepare(
                'UPDATE users
                 SET mfa_secret = :secret,
                     mfa_enabled = 1
                 WHERE user_id = :user_id
                   AND mfa_enabled = 0'
            );

            $save->execute([
                'secret' => $secret,
                'user_id' => $user['user_id']
            ]);

            if ($save->rowCount() !== 1) {
                $error = 'MFA could not be enabled. Please try again.';
            } else {
                // MFA setup has been successfully verified.
                // The database now has MFA enabled for this user.

                session_regenerate_id(true);

                // Complete the authentication process.
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role'] = $_SESSION['pending_mfa_role'];
                $_SESSION['auth_complete'] = true;
                audit_log($pdo, (int) $user['user_id'], 'LOGIN_SUCCESS', 'role=' . $_SESSION['role'] . '; mfa_setup=1');

                // Remove temporary login and setup information.
            unset(
                $_SESSION['pending_mfa_user_id'],
                $_SESSION['pending_mfa_role'],
                $_SESSION['pending_mfa_secret'],
                $_SESSION['csrf_token']
            );

                // Redirect to the correct dashboard.
            if ($_SESSION['role'] === 'student') {
                header('Location: student_dashboard.php');
                exit;
            }

            if ($_SESSION['role'] === 'lecturer') {
                header('Location: lecturer_dashboard.php');
                exit;
            }

            if ($_SESSION['role'] === 'admin') {
                header('Location: admin_dashboard.php');
                exit;
            }

                // Fallback if the role is invalid.
                session_unset();
                session_destroy();
                header('Location: login.php');
                exit;
            }
        }
    }

    $qrDataUri = $tfa->getQRCodeImageAsDataUri(
        $user['login_identifier'],
        $secret
    );

} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    exit('A system error occurred. Please check the PHP error log.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Up MFA - EduSphere</title>

    <link rel="stylesheet" href="css/setup_mfa_style.css">
</head>
<body>

    <main class="mfa-card">

        <div class="brand">EduSphere</div>
        <p class="subtitle">Secure Learning Management System</p>

        <h1>Set Up Multi-Factor Authentication</h1>

        <p class="intro">
            Complete the two steps below to secure your account
            before accessing your dashboard.
        </p>

        <div class="account-info">
            <p>
                <strong>Username Account:</strong>
                <?= htmlspecialchars(
                    $user['login_identifier'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>

            <p>
                <strong>Role:</strong>
                <?= htmlspecialchars(
                    $_SESSION['pending_mfa_role'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error-message" role="alert">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="setup_mfa.php" id="mfa-form">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION['csrf_token'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
            >

            <!-- STEP 1: SCAN QR CODE -->
            <section class="step-card">

                <div class="step-heading">
                    <span class="step-number">1</span>
                    <h2>Connect Authenticator App</h2>
                </div>

                <p class="step-description">
                    Open Google Authenticator, Microsoft Authenticator,
                    or another compatible app. Scan this QR code
                    to connect your account.
                </p>

                <div class="qr-wrapper">
                    <img
                        src="<?= htmlspecialchars(
                            $qrDataUri,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        alt="MFA setup QR code"
                        width="190"
                        height="190"
                    >
                </div>

            </section>

            <!-- STEP 2: ENTER VERIFICATION CODE -->
            <section class="step-card">

                <div class="step-heading">
                    <span class="step-number">2</span>
                    <h2>Verify Your Account</h2>
                </div>

                <p class="step-description">
                    Enter the six-digit code currently displayed
                    in your authenticator app.
                </p>

                <!-- PHP receives the complete code in this field -->
                <input
                    type="hidden"
                    name="verification_code"
                    id="verification_code"
                    value=""
                >

                <div class="code-boxes" aria-label="Six-digit verification code">

                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input
                            type="text"
                            class="code-box"
                            inputmode="numeric"
                            pattern="[0-9]"
                            maxlength="1"
                            aria-label="Verification digit <?= $i + 1 ?>"
                            autocomplete="<?= $i === 0 ? 'one-time-code' : 'off' ?>"
                            required
                        >
                    <?php endfor; ?>

                </div>

                <button type="submit" class="verify-button">
                    Verify and Enable MFA
                </button>

            </section>

        </form>

        <p class="footer-note">
            Your account will be protected by multi-factor authentication
            after successful verification.
        </p>

    </main>

    <script>
        const form = document.getElementById('mfa-form');
        const boxes = Array.from(
            document.querySelectorAll('.code-box')
        );
        const hiddenCode = document.getElementById('verification_code');

        // Move to the next box after entering one digit.
        boxes.forEach((box, index) => {

            box.addEventListener('input', () => {
                const digits = box.value.replace(/\D/g, '');

                box.value = digits.slice(-1);

                if (box.value && index < boxes.length - 1) {
                    boxes[index + 1].focus();
                }
            });

            // Backspace moves to the previous empty box.
            box.addEventListener('keydown', (event) => {
                if (
                    event.key === 'Backspace' &&
                    !box.value &&
                    index > 0
                ) {
                    boxes[index - 1].focus();
                }

                if (
                    event.key === 'ArrowLeft' &&
                    index > 0
                ) {
                    boxes[index - 1].focus();
                }

                if (
                    event.key === 'ArrowRight' &&
                    index < boxes.length - 1
                ) {
                    boxes[index + 1].focus();
                }
            });

            // Allow pasting the complete six-digit code.
            box.addEventListener('paste', (event) => {
                event.preventDefault();

                const pasted = (
                    event.clipboardData.getData('text') || ''
                ).replace(/\D/g, '').slice(0, 6);

                pasted.split('').forEach((digit, i) => {
                    boxes[i].value = digit;
                });

                const nextEmpty = boxes.find(
                    item => item.value === ''
                );

                if (nextEmpty) {
                    nextEmpty.focus();
                } else {
                    boxes[5].focus();
                }
            });
        });

        // Combine the six boxes into the field expected by PHP.
        form.addEventListener('submit', (event) => {
            const code = boxes.map(box => box.value).join('');

            hiddenCode.value = code;

            if (!/^\d{6}$/.test(code)) {
                event.preventDefault();

                const firstEmpty = boxes.find(
                    box => box.value === ''
                );

                if (firstEmpty) {
                    firstEmpty.focus();
                }
            }
        });
    </script>

</body>
</html>