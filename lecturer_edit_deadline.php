<?php
session_start();
require_once 'config/security.php';
require_once 'config/database.php'; 

require_once __DIR__ . '/includes/access.php';
require_role('lecturer');

$message = '';
$message_type = '';
$user_id = $_SESSION['user_id'];

$assignment_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($assignment_id === false || $assignment_id === null) {
    render_error(400, 'Bad Request', 'No valid assignment was selected.');
}

try {
    $stmt = $pdo->prepare("SELECT title, deadline FROM assignments WHERE id = :id AND created_by = :user_id");
    $stmt->execute([':id' => $assignment_id, ':user_id' => $user_id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        // Same response whether the id does not exist or belongs to another lecturer.
        render_forbidden();
    }

} catch (PDOException $e) {
    error_log('lecturer_edit_deadline: ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Security Error: CSRF token validation failed.");
    }

    $new_deadline = $_POST['deadline']; 
    $password = $_POST['password']; 

    try {
        $stmtUser = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :user_id");
        $stmtUser->execute([':user_id' => $user_id]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $message = "Incorrect password. Deadline change denied.";
            $message_type = 'error';
        } elseif (!validate_datetime($new_deadline)) {
            $message = "Invalid date format.";
            $message_type = 'error';
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE assignments SET deadline = :deadline WHERE id = :id");
            $stmtUpdate->execute([':deadline' => $new_deadline, ':id' => $assignment_id]);

            $old_deadline = $assignment['deadline'];
            $logDetails = "Changed deadline from $old_deadline to $new_deadline";
            
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (:user_id, 'EDIT_DEADLINE', :details, :ip)");
            $stmtLog->execute([
                ':user_id' => $user_id,
                ':details' => $logDetails,
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
            ]);

            $assignment['deadline'] = $new_deadline;
            $message = "Deadline updated to " . date('d M Y, h:i A', strtotime($new_deadline)) . " successfully.";
            $message_type = 'success';
        }
    } catch (PDOException $e) {
        error_log('lecturer_edit_deadline: ' . $e->getMessage());
        $message = "A system error occurred. Please try again later.";
        $message_type = 'error';
    }
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Deadline - EduSphere</title>
    <link rel="stylesheet" href="css/lecturer_dashboard_style.css">
    <style>
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #243b64; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 15px; }
        .form-control:focus { outline: none; border-color: #243b64; }
        .btn-submit { background-color: #243b64; color: white; padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; width: 100%; margin-top: 10px; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 25px; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .alert-success { background: #d4edda; color: #155724; }
        .warning-box { background: #fff3cd; color: #856404; padding: 15px; border-radius: 6px; font-size: 14px; margin-bottom: 20px; border: 1px solid #ffeeba; }
    </style>
</head>
<body>

    <header class="navbar">
        <div class="brand">EduSphere</div>
        <div class="navbar-right">
            <span class="role-label">Lecturer Account</span>
            <a class="logout-btn" href="logout.php">Log Out</a>
        </div>
    </header>

    <main class="container">
        <section class="welcome-section">
            <h1>Edit Assignment Deadline</h1>
            <p>Modify the deadline for this assignment. This action requires re-authentication.</p>
        </section>

        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'error' ? 'alert-error' : 'alert-success' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <section class="information" style="max-width: 600px;">
            <p style="margin-bottom: 15px;"><strong>Assignment:</strong> <?= escape($assignment['title']) ?></p>
            <p style="margin-bottom: 20px;"><strong>Current Deadline:</strong> <?= date('d M Y, h:i A', strtotime($assignment['deadline'])) ?></p>

            <div class="warning-box">
                <strong>Security Notice:</strong> You are required to enter your password to confirm this change.
            </div>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">

                <div class="form-group">
                    <label>New Deadline</label>
                    <input type="datetime-local" name="deadline" class="form-control" value="<?= escape(date('Y-m-d\TH:i', strtotime($assignment['deadline']))) ?>" required>
                </div>

                <div class="form-group">
                    <label>Confirm Your Password</label>
                    <input type="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-submit" onclick="return confirm('Are you sure you want to change the deadline?');">Update Deadline</button>
            </form>
            <br>
            <a href="lecturer_create_assignment.php" style="color: #64748b; text-decoration: none;">&larr; Back to Manage Assignments</a>
        </section>
    </main>

</body>
</html>