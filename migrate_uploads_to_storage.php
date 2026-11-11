<?php
// migrate_uploads_to_storage.php - ONE-OFF, command line only.
// Moves files from the old in-web-root  uploads/  folder to the private storage folder
// (see config/storage.php). Database rows only hold the random filename, so nothing else changes.
//
//   php migrate_uploads_to_storage.php
//
// Delete this file after running it.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/storage.php';

$old = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$new = upload_storage_dir();

if (!is_dir($old)) {
    echo "Nothing to migrate: $old does not exist.\n";
    exit(0);
}

$moved = 0;
$skipped = 0;
foreach (scandir($old) ?: [] as $name) {
    if (!preg_match('/^[a-f0-9]{32}\.[A-Za-z0-9]{1,10}$/', $name)) {
        continue; // only our own random-named uploads (skips .htaccess, etc.)
    }
    $from = $old . DIRECTORY_SEPARATOR . $name;
    $to   = $new . DIRECTORY_SEPARATOR . $name;
    if (file_exists($to)) {
        echo "skip (already exists): $name\n";
        $skipped++;
        continue;
    }
    if (@rename($from, $to) || (@copy($from, $to) && @unlink($from))) {
        $moved++;
    } else {
        echo "FAILED: $name\n";
        $skipped++;
    }
}

echo "Moved $moved file(s) to $new ($skipped skipped).\n";
echo "The old uploads/ folder can now be deleted.\n";
