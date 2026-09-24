<?php

/**
 * KBB emergency recovery — standalone.
 *
 * This file does NOT boot Laravel. That is the entire point: if an update
 * leaves the application unable to start, every tool inside the application is
 * unavailable too. This one keeps working because it depends on nothing but PHP
 * and the filesystem.
 *
 * It restores the files from an update backup, exactly as they were.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE WAS REWRITTEN, 24 September 2026
 * ---------------------------------------------------------------------------
 *
 * It shipped dead. The token below was a placeholder, and the script refused to
 * run until somebody opened the file and edited it. Nobody ever did. So at the
 * one moment it existed for — a storefront down for an hour and an updater that
 * could not install its own fix — the recovery tool answered 503 and the
 * recovery was done by hand in a database console instead.
 *
 * A tool that needs to be armed before an emergency is a tool that is disarmed
 * during one. Two things changed, and neither loosens anything:
 *
 *   1. IT RUNS FROM THE COMMAND LINE, WITH NO TOKEN AND NOTHING TO EDIT. The
 *      live host is Cloudways and it has a shell (Servers → Launch SSH
 *      Terminal). Getting to that shell already means holding the server's own
 *      credentials, which is a far higher bar than any string in a URL, so
 *      there is nothing left for a token to protect. And it works when the
 *      application does not: `php artisan` needs Laravel to boot, and the case
 *      this file is for is precisely the case where Laravel will not.
 *
 *          php kbb-recover.php                # list the backups
 *          php kbb-recover.php <backup-id>    # restore that one
 *          php kbb-recover.php --status       # is the web door open?
 *
 *   2. THE WEB DOOR TAKES ITS TOKEN FROM .env, NOT FROM THIS FILE. Set
 *      KBB_RECOVER_TOKEN in the application's .env to a long random string and
 *      the URL below works; leave it unset and there is no web door at all.
 *      That is the same closed default as before — this file is inert on the
 *      web until somebody deliberately opens it — but it is now openable
 *      without editing code, so arming it is a thing that can be done in
 *      advance and left armed.
 *
 *          php -r 'echo bin2hex(random_bytes(24));' >> /dev/stdout
 *          # put it in .env as KBB_RECOVER_TOKEN=..., then:
 *          https://your-site/kbb-recover.php?token=THAT
 *
 *      A token shorter than 24 characters is treated as no token.
 *
 * ---------------------------------------------------------------------------
 * SECURITY
 * ---------------------------------------------------------------------------
 *
 * A closed web door answers 404 and says nothing else — not 503 and not "set a
 * token", both of which confirm to anyone asking that this file is here and
 * worth coming back to. The owner does not need that message: `--status` from
 * the shell says exactly what state the door is in, and the shell is where the
 * owner is.
 *
 * The comparison is constant-time and preceded by a deliberate pause, so the
 * token cannot be recovered by timing or walked by brute force. The script only
 * ever copies files OUT of a backup directory and only ever accepts a basename,
 * so no path can be smuggled through it.
 *
 * NOTE FOR WHOEVER SHIPS THIS: it cannot travel in an update package.
 * UpdateGuard lists kbb-recover.php as never writable and BuildPackage excludes
 * public-web-root/ outright, both on purpose — a bad update must not be able to
 * damage its own escape route. Upload it over SSH or SFTP.
 */

$isCli = PHP_SAPI === 'cli';

/* Locate the application root: the folder holding bootstrap/ and storage/. */
$candidates = [
    __DIR__ . '/..',
    __DIR__ . '/../private_html/kbb-app',
    __DIR__ . '/../../private_html/kbb-app',
    __DIR__ . '/../kbb-app',
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

/** One value out of the application's .env, without booting anything. */
$envValue = static function (?string $root, string $key): string {
    if ($root === null || ! is_file($root . '/.env')) {
        return '';
    }

    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);

        if (trim($name) !== $key) {
            continue;
        }

        return trim(trim($value), "\"'");
    }

    return '';
};

$webToken = $envValue($appRoot, 'KBB_RECOVER_TOKEN');
$webDoorOpen = strlen($webToken) >= 24;

if (! $isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');

    // Constant-time, and a deliberate pause, so the token cannot be recovered
    // by timing or walked by brute force.
    usleep(300000);

    if (! $webDoorOpen || ! hash_equals($webToken, (string) ($_GET['token'] ?? ''))) {
        http_response_code(404);
        exit("Not found.\n");
    }
}

/* --------------------------------------------------------------------------
 * From here down, the caller is either on the shell or has proved the token.
 * -------------------------------------------------------------------------- */

$argument = $isCli
    ? (string) ($argv[1] ?? '')
    : (string) ($_GET['restore'] ?? '');

if ($isCli && ($argument === '--status' || $argument === '-s')) {
    echo "KBB recovery\n";
    echo 'App root:  ' . ($appRoot ?? '(not found)') . "\n";
    echo 'Web door:  ' . ($webDoorOpen
        ? 'OPEN — KBB_RECOVER_TOKEN is set in .env'
        : 'closed — KBB_RECOVER_TOKEN is not set in .env, so the URL answers 404') . "\n";
    echo "\nThis shell always works, whether the web door is open or not.\n";
    exit;
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

/* No target chosen: list what is available and stop. */
if ($argument === '') {
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
        echo '      created:  ' . ($manifest['created_at'] ?? 'unknown') . "\n";
        echo "      restores: {$replaced} files, removes {$added} added files{$hasDb}\n";
        echo '      restore:  ' . ($isCli ? "php kbb-recover.php {$id}" : "?token=...&restore={$id}") . "\n\n";
    }

    echo $isCli
        ? "Run this again with a backup id to roll that update back.\n"
        : "Add &restore=<id> to the URL to roll that update back.\n";
    exit;
}

/* Restore. Only a basename is accepted, so no path can be smuggled in. */
$id = basename($argument);
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
    echo "Files alone may not be enough — import that dump if the site still\n";
    echo "misbehaves. Restoring a database is not automated on purpose: it would\n";
    echo "overwrite any orders placed since the update.\n";
}

echo "\nNow open the site. If it works, delete nothing — leave the backups in place.\n";
