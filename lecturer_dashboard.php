<?php
session_start();

// Allow only fully authenticated lecturers.
require_once __DIR__ . '/includes/access.php';
require_role('lecturer');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/lecturer.php';

$fullName = $_SESSION['full_name'] ?? 'Lecturer';
$user_id  = (int) $_SESSION['user_id'];

$stats = ['assignments' => 0, 'open' => 0, 'students' => 0, 'materials' => 0, 'late' => 0];
$upcoming = [];
$materials_ready = true;

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS assignments, COALESCE(SUM(deadline > NOW()), 0) AS open
        FROM assignments WHERE created_by = :uid
    ");
    $stmt->execute([':uid' => $user_id]);
    $row = $stmt->fetch();
    $stats['assignments'] = (int) $row['assignments'];
    $stats['open']        = (int) $row['open'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM enrollments WHERE lecturer_id = :uid");
    $stmt->execute([':uid' => $user_id]);
    $stats['students'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM submissions s JOIN assignments a ON a.id = s.assignment_id
        WHERE a.created_by = :uid AND s.status = 'Late'
    ");
    $stmt->execute([':uid' => $user_id]);
    $stats['late'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT a.id, a.title, a.deadline, COUNT(s.id) AS submitted
        FROM assignments a
        LEFT JOIN submissions s ON s.assignment_id = a.id
        WHERE a.created_by = :uid AND a.deadline > NOW()
        GROUP BY a.id, a.title, a.deadline
        ORDER BY a.deadline ASC
        LIMIT 5
    ");
    $stmt->execute([':uid' => $user_id]);
    $upcoming = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('lecturer_dashboard: ' . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM learning_materials WHERE lecturer_id = :uid");
    $stmt->execute([':uid' => $user_id]);
    $stats['materials'] = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    // Table missing until sql/lecturer_module_update.sql is imported.
    error_log('lecturer_dashboard (materials): ' . $e->getMessage());
    $materials_ready = false;
}

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard</title>

    <link rel="stylesheet" href="css/lecturer_dashboard_style.css">
    <link rel="stylesheet" href="css/lecturer_module_style.css">

</head>
<body>

    <!-- Navigation bar -->
    <header class="navbar">
        <div class="brand"> EduSphere</div>

        <div class="navbar-right">
            <span class="role-label">Lecturer Account</span>
            <a class="logout-btn" href="logout.php">Log Out</a>
        </div>
    </header>

    <!--Main DAshboard -->
    <main class="container">
        <?php lec_nav('dashboard'); ?>

        <section class="welcome-section">
            <h1>Welcome, <?= $h($fullName) ?></h1>

            <p>
                Welcome to EduSphere. Manage your courses,
                organise learning materials and support
                your students through one platform.
            </p>
        </section>

        <h2 class="section-title">Overview</h2>

        <section class="stat-row" aria-label="Overview">
            <div class="stat"><div class="label">Assignments</div><div class="value"><?= $stats['assignments'] ?></div></div>
            <div class="stat"><div class="label">Open now</div><div class="value"><?= $stats['open'] ?></div></div>
            <div class="stat"><div class="label">Enrolled students</div><div class="value"><?= $stats['students'] ?></div></div>
            <div class="stat"><div class="label">Late submissions</div><div class="value"><?= $stats['late'] ?></div></div>
        </section>

        <!-- Lecturer features -->
        <h2 class="section-title">Lecturer Tools</h2>

        <section class="card-container">

            <div class="card">
                <h3>Course Management</h3>
                <p class="feature-description">
                    Create assignments, set deadlines and
                    review student submissions.
                </p>
            </div>

            <div class="card">
                <h3>Learning Materials</h3>
                <p class="feature-description">
                    Prepare and share notes, lecture slides
                    and other learning resources.
                    <?= $materials_ready ? '(' . $stats['materials'] . ' shared)' : '' ?>
                </p>
                <?php if ($materials_ready): ?>
                <?php else: ?>
                    <span class="feature-status">Import sql/lecturer_module_update.sql to enable</span>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Student Overview</h3>
                <p class="feature-description">
                    View the students enrolled with you
                    and their submission progress.
                </p>
            </div>

        </section>

        <!-- Upcoming deadlines -->
        <section class="information">
            <h2>Upcoming Deadlines</h2>

            <?php if ($upcoming): ?>
                <div class="table-wrap">
                <table class="lec-table">
                    <thead><tr><th>Assignment</th><th>Deadline</th><th>Submitted</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($upcoming as $a): ?>
                        <tr>
                            <td><a class="title-link" href="lecturer_view_assignment.php?id=<?= (int) $a['id'] ?>"><?= $h($a['title']) ?></a></td>
                            <td><?= $h(date('d M Y, h:i A', strtotime($a['deadline']))) ?></td>
                            <td class="num"><?= (int) $a['submitted'] ?> / <?= $stats['students'] ?></td>
                            <td><a class="btn-small btn-navy" href="lecturer_view_submissions.php?id=<?= (int) $a['id'] ?>">Submissions</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p class="muted">No open assignments. Create one from Manage Assignments.</p>
            <?php endif; ?>
        </section>

    </main>

    <footer>
        &copy; <?= date('Y') ?> EduSphere System
    </footer>

</body>
</html>
