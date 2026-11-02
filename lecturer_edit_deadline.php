<?php
// FR3: Set / Edit Assignment Deadline
// Security: SR5 Deadline Change Control & Notification (Lab 4 threat #160):
//   - lecturer must re-confirm with PASSWORD + MFA CODE before the change
//   - deadline validated syntactically (format) and semantically (future, <= 1 year, actually changed)
//   - change + audit record saved in one transaction (non-repudiation)
//   - enrolled students are notified
//   - confirmation attempts limited (5 fails -> 15 min block)
session_start();
require_once 'config/security.php';
require_once 'config/database.php';

require_once __DIR__ . '/includes/access.php';
require_role('lecturer');
require_once __DIR__ . '/includes/lecturer.php';

$message = '';
$message_type = '';
$user_id = (int) $_SESSION['user_id'];

$assignment_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($assignment_id === false || $assignment_id === null) {
    render_error(400, 'Bad Request', 'No valid assignment was selected.');
}

function load_assignment(PDO $pdo, int $assignment_id, int $user_id)
{
    $stmt = $pdo->prepare("SELECT title, deadline FROM assignments WHERE id = :id AND created_by = :user_id");
    $stmt->execute([':id' => $assignment_id, ':user_id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    $assignment = load_assignment($pdo, $assignment_id, $user_id);

    if (!$assignment) {
        // Same response whether the id does not exist or belongs to another lecturer.
        audit_denied($pdo, $user_id, 'lecturer_edit_deadline id=' . $assignment_id . ' not owner or missing');
        render_forbidden();
    }

} catch (PDOException $e) {
    error_log('lecturer_edit_deadline: ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}

// ---- Confirmation attempt limiter (per session; login is needed again for a new session)
$rl = $_SESSION['deadline_reauth'] ?? ['fails' => 0, 'until' => 0];
if ($rl['until'] > 0 && $rl['until'] <= time()) {
    $rl = ['fails' => 0, 'until' => 0];
}
$blocked_for = max(0, $rl['until'] - time());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token(lec_str($_POST['csrf_token'] ?? ''))) {
        render_error(403, 'Security Check Failed', 'Your form session has expired. Please reload the page and try again.');
    }

    $new_deadline = lec_str($_POST['deadline'] ?? '');
    $password     = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $mfa_code     = lec_str($_POST['mfa_code'] ?? '');

    do {
        if ($blocked_for > 0) {
            $message = 'Too many failed confirmations. Please wait ' . (int) ceil($blocked_for / 60) . ' minute(s) and try again.';
            $message_type = 'error';
            break;
        }

        // Syntactic checks first (cheap, no DB access).
        if (!validate_datetime($new_deadline)) {
            $message = 'Invalid date format.';
            $message_type = 'error';
            break;
        }
        if ($password === '' || strlen($password) > 128 || !preg_match('/^\d{6}$/', $mfa_code)) {
            $message = 'Enter your password and the 6-digit code from your authenticator app.';
            $message_type = 'error';
            break;
        }

        try {
            // ---- Step-up authentication: password AND current MFA code.
            $stmtUser = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = :user_id AND role = 'lecturer' AND is_active = 1");
            $stmtUser->execute([':user_id' => $user_id]);
            $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

            $password_ok = $user && password_verify($password, $user['password_hash']);
            $mfa_ok      = $password_ok && lec_verify_mfa_code($pdo, $user_id, $mfa_code);

            if (!$password_ok || !$mfa_ok) {
                $rl['fails']++;
                if ($rl['fails'] >= LEC_REAUTH_MAX_FAILS) {
                    $rl['until'] = time() + LEC_REAUTH_LOCK_SECS;
                }
                $_SESSION['deadline_reauth'] = $rl;
                lec_audit($pdo, $user_id, 'DEADLINE_REAUTH_FAILED', 'assignment_id=' . $assignment_id . '; attempt=' . $rl['fails']);

                $message = 'Incorrect password or authenticator code. Deadline change denied.';
                $message_type = 'error';
                break;
            }

            // ---- Semantic checks
            $problem = lec_deadline_problem($pdo, $new_deadline);
            if ($problem === '' && date('Y-m-d\TH:i', strtotime($assignment['deadline'])) === $new_deadline) {
                $problem = 'The new deadline is the same as the current deadline.';
            }
            if ($problem !== '') {
                $message = $problem;
                $message_type = 'error';
                break;
            }

            // ---- Update + audit + notify, all or nothing
            $old_deadline = $assignment['deadline'];
            $pdo->beginTransaction();

            $stmtUpdate = $pdo->prepare("UPDATE assignments SET deadline = :deadline WHERE id = :id AND created_by = :user_id");
            $stmtUpdate->execute([':deadline' => $new_deadline, ':id' => $assignment_id, ':user_id' => $user_id]);

            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (:user_id, 'EDIT_DEADLINE', :details, :ip)");
            $stmtLog->execute([
                ':user_id' => $user_id,
                ':details' => 'assignment_id=' . $assignment_id . '; from=' . $old_deadline . '; to=' . str_replace('T', ' ', $new_deadline) . ':00; reauth=password+mfa',
                ':ip'      => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            ]);

            // SR5 Notification: one message per enrolled student.
            $note = 'Deadline changed for "' . $assignment['title'] . '": '
                  . date('d M Y, h:i A', strtotime($old_deadline)) . ' -> '
                  . date('d M Y, h:i A', strtotime($new_deadline));
            $stmtNotify = $pdo->prepare("
                INSERT INTO notifications (user_id, assignment_id, message)
                SELECT e.student_id, :assignment_id, :message
                FROM enrollments e
                WHERE e.lecturer_id = :lecturer_id
            ");
            $stmtNotify->execute([
                ':assignment_id' => $assignment_id,
                ':message'       => mb_substr($note, 0, 255, 'UTF-8'),
                ':lecturer_id'   => $user_id,
            ]);
            $notified = $stmtNotify->rowCount();

            $pdo->commit();
            unset($_SESSION['deadline_reauth']);

            lec_flash('success', 'Deadline updated to ' . date('d M Y, h:i A', strtotime($new_deadline))
                . '. ' . $notified . ' enrolled student(s) notified.');
            header('Location: lecturer_edit_deadline.php?id=' . $assignment_id);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('lecturer_edit_deadline: ' . $e->getMessage());
            $message = "A system error occurred. Please try again later.";
            $message_type = 'error';
        }
    } while (false);

    $blocked_for = max(0, ($rl['until'] ?? 0) - time());
}

$flash = lec_take_flash();
if ($flash['text'] !== '') {
    $message = $flash['text'];
    $message_type = $flash['type'];
}

// Recent deadline history for this assignment (from the audit log).
$history = [];
try {
    $stmtHist = $pdo->prepare("
        SELECT details, created_at FROM audit_logs
        WHERE user_id = :user_id AND action = 'EDIT_DEADLINE' AND details LIKE :prefix
        ORDER BY id DESC LIMIT 5
    ");
    $stmtHist->execute([':user_id' => $user_id, ':prefix' => 'assignment_id=' . $assignment_id . ';%']);
    $history = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('lecturer_edit_deadline (history): ' . $e->getMessage());
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Deadline - EduSphere</title>
    <link rel="stylesheet" href="css/lecturer_dashboard_style.css">
    <link rel="stylesheet" href="css/lecturer_module_style.css">
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
        <?php lec_nav('assignments'); ?>

        <section class="welcome-section">
            <h1>Edit Assignment Deadline</h1>
            <p>Modify the deadline for this assignment. This action requires your password and your authenticator code.</p>
        </section>

        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'error' ? 'alert-error' : 'alert-success' ?>" role="<?= $message_type === 'error' ? 'alert' : 'status' ?>">
                <?= escape($message) ?>
            </div>
        <?php endif; ?>

        <section class="information lec-narrow">
            <p style="margin-bottom: 15px;"><strong>Assignment:</strong> <?= escape($assignment['title']) ?></p>
            <p style="margin-bottom: 20px;"><strong>Current Deadline:</strong> <?= escape(date('d M Y, h:i A', strtotime($assignment['deadline']))) ?></p>

            <div class="warning-box">
                <strong>Security Notice:</strong> Changing a deadline affects every enrolled student and is recorded in the audit log.
                Confirm with your password and the 6-digit code from your authenticator app. Enrolled students will be notified.
            </div>

            <form method="POST" action="lecturer_edit_deadline.php?id=<?= (int) $assignment_id ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">

                <div class="form-group">
                    <label for="deadline">New Deadline</label>
                    <input type="datetime-local" id="deadline" name="deadline" class="form-control" min="<?= escape(date('Y-m-d\TH:i')) ?>" value="<?= escape(date('Y-m-d\TH:i', strtotime($assignment['deadline']))) ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">Confirm Your Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" maxlength="128" autocomplete="current-password" required>
                </div>

                <div class="form-group">
                    <label for="mfa_code">Authenticator Code</label>
                    <input type="text" id="mfa_code" name="mfa_code" class="form-control" placeholder="6-digit code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required>
                </div>

                <button type="submit" class="btn-submit" <?= $blocked_for > 0 ? 'disabled' : '' ?> onclick="return confirm('Are you sure you want to change the deadline?');">Update Deadline</button>
            </form>
        </section>

        <?php if ($history): ?>
        <section class="information lec-narrow">
            <h2>Recent Deadline Changes</h2>
            <div class="table-wrap">
            <table class="lec-table">
                <thead><tr><th>Changed At</th><th>Details</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= escape(date('d M Y, h:i A', strtotime($h['created_at']))) ?></td>
                        <td class="small"><?= escape($h['details']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </section>
        <?php endif; ?>

        <a href="lecturer_create_assignment.php" class="back-link">&larr; Back to Manage Assignments</a>
    </main>

</body>
</html>
