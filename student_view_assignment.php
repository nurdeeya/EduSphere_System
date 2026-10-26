<?php
session_start();
require_once 'config/security.php';
require_once 'config/database.php';
require_once 'config/upload.php';

// SR2: only fully authenticated (MFA-verified) students.
require_once __DIR__ . '/includes/access.php';
require_role('student');

const SUBMISSION_UPLOAD_DIR = __DIR__ . '/uploads';
const ALLOWED_SUBMISSION_EXT = ['pdf', 'docx', 'zip'];
const MAX_REMARKS_LENGTH = 1000;

// SR4: identity always comes from the session, never from GET/POST.
$student_id = (int) $_SESSION['user_id'];

// Strict integer casting of the assignment id (rejects "5abc", "1.5", "-1", arrays...).
$assignment_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($assignment_id === false || $assignment_id === null) {
    render_error(400, 'Bad Request', 'No valid assignment was selected.');
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function format_remaining(int $seconds): string
{
    $days  = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins  = intdiv($seconds % 3600, 60);
    $parts = [];
    if ($days > 0)  { $parts[] = $days . ($days === 1 ? ' day' : ' days'); }
    if ($hours > 0) { $parts[] = $hours . ($hours === 1 ? ' hour' : ' hours'); }
    $parts[] = $mins . ($mins === 1 ? ' minute' : ' minutes');
    return implode(' ', $parts);
}

/** Fetch the assignment plus time-to-deadline measured by the database clock. */
function fetch_assignment(PDO $pdo, int $assignment_id)
{
    $stmt = $pdo->prepare("
        SELECT id, title, description, file_path, deadline, created_by,
               TIMESTAMPDIFF(SECOND, NOW(), deadline) AS seconds_left
        FROM assignments
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $assignment_id]);
    return $stmt->fetch();
}

