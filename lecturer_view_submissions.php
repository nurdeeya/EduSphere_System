<?php
// FR5: View Submissions (per assignment)
// Shows every ENROLLED student with their submission status (On time / Late / Not submitted).
// Security:
//   SR2  - only the lecturer who created the assignment (ownership in the WHERE clause)
//   SR4  - submissions are downloaded through download.php, which re-checks ownership
//   SR6  - status and time come from the server-side columns written by the Student Module
//   SR7  - search/filter input is whitelisted + length-bounded; all output is HTML-escaped
//   SQLi - every query is a prepared statement; LIKE wildcards in the search are escaped
session_start();
require_once 'config/security.php';
require_once 'config/database.php';

require_once __DIR__ . '/includes/access.php';
require_role('lecturer');
require_once __DIR__ . '/includes/lecturer.php';

$user_id = (int) $_SESSION['user_id'];

$assignment_id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($assignment_id === false || $assignment_id === null) {
    render_error(400, 'Bad Request', 'No valid assignment was selected.');
}

// ---- Filter inputs: whitelist + bounds
$allowed_status = ['all', 'Submitted', 'Late', 'missing'];
$status = lec_str($_GET['status'] ?? 'all');
if (!in_array($status, $allowed_status, true)) {
    $status = 'all';
}

$search = lec_str($_GET['q'] ?? '');
$search_error = '';
if ($search !== '' && (mb_strlen($search, 'UTF-8') > LEC_SEARCH_MAX || !preg_match('/^[\p{L}\p{N} .\'@_-]+$/u', $search))) {
    $search_error = 'Search may only contain letters, numbers, spaces and . \' @ _ - (max ' . LEC_SEARCH_MAX . ' characters).';
    $search = '';
}

