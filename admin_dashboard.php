//admin dashboard
<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/audit.php';

// Allow only fully authenticated Admin accounts.
if (
    !isset(
        $_SESSION['user_id'],
        $_SESSION['role'],
        $_SESSION['auth_complete']
    ) ||
    $_SESSION['auth_complete'] !== true ||
    $_SESSION['role'] !== 'admin'
) {
    header('Location: login.php');
    exit;
}

$fullName = $_SESSION['full_name'] ?? 'Administrator';

$studentCount = 0;
$lecturerCount = 0;
$adminCount = 0;
$totalAccounts = 0;
$databaseError = false;
$recentLogs = [];

try {
    // Count accounts for each role.
    $stmt = $pdo->query(
        "SELECT role, COUNT(*) AS total
         FROM users
         WHERE is_active = 1
         GROUP BY role"
    );

    $roleCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $studentCount = (int) ($roleCounts['student'] ?? 0);
    $lecturerCount = (int) ($roleCounts['lecturer'] ?? 0);
    $adminCount = (int) ($roleCounts['admin'] ?? 0);

    $totalAccounts = $studentCount
        + $lecturerCount
        + $adminCount;

    // Latest audit events for the Recent Activity panel.
    $recentLogs = $pdo->query(
        "SELECT l.action, l.details, l.created_at,
                u.full_name, u.login_identifier
         FROM audit_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT 5"
    )->fetchAll();

} catch (PDOException $e) {
    error_log('Admin dashboard error: ' . $e->getMessage());
    $databaseError = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | EduSphere</title>

    <link rel="stylesheet" href="css/admin_dashboard_style.css">
</head>
<body>

<div class="dashboard-container">

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">

        <div class="brand">
            <div class="brand-icon">E</div>

            <div class="brand-text">
                <h2>EduSphere</h2>
                <p>Learning Management System</p>
            </div>
        </div>

        <div class="sidebar-section">
            <p class="sidebar-heading">MAIN MENU</p>

            <a href="admin_dashboard.php"
               class="nav-link active"
               aria-current="page">
                <span class="nav-icon">&#9633;</span>
                <span>Dashboard</span>
            </a>

            <a href="admin_create_account.php" class="nav-link">
                <span class="nav-icon">&#43;</span>
                <span>Create Account</span>
            </a>

            <a href="reset_mfa.php" class="nav-link">
                <span class="nav-icon">&#128274;</span>
                <span>Reset User MFA</span>
            </a>

            <a href="admin_audit_logs.php" class="nav-link">
                <span class="nav-icon">&#9776;</span>
                <span>Audit Logs</span>
            </a>
        </div>

        <div class="sidebar-section">
            <p class="sidebar-heading">ACCOUNT</p>

            <a href="logout.php" class="nav-link logout-link">
                <span class="nav-icon">&#10140;</span>
                <span>Log Out</span>
            </a>
        </div>

        <div class="sidebar-footer">
            <div class="security-icon">&#128274;</div>
            <div>
                <strong>Secure Access</strong>
                <p>Administrator Portal</p>
            </div>
        </div>

    </aside>

    <!-- Main Content -->
    <main class="main-content">

        <header class="topbar">

            <button
                type="button"
                class="menu-toggle"
                id="menuToggle"
                aria-label="Toggle navigation"
                aria-controls="sidebar"
                aria-expanded="false">
                &#9776;
            </button>

            <div class="breadcrumb">
                <span>EduSphere</span>
                <span class="breadcrumb-divider">/</span>
                <strong>Dashboard</strong>
            </div>

            <div class="profile">
                <div class="profile-avatar">A</div>

                <div class="profile-details">
                    <strong>
                        <?= htmlspecialchars(
                            $fullName,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </strong>
                    <span>Administrator</span>
                </div>
            </div>

        </header>

        <section class="page-content">

            <div class="welcome-section">
                <div>
                    <p class="eyebrow">ADMINISTRATION</p>

                    <h1>Admin Dashboard</h1>

                    <p class="welcome-message">
                        Welcome back,
                        <strong>
                            <?= htmlspecialchars(
                                $fullName,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </strong>.
                        Manage EduSphere user accounts and review system activity from one place.
                    </p>
                </div>

                <a href="admin_create_account.php"
                   class="primary-button">
                    <span aria-hidden="true">&#43;</span>
                    Create New Account
                </a>
            </div>

            <?php if ($databaseError): ?>
                <div class="alert alert-error" role="alert">
                    Unable to load account statistics.
                    Please try again later.
                </div>
            <?php endif; ?>

            <!-- Account Statistics -->
            <section class="statistics-section"
                     aria-labelledby="statisticsHeading">

                <div class="section-heading">
                    <div>
                        <h2 id="statisticsHeading">Account Overview</h2>
                        <p>Summary of active user accounts.</p>
                    </div>
                </div>

                <div class="statistics-grid">

                    <article class="stat-card total-card">
                        <div class="stat-top">
                            <div class="stat-icon total-icon">
                                &#9776;
                            </div>
                            <span class="stat-label">All Roles</span>
                        </div>

                        <p class="stat-title">Total Accounts</p>

                        <p class="stat-number">
                            <?= $totalAccounts ?>
                        </p>

                        <p class="stat-description">
                            Active accounts in the system
                        </p>
                    </article>

                    <article class="stat-card student-card">
                        <div class="stat-top">
                            <div class="stat-icon student-icon">
                                &#127891;
                            </div>
                            <span class="stat-label">Student</span>
                        </div>

                        <p class="stat-title">Student Accounts</p>

                        <p class="stat-number">
                            <?= $studentCount ?>
                        </p>

                        <p class="stat-description">
                            Active student accounts
                        </p>
                    </article>

                    <article class="stat-card lecturer-card">
                        <div class="stat-top">
                            <div class="stat-icon lecturer-icon">
                                &#128218;
                            </div>
                            <span class="stat-label">Lecturer</span>
                        </div>

                        <p class="stat-title">Lecturer Accounts</p>

                        <p class="stat-number">
                            <?= $lecturerCount ?>
                        </p>

                        <p class="stat-description">
                            Active lecturer accounts
                        </p>
                    </article>

                    <article class="stat-card admin-card">
                        <div class="stat-top">
                            <div class="stat-icon admin-icon">
                                &#128272;
                            </div>
                            <span class="stat-label">Admin</span>
                        </div>

                        <p class="stat-title">Admin Accounts</p>

                        <p class="stat-number">
                            <?= $adminCount ?>
                        </p>

                        <p class="stat-description">
                            Active administrator accounts
                        </p>
                    </article>

                </div>
            </section>

            <!-- Quick Actions -->
            <section class="actions-section"
                     aria-labelledby="actionsHeading">

                <div class="section-heading">
                    <div>
                        <h2 id="actionsHeading">Quick Actions</h2>
                        <p>Common account management tasks.</p>
                    </div>
                </div>

                <div class="actions-grid">

                    <article class="action-card">
                        <div class="action-icon student-action">
                            &#127891;
                        </div>

                        <div class="action-content">
                            <h3>Create Student Account</h3>

                            <p>
                                Add a student account to the
                                EduSphere system.
                            </p>

                            <a href="admin_create_account.php?role=student"
                               class="action-link">
                                Add Student
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    </article>

                    <article class="action-card">
                        <div class="action-icon lecturer-action">
                            &#128218;
                        </div>

                        <div class="action-content">
                            <h3>Create Lecturer Account</h3>

                            <p>
                                Add a lecturer account to the
                                EduSphere system.
                            </p>

                            <a href="admin_create_account.php?role=lecturer"
                               class="action-link">
                                Add Lecturer
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    </article>

                </div>
            </section>

            <!-- Recent Activity (Audit Log) -->
            <section class="recent-activity"
                     aria-labelledby="recentHeading">

                <div class="section-heading">
                    <div>
                        <h2 id="recentHeading">Recent Activity</h2>
                        <p>Latest events from the audit log.</p>
                    </div>

                    <a href="admin_audit_logs.php" class="view-all-link">
                        View full audit log
                        <span aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <div class="table-card">
                    <div class="table-scroll">
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th scope="col">Time</th>
                                    <th scope="col">User</th>
                                    <th scope="col">Action</th>
                                    <th scope="col">Details</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$recentLogs): ?>
                                <tr>
                                    <td colspan="4" class="empty-row">
                                        No events have been recorded yet.
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td class="nowrap">
                                        <?= htmlspecialchars($log['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td>
                                        <?php if ($log['full_name'] !== null): ?>
                                            <strong><?= htmlspecialchars($log['full_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span class="user-meta"><?= htmlspecialchars($log['login_identifier'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="user-meta">Deleted user</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= htmlspecialchars(audit_action_category($log['action']), ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(audit_action_label($log['action']), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="details-cell">
                                        <?= htmlspecialchars((string) $log['details'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>

            <!-- Security Notice -->
            <section class="security-notice">
                <div class="notice-icon">&#128274;</div>

                <div>
                    <h3>Administrator Security</h3>

                    <p>
                        Keep your administrator credentials private.
                        Only authorised administrators should create
                        and manage user accounts.
                    </p>
                </div>
            </section>

            <footer class="page-footer">
                <p>
                    &copy; <?= date('Y') ?> EduSphere.
                    Learning management made easier.
                </p>
            </footer>

        </section>

    </main>

</div>

<script>
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');

    menuToggle.addEventListener('click', function () {
        const isOpen = sidebar.classList.toggle('sidebar-open');

        menuToggle.setAttribute(
            'aria-expanded',
            isOpen ? 'true' : 'false'
        );
    });
</script>

</body>
</html>
