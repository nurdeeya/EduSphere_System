<?php
session_start();

// Allow only fully authenticated lecturers.
require_once __DIR__ . '/includes/access.php';
require_role('lecturer');

$fullName = $_SESSION['full_name'] ?? 'Lecturer';
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Dashboard</title>

    <link rel="stylesheet" href="css/lecturer_dashboard_style.css">

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
        <section class="welcome-section">
            <h1>
                Welcome, <?= htmlspecialchars(
                    $fullName,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </h1>
            
            <p>
                Welcome to EduSphere. Manage your courses,
                organise learning materials and support
                your students through one platform.
            </p>
        </section>

        <h2 class="section-title">Lecturer Dashboard</h2>

        <section class="card-container">
            <div class="card">
                <h3>Account Role</h3>
                <p>Lecturer</p>
            </div>

            <div class="card">
                <h3>Account ID</h3>
                <p><?= htmlspecialchars(
                    (string) $_SESSION['user_id'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></p>
            </div> 

            <div class="card">
                <h3>Account Status</h3>
                <p>Active</p>
            </div>
        </section>

<!-- Lecturer features -->
        <h2 class="section-title">Lecturer Tools</h2>

        <section class="card-container">

            <div class="card">
                <h3>Course Management</h3>
                <p class="feature-description">
                    Organise and manage the courses assigned
                    to you.
                </p>
                <a href="lecturer_create_assignment.php" style="display: inline-block; padding: 10px 16px; background-color: #243b64; color: white; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: bold; margin-top: 10px;">Manage Assignments</a>
            </div>

            <div class="card">
                <h3>Learning Materials</h3>
                <p class="feature-description">
                    Prepare and share notes, lecture slides
                    and other learning resources.
                </p>
                <span class="feature-status">
                    Planned Feature
                </span>
            </div>

            <div class="card">
                <h3>Student Overview</h3>
                <p class="feature-description">
                    View student information and support
                    their learning activities.
                </p>
                <span class="feature-status">
                    Planned Feature
                </span>
            </div>

        </section>

        <!-- Information section -->
        <section class="information">
            <h2>Getting Started</h2>

            <p>
                You have successfully logged in to EduSphere
                as a lecturer.
            </p>

            <p>
                Use this dashboard as your starting point
                for managing teaching and learning activities.
                Additional features can be implemented
                according to the system requirements.
            </p>
        </section>

    </main>

    <footer>
        &copy; <?= date('Y') ?> EduSphere System
    </footer>

</body>
</html>