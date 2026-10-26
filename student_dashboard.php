<?php
session_start();
require_once 'config/security.php';
require_once 'config/database.php';

// SR2: only fully authenticated (MFA-verified) students.
require_once __DIR__ . '/includes/access.php';
require_role('student');

$student_id = (int) $_SESSION['user_id'];

try {
    // Name / student ID come from the database (not every login path stores them in the session).
    $stmt = $pdo->prepare("
        SELECT full_name, login_identifier
        FROM users
        WHERE user_id = :user_id AND role = 'student' AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':user_id' => $student_id]);
    $student = $stmt->fetch();

    if (!$student) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // All assignments + this student's own submission (if any). student_id comes from the session only.
    $stmt = $pdo->prepare("
        SELECT a.id, a.title, a.deadline, a.file_path,
               s.status       AS submission_status,
               s.submitted_at AS submitted_at,
               (a.deadline < NOW()) AS is_past_deadline
        FROM assignments a
        JOIN enrollments e
               ON e.lecturer_id = a.created_by AND e.student_id = :enrolled_student_id
        LEFT JOIN submissions s
               ON s.assignment_id = a.id AND s.student_id = :student_id
        ORDER BY (a.deadline < NOW()) ASC, a.deadline ASC
    ");
    $stmt->execute([':student_id' => $student_id, ':enrolled_student_id' => $student_id]);
    $assignments = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('student_dashboard: ' . $e->getMessage());
    http_response_code(500);
    exit('A system error occurred. Please try again later.');
}

$total     = count($assignments);
$submitted = 0;
foreach ($assignments as $row) {
    if ($row['submission_status'] !== null) {
        $submitted++;
    }
}
$pending = $total - $submitted;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard</title>
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

    <main class="container">
        <section class="welcome-section">
            <h1>Welcome, <?= escape($student['full_name']) ?></h1>
            <p>
                Student ID: <strong><?= escape($student['login_identifier']) ?></strong><br>
                View your assignments, download learning materials and submit your work below.
            </p>
        </section>

        <section class="card-container">
            <div class="card">
                <h3>Total Assignments</h3>
                <p><?= escape((string) $total) ?></p>
            </div>
            <div class="card">
                <h3>Submitted</h3>
                <p><?= escape((string) $submitted) ?></p>
            </div>
            <div class="card">
                <h3>Not Submitted</h3>
                <p><?= escape((string) $pending) ?></p>
            </div>
        </section>

        <h2 class="section-title">Your Assignments</h2>

        <section class="information">
            <?php if ($total === 0): ?>
                <p class="muted">There are no assignments available for your classes yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="assignment-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Deadline</th>
                                <th>Lecturer Material</th>
                                <th>Submission Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td data-label="Title" class="title-cell"><?= escape($a['title']) ?></td>
                                <td data-label="Deadline"><?= escape(date('d M Y, h:i A', strtotime($a['deadline']))) ?></td>
                                <td data-label="Lecturer Material">
                                    <?php if (!empty($a['file_path'])): ?>
                                        <a class="btn btn-outline" href="download.php?type=material&amp;id=<?= (int) $a['id'] ?>">Download</a>
                                    <?php else: ?>
                                        <span class="muted">No file</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Submission Status">
                                    <?php if ($a['submission_status'] === 'Submitted'): ?>
                                        <span class="badge badge-ok">Submitted (On Time)</span>
                                    <?php elseif ($a['submission_status'] === 'Late'): ?>
                                        <span class="badge badge-late">Submitted (Late)</span>
                                    <?php else: ?>
                                        <span class="badge <?= $a['is_past_deadline'] ? 'badge-overdue' : 'badge-none' ?>">Not Submitted</span>
                                        <?php if ($a['is_past_deadline']): ?>
                                            <span class="sub-note">Deadline passed &ndash; late submissions are still accepted</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($a['submitted_at'] !== null): ?>
                                        <span class="sub-note"><?= escape(date('d M Y, h:i A', strtotime($a['submitted_at']))) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Action">
                                    <a class="btn" href="student_view_assignment.php?id=<?= (int) $a['id'] ?>">Submit / View Assignment</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer>
        &copy; <?= date('Y') ?> EduSphere System
    </footer>

</body>
</html>
