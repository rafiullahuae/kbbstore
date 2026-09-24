<?php

/**
 * KBB Doctor — a diagnostic that runs when the application will not.
 *
 * It reports what the application is doing rather than checking for any
 * particular bug: environment, recently changed files, database state, log
 * tails, the errors Laravel recorded, the public web root, and the update
 * backups. It does not boot Laravel, which is the point — the moment you most
 * need a diagnostic is the moment the application will not start, and
 * `php artisan about` needs the very thing that is broken.
 *
 * ---------------------------------------------------------------------------
 * IT IS NOW COMMAND-LINE ONLY, AND THAT IS A FIX, NOT A RESTRICTION
 * ---------------------------------------------------------------------------
 *
 *     php kbb-doctor.php              # the full report
 *     php kbb-doctor.php --caches     # clear compiled config, routes and views
 *     php kbb-doctor.php --up         # lift maintenance mode
 *     php kbb-doctor.php --log=name   # tail a specific log file
 *
 * Requested over the web it answers 404 and nothing else. Two reasons, and the
 * first one is not theoretical.
 *
 *   1. THE WEB VERSION WAS OPEN. It compared the request's token against a
 *      constant that still held the rotate-me placeholder it had been committed
 *      with — and, unlike kbb-recover.php, which explicitly refused to run
 *      while its own token was still that placeholder, this file had no such
 *      guard. The placeholder WAS the live token. It was in this repository,
 *      and the docblock at the top of this very file published the whole URL
 *      with the token already in it. Anyone who had ever seen the file could
 *      open the live shop's doctor: log tails and stack traces, every table in
 *      the database enumerated, the web root listed, the caches cleared.
 *
 *      (Neither the placeholder nor a working URL is written out anywhere in
 *      this file any more. Reproducing it in a comment explaining why it was
 *      dangerous would hand it to exactly the reader it was taken away from,
 *      and EmergencyToolsAreArmedTest fails if either comes back.)
 *
 *      That is not a page that should exist on a shop that takes orders,
 *      whatever the token on it.
 *
 *   2. THERE IS NOTHING LEFT FOR IT TO DO OVER HTTP. The live host is Cloudways
 *      and it has a shell (Servers → Launch SSH Terminal). Everything below is
 *      one SSH session away, and getting into that session means holding the
 *      server's own credentials rather than a string in a query string — a
 *      strictly higher bar than any token this file could carry.
 *
 * The recommendation that goes with this file: DELETE IT from the live web root
 * once you have read this. `rm public_html/kbb-doctor.php`. It is kept in the
 * repository because the report it prints is genuinely useful in one command
 * when Laravel will not boot, and because a CLI-only file on the server is
 * harmless — but a diagnostic is not something a shop needs sitting in its web
 * root at all.
 *
 * ▲ AND IT CANNOT BE FIXED BY SHIPPING A PACKAGE. BuildPackage excludes
 *   public-web-root/ and UpdateGuard permits no path outside app/, config/,
 *   database/, resources/, routes/ and public/build/. The copy on the live
 *   server is only replaced by uploading this file over SSH or SFTP, or
 *   removed by deleting it there. Until one of those happens the OLD file is
 *   still live and still open.
 */

if (PHP_SAPI !== 'cli') {
    // No token, no message, no hint that anything is here. See above.
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    exit("Not found.\n");
}

/* ------------------------------------------------------------- locate app */
$app = null;
foreach ([
    __DIR__.'/..',
    __DIR__.'/../private_html/kbb-app',
    __DIR__.'/../../private_html/kbb-app',
    __DIR__.'/../kbb-app',
    __DIR__.'/../../kbb-upgrade-app',
    __DIR__.'/../kbb-upgrade-app',
    __DIR__.'/../../../kbb-upgrade-app',
] as $c) {
    if (is_dir($c.'/bootstrap') && is_dir($c.'/storage')) {
        $app = realpath($c);
        break;
    }
}

if (! $app) {
    fwrite(STDERR, "Could not locate the application folder.\n");
    exit(1);
}

