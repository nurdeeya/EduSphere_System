<?php
// FR2/FR4: View an assignment the lecturer created.
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

try {
    // Ownership is part of the WHERE clause, so another lecturer's id returns nothing.
    $stmt = $pdo->prepare("
        SELECT id, title, description, file_path, deadline, created_at,
               (deadline > NOW()) AS is_open
        FROM assignments
        WHERE id = :id AND created_by = :user_id
    ");
    $stmt->execute([':id' => $assignment_id, ':user_id' => $user_id]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$assignment) {
        // Same response whether the id does not exist or belongs to another lecturer.
        audit_denied($pdo, $user_id, 'lecturer_view_assignment id=' . $assignment_id . ' not owner or missing');
        render_forbidden();
    }

    $stmtCount = $pdo->prepare("
        SELECT COUNT(*) AS submitted, COALESCE(SUM(status = 'Late'), 0) AS late
        FROM submissions WHERE assignment_id = :id
    ");
    $stmtCount->execute([':id' => $assignment_id]);
    $counts = $stmtCount->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('lecturer_view_assignment: ' . $e->getMessage());
    render_error(500, 'System Error', 'A system error occurred. Please try again later.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Assignment - EduSphere</title>
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
            <h1>Assignment Details</h1>
            <p>View the full details and files for this assignment.</p>
        </section>

        <section class="information" style="max-width: 760px;">

            <div class="detail-box">
                <div class="detail-label">Assignment Title</div>
                <div class="detail-value"><strong><?= escape($assignment['title']) ?></strong></div>

                <div class="detail-label">Description</div>
                <div class="detail-value">
                    <?= !empty($assignment['description']) ? nl2br(escape($assignment['description'])) : '<em class="muted">No description provided.</em>' ?>
                </div>

                <div class="detail-label">Deadline</div>
                <div class="detail-value">
                    <?= escape(date('d M Y, h:i A', strtotime($assignment['deadline']))) ?>
                    <?php if ((int) $assignment['is_open'] === 1): ?>
                        <span class="badge badge-open">Open</span>
                    <?php else: ?>
                        <span class="badge badge-closed">Closed</span>
                    <?php endif; ?>
                </div>

                <div class="detail-label">Submissions</div>
                <div class="detail-value">
                    <?= (int) $counts['submitted'] ?> received<?= (int) $counts['late'] > 0 ? ', ' . (int) $counts['late'] . ' late' : '' ?>
                </div>

                <div class="detail-label">Attached File</div>
                <div class="detail-value">
                    <?php if (!empty($assignment['file_path'])): ?>
                        <!-- Served through download.php (login + ownership checked), never straight from uploads/ -->
                        <a href="download.php?type=material&amp;id=<?= (int) $assignment['id'] ?>" class="btn-link-card">Download File</a>
                    <?php else: ?>
                        <em class="muted">No file attached.</em>
                    <?php endif; ?>
                </div>
            </div>

            <div class="actions">
                <a href="lecturer_view_submissions.php?id=<?= (int) $assignment['id'] ?>" class="btn-small btn-navy">View Submissions</a>
                <a href="lecturer_edit_deadline.php?id=<?= (int) $assignment['id'] ?>" class="btn-small btn-edit">Edit Deadline</a>
            </div>

            <a href="lecturer_create_assignment.php" class="back-link">&larr; Back to Manage Assignments</a>
        </section>
    </main>

</body>
</html>
