<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/audit.php';

// Fully authenticated (MFA-verified) Admin accounts only.
require_role('admin');

$fullName = $_SESSION['full_name'] ?? 'Administrator';
$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

const AUDIT_PER_PAGE = 25;

/** Read a GET field as a trimmed string (arrays and other tricks become ''). */
function get_str(string $key, int $max = 100): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max, 'UTF-8') : '';
}

/** Validate a Y-m-d date; returns '' when invalid. */
function valid_date(string $v): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

$filterAction = get_str('action', 100);
$filterRole   = get_str('role', 20);
$filterSearch = get_str('q', 100);
$filterFrom   = valid_date(get_str('from', 10));
$filterTo     = valid_date(get_str('to', 10));

if (!in_array($filterRole, ['student', 'lecturer', 'admin'], true)) {
    $filterRole = '';
}
if ($filterFrom !== '' && $filterTo !== '' && $filterFrom > $filterTo) {
    [$filterFrom, $filterTo] = [$filterTo, $filterFrom];
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;

$logs = [];
$actions = [];
$totalRows = 0;
$totalPages = 1;
$stats = ['total' => 0, 'last24h' => 0, 'alerts' => 0, 'users' => 0];
$databaseError = false;

try {
    // Build the WHERE clause from the validated filters only.
    $where = [];
    $params = [];

    if ($filterAction !== '') {
        $where[] = 'l.action = :action';
        $params[':action'] = $filterAction;
    }
    if ($filterRole !== '') {
        $where[] = 'u.role = :role';
        $params[':role'] = $filterRole;
    }
    if ($filterFrom !== '') {
        $where[] = 'l.created_at >= :from_date';
        $params[':from_date'] = $filterFrom . ' 00:00:00';
    }
    if ($filterTo !== '') {
        $where[] = 'l.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)';
        $params[':to_date'] = $filterTo . ' 00:00:00';
    }
    if ($filterSearch !== '') {
        $like = '%' . addcslashes($filterSearch, '\\%_') . '%';
        $where[] = '(u.full_name LIKE :q1 OR u.login_identifier LIKE :q2
                     OR l.details LIKE :q3 OR l.ip_address LIKE :q4)';
        $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = $like;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Matching row count -> pagination.
    $count = $pdo->prepare(
        "SELECT COUNT(*)
         FROM audit_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         $whereSql"
    );
    $count->execute($params);
    $totalRows = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / AUDIT_PER_PAGE));
    $page = min($page, $totalPages);

    // One page of results, newest first.
    $stmt = $pdo->prepare(
        "SELECT l.id, l.user_id, l.action, l.details, l.ip_address, l.created_at,
                u.full_name, u.login_identifier, u.role
         FROM audit_logs l
         LEFT JOIN users u ON u.user_id = l.user_id
         $whereSql
         ORDER BY l.created_at DESC, l.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', AUDIT_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * AUDIT_PER_PAGE, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    // Action list for the filter dropdown.
    $actions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')
                   ->fetchAll(PDO::FETCH_COLUMN);

    // Overview cards (unfiltered).
    $row = $pdo->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)), 0) AS last24h,
                COALESCE(SUM(action IN ('ACCESS_DENIED', 'ACCOUNT_LOCKED')), 0) AS alerts,
                COUNT(DISTINCT user_id) AS users
         FROM audit_logs"
    )->fetch();
    $stats = array_map('intval', $row ?: $stats);

} catch (PDOException $e) {
    error_log('Audit log page error: ' . $e->getMessage());
    $databaseError = true;
}

/** Link to a results page, keeping the active filters. */
function page_url(int $p): string
{
    $q = array_filter([
        'action' => get_str('action'),
        'role'   => get_str('role', 20),
        'q'      => get_str('q'),
        'from'   => get_str('from', 10),
        'to'     => get_str('to', 10),
        'page'   => $p > 1 ? $p : null,
    ], static fn($v) => $v !== null && $v !== '');
    return 'admin_audit_logs.php' . ($q ? '?' . http_build_query($q) : '');
}

