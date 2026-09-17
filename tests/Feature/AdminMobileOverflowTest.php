<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompiledCaches;

/**
 * No admin screen may scroll sideways on a phone.
 *
 * FOUR SCREENS DID, and none of them showed up in the usual check.
 * `document.documentElement.scrollWidth` reads exactly 390 on every one of
 * them, because the admin is a two-pane layout whose `#content` column is
 * itself `overflow-x: auto` — the page does not scroll, the column does. The
 * numbers measured at the tip, in Chromium at a 390px viewport:
 *
 *   Demo Content       #content.scrollWidth 490   div.card.pad.dccard
 *   Shop Filters                            483   div.card.pad.sfprev
 *   Quantity bundles                        429   a bare <table>
 *   Section dividers                        416   div.mmcols
 *
 * WHY THIS WALKS EVERY SCREEN rather than asserting four numbers. Four
 * assertions pin four screens and say nothing about the fifth. The causes were
 * not even the same as each other — a missing breakpoint, a grid declared in an
 * inline style attribute that no media query could reach, an unwrapped wide
 * table, and a <select> whose widest option set a 291px floor — so there is no
 * single structural rule to assert instead. The invariant that covers all of
 * them, and the next one, is the measurement itself.
 *
 * WHY IT SKIPS. Pest has no layout engine, so this needs real Chromium and a
 * server. CI installs PHP only (see .github/workflows/ci.yml), so it skips
 * there and the structural guards elsewhere carry CI. Set KBB_BROWSER_TESTS=1
 * with node, playwright and a Chromium binary present to run it — which is how
 * the numbers above and their green counterparts were produced.
 *
 * The preview server is built the way bootstrap/app.php already provides for:
 * KBB_PUBLIC_PATH points usePublicPath() at a throwaway web root, so the line
 * CLAUDE.md says to leave alone is left alone.
 */

/** Where node, playwright and Chromium have to be for this to mean anything. */
function overflowPrereqs(): array
{
    $chrome = env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');

    $missing = [];

    if (! env('KBB_BROWSER_TESTS')) {
        $missing[] = 'KBB_BROWSER_TESTS is not set';
    }

    if (! is_file($chrome)) {
        $missing[] = "no Chromium at {$chrome}";
    }

    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        $missing[] = 'node is not on PATH';
    }

    if (! is_file(base_path('tests/browser/admin-overflow.mjs'))) {
        $missing[] = 'tests/browser/admin-overflow.mjs is missing';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/**
 * A preview of THIS checkout, on its own database, with one admin in it.
 *
 * Its own database because the suite's connection is inside a transaction that
 * an external process cannot see, so a server pointed at it would find no
 * admin user and no settings at all.
 *
 * @return array{base:string, email:string, password:string, stop:callable}
 */
function bootOverflowPreview(): array
{
    $dir = storage_path('framework/testing/lane-aw-overflow');
    $root = $dir . '/webroot';
    $db = $dir . '/preview.sqlite';

    // The front controller walks up from itself looking for the application;
    // `<webroot>/../kbb-upgrade-app` is one of the places it looks.
    @mkdir($root, 0o777, true);
    @unlink($dir . '/kbb-upgrade-app');
    @symlink(base_path(), $dir . '/kbb-upgrade-app');

    copy(base_path('public-web-root/index.php'), $root . '/index.php');

    // COPIED, never symlinked: this directory is rm -rf'd on the way out and a
    // symlink here would put the repo's tracked build assets in reach of that.
    if (! is_dir($root . '/build')) {
        exec('cp -r ' . escapeshellarg(base_path('public/build')) . ' ' . escapeshellarg($root . '/build'));
    }

    @unlink($db);
    touch($db);

    $env = [
        'KBB_PUBLIC_PATH' => $root,
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $db,
        // The suite's .env is the TEST env, where both are `array`. An array
        // session does not survive the redirect after a login POST, so every
        // form answers 419 and nothing behind the admin guard is reachable.
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'file',
        'APP_KEY' => (string) config('app.key'),
        // A single-process `php -S` serves one request at a time, and each
        // admin screen fetches its own data while the document is still being
        // delivered — so the walk intermittently stalled and the run came back
        // ok:false. Workers, plus the one retry in walkAdminScreens(), because
        // a guard that fails at random teaches people to re-run it.
        'PHP_CLI_SERVER_WORKERS' => '4',
    ];

    /*
     * A compiled-cache directory of this preview's own. A shell env prefix ADDS
     * to the inherited environment, so without this the `migrate --force` below
     * follows the suite's APP_CONFIG_CACHE: it boots from the suite's compiled
     * config -- the wrong database -- and its own warm_caches_2_60_4 then
     * overwrites that file with this preview's settings, which the suite reads
     * at its next boot. Tests\Support\CompiledCaches::environmentFor() carries
     * the reasoning and the measurement.
     */
    $env += CompiledCaches::environmentFor($dir . '/compiled');

    $envPrefix = '';

    foreach ($env as $k => $v) {
        $envPrefix .= $k . '=' . escapeshellarg($v) . ' ';
    }

    exec($envPrefix . 'php ' . escapeshellarg(base_path('artisan')) . ' migrate --force 2>&1', $out, $code);

    if ($code !== 0) {
        throw new RuntimeException("preview migrate failed:\n" . implode("\n", array_slice($out, -20)));
    }

    // The admin user goes in over a second connection rather than through
    // another artisan call, so the password hash is made the same way the app
    // makes it.
    config()->set('database.connections.lane_aw_preview', [
        'driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);

    $email = 'overflow-walker@example.test';
    $password = 'lane-aw-password';

    AdminUser::on('lane_aw_preview')->create([
        'name' => 'Overflow Walker', 'email' => $email, 'password' => $password, 'role' => 'owner',
    ]);

    DB::purge('lane_aw_preview');

    $port = 8400 + random_int(30, 120);
    $command = $envPrefix . 'php -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($root) . ' ' . escapeshellarg($root . '/index.php');

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $dir . '/serve.log', 'w'], 2 => ['file', $dir . '/serve.log', 'a']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the preview server');
    }

    $base = 'http://127.0.0.1:' . $port;

    $up = false;

    for ($i = 0; $i < 60; $i++) {
        usleep(300_000);
        $ch = curl_init($base . '/admin/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            $up = true;
            break;
        }
    }

    $stop = function () use ($process, $pipes, $dir) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);

        if ($status['running'] ?? false) {
            // proc_terminate reaches the shell, not always the child it spawned.
            exec('pkill -P ' . (int) $status['pid'] . ' 2>/dev/null');
            proc_terminate($process);
        }

        proc_close($process);
        exec('rm -rf ' . escapeshellarg($dir));
    };

    if (! $up) {
        $log = @file_get_contents($dir . '/serve.log') ?: '';
        $stop();

        throw new RuntimeException("preview server never answered on {$base}\n" . substr($log, -800));
    }

    return ['base' => $base, 'email' => $email, 'password' => $password, 'stop' => $stop];
}

