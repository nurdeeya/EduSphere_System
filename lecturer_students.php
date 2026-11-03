<?php
// Student Overview: students enrolled with this lecturer and their submission progress.
// Read-only. SR2: only this lecturer's own enrolments are ever queried.
session_start();
require_once 'config/security.php';
require_once 'config/database.php';

require_once __DIR__ . '/includes/access.php';
require_role('lecturer');
require_once __DIR__ . '/includes/lecturer.php';

$user_id = (int) $_SESSION['user_id'];

try {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE created_by = :uid");
    $stmtTotal->execute([':uid' => $user_id]);
    $total_assignments = (int) $stmtTotal->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT u.full_name, u.login_identifier, u.email, e.enrolled_at,
               COUNT(s.id)                       AS submitted,
               COALESCE(SUM(s.status = 'Late'), 0) AS late
        FROM enrollments e
        JOIN users u ON u.user_id = e.student_id AND u.role = 'student'
        LEFT JOIN assignments a ON a.created_by = e.lecturer_id
        LEFT JOIN submissions s ON s.assignment_id = a.id AND s.student_id = e.student_id
        WHERE e.lecturer_id = :lecturer_id
        GROUP BY u.user_id, u.full_name, u.login_identifier, u.email, e.enrolled_at
        ORDER BY u.full_name
    ");
    $stmt->execute([':lecturer_id' => $user_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('lecturer_students: ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students - EduSphere</title>
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
        <?php lec_nav('students'); ?>

        <section class="welcome-section">
            <h1>Student Overview</h1>
            <p>Students enrolled with you and how many of your <?= $total_assignments ?> assignment(s) each has submitted.</p>
        </section>

        <section class="information">
            <?php if ($students): ?>
                <div class="table-wrap">
                <table class="lec-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Student ID</th>
                            <th>Email</th>
                            <th>Submitted</th>
                            <th>Late</th>
                            <th>Enrolled Since</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $s): ?>
                        <tr>
                            <td><?= escape($s['full_name']) ?></td>
                            <td><?= escape($s['login_identifier']) ?></td>
                            <td><?= escape($s['email']) ?></td>
                            <td class="num"><?= (int) $s['submitted'] ?> / <?= $total_assignments ?></td>
                            <td class="num"><?= (int) $s['late'] > 0 ? '<span class="badge badge-late">' . (int) $s['late'] . '</span>' : '0' ?></td>
                            <td><?= escape(date('d M Y', strtotime($s['enrolled_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p class="muted">No students are enrolled with you yet. Enrolments are managed by the administrator.</p>
            <?php endif; ?>
        </section>

        <a href="lecturer_dashboard.php" class="back-link">&larr; Back to Dashboard</a>
    </main>

</body>
</html>
