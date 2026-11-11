<?php
// config/upload.php - shared secure upload helper (SR3: Secure File Upload).
//
// Returns the NEW random filename on success, or an error sentence on failure.
// (Return format is unchanged so existing callers keep working.)

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/malware_scan.php';
require_once __DIR__ . '/../includes/audit.php';

const UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10MB

// Fail closed: if the malware scanner cannot give a definite "clean", the upload is refused.
// Only set to false on a development machine while phpMussel is not set up yet.
const UPLOAD_SCAN_FAIL_CLOSED = true;

// Default whitelist: extension => MIME types that libmagic may report for it.
// DOCX/PPTX are ZIP containers, so some finfo versions report application/zip.
const UPLOAD_DEFAULT_TYPES = [
    'pdf'  => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'zip'  => ['application/zip', 'application/x-zip-compressed'],
];

/** Record a blocked upload in the audit log (when a DB connection and session user exist). */
function upload_audit_block(string $reason, string $client_name): void
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!($pdo instanceof PDO) || !isset($_SESSION['user_id'])) {
        return;
    }
    $safe_name = substr((string) preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($client_name)), 0, 100);
    audit_log($pdo, (int) $_SESSION['user_id'], 'UPLOAD_BLOCKED', $reason . '; original_name=' . $safe_name);
}

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

    // 4. Malware scan (phpMussel) BEFORE the file is stored anywhere.
    $scan = malware_scan_file($file['tmp_name']);
    if ($scan['status'] === 'infected') {
        error_log('upload blocked (malware): ' . $scan['detail']);
        upload_audit_block('malware detected', (string) $file['name']);
        return "The file was rejected because it may contain malware.";
    }
    if ($scan['status'] !== 'clean') {
        error_log('upload scan unavailable: ' . $scan['detail']);
        if (UPLOAD_SCAN_FAIL_CLOSED) {
            upload_audit_block('scan unavailable: ' . $scan['detail'], (string) $file['name']);
            return "The file could not be security-scanned right now, so it was not accepted. Please try again later or contact your administrator.";
        }
    }

    // 5. Never store uploads inside the web root.
    if (path_is_inside_webroot($upload_dir)) {
        error_log('upload refused: upload directory is inside the web root: ' . $upload_dir);
        return "File upload is not available (server storage is misconfigured).";
    }

    // 6. change file name to random (to avoid overwrite & path traversal)
    $new_filename = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0750, true);
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