/** Fetch THIS student's submission for the assignment (ownership enforced in the WHERE clause). */
function fetch_submission(PDO $pdo, int $assignment_id, int $student_id)
{
    $stmt = $pdo->prepare("
        SELECT id, file_path, submitted_at, status, remarks
        FROM submissions
        WHERE assignment_id = :assignment_id AND student_id = :student_id
        LIMIT 1
    ");
    $stmt->execute([':assignment_id' => $assignment_id, ':student_id' => $student_id]);
    return $stmt->fetch();
}

try {
    $assignment = fetch_assignment($pdo, $assignment_id);
} catch (PDOException $e) {
    error_log('student_view_assignment (assignment): ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}

// SR2: the student must be enrolled with the lecturer who created this assignment.
// One 403 for "does not exist" and "not enrolled" so ids cannot be enumerated.
$allowed = false;
if ($assignment) {
    try {
        $allowed = student_is_enrolled($pdo, $student_id, (int) $assignment['created_by']);
    } catch (PDOException $e) {
        error_log('student_view_assignment (enrolment): ' . $e->getMessage());
        render_error(500, 'System Error', 'A system error occurred. Please try again later.');
    }
}
if (!$allowed) {
    audit_denied($pdo, $student_id, 'student_view_assignment id=' . $assignment_id . ' not enrolled or missing');
    render_forbidden();
}

$message = '';
$message_type = '';

// Flash message left by the post/redirect/get below.
if (isset($_SESSION['student_flash'])) {
    $message = $_SESSION['student_flash']['text'];
    $message_type = $_SESSION['student_flash']['type'];
    unset($_SESSION['student_flash']);
}

// ---------------------------------------------------------------- POST: submit / re-submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploaded_name = null;

    do {
        // A body larger than post_max_size arrives with empty $_POST/$_FILES.
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $message = 'The file is too large. The maximum allowed size is 10MB.';
            $message_type = 'error';
            break;
        }

        // SR7: CSRF check on every state-changing request.
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            $message = 'Security check failed (invalid CSRF token). Please reload the page and try again.';
            $message_type = 'error';
            break;
        }

        $remarks = trim((string) ($_POST['remarks'] ?? ''));
        if (mb_strlen($remarks, 'UTF-8') > MAX_REMARKS_LENGTH) {
            $message = 'Remarks are too long (maximum ' . MAX_REMARKS_LENGTH . ' characters).';
            $message_type = 'error';
            break;
        }
        $remarks = ($remarks === '') ? null : $remarks;

        $file = $_FILES['submission_file'] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error'])) {
            $message = 'Please choose a file to submit.';
            $message_type = 'error';
            break;
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            $message = 'Please choose a file to submit.';
            $message_type = 'error';
            break;
        }
        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $message = 'File size exceeds the 10MB limit.';
            $message_type = 'error';
            break;
        }

        // secure_file_upload() keeps the client-supplied extension, so whitelist it first
        // (otherwise a valid PDF named "x.php" would be stored as "<hash>.php").
        $client_ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($client_ext, ALLOWED_SUBMISSION_EXT, true)) {
            $message = 'Invalid file type. Only PDF, DOCX, and ZIP are allowed.';
            $message_type = 'error';
            break;
        }

        // SR3: magic-byte MIME check, 10MB limit, random filename.
        $result = secure_file_upload($file, SUBMISSION_UPLOAD_DIR);

        // The helper returns either the new filename or an error sentence.
        if (!is_string($result) || !preg_match('/^[a-f0-9]{32}\.(pdf|docx|zip)$/i', $result)) {
            $message = is_string($result) ? $result : 'File upload failed.';
            $message_type = 'error';
            break;
        }
        $uploaded_name = $result;

        try {
            $old = fetch_submission($pdo, $assignment_id, $student_id);

            // SR6: timestamp and status are computed by the database clock in one statement.
            // student_id / assignment_id are bound from the session / validated GET id only.
            $stmt = $pdo->prepare("
                INSERT INTO submissions (assignment_id, student_id, file_path, submitted_at, status, remarks)
                SELECT a.id, :student_id, :file_path, NOW(),
                       IF(NOW() <= a.deadline, 'Submitted', 'Late'), :remarks
                FROM assignments a
                WHERE a.id = :assignment_id
                ON DUPLICATE KEY UPDATE
                    file_path    = VALUES(file_path),
                    submitted_at = VALUES(submitted_at),
                    status       = VALUES(status),
                    remarks      = VALUES(remarks)
            ");
            $stmt->execute([
                ':student_id'    => $student_id,
                ':file_path'     => $uploaded_name,
                ':remarks'       => $remarks,
                ':assignment_id' => $assignment_id,
            ]);

            $saved = fetch_submission($pdo, $assignment_id, $student_id);
            if (!$saved || $saved['file_path'] !== $uploaded_name) {
                throw new RuntimeException('Submission row was not written.');
            }

            $action = $old ? 'SUBMISSION_UPDATED' : 'SUBMISSION_CREATED';
            $log = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, details, ip_address)
                VALUES (:user_id, :action, :details, :ip_address)
            ");
            $log->execute([
                ':user_id'    => $student_id,
                ':action'     => $action,
                ':details'    => 'assignment_id=' . $assignment_id . '; file=' . $uploaded_name . '; status=' . $saved['status'],
                ':ip_address' => client_ip(),
            ]);

            // Housekeeping: remove the file this submission replaced (name pattern is re-checked).
            if ($old && preg_match('/^[a-f0-9]{32}\.[A-Za-z0-9]{1,10}$/', $old['file_path']) && $old['file_path'] !== $uploaded_name) {
                $old_path = SUBMISSION_UPLOAD_DIR . '/' . basename($old['file_path']);
                if (is_file($old_path)) {
                    @unlink($old_path);
                }
            }

            $_SESSION['student_flash'] = [
                'type' => 'success',
                'text' => $saved['status'] === 'Late'
                    ? 'Your submission was received, but it was marked as LATE because the deadline has passed.'
                    : 'Your submission was received on time. Well done!',
            ];
            header('Location: student_view_assignment.php?id=' . $assignment_id);
            exit;
        } catch (Throwable $e) {
            error_log('student_view_assignment (submit): ' . $e->getMessage());
            // Do not leave an orphaned upload behind.
            $orphan = SUBMISSION_UPLOAD_DIR . '/' . basename($uploaded_name);
            if (is_file($orphan)) {
                @unlink($orphan);
            }
            $message = 'A system error occurred while saving your submission. Please try again.';
            $message_type = 'error';
        }
    } while (false);
}

