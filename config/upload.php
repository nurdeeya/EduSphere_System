<?php
// config/upload.php - shared secure upload helper (SR3: Secure File Upload).
//
// Returns the NEW random filename on success, or an error sentence on failure.
// (Return format is unchanged so existing callers keep working.)

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10MB

// Default whitelist: extension => MIME types that libmagic may report for it.
// DOCX/PPTX are ZIP containers, so some finfo versions report application/zip.
const UPLOAD_DEFAULT_TYPES = [
    'pdf'  => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
];

function secure_file_upload($file, $upload_dir, ?array $allowed_types = null) {
    $allowed_types = $allowed_types ?? UPLOAD_DEFAULT_TYPES;

    // 0. Reject malformed $_FILES entries (e.g. name="file[]" arrays).
    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])
        || is_array($file['error']) || is_array($file['name'])) {
        return "File upload error.";
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return "File size exceeds 10MB limit.";
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return "File upload error.";
    }

    // 1. Check Saiz (Max 10MB) - bounds check, also rejects empty files.
    if ($file['size'] <= 0 || $file['size'] > UPLOAD_MAX_BYTES || filesize($file['tmp_name']) > UPLOAD_MAX_BYTES) {
        return "File size exceeds 10MB limit.";
    }

    // 2. Whitelist extension FIRST. Without this, a real PDF named "shell.php"
    //    would pass the MIME check and be saved as "<random>.php".
    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed_types[$ext])) {
        return "Invalid file type. Only " . strtoupper(implode(', ', array_keys($allowed_types))) . " are allowed.";
    }

    // 3. Check MIME Type (Magic Bytes) - must match the claimed extension.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types[$ext], true)) {
        return "Invalid file type. The file content does not match its extension.";
    }

    // 4. change file name to random (to avoid overwrite & path traversal)
    $new_filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $destination = rtrim($upload_dir, '/\\') . '/' . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return $new_filename; // Return new file name
    } else {
        return "Failed to save file.";
    }
}

/** True when secure_file_upload() returned a stored filename (not an error sentence). */
function upload_succeeded($result): bool {
    return is_string($result) && preg_match('/^[a-f0-9]{32}\.[a-z0-9]{1,10}$/', $result) === 1;
}
?>
