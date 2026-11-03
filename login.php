
<?php
session_start();

// Redirect only fully authenticated users.
if (
    isset(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['auth_complete']
    ) &&
    $_SESSION['auth_complete'] === true
) {
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
}

// Display login messages.
$error = $_SESSION['login_error'] ?? '';
$showLockoutPopup = $_SESSION['show_lockout_popup'] ?? false;

unset($_SESSION['login_error']);
unset($_SESSION['show_lockout_popup']);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduSphere System</title>

    <link rel="stylesheet" href="css/login_style.css">
</head>

<body>
    <div class="login-container">
        <h1>EduSphere System</h1>
        <p class="subtitle">
            Sign in using your university credentials.
        </p>

    <!-- display error message -->
    <?php if ($error !== ''): ?>
    <div
        class="error"
        role="alert"
        aria-live="assertive"
    >
        <strong>Login Notice</strong>
        <p>
            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </p>
    </div>
    <?php endif; ?>

    <?php if ($showLockoutPopup): ?>
    <script>
        alert(<?= json_encode(
            $error,
            JSON_HEX_TAG |
            JSON_HEX_APOS |
            JSON_HEX_QUOT |
            JSON_HEX_AMP
        ) ?>);
    </script>
<?php endif; ?>
        
        <form action="includes/authentication.php" method="POST">
            <label for="login_identifier">Username</label>
            <input
                type="text"
                id="login_identifier"
                name="login_identifier"
                placeholder ="Enter username"
                autocomplete="username"
                required
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder ="Enter password"
                autocomplete="new-password"
                required
            >

                <!--<div class="forgot-password-container" style="text-align: right;">
                    <a href="forgot_password.php" class="forgot-password-link">Forgot Password?</a>
                </div> -->
            <br>
            <button type="submit">Log In</button>

    </div>
</body>
</html>