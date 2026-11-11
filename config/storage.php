<?php
// config/storage.php - where uploaded files (and phpMussel's working data) live.
//
// SR3: uploads are stored OUTSIDE the publicly accessible web root, so no URL can ever
// reach them. They are only served through download.php / material_download.php, which
// check login, role and ownership first.
//
// Default location: a folder called "eduspace_storage" next to the web root, e.g.
//     XAMPP (Windows):   C:\xampp\eduspace_storage       (web root = C:\xampp\htdocs)
//     Linux / Apache:    /var/www/eduspace_storage       (web root = /var/www/html)
// Override with the EDUSPACE_STORAGE_DIR environment variable (absolute path).

/** Absolute path of the storage root (not guaranteed to exist yet). */
function upload_storage_root(): string
{
    $env = getenv('EDUSPACE_STORAGE_DIR');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/\\');
    }

    // Note: on the command line DOCUMENT_ROOT is '' and realpath('') would return the current folder.
    $docroot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    if ($docroot !== false && $docroot !== '') {
        return dirname($docroot) . DIRECTORY_SEPARATOR . 'eduspace_storage';
    }

    // CLI (no document root): assume the project sits directly inside the web root
    // (e.g. C:\wamp64\www\EduSpace_System), so use the web root's parent - the same
    // folder the web server picks above.
    return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'eduspace_storage';
}

/** True when $path is inside the web server's document root (which uploads must never be). */
function path_is_inside_webroot(string $path): bool
{
    if (empty($_SERVER['DOCUMENT_ROOT'])) {
        return false; // CLI: nothing is web-served.
    }
    $docroot = realpath((string) $_SERVER['DOCUMENT_ROOT']);
    if ($docroot === false) {
        return false;
    }

    // The folder may not exist yet: resolve its nearest existing parent, then re-append the rest.
    $rest = '';
    $real = realpath($path);
    $probe = $path;
    while ($real === false) {
        $parent = dirname($probe);
        if ($parent === $probe) {
            return false;
        }
        $rest  = DIRECTORY_SEPARATOR . basename($probe) . $rest;
        $probe = $parent;
        $real  = realpath($probe);
    }
    $real .= $rest;
    $docroot = rtrim($docroot, '/\\') . DIRECTORY_SEPARATOR;
    $real    = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;
    return strncasecmp($real, $docroot, strlen($docroot)) === 0;
}

/** Create a private directory if needed (0750) and return its path. */
function storage_ensure_dir(string $dir): string
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        error_log('storage: cannot create directory ' . $dir);
    }
    return $dir;
}

/**
 * Directory that holds uploaded assignment files, submissions and learning materials.
 * Created on first use. Contains a deny-all .htaccess as defence in depth in case an
 * administrator later points the web root at a parent folder by mistake.
 */
function upload_storage_dir(): string
{
    $dir = storage_ensure_dir(upload_storage_root() . DIRECTORY_SEPARATOR . 'uploads');

    $guard = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($dir) && !is_file($guard)) {
        @file_put_contents(
            $guard,
            "Require all denied\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\nOptions -Indexes\n"
        );
    }
    return $dir;
}