try {
    $stmt = $pdo->prepare("
        SELECT id, title, deadline, (deadline > NOW()) AS is_open
        FROM assignments
        WHERE id = :id AND created_by = :user_id
    ");
    $stmt->execute([':id' => $assignment_id, ':user_id' => $user_id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        audit_denied($pdo, $user_id, 'lecturer_view_submissions id=' . $assignment_id . ' not owner or missing');
        render_forbidden();
    }

    // Summary over ALL enrolled students (not affected by filters).
    $stmtSum = $pdo->prepare("
        SELECT COUNT(*) AS enrolled,
               COALESCE(SUM(s.status = 'Submitted'), 0) AS on_time,
               COALESCE(SUM(s.status = 'Late'), 0)      AS late,
               COALESCE(SUM(s.id IS NULL), 0)           AS missing
        FROM enrollments e
        LEFT JOIN submissions s
               ON s.student_id = e.student_id AND s.assignment_id = :assignment_id
        WHERE e.lecturer_id = :lecturer_id
    ");
    $stmtSum->execute([':assignment_id' => $assignment_id, ':lecturer_id' => $user_id]);
    $summary = $stmtSum->fetch(PDO::FETCH_ASSOC);

    // Filtered list. Only fixed SQL fragments are appended; user values are always bound.
    $sql = "
        SELECT u.full_name, u.login_identifier,
               s.id AS submission_id, s.submitted_at, s.status, s.remarks
        FROM enrollments e
        JOIN users u ON u.user_id = e.student_id AND u.role = 'student'
        LEFT JOIN submissions s
               ON s.student_id = e.student_id AND s.assignment_id = :assignment_id
        WHERE e.lecturer_id = :lecturer_id
    ";
    $params = [':assignment_id' => $assignment_id, ':lecturer_id' => $user_id];

    if ($status === 'Submitted' || $status === 'Late') {
        $sql .= " AND s.status = :status";
        $params[':status'] = $status;
    } elseif ($status === 'missing') {
        $sql .= " AND s.id IS NULL";
    }

    if ($search !== '') {
        // Escape LIKE wildcards so "%" or "_" are searched literally.
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $sql .= " AND (u.full_name LIKE :q1 OR u.login_identifier LIKE :q2)";
        $params[':q1'] = $like;
        $params[':q2'] = $like;
    }

    $sql .= " ORDER BY (s.id IS NULL), s.submitted_at, u.full_name";

    $stmtList = $pdo->prepare($sql);
    $stmtList->execute($params);
    $rows = $stmtList->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('lecturer_view_submissions: ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}

$status_labels = ['all' => 'All students', 'Submitted' => 'On time', 'Late' => 'Late', 'missing' => 'Not submitted'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submissions - EduSphere</title>
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
            <h1>Submissions: <?= escape($assignment['title']) ?></h1>
            <p>
                Deadline: <?= escape(date('d M Y, h:i A', strtotime($assignment['deadline']))) ?>
                <?php if ((int) $assignment['is_open'] === 1): ?>
                    <span class="badge badge-open">Open</span>
                <?php else: ?>
                    <span class="badge badge-closed">Closed</span>
                <?php endif; ?>
            </p>
        </section>

        <section class="stat-row" aria-label="Submission summary">
            <div class="stat"><div class="label">Enrolled</div><div class="value"><?= (int) $summary['enrolled'] ?></div></div>
            <div class="stat"><div class="label">On time</div><div class="value"><?= (int) $summary['on_time'] ?></div></div>
            <div class="stat"><div class="label">Late</div><div class="value"><?= (int) $summary['late'] ?></div></div>
            <div class="stat"><div class="label">Not submitted</div><div class="value"><?= (int) $summary['missing'] ?></div></div>
        </section>

        <section class="information">
            <h2>Students</h2>

            <form method="GET" action="lecturer_view_submissions.php" class="filter-bar">
                <input type="hidden" name="id" value="<?= (int) $assignment_id ?>">
                <div class="form-group">
                    <label for="q">Search name or student ID</label>
                    <input type="text" id="q" name="q" class="form-control" maxlength="<?= LEC_SEARCH_MAX ?>" value="<?= escape($search) ?>">
                </div>
                <div class="form-group" style="flex: 0 1 200px;">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <?php foreach ($status_labels as $value => $label): ?>
                            <option value="<?= escape($value) ?>" <?= $value === $status ? 'selected' : '' ?>><?= escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn-submit">Filter</button>
            </form>

            <?php if ($search_error): ?>
                <div class="alert alert-error" role="alert" style="margin-top: 15px;"><?= escape($search_error) ?></div>
            <?php endif; ?>

            <?php if ($rows): ?>
                <div class="table-wrap">
                <table class="lec-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Status</th>
                            <th>Submitted At</th>
                            <th>Remarks</th>
                            <th>File</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= escape($r['full_name']) ?></td>
                            <td><?= escape($r['login_identifier']) ?></td>
                            <td>
                                <?php if ($r['submission_id'] === null): ?>
                                    <span class="badge badge-missing">Not submitted</span>
                                <?php elseif ($r['status'] === 'Late'): ?>
                                    <span class="badge badge-late">Late</span>
                                <?php else: ?>
                                    <span class="badge badge-ok">On time</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $r['submitted_at'] ? escape(date('d M Y, h:i A', strtotime($r['submitted_at']))) : '<span class="muted">-</span>' ?></td>
                            <td><?= $r['remarks'] !== null && $r['remarks'] !== '' ? nl2br(escape($r['remarks'])) : '<span class="muted">-</span>' ?></td>
                            <td>
                                <?php if ($r['submission_id'] !== null): ?>
                                    <a class="btn-small btn-navy" href="download.php?type=submission&amp;id=<?= (int) $r['submission_id'] ?>">Download</a>
                                <?php else: ?>
                                    <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p class="muted" style="margin-top: 15px;">No students match this filter.</p>
            <?php endif; ?>
        </section>

        <a href="lecturer_view_assignment.php?id=<?= (int) $assignment_id ?>" class="back-link">&larr; Back to Assignment</a>
    </main>

</body>
</html>
