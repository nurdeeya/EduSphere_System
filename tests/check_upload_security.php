<?php
// tests/check_upload_security.php - command line self-check for SR3 (secure file upload).
//   php tests/check_upload_security.php
// Delete the tests/ folder before deploying to a public server.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/storage.php';
require_once __DIR__ . '/../config/malware_scan.php';

$fail = 0;
function check(string $label, bool $ok, string $extra = ''): void
{
    global $fail;
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . ($extra !== '' ? "  ($extra)" : '') . "\n";
    if (!$ok) { $fail++; }
}

// 1. Storage is outside the web root and not inside the project folder.
$root = upload_storage_root();
$project = realpath(__DIR__ . '/..');
$webroot = dirname($project); // project sits directly inside the web root (e.g. C:\wamp64\www)
$norm = static function (string $p): string { return strtolower(rtrim(str_replace('\\', '/', $p), '/')) . '/'; };
check(
    'Storage root is outside the project folder and the web root',
    strncmp($norm($root), $norm($webroot), strlen($norm($webroot))) !== 0,
    $root
);

// 2. Scanner is installed and has signatures.
// Work in a dedicated folder (<storage>/phpmussel/eicar-test) so that folder - and only it - can be
// excluded from Windows Defender. Otherwise Defender deletes the EICAR file before phpMussel sees it.
$testDir = storage_ensure_dir(malware_scan_home() . DIRECTORY_SEPARATOR . 'eicar-test');
echo "Test folder (exclude it in Windows Security if the EICAR check fails): $testDir\n";
$tmp = $testDir . DIRECTORY_SEPARATOR . 'sample_' . bin2hex(random_bytes(4)) . '.tmp';
file_put_contents($tmp, "This is a harmless text file.\n");
$r = malware_scan_file($tmp);
check('phpMussel scans a harmless file as clean', $r['status'] === 'clean', $r['status'] . ': ' . $r['detail']);

// 3. Scanner catches the standard EICAR anti-virus test string (harmless; built from two
//    halves so this file itself is not flagged by antivirus software).
$eicar = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$' . 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
file_put_contents($tmp, $eicar);
clearstatcache();
if (!is_file($tmp)) {
    echo "        (the EICAR file vanished - your antivirus removed it. Exclude the test folder above.)\n";
}
$r = malware_scan_file($tmp);
check('phpMussel flags the EICAR test file', $r['status'] === 'infected', $r['status'] . ': ' . $r['detail']);

@unlink($tmp);
echo $fail === 0 ? "\nAll checks passed.\n" : "\n$fail check(s) failed.\n";
exit($fail === 0 ? 0 : 1);
