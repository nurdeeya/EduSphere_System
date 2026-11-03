
<?php
require_once __DIR__ . '/config/database.php';

$loginIdentifier = 'admin.esphere'; //username
$fullName = 'EduSphere Administrator';
$email = 'admin@edusphere.local'; //email
$password = 'Fa17!Lmr-Z72xHfzr$'; //password

try {
    // Prevent creating the same account twice.
    $check = $pdo->prepare(
        'SELECT user_id FROM users
         WHERE login_identifier = :identifier
         LIMIT 1'
    );

    $check->execute([
        'identifier' => $loginIdentifier
    ]);

    if ($check->fetch()) {
        exit(
            'Admin account already exists. '
            . 'No changes were made.'
        );
    }

    // Hash the password securely.
    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    // Create an active Admin account.
    // MFA remains disabled until the first login setup.
    $sql = 'INSERT INTO users (
                login_identifier,
                full_name,
                email,
                password_hash,
                role,
                failed_login_attempts,
                locked_until,
                is_active,
                mfa_enabled,
                mfa_secret
            ) VALUES (
                :identifier,
                :full_name,
                :email,
                :password_hash,
                :role,
                0,
                NULL,
                1,
                0,
                NULL
            )';

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        'identifier' => $loginIdentifier,
        'full_name' => $fullName,
        'email' => $email,
        'password_hash' => $passwordHash,
        'role' => 'admin'
    ]);

    echo 'Admin account created successfully.';
    echo '<br>Username: admin.esphere';
    echo '<br>MFA must be configured during first login.';

} catch (PDOException $e) {
    echo '<pre>';
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo '</pre>';
}