// ---------------------------------------------------------------- Render data
try {
    $assignment = fetch_assignment($pdo, $assignment_id);   // fresh clock reading
    $submission = fetch_submission($pdo, $assignment_id, $student_id);
} catch (PDOException $e) {
    error_log('student_view_assignment (render): ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}

$seconds_left = (int) $assignment['seconds_left'];
$is_past      = $seconds_left <= 0;
$csrf_token   = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment - EduSphere</title>
    <link rel="stylesheet" href="css/student_dashboard_style.css">
    <link rel="stylesheet" href="css/student_assignments_style.css">
</head>
<body>

    <header class="navbar">
        <div class="brand">EduSphere</div>
        <div class="navbar-right">
            <span class="role-label">Student Account</span>
            <a class="logout-btn" href="logout.php">Log Out</a>
        </div>
    </header>

    <main class="container" style="max-width: 800px;">
        <section class="welcome-section">
            <h1><?= escape($assignment['title']) ?></h1>
            <p>Review the assignment details and submit your work below.</p>
        </section>

        <?php if ($message !== ''): ?>
            <div class="alert <?= $message_type === 'error' ? 'alert-error' : 'alert-success' ?>" role="alert">
                <?= escape($message) ?>
            </div>
        <?php endif; ?>

        <section class="information" style="margin-bottom: 25px;">
            <h2>Assignment Details</h2>
            <div class="detail-box" style="margin-top: 12px;">
                <div class="detail-label">Title</div>
                <div class="detail-value"><strong><?= escape($assignment['title']) ?></strong></div>

                <div class="detail-label">Description</div>
                <div class="detail-value">
                    <?php if (!empty($assignment['description'])): ?>
                        <?= nl2br(escape($assignment['description'])) ?>
                    <?php else: ?>
                        <span class="muted">No description provided.</span>
                    <?php endif; ?>
                </div>

                <div class="detail-label">Lecturer Material</div>
                <div class="detail-value">
                    <?php if (!empty($assignment['file_path'])): ?>
                        <a class="btn" href="download.php?type=material&amp;id=<?= (int) $assignment['id'] ?>">Download Material</a>
                    <?php else: ?>
                        <span class="muted">No file attached.</span>
                    <?php endif; ?>
                </div>

                <div class="detail-label">Deadline</div>
                <div class="detail-value"><?= escape(date('d M Y, h:i A', strtotime($assignment['deadline']))) ?></div>

                <div class="detail-label">Time Remaining</div>
                <div class="detail-value">
                    <?php if ($is_past): ?>
                        <span class="countdown closed">Deadline passed &ndash; submissions are still accepted but will be marked Late.</span>
                    <?php else: ?>
                        <span class="countdown open" id="countdown" data-seconds="<?= $seconds_left ?>"><?= escape(format_remaining($seconds_left)) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="information">
            <h2>Your Submission</h2>

            <?php if ($submission): ?>
                <div class="detail-box" style="margin-top: 12px;">
                    <div class="detail-label">Status</div>
                    <div class="detail-value">
                        <?php if ($submission['status'] === 'Late'): ?>
                            <span class="badge badge-late">Submitted (Late)</span>
                        <?php else: ?>
                            <span class="badge badge-ok">Submitted (On Time)</span>
                        <?php endif; ?>
                    </div>

                    <div class="detail-label">Submitted File</div>
                    <div class="detail-value">
                        <?= escape($submission['file_path']) ?><br>
                        <a class="btn btn-outline" style="margin-top: 8px;" href="download.php?type=submission&amp;id=<?= (int) $submission['id'] ?>">Download My Submission</a>
                    </div>

                    <div class="detail-label">Submitted At (server time)</div>
                    <div class="detail-value"><?= escape(date('d M Y, h:i:s A', strtotime($submission['submitted_at']))) ?></div>

                    <div class="detail-label">Remarks</div>
                    <div class="detail-value">
                        <?php if ($submission['remarks'] !== null && $submission['remarks'] !== ''): ?>
                            <?= nl2br(escape($submission['remarks'])) ?>
                        <?php else: ?>
                            <span class="muted">No remarks.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <h3 style="margin: 20px 0 12px; color: #243b64;">Update Submission</h3>
                <p class="help-text" style="margin-bottom: 14px;">
                    Uploading a new file replaces your current submission and re-calculates the status
                    from the time of the new upload.
                </p>
            <?php else: ?>
                <p style="margin: 8px 0 18px; color: #64748b;">You have not submitted this assignment yet.</p>
            <?php endif; ?>

            <form method="POST" action="student_view_assignment.php?id=<?= (int) $assignment_id ?>" enctype="multipart/form-data" id="submit-form">
                <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">

                <div class="form-group">
                    <label for="submission_file"><?= $submission ? 'New File' : 'Assignment File' ?> (PDF, DOCX, ZIP &ndash; max 10MB)</label>
                    <input type="file" id="submission_file" name="submission_file" class="form-control" accept=".pdf,.docx,.zip" required>
                </div>

                <div class="form-group">
                    <label for="remarks">Comments / Remarks (optional)</label>
                    <textarea id="remarks" name="remarks" class="form-control" rows="3" maxlength="<?= MAX_REMARKS_LENGTH ?>"></textarea>
                </div>

                <button type="submit" class="btn btn-block"><?= $submission ? 'Update Submission' : 'Submit Assignment' ?></button>
            </form>
        </section>

        <a class="back-link" href="student_dashboard.php">&larr; Back to Dashboard</a>
    </main>

    <footer>
        &copy; <?= date('Y') ?> EduSphere System
    </footer>

    <script>
    // Convenience only - the server enforces size, type and deadline status.
    (function () {
        var input = document.getElementById('submission_file');
        if (input) {
            input.addEventListener('change', function () {
                if (input.files[0] && input.files[0].size > 10 * 1024 * 1024) {
                    alert('This file is larger than 10MB. Please choose a smaller file.');
                    input.value = '';
                }
            });
        }
        var el = document.getElementById('countdown');
        if (el) {
            var left = parseInt(el.getAttribute('data-seconds'), 10);
            setInterval(function () {
                left -= 1;
                if (left <= 0) { location.reload(); return; }
                var d = Math.floor(left / 86400), h = Math.floor(left % 86400 / 3600),
                    m = Math.floor(left % 3600 / 60), s = left % 60, out = '';
                if (d > 0) out += d + 'd ';
                out += h + 'h ' + m + 'm ' + s + 's';
                el.textContent = out;
            }, 1000);
        }
    })();
    </script>
</body>
</html>
