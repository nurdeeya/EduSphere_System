<?php
// FR2: Create Assignments  +  FR4 (assignment file upload)
// Security: SR1/SR2 (MFA-verified lecturer only), SR3 (secure upload), SR7 (input validation),
//           prepared statements (SQLi), length bounds (buffer overflow), audit log (repudiation).
session_start();
require_once 'config/security.php';
require_once 'config/database.php';
require_once 'config/upload.php';

require_once __DIR__ . '/includes/access.php';
require_role('lecturer');
require_once __DIR__ . '/includes/lecturer.php';

$message = '';
$message_type = '';
$user_id = (int) $_SESSION['user_id'];   // identity always from the session, never the form

// Keep what the lecturer typed if validation fails.
$old = ['title' => '', 'description' => '', 'deadline' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploaded_name = null;

    do {
        if (lec_post_too_large()) {
            $message = 'The upload is too large. The maximum file size is 10MB.';
            $message_type = 'error';
            break;
        }

        if (!verify_csrf_token(lec_str($_POST['csrf_token'] ?? ''))) {
            render_error(403, 'Security Check Failed', 'Your form session has expired. Please reload the page and try again.');
        }

        $title       = lec_str($_POST['title'] ?? '');
        $description = lec_str($_POST['description'] ?? '');
        $deadline    = lec_str($_POST['deadline'] ?? '');
        $old = ['title' => $title, 'description' => $description, 'deadline' => $deadline];

        // ---- Syntactic validation
        if ($title === '' || $deadline === '') {
            $message = 'Title and deadline are required.';
        } elseif (!lec_text_ok($title, LEC_TITLE_MAX)) {
            $message = 'Title must be at most ' . LEC_TITLE_MAX . ' characters with no control characters.';
        } elseif (!lec_text_ok($description, LEC_DESCRIPTION_MAX, true)) {
            $message = 'Description must be at most ' . LEC_DESCRIPTION_MAX . ' characters.';
        } elseif (!validate_datetime($deadline)) {
            $message = 'Invalid date format.';
        }
        if ($message !== '') {
            $message_type = 'error';
            break;
        }

        try {
            // ---- Semantic validation (deadline must make sense, not just parse)
            $problem = lec_deadline_problem($pdo, $deadline);
            if ($problem !== '') {
                $message = $problem;
                $message_type = 'error';
                break;
            }

            // Duplicate title check for this lecturer.
            $dup = $pdo->prepare("SELECT 1 FROM assignments WHERE created_by = :uid AND title = :title LIMIT 1");
            $dup->execute([':uid' => $user_id, ':title' => $title]);
            if ($dup->fetchColumn()) {
                $message = 'You already have an assignment with this title.';
                $message_type = 'error';
                break;
            }
        } catch (PDOException $e) {
            error_log('lecturer_create_assignment (validate): ' . $e->getMessage());
            $message = 'A system error occurred. Please try again later.';
            $message_type = 'error';
            break;
        }

        // ---- Optional assignment file (SR3)
        $file = $_FILES['assignment_file'] ?? null;
        if (is_array($file) && isset($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            $result = secure_file_upload($file, __DIR__ . '/uploads');
            if (!upload_succeeded($result)) {
                $message = is_string($result) ? $result : 'File upload failed.';
                $message_type = 'error';
                break;
            }
            $uploaded_name = $result;
        }

        // ---- Save assignment + audit record together
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO assignments (title, description, file_path, deadline, created_by)
                VALUES (:title, :description, :file_path, :deadline, :created_by)
            ");
            $stmt->execute([
                ':title'       => $title,
                ':description' => $description === '' ? null : $description,
                ':file_path'   => $uploaded_name,
                ':deadline'    => $deadline,
                ':created_by'  => $user_id,
            ]);
            $new_id = (int) $pdo->lastInsertId();

            $log = $pdo->prepare("
                INSERT INTO audit_logs (user_id, action, details, ip_address)
                VALUES (:user_id, 'ASSIGNMENT_CREATED', :details, :ip)
            ");
            $log->execute([
                ':user_id' => $user_id,
                ':details' => 'assignment_id=' . $new_id . '; deadline=' . $deadline . '; file=' . ($uploaded_name ?? 'none'),
                ':ip'      => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            ]);

            $pdo->commit();

            lec_flash('success', "Assignment '" . $title . "' successfully created!");
            header('Location: lecturer_create_assignment.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('lecturer_create_assignment (insert): ' . $e->getMessage());
            // Do not leave an orphaned upload behind.
            if ($uploaded_name !== null && is_file(__DIR__ . '/uploads/' . $uploaded_name)) {
                @unlink(__DIR__ . '/uploads/' . $uploaded_name);
            }
            $message = 'A system error occurred. Please try again later.';
            $message_type = 'error';
        }
    } while (false);
}

$flash = lec_take_flash();
if ($flash['text'] !== '') {
    $message = $flash['text'];
    $message_type = $flash['type'];
}

try {
    $stmtFetch = $pdo->prepare("
        SELECT a.id, a.title, a.deadline, a.file_path,
               (a.deadline > NOW()) AS is_open,
               COUNT(s.id) AS submitted,
               SUM(s.status = 'Late') AS late
        FROM assignments a
        LEFT JOIN submissions s ON s.assignment_id = a.id
        WHERE a.created_by = :user_id
        GROUP BY a.id, a.title, a.deadline, a.file_path, a.created_at
        ORDER BY a.created_at DESC
    ");
    $stmtFetch->execute([':user_id' => $user_id]);
    $assignments = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE lecturer_id = :uid");
    $stmtCount->execute([':uid' => $user_id]);
    $enrolled = (int) $stmtCount->fetchColumn();
} catch (PDOException $e) {
    error_log('lecturer_create_assignment (list): ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}

$csrf_token = generate_csrf_token();
$min_deadline = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assignments - EduSphere</title>
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
            <h1>Manage Assignments</h1>
            <p>Create a new assignment, change a deadline, or check who has submitted.</p>
        </section>

        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'error' ? 'alert-error' : 'alert-success' ?>" role="<?= $message_type === 'error' ? 'alert' : 'status' ?>">
                <?= escape($message) ?>
            </div>
        <?php endif; ?>

        <section class="information lec-narrow">
            <h2>Create New Assignment</h2>

            <form method="POST" action="lecturer_create_assignment.php" enctype="multipart/form-data" style="margin-top: 15px;">
                <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
                <div class="form-group">
                    <label for="title">Assignment Title <span class="char-count" data-for="title"></span></label>
                    <input type="text" id="title" name="title" class="form-control" maxlength="<?= LEC_TITLE_MAX ?>" value="<?= escape($old['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="description">Description <span class="char-count" data-for="description"></span></label>
                    <textarea id="description" name="description" class="form-control" rows="4" maxlength="<?= LEC_DESCRIPTION_MAX ?>"><?= escape($old['description']) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="assignment_file">Upload Assignment File (optional)</label>
                    <input type="file" id="assignment_file" name="assignment_file" class="form-control" accept=".pdf,.docx,.zip">
                    <p class="form-hint">PDF, DOCX or ZIP, up to 10MB. The file content is checked, not just its name.</p>
                </div>
                <div class="form-group">
                    <label for="deadline">Deadline</label>
                    <input type="datetime-local" id="deadline" name="deadline" class="form-control" min="<?= escape($min_deadline) ?>" value="<?= escape($old['deadline']) ?>" required>
                    <p class="form-hint">Must be in the future and within one year.</p>
                </div>
                <button type="submit" class="btn-submit">Create Assignment</button>
            </form>
        </section>

        <section class="information">
            <h2>Your Existing Assignments</h2>
            <p class="small">Students enrolled with you: <strong><?= $enrolled ?></strong></p>

            <?php if (count($assignments) > 0): ?>
                <div class="table-wrap">
                <table class="lec-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Deadline</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <?php $aid = (int) $assignment['id']; ?>
                            <tr>
                                <td>
                                    <a class="title-link" href="lecturer_view_assignment.php?id=<?= $aid ?>"><?= escape($assignment['title']) ?></a>
                                    <?php if (!empty($assignment['file_path'])): ?><br><span class="small">File attached</span><?php endif; ?>
                                </td>
                                <td><?= escape(date('d M Y, h:i A', strtotime($assignment['deadline']))) ?></td>
                                <td>
                                    <?php if ((int) $assignment['is_open'] === 1): ?>
                                        <span class="badge badge-open">Open</span>
                                    <?php else: ?>
                                        <span class="badge badge-closed">Closed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="num">
                                    <?= (int) $assignment['submitted'] ?> / <?= $enrolled ?>
                                    <?php if ((int) $assignment['late'] > 0): ?><br><span class="small"><?= (int) $assignment['late'] ?> late</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a href="lecturer_view_submissions.php?id=<?= $aid ?>" class="btn-small btn-navy">Submissions</a>
                                        <a href="lecturer_edit_deadline.php?id=<?= $aid ?>" class="btn-small btn-edit">Edit Deadline</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p class="muted">You haven't created any assignments yet.</p>
            <?php endif; ?>
        </section>

        <a href="lecturer_dashboard.php" class="back-link">&larr; Back to Dashboard</a>
    </main>

    <script>
    // Live character counters (the server enforces the same limits).
    document.querySelectorAll('.char-count').forEach(function (el) {
        var input = document.getElementById(el.dataset.for);
        var update = function () { el.textContent = input.value.length + ' / ' + input.maxLength; };
        input.addEventListener('input', update);
        update();
    });
    </script>
</body>
</html>
