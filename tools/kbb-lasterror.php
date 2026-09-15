<?php
/**
 * KBB Last Error — read-only, prints the head of the most recent log entries.
 *
 * A stack trace without its first lines says where the call came from but not
 * what went wrong. This prints the part that matters: timestamp, exception
 * class, message, and the first frames inside the application.
 *
 * It reads one file. It never writes, never deletes, never touches the
 * database and does not boot Laravel.
 *
 * SET YOUR OWN TOKEN below, then visit:
 *   /kbb-upgrade/kbb-lasterror.php?token=YOUR-TOKEN
 *   &n=3      how many entries (default 3, max 10)
 *
 * DELETE THIS FILE once you have pasted the output.
 */

const ERROR_TOKEN = 'REPLACE-THIS-WITH-A-LONG-RANDOM-STRING';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

usleep(250000);
if (hash('sha256', ERROR_TOKEN) === '7f50eeaa93255714df254fcad0e19ce62ce7663dfb7f6fca24ec51821e4437a3'
    || strlen(ERROR_TOKEN) < 20) {
    http_response_code(500);
    exit("Set ERROR_TOKEN at the top of this file to a long random string first.\n");
}
if (! hash_equals(ERROR_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit('Not found.');
}

/* ------------------------------------------------------------- locate app */
$app = null;
foreach ([__DIR__.'/..', __DIR__.'/../../kbb-upgrade-app', __DIR__.'/../kbb-upgrade-app', __DIR__.'/../../../kbb-upgrade-app', __DIR__] as $c) {
    if (is_dir($c.'/bootstrap') && is_dir($c.'/storage')) { $app = realpath($c); break; }
}
if (! $app) { http_response_code(500); exit("Could not locate the application folder.\n"); }

$log = $app.'/storage/logs/laravel.log';
if (! is_file($log)) { exit("No laravel.log at {$log}\n"); }

$want = max(1, min(10, (int) ($_GET['n'] ?? 3)));

/* Read the tail only — the log can be large. */
$size = filesize($log);
$read = min($size, 512 * 1024);
$fh = fopen($log, 'rb');
fseek($fh, -$read, SEEK_END);
$tail = (string) fread($fh, $read);
fclose($fh);

echo "KBB LAST ERROR\n";
echo "log      : {$log}\n";
echo "size     : ".number_format($size)." bytes\n";
echo "php      : ".PHP_VERSION."\n";
echo "read     : last ".number_format($read)." bytes\n\n";

/* Entries start with [YYYY-MM-DD HH:MM:SS] at the start of a line. */
$parts = preg_split('/^(?=\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\])/m', $tail, -1, PREG_SPLIT_NO_EMPTY);
$parts = array_slice($parts, -$want);

if (! $parts) { exit("No log entries found in the tail.\n"); }

foreach ($parts as $i => $entry) {
    echo str_repeat('=', 70)."\n";
    echo 'ENTRY '.($i + 1)." of ".count($parts)."\n";
    echo str_repeat('=', 70)."\n";

    $lines = preg_split('/\R/', $entry) ?: [];

    // The head: everything before the stack trace, which is what was missing.
    foreach (array_slice($lines, 0, 6) as $line) {
        echo mb_substr($line, 0, 1000)."\n";
    }

    // Then only the frames inside the application — vendor frames are noise.
    $shown = 0;
    foreach ($lines as $line) {
        if ($shown >= 8) { break; }
        if (preg_match('/^#\d+ .*\/(app|routes|database|bootstrap)\//', trim($line)) === 1) {
            echo trim($line)."\n";
            $shown++;
        }
    }

    echo "\n";
}

echo "-- end of report --\n";
