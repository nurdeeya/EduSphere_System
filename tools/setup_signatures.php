<?php
// tools/setup_signatures.php - ONE-OFF helper, command line only.
//
// phpMussel cannot read the downloaded *.gz signature files directly: it opens each enabled
// file raw and expects it to begin with the text "phpMussel". This script
//   1. decompresses every *.gz in  <storage>/phpmussel/signatures/  (e.g. clamav.hdb.gz -> clamav.hdb),
//   2. checks each result really starts with "phpMussel",
//   3. writes <storage>/phpmussel/phpmussel.ini listing which signature files to enable.
//
//   php tools\setup_signatures.php          light set (recommended; fast, low memory)
//   php tools\setup_signatures.php --all    everything (executables, ELF, Flash, e-mail, ...; heavy)
//
// Safe to run again after updating the signature files.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('gzopen')) {
    fwrite(STDERR, "The PHP zlib extension is required (enable php_zlib / extension=zlib).\n");
    exit(1);
}

require_once __DIR__ . '/../config/storage.php';

$all  = in_array('--all', $argv, true);
$home = upload_storage_root() . DIRECTORY_SEPARATOR . 'phpmussel';
$dir  = $home . DIRECTORY_SEPARATOR . 'signatures';

if (!is_dir($dir)) {
    fwrite(STDERR, "Signatures folder not found: $dir\n");
    exit(1);
}

// Light set: skips the big executable/ELF/Mach-O/Flash/e-mail/Java files. Uploads here are
// PDF / Office / ZIP, so the general, PDF and Office (OLE) signatures matter most.
$heavy = '/(_exe|_elf|_macho|_swf|_email|_java)|^clamav\.mdb$/i';

$gz = glob($dir . DIRECTORY_SEPARATOR . '*.gz') ?: [];
if (!$gz) {
    echo "No .gz files found in $dir (already extracted?). Rebuilding the config from the files there.\n";
}

foreach ($gz as $path) {
    $out = substr($path, 0, -3);
    $in  = gzopen($path, 'rb');
    $tmp = $out . '.tmp';
    $fh  = fopen($tmp, 'wb');
    if (!$in || !$fh) {
        fwrite(STDERR, "FAILED to open: " . basename($path) . "\n");
        continue;
    }
    while (!gzeof($in)) {
        $chunk = gzread($in, 1048576);
        if ($chunk === false) { break; }
        fwrite($fh, $chunk);
    }
    gzclose($in);
    fclose($fh);
    rename($tmp, $out);
    echo "extracted " . basename($out) . "\n";
}

// Build the enabled list from the files that really are phpMussel signature files.
$active = [];
$skipped = [];
foreach (scandir($dir) ?: [] as $name) {
    $full = $dir . DIRECTORY_SEPARATOR . $name;
    if (!is_file($full) || preg_match('/\.(gz|dat|tmp)$/i', $name)) {
        continue;
    }
    $fh = fopen($full, 'rb');
    $head = $fh ? fread($fh, 9) : '';
    if ($fh) { fclose($fh); }
    if ($head !== 'phpMussel') {
        $skipped[] = $name;
        continue;
    }
    if (!$all && preg_match($heavy, $name)) {
        continue;
    }
    $active[] = $name;
}
sort($active);

if (!$active) {
    fwrite(STDERR, "No valid phpMussel signature files found. Nothing enabled.\n");
    exit(1);
}

$ini = "; phpMussel configuration for EduSpace. Written by tools/setup_signatures.php.\n"
     . "[core]\nscan_cache_expiry = 0\n\n"
     . "[signatures]\nactive = " . implode(',', $active) . "\n";
file_put_contents($home . DIRECTORY_SEPARATOR . 'phpmussel.ini', $ini);

echo "\nEnabled " . count($active) . " signature file(s) (" . ($all ? 'all' : 'light set') . ").\n";
if ($skipped) {
    echo "Ignored (not phpMussel format): " . implode(', ', $skipped) . "\n";
}
echo "Config written to $home" . DIRECTORY_SEPARATOR . "phpmussel.ini\n";