$hasFilters = ($filterAction . $filterRole . $filterSearch . $filterFrom . $filterTo) !== '';
$firstRow = $totalRows === 0 ? 0 : ($page - 1) * AUDIT_PER_PAGE + 1;
$lastRow = min($totalRows, $page * AUDIT_PER_PAGE);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Audit Logs | EduSphere</title>

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

            <a href="admin_dashboard.php" class="nav-link">
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

            <a href="admin_audit_logs.php"
               class="nav-link active"
               aria-current="page">
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
                <strong>Audit Logs</strong>
            </div>

            <div class="profile">
                <div class="profile-avatar">A</div>

                <div class="profile-details">
                    <strong><?= $h($fullName) ?></strong>
                    <span>Administrator</span>
                </div>
            </div>

        </header>

        <section class="page-content">

            <div class="welcome-section">
                <div>
                    <p class="eyebrow">ADMINISTRATION</p>

                    <h1>Audit Logs</h1>

                    <p class="welcome-message">
                        A read-only record of sign-ins, account changes,
                        uploads and blocked access attempts across EduSphere.
                    </p>
                </div>
            </div>

            <?php if ($databaseError): ?>
                <div class="alert alert-error" role="alert">
                    Unable to load the audit log. Please try again later.
                </div>
            <?php endif; ?>

            <!-- Overview -->
            <section class="statistics-section" aria-labelledby="auditOverview">

                <div class="section-heading">
                    <div>
                        <h2 id="auditOverview">Activity Overview</h2>
                        <p>Summary of all recorded events.</p>
                    </div>
                </div>

                <div class="statistics-grid">
                    <article class="stat-card total-card">
                        <p class="stat-title">Total Events</p>
                        <p class="stat-number"><?= $stats['total'] ?></p>
                        <p class="stat-description">All recorded entries</p>
                    </article>

                    <article class="stat-card student-card">
                        <p class="stat-title">Last 24 Hours</p>
                        <p class="stat-number"><?= $stats['last24h'] ?></p>
                        <p class="stat-description">Recent activity</p>
                    </article>

                    <article class="stat-card lecturer-card">
                        <p class="stat-title">Security Alerts</p>
                        <p class="stat-number"><?= $stats['alerts'] ?></p>
                        <p class="stat-description">Denied access and lockouts</p>
                    </article>

                    <article class="stat-card admin-card">
                        <p class="stat-title">Users Recorded</p>
                        <p class="stat-number"><?= $stats['users'] ?></p>
                        <p class="stat-description">Distinct accounts with activity</p>
                    </article>
                </div>
            </section>

            <!-- Log table -->
            <section class="audit-section" aria-labelledby="auditHeading">

                <div class="section-heading">
                    <div>
                        <h2 id="auditHeading">Event Log</h2>
                        <p>
                            <?php if ($totalRows > 0): ?>
                                Showing <?= $firstRow ?>&ndash;<?= $lastRow ?>
                                of <?= $totalRows ?>
                                <?= $totalRows === 1 ? 'event' : 'events' ?>,
                                newest first.
                            <?php else: ?>
                                No events to show.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <form class="audit-filters" method="get" action="admin_audit_logs.php">

                    <div class="filter-field filter-search">
                        <label for="fq">Search</label>
                        <input type="search" id="fq" name="q"
                               value="<?= $h($filterSearch) ?>"
                               maxlength="100"
                               placeholder="User, details or IP address">
                    </div>

                    <div class="filter-field">
                        <label for="faction">Action</label>
                        <select id="faction" name="action">
                            <option value="">All actions</option>
                            <?php foreach ($actions as $a): ?>
                                <option value="<?= $h($a) ?>"
                                    <?= $a === $filterAction ? 'selected' : '' ?>>
                                    <?= $h(audit_action_label($a)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="frole">User role</label>
                        <select id="frole" name="role">
                            <option value="">All roles</option>
                            <?php foreach (['student', 'lecturer', 'admin'] as $r): ?>
                                <option value="<?= $r ?>"
                                    <?= $r === $filterRole ? 'selected' : '' ?>>
                                    <?= ucfirst($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-field">
                        <label for="ffrom">From</label>
                        <input type="date" id="ffrom" name="from"
                               value="<?= $h($filterFrom) ?>">
                    </div>

                    <div class="filter-field">
                        <label for="fto">To</label>
                        <input type="date" id="fto" name="to"
                               value="<?= $h($filterTo) ?>">
                    </div>

                    <div class="filter-buttons">
                        <button type="submit" class="primary-button">Filter</button>
                        <?php if ($hasFilters): ?>
                            <a href="admin_audit_logs.php" class="secondary-button">Clear</a>
                        <?php endif; ?>
                    </div>

                </form>

                <div class="table-card">
                    <div class="table-scroll">
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th scope="col">Time</th>
                                    <th scope="col">User</th>
                                    <th scope="col">Action</th>
                                    <th scope="col">Details</th>
                                    <th scope="col">IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="5" class="empty-row">
                                        <?= $hasFilters
                                            ? 'No events match the selected filters.'
                                            : 'No events have been recorded yet.' ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td class="nowrap"><?= $h($log['created_at']) ?></td>
                                    <td>
                                        <?php if ($log['full_name'] !== null): ?>
                                            <strong><?= $h($log['full_name']) ?></strong>
                                            <span class="user-meta">
                                                <?= $h($log['login_identifier']) ?>
                                                &middot; <?= $h(ucfirst($log['role'])) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="user-meta">
                                                Deleted user (#<?= (int) $log['user_id'] ?>)
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $h(audit_action_category($log['action'])) ?>">
                                            <?= $h(audit_action_label($log['action'])) ?>
                                        </span>
                                    </td>
                                    <td class="details-cell">
                                        <?= $log['details'] !== null && $log['details'] !== ''
                                            ? $h($log['details'])
                                            : '<span class="user-meta">&mdash;</span>' ?>
                                    </td>
                                    <td class="nowrap"><?= $h($log['ip_address'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="Audit log pages">
                        <?php if ($page > 1): ?>
                            <a href="<?= $h(page_url($page - 1)) ?>" rel="prev">&larr; Previous</a>
                        <?php else: ?>
                            <span class="disabled">&larr; Previous</span>
                        <?php endif; ?>

                        <span class="page-status">Page <?= $page ?> of <?= $totalPages ?></span>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= $h(page_url($page + 1)) ?>" rel="next">Next &rarr;</a>
                        <?php else: ?>
                            <span class="disabled">Next &rarr;</span>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>

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
