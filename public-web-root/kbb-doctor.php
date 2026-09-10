<?php
/**
 * KBB Doctor — the last file you will ever upload by hand.
 *
 * Everything here is GENERIC. It reports what the application is doing rather
 * than checking for any particular bug, which is why it never needs replacing:
 * environment, recently changed files, database state, log tails, and the
 * errors Laravel recorded. Whatever breaks next, this shows it.
 *
 * It does not boot Laravel. That is the point — the moment you most need a
 * diagnostic is the moment the application will not start.
 *
 * URL:  /kbb-upgrade/kbb-doctor.php?token=REDACTED-ROTATE-AND-SET-YOUR-OWN-See-KBB-Master-Plan-risk-register
 *
 * Two safe actions are built in, both always useful and neither bug-specific:
 *   &do=caches      clear compiled config, routes and views
 *   &do=up          lift maintenance mode
 *
 * Delete this file before the site goes to a real domain.
 */

const DOCTOR_TOKEN = 'REDACTED-ROTATE-AND-SET-YOUR-OWN-See-KBB-Master-Plan-risk-register';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

usleep(250000);
if (! hash_equals(DOCTOR_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(404);
    exit('Not found.');
}

/* ------------------------------------------------------------- locate app */
$app = null;
foreach ([__DIR__.'/..', __DIR__.'/../../kbb-upgrade-app', __DIR__.'/../kbb-upgrade-app', __DIR__.'/../../../kbb-upgrade-app'] as $c) {
    if (is_dir($c.'/bootstrap') && is_dir($c.'/storage')) { $app = realpath($c); break; }
}
if (! $app) { http_response_code(500); exit('Could not locate the application folder.'); }

/* ---------------------------------------------------------------- actions */
$did = '';
if (($_GET['do'] ?? '') === 'caches') {
    $n = 0;
    foreach (['bootstrap/cache/*.php', 'storage/framework/views/*.php'] as $glob) {
        foreach (glob($app.'/'.$glob) ?: [] as $f) { if (@unlink($f)) { $n++; } }
    }
    $dir = $app.'/storage/framework/cache/data';
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $i) { $i->isDir() ? @rmdir($i->getPathname()) : (@unlink($i->getPathname()) && $n++); }
    }
    if (function_exists('opcache_reset')) { @opcache_reset(); }
    $did = "Cleared {$n} cached files (config, routes, views, application cache) and reset OPcache.";
}
if (($_GET['do'] ?? '') === 'up') {
    $did = @unlink($app.'/storage/framework/down') ? 'Maintenance mode lifted.' : 'The site was not in maintenance mode.';
}

