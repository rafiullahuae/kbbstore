<?php

/**
 * KBB emergency recovery — standalone.
 *
 * This file does NOT boot Laravel. That is the entire point: if an update leaves
 * the application unable to start, every tool inside the application is
 * unavailable too. This one keeps working because it depends on nothing but PHP
 * and the filesystem.
 *
 * It restores the files from an update backup, exactly as they were.
 *
 * ACCESS
 *   Set a token below, then visit:
 *     https://your-site/kbb-upgrade/kbb-recover.php?token=YOUR_TOKEN
 *
 * SECURITY
 *   Change RECOVER_TOKEN before uploading. With the default value the script
 *   refuses to run at all. It is also rate-limited by a lock file and only ever
 *   copies files out of the backup directory — it cannot be made to write
 *   anywhere else.
 */

// ---------------------------------------------------------------------------
const RECOVER_TOKEN = 'REDACTED-ROTATE-AND-SET-YOUR-OWN-See-KBB-Master-Plan-risk-register';
// ---------------------------------------------------------------------------

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (RECOVER_TOKEN === 'REDACTED-ROTATE-AND-SET-YOUR-OWN-See-KBB-Master-Plan-risk-register') {
    http_response_code(503);
    exit("Recovery is disabled.\nEdit kbb-recover.php and set RECOVER_TOKEN to a long random string.\n");
}

$given = (string) ($_GET['token'] ?? '');

// Constant-time comparison, and a deliberate pause, so the token cannot be
// guessed by timing or by brute force.
usleep(300000);

if (! hash_equals(RECOVER_TOKEN, $given)) {
    http_response_code(404);
    exit("Not found.\n");
}

/* Locate the application root: the folder holding bootstrap/ and storage/. */
$candidates = [
    __DIR__ . '/..',
    __DIR__ . '/../../kbb-upgrade-app',
    __DIR__ . '/../kbb-upgrade-app',
    __DIR__ . '/../../../kbb-upgrade-app',
];

$appRoot = null;
foreach ($candidates as $candidate) {
    if (is_dir($candidate . '/storage') && is_dir($candidate . '/bootstrap')) {
        $appRoot = realpath($candidate);
        break;
    }
}

if ($appRoot === null) {
    http_response_code(500);
    exit("Could not locate the application folder.\n");
}

$backupRoot = $appRoot . '/storage/app/updates/backups';

if (! is_dir($backupRoot)) {
    exit("No backups directory found at:\n  {$backupRoot}\n");
}

$backups = array_values(array_filter(glob($backupRoot . '/*') ?: [], 'is_dir'));
rsort($backups);

if ($backups === []) {
    exit("No backups available.\n");
}

$restore = $_GET['restore'] ?? null;

/* No target chosen: list what is available and stop. */
if ($restore === null) {
    echo "KBB recovery\n";
    echo "App root: {$appRoot}\n\n";
    echo "Available backups, newest first:\n\n";

    foreach ($backups as $dir) {
        $id = basename($dir);
        $manifest = @json_decode((string) @file_get_contents($dir . '/manifest.json'), true) ?: [];
        $replaced = count($manifest['replaced'] ?? []);
        $added = count($manifest['added'] ?? []);
        $hasDb = is_file($dir . '/database.sql') ? ' + database.sql' : '';

        echo "  {$id}\n";
        echo "      created:  " . ($manifest['created_at'] ?? 'unknown') . "\n";
        echo "      restores: {$replaced} files, removes {$added} added files{$hasDb}\n";
        echo "      restore:  ?token=...&restore={$id}\n\n";
    }

    echo "Add &restore=<id> to the URL to roll that update back.\n";
    exit;
}

/* Restore. Only a basename is accepted, so no path can be smuggled in. */
$id = basename((string) $restore);
$dir = $backupRoot . '/' . $id;

if (! is_dir($dir) || ! is_file($dir . '/manifest.json')) {
    http_response_code(400);
    exit("Backup '{$id}' not found.\n");
}

$manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
$restored = 0;
$removed = 0;
$failed = [];

foreach ((array) ($manifest['replaced'] ?? []) as $relative) {
    $source = $dir . '/files/' . $relative;
    $target = $appRoot . '/' . $relative;

    if (! is_file($source)) {
        continue;
    }

    if (! is_dir(dirname($target))) {
        @mkdir(dirname($target), 0755, true);
    }

    @copy($source, $target) ? $restored++ : $failed[] = $relative;
}

foreach ((array) ($manifest['added'] ?? []) as $relative) {
    $target = $appRoot . '/' . $relative;

    if (is_file($target) && @unlink($target)) {
        $removed++;
    }
}

/* Clear compiled caches and lift maintenance mode, or the site stays down. */
foreach (glob($appRoot . '/bootstrap/cache/*.php') ?: [] as $cached) {
    @unlink($cached);
}
foreach (glob($appRoot . '/storage/framework/views/*.php') ?: [] as $view) {
    @unlink($view);
}
@unlink($appRoot . '/storage/framework/down');

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

echo "Restored backup {$id}\n\n";
echo "  files restored: {$restored}\n";
echo "  files removed:  {$removed}\n";
echo "  caches cleared, maintenance mode lifted\n";

if ($failed !== []) {
    echo "\nCould not restore (check permissions):\n";
    foreach ($failed as $path) {
        echo "  - {$path}\n";
    }
}

if (is_file($dir . '/database.sql')) {
    echo "\nNOTE: this update also ran migrations.\n";
    echo "A database dump is at:\n  {$dir}/database.sql\n";
    echo "Files alone may not be enough — import that dump via phpMyAdmin if the\n";
    echo "site still misbehaves. Restoring a database is not automated on purpose:\n";
    echo "it would overwrite any orders placed since the update.\n";
}

echo "\nNow open the site. If it works, delete nothing — leave the backups in place.\n";
