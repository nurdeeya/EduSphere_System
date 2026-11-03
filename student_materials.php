<?php
// student_materials.php - student side of FR4 (provided by the Lecturer Module for the Student Module).
// Lists learning materials from lecturers the student is enrolled with, plus
// deadline-change notifications (SR5). New file only: no Student Module file was changed.
// To show it, the Student Module only needs a link: <a href="student_materials.php">Learning Materials</a>
session_start();
require_once 'config/security.php';
require_once 'config/database.php';

require_once __DIR__ . '/includes/access.php';
require_role('student');

$student_id = (int) $_SESSION['user_id'];

try {
    // Enrolment enforced in the JOIN: only materials of the student's own lecturers.
    $stmt = $pdo->prepare("
        SELECT m.id, m.title, m.description, m.file_path, m.uploaded_at, u.full_name AS lecturer
        FROM learning_materials m
        JOIN enrollments e ON e.lecturer_id = m.lecturer_id AND e.student_id = :student_id
        JOIN users u ON u.user_id = m.lecturer_id
        ORDER BY m.uploaded_at DESC, m.id DESC
    ");
    $stmt->execute([':student_id' => $student_id]);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmtN = $pdo->prepare("
        SELECT id, message, is_read, created_at FROM notifications
        WHERE user_id = :student_id
        ORDER BY id DESC LIMIT 10
    ");
    $stmtN->execute([':student_id' => $student_id]);
    $notifications = $stmtN->fetchAll(PDO::FETCH_ASSOC);

    // Viewing the page marks this student's notifications as read.
    $mark = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = :student_id AND is_read = 0");
    $mark->execute([':student_id' => $student_id]);
} catch (PDOException $e) {
    error_log('student_materials: ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Materials - EduSphere</title>
    <link rel="stylesheet" href="css/student_dashboard_style.css">
    <link rel="stylesheet" href="css/student_assignments_style.css">
</head>
<body>

    <header class="navbar">
        <div class="brand">EduSphere</div>
        <div class="navbar-right">
            <a class="nav-link" href="student_dashboard.php">Dashboard</a>
            <span class="role-label">Student Account</span>
            <a class="logout-btn" href="logout.php">Log Out</a>
        </div>
    </header>

    <main class="container">
        <section class="welcome-section">
            <h1>Learning Materials</h1>
            <p>Notes and slides shared by your lecturers, and recent deadline changes.</p>
        </section>

        <?php if ($notifications): ?>
        <h2 class="section-title">Deadline Updates</h2>
        <section class="information" style="margin-bottom: 25px;">
            <?php foreach ($notifications as $n): ?>
                <div class="alert <?= (int) $n['is_read'] === 0 ? 'alert-error' : '' ?>" style="<?= (int) $n['is_read'] === 1 ? 'background:#f8fafc;color:#475569;' : '' ?>">
                    <?= (int) $n['is_read'] === 0 ? '<strong>New:</strong> ' : '' ?><?= escape($n['message']) ?>
                    <span class="sub-note"><?= escape(date('d M Y, h:i A', strtotime($n['created_at']))) ?></span>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <h2 class="section-title">Materials</h2>
        <section class="information">
            <?php if ($materials): ?>
                <div class="table-wrap">
                    <table class="assignment-table">
                        <thead><tr><th>Title</th><th>Lecturer</th><th>Uploaded</th><th>File</th></tr></thead>
                        <tbody>
                        <?php foreach ($materials as $m): ?>
                            <tr>
                                <td data-label="Title" class="title-cell">
                                    <?= escape($m['title']) ?>
                                    <?php if (!empty($m['description'])): ?><span class="sub-note"><?= nl2br(escape($m['description'])) ?></span><?php endif; ?>
                                </td>
                                <td data-label="Lecturer"><?= escape($m['lecturer']) ?></td>
                                <td data-label="Uploaded"><?= escape(date('d M Y', strtotime($m['uploaded_at']))) ?></td>
                                <td data-label="File">
                                    <a class="btn btn-outline" href="material_download.php?id=<?= (int) $m['id'] ?>">Download <?= escape(strtoupper(pathinfo($m['file_path'], PATHINFO_EXTENSION))) ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="muted">Your lecturers have not shared any materials yet.</p>
            <?php endif; ?>
        </section>

        <a href="student_dashboard.php" class="back-link">&larr; Back to Dashboard</a>
    </main>

</body>
</html>