/** @return array<string,mixed> */
function walkAdminScreens(array $preview, string $chrome, int $width, bool $probe = false, int $probeWidth = 1672): array
{
    $env = [
        'KBB_BROWSER_BASE' => $preview['base'],
        'KBB_BROWSER_EMAIL' => $preview['email'],
        'KBB_BROWSER_PASSWORD' => $preview['password'],
        'KBB_BROWSER_CHROME' => $chrome,
        'KBB_BROWSER_WIDTH' => (string) $width,
        'KBB_BROWSER_PROBE' => $probe ? '1' : '0',
        'KBB_BROWSER_PROBE_W' => (string) $probeWidth,
        // playwright is installed globally in this environment.
        'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
    ];

    $prefix = '';

    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }

    $command = $prefix . 'node ' . escapeshellarg(base_path('tests/browser/admin-overflow.mjs')) . ' 2>/dev/null';

    // One retry. Driving a browser against a local server is not perfectly
    // deterministic, and this must fail for the reason it is about — a screen
    // that is too wide — rather than for a stalled request.
    $decoded = null;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $decoded = json_decode((string) shell_exec($command), true);

        if (is_array($decoded) && ($decoded['ok'] ?? false)) {
            return $decoded;
        }
    }

    if (! is_array($decoded)) {
        throw new RuntimeException('walker returned no JSON');
    }

    return $decoded;
}

it('never lets an admin screen overflow its content column on a phone', function () {
    ['chrome' => $chrome, 'missing' => $missing] = overflowPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped(
            'Needs real Chromium: ' . implode('; ', $missing)
            . '. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $preview = bootOverflowPreview();

    try {
        // The harness has to be shown capable of failing before a clean result
        // from it means anything. A block wider than the content column must
        // make EVERY screen report an overflow — 1672px is the column's own
        // width at a 1920 viewport, so nothing narrower would prove it.
        $probe = walkAdminScreens($preview, $chrome, 1920, true, 1672);

        expect($probe['ok'])->toBeTrue($probe['error'] ?? '');

        $measured = array_values(array_filter($probe['rows'], fn ($r) => isset($r['scrollWidth'])));
        $caught = array_filter($measured, fn ($r) => $r['scrollWidth'] > $r['clientWidth']);

        expect($measured)->not->toBeEmpty()
            ->and(count($caught))->toBe(count($measured));

        // Now the real measurement.
        $walk = walkAdminScreens($preview, $chrome, 390);

        expect($walk['ok'])->toBeTrue($walk['error'] ?? '');

        $rows = array_values(array_filter($walk['rows'], fn ($r) => isset($r['scrollWidth'])));

        expect($rows)->not->toBeEmpty();

        $over = [];

        foreach ($rows as $row) {
            if ($row['scrollWidth'] > $row['clientWidth']) {
                $over[] = "{$row['id']}: #content.scrollWidth {$row['scrollWidth']} > clientWidth {$row['clientWidth']} (widest: {$row['worst']})";
            }
        }

        expect($over)->toBe([], "screens scrolling sideways at 390px:\n" . implode("\n", $over));
    } finally {
        ($preview['stop'])();
    }
});
