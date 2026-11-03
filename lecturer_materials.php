<?php
// FR4: Upload Learning Materials (lecture notes, slides) for enrolled students.
// Security: SR2 (lecturer only, own materials only), SR3 (secure upload: extension + magic-byte
//           check, 10MB cap, random filename, served only via material_download.php),
//           SR7 (validated + length-bounded inputs, escaped output), CSRF on every POST,
//           prepared statements, audit log for upload/delete.
session_start();
require_once 'config/security.php';
require_once 'config/database.php';
require_once 'config/upload.php';

require_once __DIR__ . '/includes/access.php';
require_role('lecturer');
require_once __DIR__ . '/includes/lecturer.php';

const MATERIAL_TYPES = [
    'pdf'  => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
];
const MATERIAL_DIR = __DIR__ . '/uploads';

$user_id = (int) $_SESSION['user_id'];
$message = '';
$message_type = '';
$old = ['title' => '', 'description' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploaded_name = null;

    do {
        if (lec_post_too_large()) {
            $message = 'The upload is too large. The maximum file size is 10MB.';
            $message_type = 'error';
            break;
        }
        if (!verify_csrf_token(lec_str($_POST['csrf_token'] ?? ''))) {
            render_error(403, 'Security Check Failed', 'Your form session has expired. Please reload the page and try again.');
        }

        $action = lec_str($_POST['action'] ?? '');

        // ------------------------------------------------------------ DELETE
        if ($action === 'delete') {
            $material_id = filter_var($_POST['material_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($material_id === false || $material_id === null) {
                render_error(400, 'Bad Request', 'No valid material was selected.');
            }
            try {
                // Ownership enforced in the WHERE clause.
                $stmt = $pdo->prepare("SELECT file_path, title FROM learning_materials WHERE id = :id AND lecturer_id = :uid");
                $stmt->execute([':id' => $material_id, ':uid' => $user_id]);
                $row = $stmt->fetch();
                if (!$row) {
                    audit_denied($pdo, $user_id, 'lecturer_materials delete id=' . $material_id . ' not owner or missing');
                    render_forbidden();
                }

                $pdo->beginTransaction();
                $del = $pdo->prepare("DELETE FROM learning_materials WHERE id = :id AND lecturer_id = :uid");
                $del->execute([':id' => $material_id, ':uid' => $user_id]);
                $log = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (:uid, 'MATERIAL_DELETED', :details, :ip)");
                $log->execute([
                    ':uid'     => $user_id,
                    ':details' => 'material_id=' . $material_id . '; file=' . $row['file_path'],
                    ':ip'      => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
                ]);
                $pdo->commit();

                // Remove the file only if the stored name has the safe random pattern.
                if (upload_succeeded($row['file_path']) && is_file(MATERIAL_DIR . '/' . basename($row['file_path']))) {
                    @unlink(MATERIAL_DIR . '/' . basename($row['file_path']));
                }
                lec_flash('success', "Material '" . $row['title'] . "' deleted.");
                header('Location: lecturer_materials.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('lecturer_materials (delete): ' . $e->getMessage());
                $message = 'A system error occurred. Please try again later.';
                $message_type = 'error';
                break;
            }
        }

        if ($action !== 'upload') {
            render_error(400, 'Bad Request', 'Unknown action.');
        }

        // ------------------------------------------------------------ UPLOAD
        $title       = lec_str($_POST['title'] ?? '');
        $description = lec_str($_POST['description'] ?? '');
        $old = ['title' => $title, 'description' => $description];

        if ($title === '') {
            $message = 'Title is required.';
        } elseif (!lec_text_ok($title, LEC_MATERIAL_TITLE_MAX)) {
            $message = 'Title must be at most ' . LEC_MATERIAL_TITLE_MAX . ' characters with no control characters.';
        } elseif (!lec_text_ok($description, LEC_MATERIAL_DESC_MAX, true)) {
            $message = 'Description must be at most ' . LEC_MATERIAL_DESC_MAX . ' characters.';
        }
        if ($message !== '') {
            $message_type = 'error';
            break;
        }

        $file = $_FILES['material_file'] ?? null;
        if (!is_array($file) || !isset($file['error']) || is_array($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $message = 'Please choose a file to upload.';
            $message_type = 'error';
            break;
        }

        $result = secure_file_upload($file, MATERIAL_DIR, MATERIAL_TYPES);
        if (!upload_succeeded($result)) {
            $message = is_string($result) ? $result : 'File upload failed.';
            $message_type = 'error';
            break;
        }
        $uploaded_name = $result;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO learning_materials (lecturer_id, title, description, file_path, file_size)
                VALUES (:uid, :title, :description, :file_path, :file_size)
            ");
            $stmt->execute([
                ':uid'         => $user_id,
                ':title'       => $title,
                ':description' => $description === '' ? null : $description,
                ':file_path'   => $uploaded_name,
                ':file_size'   => (int) filesize(MATERIAL_DIR . '/' . $uploaded_name),
            ]);
            $new_id = (int) $pdo->lastInsertId();

            $log = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (:uid, 'MATERIAL_UPLOADED', :details, :ip)");
            $log->execute([
                ':uid'     => $user_id,
                ':details' => 'material_id=' . $new_id . '; file=' . $uploaded_name,
                ':ip'      => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            ]);
            $pdo->commit();

            lec_flash('success', "Material '" . $title . "' uploaded. Enrolled students can now download it.");
            header('Location: lecturer_materials.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('lecturer_materials (upload): ' . $e->getMessage());
            if (is_file(MATERIAL_DIR . '/' . $uploaded_name)) {
                @unlink(MATERIAL_DIR . '/' . $uploaded_name);
            }
            $message = 'A system error occurred. Please try again later.';
            $message_type = 'error';
        }
    } while (false);
}

$flash = lec_take_flash();
if ($flash['text'] !== '') {
    $message = $flash['text'];
    $message_type = $flash['type'];
}

try {
    $stmt = $pdo->prepare("
        SELECT id, title, description, file_path, file_size, uploaded_at
        FROM learning_materials
        WHERE lecturer_id = :uid
        ORDER BY uploaded_at DESC, id DESC
    ");
    $stmt->execute([':uid' => $user_id]);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('lecturer_materials (list): ' . $e->getMessage());
    render_error(500, 'System Error', 'Learning materials are unavailable. Make sure sql/lecturer_module_update.sql has been imported.');
}

function human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

$csrf_token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Materials - EduSphere</title>
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
        <?php lec_nav('materials'); ?>

        <section class="welcome-section">
            <h1>Learning Materials</h1>
            <p>Share lecture notes, slides and other resources with the students enrolled with you.</p>
        </section>

        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'error' ? 'alert-error' : 'alert-success' ?>" role="<?= $message_type === 'error' ? 'alert' : 'status' ?>">
                <?= escape($message) ?>
            </div>
        <?php endif; ?>

        <section class="information lec-narrow">
            <h2>Upload New Material</h2>
            <form method="POST" action="lecturer_materials.php" enctype="multipart/form-data" style="margin-top: 15px;">
                <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
                <input type="hidden" name="action" value="upload">
                <div class="form-group">
                    <label for="title">Title <span class="char-count" data-for="title"></span></label>
                    <input type="text" id="title" name="title" class="form-control" maxlength="<?= LEC_MATERIAL_TITLE_MAX ?>" value="<?= escape($old['title']) ?>" placeholder="e.g. Week 5 - Threat Modelling Slides" required>
                </div>
                <div class="form-group">
                    <label for="description">Description (optional) <span class="char-count" data-for="description"></span></label>
                    <textarea id="description" name="description" class="form-control" rows="3" maxlength="<?= LEC_MATERIAL_DESC_MAX ?>"><?= escape($old['description']) ?></textarea>
                </div>
                <div class="form-group">
                    <label for="material_file">File</label>
                    <input type="file" id="material_file" name="material_file" class="form-control" accept=".pdf,.docx,.pptx,.zip" required>
                    <p class="form-hint">PDF, DOCX, PPTX or ZIP, up to 10MB. The file content is checked, not just its name.</p>
                </div>
                <button type="submit" class="btn-submit">Upload Material</button>
            </form>
        </section>

        <section class="information">
            <h2>Your Materials</h2>
            <?php if ($materials): ?>
                <div class="table-wrap">
                <table class="lec-table">
                    <thead>
                        <tr><th>Title</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($materials as $m): ?>
                        <tr>
                            <td>
                                <strong><?= escape($m['title']) ?></strong>
                                <?php if (!empty($m['description'])): ?><br><span class="small"><?= nl2br(escape($m['description'])) ?></span><?php endif; ?>
                            </td>
                            <td><?= escape(strtoupper(pathinfo($m['file_path'], PATHINFO_EXTENSION))) ?></td>
                            <td class="num"><?= escape(human_size((int) $m['file_size'])) ?></td>
                            <td><?= escape(date('d M Y, h:i A', strtotime($m['uploaded_at']))) ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn-small btn-navy" href="material_download.php?id=<?= (int) $m['id'] ?>">Download</a>
                                    <form method="POST" action="lecturer_materials.php" onsubmit="return confirm('Delete this material? Students will no longer be able to download it.');">
                                        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="material_id" value="<?= (int) $m['id'] ?>">
                                        <button type="submit" class="btn-small btn-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php else: ?>
                <p class="muted">You haven't uploaded any learning materials yet.</p>
            <?php endif; ?>
        </section>

        <a href="lecturer_dashboard.php" class="back-link">&larr; Back to Dashboard</a>
    </main>

    <script>
    document.querySelectorAll('.char-count').forEach(function (el) {
        var input = document.getElementById(el.dataset.for);
        var update = function () { el.textContent = input.value.length + ' / ' + input.maxLength; };
        input.addEventListener('input', update);
        update();
    });
    </script>
</body>
</html>
