
<?php

require_once __DIR__ . '/vendor/autoload.php';

use RobThree\Auth\TwoFactorAuth;
use RobThree\Auth\Providers\Qr\QRServerProvider;

try {
    $tfa = new TwoFactorAuth(
        new QRServerProvider(),
        'EduSphere'
    );

    $secret = $tfa->createSecret();

    if (empty($secret)) {
        throw new RuntimeException(
            'Failed to generate secret.'
        );
    }

    echo 'PASS: MFA library loaded successfully.<br>';
    echo 'PASS: Temporary MFA secret generated.<br>';
    echo 'Secret length: ' . strlen($secret);

} catch (Throwable $e) {
    http_response_code(500);

    error_log((string) $e);

    echo 'MFA test failed. Check the PHP error log.';
}