/* ------------------------------------------------------------------ flags */
$flags = [];
$pickedLog = '';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--log=')) {
        $pickedLog = substr($arg, 6);

        continue;
    }

    $flags[ltrim($arg, '-')] = true;
}

/* ---------------------------------------------------------------- actions */
$did = '';

if (isset($flags['caches'])) {
    $n = 0;

    foreach (['bootstrap/cache/*.php', 'storage/framework/views/*.php'] as $glob) {
        foreach (glob($app.'/'.$glob) ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
    }

    $dir = $app.'/storage/framework/cache/data';

    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $i) {
            $i->isDir() ? @rmdir($i->getPathname()) : (@unlink($i->getPathname()) && $n++);
        }
    }

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    $did = "Cleared {$n} cached files (config, routes, views, application cache) and reset OPcache.";
}

if (isset($flags['up'])) {
    $did = @unlink($app.'/storage/framework/down')
        ? 'Maintenance mode lifted.'
        : 'The site was not in maintenance mode.';
}

/* -------------------------------------------------------------------- env */
$env = [];

if (is_file($app.'/.env')) {
    foreach (file($app.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if ($l === '' || $l[0] === '#' || ! str_contains($l, '=')) {
            continue;
        }

        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}

/*
 * Masked even here. The shell can read .env directly, so this is not a
 * security boundary — it is so that a report pasted into a chat, an issue or a
 * bug report does not carry the database password with it.
 */
$show = static fn (string $k): string => preg_match('/(pass|secret|token|key)/i', $k)
    ? (isset($env[$k]) && $env[$k] !== '' ? '(set)' : '(empty)')
    : ($env[$k] ?? '—');

/* ------------------------------------------------ recently changed files */
$recent = [];

foreach (['app', 'routes', 'config', 'resources/views', 'database'] as $sub) {
    $dir = $app.'/'.$sub;

    if (! is_dir($dir)) {
        continue;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile() && in_array($f->getExtension(), ['php', 'js', 'css'], true)) {
            $recent[str_replace($app.'/', '', $f->getPathname())] = $f->getMTime();
        }
    }
}

arsort($recent);
$recent = array_slice($recent, 0, 25, true);

/* --------------------------------------------------------------- database */
$db = ['ok' => false, 'error' => '', 'tables' => []];

if (($env['DB_DATABASE'] ?? '') !== '') {
    try {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $env['DB_HOST'] ?? '127.0.0.1',
                $env['DB_PORT'] ?? '3306',
                $env['DB_DATABASE']
            ),
            $env['DB_USERNAME'] ?? '',
            $env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $db['ok'] = true;

        $rows = $pdo->query(
            'SELECT TABLE_NAME n, TABLE_ROWS r FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $db['tables'][$row['n']] = (int) $row['r'];
        }
    } catch (Throwable $e) {
        $db['error'] = $e->getMessage();
    }
}

