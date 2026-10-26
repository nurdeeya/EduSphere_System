<?php
session_start();
require_once 'config/security.php';
require_once 'config/database.php';
require_once 'config/upload.php'; 

require_once __DIR__ . '/includes/access.php';
require_role('lecturer');

$message = '';
$message_type = '';
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die("Security Error: CSRF token validation failed.");
    }

    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $deadline = $_POST['deadline']; 
    $file_path = null;

    if (empty($title) || empty($deadline)) {
        $message = "Title and deadline are required.";
        $message_type = 'error';
    } elseif (strlen($title) > 100) {
        $message = "Title is too long (max 100 chars).";
        $message_type = 'error';
    } elseif (!validate_datetime($deadline)) {
        $message = "Invalid date format.";
        $message_type = 'error';
    } else {
        
        if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload_result = secure_file_upload($_FILES['assignment_file'], 'uploads/');
            
            if (strpos($upload_result, 'Error') !== false || strpos($upload_result, 'exceeds') !== false || strpos($upload_result, 'Invalid') !== false || strpos($upload_result, 'Failed') !== false) {
                $message = $upload_result;
                $message_type = 'error';
            } else {
                $file_path = $upload_result; 
            }
        }

        if ($message_type !== 'error') {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO assignments (title, description, file_path, deadline, created_by) 
                    VALUES (:title, :description, :file_path, :deadline, :created_by)
                ");
                
                $stmt->execute([
                    ':title'       => $title,
                    ':description' => $description,
                    ':file_path'   => $file_path,
                    ':deadline'    => $deadline,
                    ':created_by'  => $user_id
                ]);

                $_SESSION['success_msg'] = "Assignment '<strong>" . escape($title) . "</strong>' successfully created!";
                
                header('Location: lecturer_create_assignment.php');
                exit;

            } catch (PDOException $e) {
                $message = "System error: " . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

if (isset($_SESSION['success_msg'])) {
    $message = $_SESSION['success_msg'];
    $message_type = 'success';
    unset($_SESSION['success_msg']); 
}

try {
    $stmtFetch = $pdo->prepare("
        SELECT id, title, deadline 
        FROM assignments 
        WHERE created_by = :user_id 
        ORDER BY created_at DESC
    ");
    $stmtFetch->execute([':user_id' => $user_id]);
    $assignments = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching assignments: " . $e->getMessage());
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Assignments - EduSphere</title>
    <link rel="stylesheet" href="css/lecturer_dashboard_style.css">
    <style>
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #243b64; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 15px; }
        .form-control:focus { outline: none; border-color: #243b64; }
        .btn-submit { background-color: #243b64; color: white; padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: bold; width: 100%; margin-top: 10px; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 25px; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .alert-success { background: #d4edda; color: #155724; }
        .table-assignments { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .table-assignments th, .table-assignments td { padding: 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        .table-assignments th { background-color: #f8fafc; color: #64748b; font-weight: bold; }
        .btn-edit { background: #f59e0b; color: white; padding: 6px 12px; border-radius: 4px; text-decoration: none; font-size: 13px; }
    </style>
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
        <section class="welcome-section">
            <h1>Manage Assignments</h1>
            <p>Create a new assignment or edit an existing assignment below.</p>
        </section>

        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'error' ? 'alert-error' : 'alert-success' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <section class="information" style="max-width: 600px; margin-bottom: 30px;">
            <h2>Create New Assignment</h2>
           
            <form method="POST" action="" enctype="multipart/form-data" style="margin-top: 15px;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <div class="form-group">
                    <label>Assignment Title</label>
                    <input type="text" name="title" class="form-control" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
              
                <div class="form-group">
                    <label>Upload Assignment File (PDF, DOCX, ZIP - Max 10MB)</label>
                    <input type="file" name="assignment_file" class="form-control" accept=".pdf,.docx,.zip">
                </div>
                <div class="form-group">
                    <label>Deadline</label>
                    <input type="datetime-local" name="deadline" class="form-control" required>
                </div>
                <button type="submit" class="btn-submit">Create Assignment</button>
            </form>
        </section>

        <section class="information" style="max-width: 800px;">
            <h2>Your Existing Assignments</h2>
            <p style="color: #64748b; margin-bottom: 15px;">List of assignments you have created.</p>
            
            <?php if (count($assignments) > 0): ?>
                <table class="table-assignments">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Deadline</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td>
    <a href="lecturer_view_assignment.php?id=<?= $assignment['id'] ?>" style="color: #243b64; font-weight: bold; text-decoration: none;">
        <?= escape($assignment['title']) ?>
    </a>
</td>
                                <td><?= date('d M Y, h:i A', strtotime($assignment['deadline'])) ?></td>
                                <td>
                                    <a href="lecturer_edit_deadline.php?id=<?= $assignment['id'] ?>" class="btn-edit">Edit Deadline</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="font-style: italic; color: #64748b;">You haven't created any assignments yet.</p>
            <?php endif; ?>
        </section>

        <br>
        <a href="lecturer_dashboard.php" style="color: #64748b; text-decoration: none;">&larr; Back to Dashboard</a>
    </main>

</body>
</html>