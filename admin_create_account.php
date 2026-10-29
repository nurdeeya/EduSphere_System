
<?php
session_start();

require_once __DIR__ . '/config/database.php';

// Allow only fully authenticated Admin accounts.
if (
    !isset(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['auth_complete']
    ) ||
    $_SESSION['auth_complete'] !== true ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

// Create a CSRF token for form protection.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$fullName = $_SESSION['full_name'] ?? 'Administrator';

$errors = [];
$success = '';
$loginIdentifier = '';
$accountFullName = '';
$email = '';

// Allow the dashboard quick-action links to preselect a role.
$selectedRole = $_POST['role']
    ?? $_GET['role']
    ?? 'student';

if (!in_array($selectedRole, ['student', 'lecturer'], true)) {
    $selectedRole = 'student';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginIdentifier = trim($_POST['login_identifier'] ?? '');
    $accountFullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $selectedRole = $_POST['role'] ?? '';

    // Validate CSRF token.
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION['csrf_token'], $submittedToken)
    ) {
        $errors[] = 'Invalid form request. Please refresh the page and try again.';
    }

    // Validate username.
    if (
        $loginIdentifier === '' ||
        !preg_match('/\A[a-zA-Z0-9._-]{3,50}\z/', $loginIdentifier)
    ) {
        $errors[] = 'Username must contain 3–50 characters and use only letters, numbers, dots, underscores or hyphens.';
    }

    // Validate full name.
    if (
        $accountFullName === '' ||
        mb_strlen($accountFullName) > 100
    ) {
        $errors[] = 'Full name is required and must not exceed 100 characters.';
    }

    // Validate email.
    if (
        $email === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 254
    ) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Validate role.
    if (!in_array($selectedRole, ['student', 'lecturer'], true)) {
        $errors[] = 'Please select a valid account role.';
    }

    // Validate password.
    if (strlen($password) < 12) {
        $errors[] = 'Password must contain at least 12 characters.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Password and confirm password do not match.';
    }

    if (empty($errors)) {
        try {
            // Check whether the username or email already exists.
            $check = $pdo->prepare(
                'SELECT login_identifier, email
                 FROM users
                 WHERE login_identifier = :username
                    OR email = :email
                 LIMIT 1'
            );

            $check->execute([
                'username' => $loginIdentifier,
                'email' => $email
            ]);

            $existingUser = $check->fetch(PDO::FETCH_ASSOC);

            if ($existingUser) {
                if (
                    strcasecmp(
                        $existingUser['login_identifier'],
                        $loginIdentifier
                    ) === 0
                ) {
                    $errors[] = 'This username is already in use.';
                }

                if (
                    strcasecmp(
                        $existingUser['email'],
                        $email
                    ) === 0
                ) {
                    $errors[] = 'This email address is already in use.';
                }
            } else {
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                if ($passwordHash === false) {
                    throw new RuntimeException(
                        'Password hashing failed.'
                    );
                }

                // Create an active account with MFA not yet configured.
                $insert = $pdo->prepare(
                    'INSERT INTO users (
                        login_identifier,
                        full_name,
                        email,
                        password_hash,
                        role,
                        mfa_enabled,
                        is_active,
                        failed_login_attempts,
                        locked_until
                    ) VALUES (
                        :username,
                        :full_name,
                        :email,
                        :password_hash,
                        :role,
                        0,
                        1,
                        0,
                        NULL
                    )'
                );

                $insert->execute([
                    'username' => $loginIdentifier,
                    'full_name' => $accountFullName,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => $selectedRole
                ]);

                $success = ucfirst($selectedRole)
                    . ' account created successfully.';

                // Clear form fields after successful creation.
                $loginIdentifier = '';
                $accountFullName = '';
                $email = '';
                $selectedRole = 'student';

                // Use a fresh token after submission.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        } catch (PDOException $e) {
            error_log(
                'Admin account creation error: ' . $e->getMessage()
            );

            $errors[] = 'Unable to create the account. Please check the database configuration and try again.';
        } catch (RuntimeException $e) {
            error_log($e->getMessage());
            $errors[] = 'Unable to process the password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account | EduSphere</title>

    <link rel="stylesheet" href="css/admin_create_account_style.css">
</head>
<body>

<div class="dashboard-container">

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">

        <div class="brand">
            <div class="brand-icon">E</div>

            <div class="brand-text">
                <h2>EduSphere</h2>
                <p>Learning Management System</p>
            </div>
        </div>

        <div class="sidebar-section">
            <p class="sidebar-heading">MAIN MENU</p>

            <a href="admin_dashboard.php" class="nav-link">
                <span class="nav-icon">&#9633;</span>
                <span>Dashboard</span>
            </a>

            <a href="admin_create_account.php"
               class="nav-link active"
               aria-current="page">
                <span class="nav-icon">&#43;</span>
                <span>Create Account</span>
            </a>
        </div>

        <div class="sidebar-section">
            <p class="sidebar-heading">ACCOUNT</p>

            <a href="logout.php" class="nav-link logout-link">
                <span class="nav-icon">&#10140;</span>
                <span>Log Out</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <div class="security-icon">&#128274;</div>
            <div>
                <strong>Secure Access</strong>
                <p>Administrator Portal</p>
            </div>
        </div>

    </aside>

    <!-- Main Content -->
    <main class="main-content">

        <header class="topbar">

            <button
                type="button"
                class="menu-toggle"
                id="menuToggle"
                aria-label="Toggle navigation"
                aria-controls="sidebar"
                aria-expanded="false">
                &#9776;
            </button>

            <div class="breadcrumb">
                <a href="admin_dashboard.php">EduSphere</a>
                <span class="breadcrumb-divider">/</span>
                <strong>Create Account</strong>
            </div>

            <div class="profile">
                <div class="profile-avatar">A</div>

                <div class="profile-details">
                    <strong><?= htmlspecialchars(
                        $fullName,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></strong>
                    <span>Administrator</span>
                </div>
            </div>

        </header>

        <section class="page-content">

            <div class="welcome-section">
                <div>
                    <p class="eyebrow">ACCOUNT MANAGEMENT</p>
                    <h1>Create New Account</h1>
                    <p class="welcome-message">
                        Create student and lecturer accounts for EduSphere.
                    </p>
                </div>

                <a href="admin_dashboard.php" class="secondary-button">
                    &larr; Back to Dashboard
                </a>
            </div>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success" role="status">
                    <strong>Success!</strong>
                    <?= htmlspecialchars(
                        $success,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                    The new user can log in and complete MFA setup.
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-error" role="alert">
                    <strong>Please check the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="form-layout">

                <section class="form-card">
                    <div class="card-heading">
                        <div class="heading-icon">&#43;</div>
                        <div>
                            <h2>Account Information</h2>
                            <p>Enter the details for the new user.</p>
                        </div>
                    </div>

                    <form method="POST"
                          action="admin_create_account.php"
                          class="account-form">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $_SESSION['csrf_token'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>">

                        <div class="form-group">
                            <label for="role">Account Role
                                <span class="required">*</span>
                            </label>

                            <select id="role" name="role" required>
                                <option value="student"
                                    <?= $selectedRole === 'student'
                                        ? 'selected' : '' ?>>
                                    Student
                                </option>

                                <option value="lecturer"
                                    <?= $selectedRole === 'lecturer'
                                        ? 'selected' : '' ?>>
                                    Lecturer
                                </option>
                            </select>

                            <small>
                                Administrator accounts cannot be created
                                from this form.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="full_name">Full Name
                                <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                id="full_name"
                                name="full_name"
                                maxlength="100"
                                autocomplete="name"
                                placeholder="Enter user's full name"
                                value="<?= htmlspecialchars(
                                    $accountFullName,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="login_identifier">Username
                                <span class="required">*</span>
                            </label>

                            <input
                                type="text"
                                id="login_identifier"
                                name="login_identifier"
                                minlength="3"
                                maxlength="50"
                                pattern="[A-Za-z0-9._-]{3,50}"
                                autocomplete="username"
                                placeholder="e.g. student123"
                                value="<?= htmlspecialchars(
                                    $loginIdentifier,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required>

                            <small>
                                Use 3–50 letters, numbers, dots,
                                underscores or hyphens.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="email">Email Address
                                <span class="required">*</span>
                            </label>

                            <input
                                type="email"
                                id="email"
                                name="email"
                                maxlength="254"
                                autocomplete="email"
                                placeholder="e.g. user@example.com"
                                value="<?= htmlspecialchars(
                                    $email,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                required>
                        </div>

                        <div class="form-group">
                            <label for="password">Initial Password
                                <span class="required">*</span>
                            </label>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                minlength="12"
                                autocomplete="new-password"
                                placeholder="Enter a strong password"
                                required>

                            <small>
                                Minimum 12 characters. Give the password
                                to the intended user securely.
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">
                                Confirm Password
                                <span class="required">*</span>
                            </label>

                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                minlength="12"
                                autocomplete="new-password"
                                placeholder="Re-enter the password"
                                required>
                        </div>

                        <div class="form-actions">
                            <button type="reset" class="reset-button">
                                Clear Form
                            </button>

                            <button type="submit" class="primary-button">
                                <span aria-hidden="true">&#43;</span>
                                Create Account
                            </button>
                        </div>

                    </form>
                </section>

                <aside class="info-column">

                    <section class="info-card">
                        <div class="info-icon">&#128274;</div>
                        <h3>Security Protection</h3>
                        <ul>
                            <li>Passwords are hashed before storage.</li>
                            <li>Input is validated on the server.</li>
                            <li>Database queries use prepared statements.</li>
                            <li>Only authenticated administrators can access this page.</li>
                        </ul>
                    </section>

                    <section class="info-card account-note">
                        <div class="info-icon">&#9432;</div>
                        <h3>After Account Creation</h3>
                        <p>
                            The account is active, but MFA is not yet
                            configured. On first login, the user should
                            be directed to the MFA setup page according
                            to the existing authentication flow.
                        </p>
                    </section>

                </aside>

            </div>

            <footer class="page-footer">
                <p>
                    &copy; <?= date('Y') ?> EduSphere.
                    Learning management made easier.
                </p>
            </footer>

        </section>

    </main>

</div>

<script>
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    menuToggle.addEventListener('click', function () {
        const isOpen = sidebar.classList.toggle('sidebar-open');

        menuToggle.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );
    });
</script>

</body>
</html>