/* ------------------------------------------------------------------- logs */
$logs = glob($app.'/storage/logs/*.log') ?: [];
usort($logs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$pick = $pickedLog !== '' ? $pickedLog : (isset($logs[0]) ? basename($logs[0]) : '');

function tailFile(string $path, int $lines = 40): string
{
    if (! is_file($path)) {
        return '(not found)';
    }

    $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return implode("\n", array_slice($all, -$lines)) ?: '(empty)';
}

/** Error lines with the file:line pulled out, newest first. */
function errorLines(string $path, int $count = 8): string
{
    if (! is_file($path)) {
        return '(no log)';
    }

    $out = [];

    foreach (array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        if (! preg_match('/^\[[^\]]+\]\s+\w+\.(ERROR|CRITICAL|EMERGENCY):/', $line)) {
            continue;
        }

        $entry = mb_substr($line, 0, 900);

        // Laravel names the class that threw; the first APP path in the trace
        // is usually the file that actually caused it.
        if (preg_match_all('#(/[^\s:"]*/(?:app|routes|config|resources)/[^\s:"]+\.php):(\d+)#', $line, $m, PREG_SET_ORDER)) {
            $entry .= "\n    ---> ".$m[0][1].' line '.$m[0][2];
        }

        $out[] = $entry;

        if (count($out) >= $count) {
            break;
        }
    }

    return $out ? implode("\n\n", $out) : '(no errors logged)';
}

/* ----------------------------------------------------------------- report */
$down = is_file($app.'/storage/framework/down');
$t = fn (?int $ts) => $ts ? date('Y-m-d H:i', $ts) : '—';
$rule = static fn (string $title) => "\n".str_repeat('─', 74)."\n".strtoupper($title)."\n".str_repeat('─', 74)."\n";

echo "KBB Doctor\n";
echo $app."\n";

if ($did !== '') {
    echo "\n▸ {$did}\n";
}

echo $rule('state');
printf("  %-16s %s\n", 'PHP', PHP_VERSION);
printf("  %-16s %s\n", 'Maintenance', $down ? 'DOWN' : 'live');
printf("  %-16s %s\n", 'App key', str_starts_with($env['APP_KEY'] ?? '', 'base64:') ? 'set' : 'MISSING');
printf("  %-16s %s\n", 'Debug', $show('APP_DEBUG'));
printf("  %-16s %s\n", 'App URL', $show('APP_URL'));
printf("  %-16s %s\n", 'Base path', $show('KBB_BASE_PATH'));
printf("  %-16s %s\n", 'Health token', $show('KBB_HEALTH_TOKEN'));
printf("  %-16s %s\n", 'Recover token', $show('KBB_RECOVER_TOKEN'));
printf("  %-16s %s\n", 'Database', $db['ok'] ? 'connected' : 'FAILED');
printf("  %-16s %d\n", 'Tables', count($db['tables']));
printf("  %-16s %s\n", 'Vendor', is_dir($app.'/vendor') ? 'installed' : 'MISSING');

if ($db['error'] !== '') {
    echo "\n  ".$db['error']."\n";
}

echo $rule('errors — newest first');
echo errorLines($app.'/storage/logs/laravel.log')."\n";

echo $rule('recently changed files');
foreach ($recent as $rel => $ts) {
    printf("  %-58s %s\n", mb_strimwidth($rel, 0, 58, '…'), $t($ts));
}
echo "\n  Newest first — this is how you tell whether an update actually landed.\n";

echo $rule('logs');
echo '  available: '.($logs ? implode('  ', array_map('basename', $logs)) : '(none)')."\n";
echo '  showing:   '.($pick !== '' ? $pick : '(none)')."  — use --log=<name> for another\n\n";
echo ($pick !== '' ? tailFile($app.'/storage/logs/'.basename($pick)) : '(no logs)')."\n";

echo $rule('the public web root itself');
echo "  This is ".__DIR__.", not the app directory above, which is a different\n";
echo "  folder entirely. Listed because a 405 or 500 that never shows up in the\n";
echo "  app's own error log is often something sitting here: a leftover WordPress\n";
echo "  index.php or .htaccess still taking precedence over Laravel's front\n";
echo "  controller for some URLs but not others.\n\n";

foreach (scandir(__DIR__) ?: [] as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }

    $full = __DIR__.'/'.$entry;
    printf(
        "  %-42s %12s  %s\n",
        $entry.(is_dir($full) ? '/' : ''),
        is_dir($full) ? '—' : number_format(filesize($full)).' b',
        date('Y-m-d H:i', filemtime($full))
    );
}

echo $rule('update backups');
$dirs = array_filter(glob($app.'/storage/app/updates/backups/*') ?: [], 'is_dir');
rsort($dirs);

if (! $dirs) {
    echo "  None yet.\n";
}

foreach (array_slice($dirs, 0, 10) as $d) {
    $mf = json_decode((string) @file_get_contents($d.'/manifest.json'), true) ?: [];
    printf(
        "  %-34s %5d files  %s\n",
        basename($d),
        count($mf['replaced'] ?? []),
        (string) ($mf['created_at'] ?? '')
    );
}

echo "\n  Restore from Core Updates in the admin, or `php kbb-recover.php` if the\n";
echo "  application will not start.\n\n";