/* -------------------------------------------------------------------- env */
$env = [];
if (is_file($app.'/.env')) {
    foreach (file($app.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if ($l === '' || $l[0] === '#' || ! str_contains($l, '=')) { continue; }
        [$k, $v] = explode('=', $l, 2);
        $env[trim($k)] = trim(trim($v), "\"'");
    }
}
$secret = fn (string $k) => preg_match('/(pass|secret|token|key)/i', $k) ? '••••••' : ($env[$k] ?? '—');

/* ---------------------------------------------------- recently changed files */
$recent = [];
foreach (['app', 'routes', 'config', 'resources/views', 'database'] as $sub) {
    $dir = $app.'/'.$sub;
    if (! is_dir($dir)) { continue; }
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
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE']),
            $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $db['ok'] = true;
        foreach ($pdo->query("SELECT TABLE_NAME n, TABLE_ROWS r FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $db['tables'][$row['n']] = (int) $row['r'];
        }
    } catch (Throwable $e) { $db['error'] = $e->getMessage(); }
}

/* ------------------------------------------------------------------- logs */
$logs = glob($app.'/storage/logs/*.log') ?: [];
usort($logs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
$pick = $_GET['log'] ?? (isset($logs[0]) ? basename($logs[0]) : '');

function tailFile(string $path, int $lines = 40): string {
    if (! is_file($path)) { return '(not found)'; }
    $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return htmlspecialchars(implode("\n", array_slice($all, -$lines))) ?: '(empty)';
}

/** Error lines with the file:line pulled out, newest first. */
function errorLines(string $path, int $count = 8): string {
    if (! is_file($path)) { return '(no log)'; }
    $out = [];
    foreach (array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $line) {
        if (! preg_match('/^\[[^\]]+\]\s+\w+\.(ERROR|CRITICAL|EMERGENCY):/', $line)) { continue; }
        $entry = mb_substr($line, 0, 900);
        // Laravel names the class that threw; the first APP path in the trace is
        // usually the file that actually caused it.
        if (preg_match_all('#(/[^\s:"]*/(?:app|routes|config|resources)/[^\s:"]+\.php):(\d+)#', $line, $m, PREG_SET_ORDER)) {
            $entry .= "\n    ---> " . $m[0][1] . ' line ' . $m[0][2];
        }
        $out[] = $entry;
        if (count($out) >= $count) { break; }
    }
    return $out ? htmlspecialchars(implode("\n\n", $out)) : '(no errors logged)';
}

$down = is_file($app.'/storage/framework/down');
$t = fn (?int $ts) => $ts ? date('Y-m-d H:i', $ts) : '—';
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>KBB Doctor</title><style>
body{background:#0f1720;color:#dbe4ee;font:14px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0}
.w{max-width:1000px;margin:0 auto;padding:26px 18px 70px}
h1{font-size:21px;margin:0 0 2px} h2{font-size:12px;text-transform:uppercase;letter-spacing:.09em;color:#8b9bad;margin:26px 0 8px}
.muted{color:#8b9bad;font-size:12px} a{color:#5fb3f5}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}
.box{background:#16212d;border:1px solid #24323f;border-radius:10px;padding:12px 14px;font-size:13px}
.box b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#8b9bad;margin-bottom:3px}
pre{background:#0b1219;border:1px solid #1c2836;border-radius:8px;padding:12px;overflow:auto;font-size:12px;color:#9fb3c8;white-space:pre-wrap;word-break:break-word}
table{width:100%;border-collapse:collapse;font-size:12.5px} td{padding:6px 9px;border-bottom:1px solid #1c2836}
.btn{display:inline-block;background:#2c6b3f;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:13px;margin-right:8px}
.btn.grey{background:#24323f}
.ok{color:#7fd6a0}.bad{color:#f0a0a0}
.note{background:#14321f;border:1px solid #2c6b3f;color:#a7e0bb;border-radius:8px;padding:10px 14px;margin:14px 0;font-size:13px}
</style></head><body><div class="w">

<h1>KBB Doctor</h1>
<div class="muted"><?= htmlspecialchars($app) ?></div>
<?php if ($did): ?><div class="note"><?= htmlspecialchars($did) ?></div><?php endif; ?>

<p style="margin-top:16px">
  <a class="btn" href="?token=<?= urlencode(DOCTOR_TOKEN) ?>&amp;do=caches">Clear all caches</a>
  <?php if ($down): ?><a class="btn" href="?token=<?= urlencode(DOCTOR_TOKEN) ?>&amp;do=up">Lift maintenance mode</a><?php endif; ?>
  <a class="btn grey" href="?token=<?= urlencode(DOCTOR_TOKEN) ?>">Refresh</a>
</p>

<h2>State</h2>
<div class="grid">
  <div class="box"><b>PHP</b><?= PHP_VERSION ?></div>
  <div class="box"><b>Maintenance</b><span class="<?= $down ? 'bad' : 'ok' ?>"><?= $down ? 'DOWN' : 'live' ?></span></div>
  <div class="box"><b>App key</b><?= str_starts_with($env['APP_KEY'] ?? '', 'base64:') ? '<span class="ok">set</span>' : '<span class="bad">missing</span>' ?></div>
  <div class="box"><b>Debug</b><?= htmlspecialchars($env['APP_DEBUG'] ?? '—') ?></div>
  <div class="box"><b>Base path</b><?= htmlspecialchars($env['KBB_BASE_PATH'] ?? '—') ?></div>
  <div class="box"><b>Database</b><?= $db['ok'] ? '<span class="ok">connected</span>' : '<span class="bad">failed</span>' ?></div>
  <div class="box"><b>Tables</b><?= count($db['tables']) ?></div>
  <div class="box"><b>Vendor</b><?= is_dir($app.'/vendor') ? '<span class="ok">installed</span>' : '<span class="bad">missing</span>' ?></div>
</div>
<?php if ($db['error']): ?><pre><?= htmlspecialchars($db['error']) ?></pre><?php endif; ?>

<h2>Errors — newest first</h2>
<pre><?= errorLines($app.'/storage/logs/laravel.log') ?></pre>

<h2>Recently changed files</h2>
<table><?php foreach ($recent as $rel => $ts): ?>
  <tr><td><?= htmlspecialchars($rel) ?></td><td class="muted" style="text-align:right;white-space:nowrap"><?= $t($ts) ?></td></tr>
<?php endforeach; ?></table>
<div class="muted">Newest first — this is how you tell whether an update actually landed.</div>

<h2>Logs</h2>
<p class="muted"><?php foreach ($logs as $l): $b = basename($l); ?>
  <a href="?token=<?= urlencode(DOCTOR_TOKEN) ?>&amp;log=<?= urlencode($b) ?>"><?= htmlspecialchars($b) ?></a>&nbsp;&nbsp;
<?php endforeach; ?></p>
<pre><?= $pick ? tailFile($app.'/storage/logs/'.basename($pick)) : '(no logs)' ?></pre>

<h2>This directory — the public web root itself</h2>
<div class="muted">This is <code>__DIR__</code>, where this file actually lives — not the app
directory above, which is a different folder entirely. Listed because a 405 or 500 that never
shows up in the app's own error log is often something sitting here: a leftover WordPress
index.php or .htaccess still present from before the migration, taking precedence over Laravel's
own front controller for some URLs but not others.</div>
<table><?php
foreach (scandir(__DIR__) as $entry) {
    if ($entry === '.' || $entry === '..') { continue; }
    $full = __DIR__ . '/' . $entry;
    $isDir = is_dir($full);
    echo '<tr><td>' . ($isDir ? '📁 ' : '') . htmlspecialchars($entry) . ($isDir ? '/' : '') . '</td>'
        . '<td class="muted">' . ($isDir ? '—' : number_format(filesize($full)) . ' b') . '</td>'
        . '<td class="muted" style="text-align:right">' . date('Y-m-d H:i', filemtime($full)) . '</td></tr>';
}
?></table>

<h2>Backups</h2>
<table><?php
$dirs = array_filter(glob($app.'/storage/app/updates/backups/*') ?: [], 'is_dir');
rsort($dirs);
if (! $dirs) { echo '<tr><td class="muted">None yet.</td></tr>'; }
foreach (array_slice($dirs, 0, 10) as $d):
  $mf = json_decode((string) @file_get_contents($d.'/manifest.json'), true) ?: []; ?>
  <tr><td><code><?= htmlspecialchars(basename($d)) ?></code></td>
      <td class="muted"><?= count($mf['replaced'] ?? []) ?> files</td>
      <td class="muted" style="text-align:right"><?= htmlspecialchars($mf['created_at'] ?? '') ?></td></tr>
<?php endforeach; ?></table>
<div class="muted">Restore from Core Updates in the admin, or with kbb-recover.php if the app will not start.</div>

<p class="muted" style="margin-top:26px">Generic by design — it reports state rather than testing for any particular fault, so it never needs replacing. Delete it before this goes to a real domain.</p>
</div></body></html>
