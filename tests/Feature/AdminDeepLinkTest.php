<?php

declare(strict_types=1);

use App\Models\AdminUser;

/**
 * Sixteen admin screens could be clicked but not linked to.
 *
 * Reproduced in Chromium, logged in as an owner, on one session:
 *
 *   click "Analytics" in the sidebar
 *     -> "Analytics How the shop is trading, worked out from your real orders…"
 *   open /admin?go=analytics
 *     -> "Analytics could not be loaded — the admin script did not finish
 *         starting up. Reload the page."
 *
 * Same user, same screen, two ways in, two answers. The second is a dead end:
 * the message asks for a reload and the address is the cause, so reloading
 * reproduces it exactly.
 *
 * WHY. app.blade.php reads `?go=` / `#` and navigates a few lines after
 * buildNav(), thousands of lines before the end of the document. The screens in
 * LIVE_RENDERED are not drawn by the go() that exists at that point — they are
 * drawn by a later wrapper around window.go, installed by the second script and
 * by one partial per screen, none of which has been parsed yet. So the
 * navigation reached an incomplete go(), mountFrame() painted the startup card
 * that exists for the case where the wiring never arrives, and that was the
 * final state. A click cannot hit this, because by the time anything is
 * clickable every wrapper is installed.
 *
 * THE FIX IS ONE REPLAY, NOT SIXTEEN BOOT HOOKS. Ten screens had already solved
 * this one at a time, each with its own bootIfCurrent() reading its own signal.
 * The console now remembers the navigation it could not serve and repeats it
 * once, after the document is parsed — but only if nothing drew the screen in
 * the meantime, which is what stops the ten that boot themselves from rendering
 * twice.
 *
 * WHAT THIS FILE CAN SEE. The structural half reads the rendered console and
 * pins the shape that makes the defect impossible: the immediate navigation is
 * still there, a second one is queued behind DOMContentLoaded, it is armed only
 * for the ids that get the startup card, and it is conditional on nothing
 * having drawn. The browser half drives a real Chromium through all three
 * entrances to every one of those ids and compares them, which is the only
 * place the symptom itself is visible.
 */

/* ---------------------------------------------------- reading the source --- */

/**
 * The deep-link boot block, as it is rendered into the console document.
 *
 * Sliced by its own BEGIN/END markers rather than by searching for a line of
 * code: the whole point of the assertions below is that a particular line is
 * PRESENT, and a search that found it anywhere in a 900KB document would pass
 * against a file where it had moved into another screen entirely.
 */
function dlBootRegion(): string
{
    $html = view('admin.app')->render();

    $at = strpos($html, 'LANE DA · deep links · BEGIN');
    expect($at)->not->toBeFalse('the deep-link boot region was renamed or removed');

    /*
     * Backed up to the `/*` that opens the region's own banner comment, not to
     * the marker text inside it. Slicing at the marker would leave the banner
     * with no opening delimiter, and dlBootCode()'s comment stripper would then
     * leave the whole of that prose in place and read it as code — which is the
     * exact hazard the stripper exists for.
     */
    $start = strrpos(substr($html, 0, (int) $at), '/*');
    expect($start)->not->toBeFalse('the deep-link region no longer opens with a comment');

    $end = strpos($html, 'LANE DA · deep links · END', (int) $at);
    expect($end)->not->toBeFalse('the deep-link boot region has no end marker');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/**
 * The same region with its prose removed.
 *
 * EVERY ASSERTION BELOW RUNS ON THIS AND NOT ON THE RAW REGION, because that
 * region is mostly comment. It explains what it does using the names of the
 * things it does it with — LIVE_RENDERED, window.go, DOMContentLoaded — so a
 * `toContain` against the raw text would go green against a block whose code
 * had been deleted and whose comment had been left behind. Four lanes in this
 * repo have already been caught by a check that read prose as code; this is the
 * same hazard pointed the other way.
 */
function dlBootCode(): string
{
    $src = dlBootRegion();

    // Block comments first, so a // inside one cannot confuse the second pass.
    $src = (string) preg_replace('#/\*.*?\*/#s', ' ', $src);

    // Then line comments, only where // opens one: never inside a URL, which
    // is always preceded by a colon.
    $src = (string) preg_replace('#(^|[\s;{(])//[^\n]*#m', '$1', $src);

    return $src;
}

/* -------------------------------------------------- the structural half ---- */

it('still navigates the moment it reads the address, so a click and the first paint are unchanged', function () {
    $code = dlBootCode();

    /*
     * The immediate navigation is load-bearing and must not be deferred. Ten
     * screens boot themselves off the state it leaves behind — 'rev-all' reads
     * `cur`, Review Settings reads which sidebar row is marked, Rating Badge
     * reads the address — and all ten run at parse time or on DOMContentLoaded,
     * which is after any point this block could defer to. Deferring would not
     * stop them; it would make them fire against a console that had not
     * navigated, and then the replay would draw each of those screens twice.
     */
    expect(str_contains($code, "new URLSearchParams(window.location.search).get('go')"))
        ->toBeTrue('the boot no longer reads ?go=')
        ->and(str_contains($code, 'window.location.hash'))
        ->toBeTrue('the boot no longer reads the #fragment')
        ->and(str_contains($code, 'TITLES[asked] ? asked :'))
        ->toBeTrue('the boot no longer rejects an id the console does not know, so a typo would route somewhere')
        ->and(preg_match('/\n\s*go\(target\);/', $code))
        ->toBe(1, 'the immediate navigation is gone or has been deferred — the ten screens that boot off the state it leaves would fire against a console that never navigated');
});

it('queues one more navigation for after the document is parsed, which is the fix', function () {
    $code = dlBootCode();

    /*
     * The second pass goes through window.go, NOT through the local go(): the
     * whole defect is that the local one is the incomplete chain. By the time
     * this runs, window.go is every wrapper the document installs.
     */
    expect(str_contains($code, "typeof window.go==='function'") && str_contains($code, 'window.go(target)'))
        ->toBeTrue('nothing replays the navigation through the completed window.go, so the sixteen screens are unreachable by link again');

    /*
     * And it is queued behind DOMContentLoaded as a TASK, not run inside the
     * handler. The ordering is the mechanism: this block registers before any
     * partial is parsed, so its handler runs first of all of them — ahead of
     * the ten bootIfCurrent()s whose result it exists to read. A task queued
     * from inside it runs after the lot. Called directly in the handler it
     * would read the DOM before those ten had a turn and redraw every one of
     * them.
     */
    expect(str_contains($code, "document.addEventListener('DOMContentLoaded'"))
        ->toBeTrue('the replay is no longer deferred to DOMContentLoaded, so it runs before the partials that draw these screens exist')
        ->and(preg_match('/setTimeout\(\s*replay\s*,\s*0\s*\)/', $code))
        ->toBe(1, 'the replay is run inside the DOMContentLoaded handler rather than queued as a task from it, so it reads the DOM before the ten screens that boot themselves have had their turn — and redraws them');
});

it('replays only when nothing drew the screen, which is what stops a second render', function () {
    $code = dlBootCode();

    /*
     * THE NO-DOUBLE-RENDER GUARANTEE, and it is structural rather than
     * remembered. A marker element is dropped inside #content after the first
     * navigation. Every renderer in this console paints by assigning
     * #content.innerHTML, which destroys the marker, so "the marker is still
     * there" means "nothing has drawn" with no cooperation from the screens.
     *
     * INSIDE #content and not ON it. A data- attribute on #content itself
     * survives innerHTML, so it would report every screen as undrawn for ever
     * and the replay would fire every time — including on the nine screens that
     * already boot themselves, which is precisely the double render this
     * console has shipped before with a control that got two handlers.
     */
    expect(str_contains($code, "insertAdjacentHTML('beforeend'") && str_contains($code, 'data-kbb-deeplink'))
        ->toBeTrue('the boot no longer marks the placeholder it painted, so the replay has no way to tell a drawn screen from an undrawn one');

    expect(preg_match("/querySelector\('#content \[data-kbb-deeplink\]'\)/", $code))
        ->toBe(1, 'the replay no longer looks for the marker as a DESCENDANT of #content — an attribute on #content itself survives innerHTML, so every screen would replay and the nine that boot themselves would render twice');

    expect(preg_match("/if\(!document\.querySelector\('#content \[data-kbb-deeplink\]'\)\) return;/", $code))
        ->toBe(1, 'the replay is no longer conditional on the marker, so it fires even when a screen has already drawn itself');

    // And it is a one-shot, so no later event can drive it a second time.
    expect(str_contains($code, 'if(done) return;') && str_contains($code, 'done=true;'))
        ->toBeTrue('the replay is no longer a one-shot');
});

it('arms the replay from LIVE_RENDERED rather than from a list of ids written beside it', function () {
    $code = dlBootCode();

    /*
     * The arming test has to name the case where the startup card was painted
     * instead of a screen, and LIVE_RENDERED is exactly that case: go() sends
     * every one of those ids through renderFrame or renderReviewFrame into
     * mountFrame, which paints the card for them and only for them.
     *
     * It must be ASKED of that set, not copied out of it. A hand-written list
     * here would be correct until the next lane added a screen to LIVE_RENDERED
     * and updated one copy, and the failure would be silent — a screen that
     * cannot be linked to, which is the defect this file is about.
     */
    expect(preg_match('/if\(!LIVE_RENDERED\.has\(target\)\) return;/', $code))
        ->toBe(1, 'the replay is no longer armed from LIVE_RENDERED, so it either misses screens that get the startup card or fires for screens that do not');

    $ids = ['orders', 'payments', 'analytics', 'seo', 'blog', 'posts', 'store-settings', 'quiz-leads',
        'rev-settings', 'htmlblocks', 'rev-add', 'rev-likes', 'rev-io', 'rev-badge', 'rev-capsule', 'rev-assign'];

    foreach ($ids as $id) {
        expect(str_contains($code, "'".$id."'"))
            ->toBeFalse("the boot names '{$id}' itself instead of asking LIVE_RENDERED, so the two lists can drift apart");
    }
});

it('leaves the startup card and the not-built card exactly where they were', function () {
    $html = view('admin.app')->render();

    /*
     * The safety net is not deleted, only made truthful. It was written for a
     * partial that fails to parse and therefore never installs its wrapper —
     * the owner has to be told rather than shown an empty panel. After the
     * replay that is the ONLY case it describes: the second go() finds the
     * chain still has no renderer for the id and falls back to the same static
     * card.
     */
    expect(str_contains($html, 'function frameStartupHTML(title)'))
        ->toBeTrue('the startup card is gone, so a partial that fails to parse leaves the owner an empty screen')
        ->and(str_contains($html, 'if(LIVE_RENDERED.has(id)){box.innerHTML=frameStartupHTML(title);return;}'))
        ->toBeTrue('mountFrame no longer paints the startup card for a LIVE_RENDERED id');

    /*
     * And the separate, honest answer for a screen that was drawn up and never
     * built keeps its own path through the probe. Turning that into a spinner
     * or a blank panel would be a worse bug than the one being fixed.
     */
    expect(str_contains($html, 'function frameNotBuiltHTML(title,id)'))
        ->toBeTrue('the not-built card is gone')
        ->and(str_contains($html, "isn't installed yet"))
        ->toBeTrue('the not-built card no longer says the screen is not installed')
        ->and(str_contains($html, ': frameNotBuiltHTML(title,id);'))
        ->toBeTrue('mountFrame no longer answers a failed probe with the not-built card, so a screen that really is missing shows something else');
});

it('serves the console to an owner on the bare admin path, which is where every one of these links points', function () {
    $admin = AdminUser::create([
        'name' => 'Deep Link Owner',
        'email' => 'deep-link-owner@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');

    /*
     * ?go= and # are read in the browser, so the server sees the same request
     * for all sixteen and there is exactly one thing to assert here: that the
     * document carrying the boot block is what it answers with.
     */
    $this->get('/admin')->assertOk()->assertSee('LANE DA · deep links · BEGIN', false);
});

/* ----------------------------------------------------- the browser half ---- */

/** Where node, playwright and Chromium have to be for the browser half to mean anything. */
function dlPrereqs(): array
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

    if (! is_file(base_path('tests/browser/admin-deep-links.mjs'))) {
        $missing[] = 'tests/browser/admin-deep-links.mjs is missing';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/**
 * A preview of THIS checkout on its own database, with one owner on it.
 *
 * Its own database because the suite's connection is inside a transaction an
 * external process cannot see, and its own directory because two other tests
 * boot previews and all three may run in one session.
 *
 * @return array{base:string, email:string, password:string, stop:callable}
 */
function bootDeepLinkPreview(): array
{
    $dir = storage_path('framework/testing/lane-da-deeplinks');
    $root = $dir.'/webroot';
    $db = $dir.'/preview.sqlite';

    @mkdir($root, 0o777, true);
    @unlink($dir.'/kbb-upgrade-app');
    @symlink(base_path(), $dir.'/kbb-upgrade-app');

    copy(base_path('public-web-root/index.php'), $root.'/index.php');

    /*
     * COPIED, never symlinked: this directory is rm -rf'd on the way out and a
     * symlink would put the repo's tracked build assets in reach of that. The
     * console is one self-contained document with no @vite directive, so it
     * renders identically whether the copy happened or not.
     */
    if (! is_dir($root.'/build') && is_dir(base_path('public/build'))) {
        exec('cp -r '.escapeshellarg(base_path('public/build')).' '.escapeshellarg($root.'/build'));
    }

    @unlink($db);
    touch($db);

    $env = [
        'KBB_PUBLIC_PATH' => $root,
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $db,
        // The suite's .env has both as `array`, and an array session does not
        // survive the redirect after a login POST.
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'file',
        'APP_KEY' => (string) config('app.key'),
        'PHP_CLI_SERVER_WORKERS' => '4',
    ];

    $envPrefix = '';

    foreach ($env as $k => $v) {
        $envPrefix .= $k.'='.escapeshellarg($v).' ';
    }

    exec($envPrefix.'php '.escapeshellarg(base_path('artisan')).' migrate --force 2>&1', $out, $code);

    if ($code !== 0) {
        throw new RuntimeException("preview migrate failed:\n".implode("\n", array_slice($out, -20)));
    }

    config()->set('database.connections.lane_da_preview', [
        'driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);

    $email = 'deep-link-walker@example.test';
    $password = 'lane-da-password';

    AdminUser::on('lane_da_preview')->create([
        'name' => 'Deep Link Walker', 'email' => $email, 'password' => $password, 'role' => 'owner',
    ]);

    \Illuminate\Support\Facades\DB::purge('lane_da_preview');

    $port = 8740 + random_int(30, 120);
    $command = $envPrefix.'php -S 127.0.0.1:'.$port.' -t '.escapeshellarg($root).' '.escapeshellarg($root.'/index.php');

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $dir.'/serve.log', 'w'], 2 => ['file', $dir.'/serve.log', 'a']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the preview server');
    }

    $base = 'http://127.0.0.1:'.$port;
    $up = false;

    for ($i = 0; $i < 60; $i++) {
        usleep(300_000);
        $ch = curl_init($base.'/admin/login');
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
            // Only this server's own children, by pid. Other lanes run servers
            // on this box and a broad pkill has already killed one of them.
            exec('pkill -P '.(int) $status['pid'].' 2>/dev/null');
            proc_terminate($process);
        }

        proc_close($process);
        exec('rm -rf '.escapeshellarg($dir));
    };

    if (! $up) {
        $log = @file_get_contents($dir.'/serve.log') ?: '';
        $stop();

        throw new RuntimeException("preview server never answered on {$base}\n".substr($log, -800));
    }

    return ['base' => $base, 'email' => $email, 'password' => $password, 'stop' => $stop];
}

/** @return array<string,mixed> */
function driveDeepLinks(array $preview, string $chrome, array $ids): array
{
    $env = [
        'KBB_DL_BASE' => $preview['base'],
        'KBB_DL_EMAIL' => $preview['email'],
        'KBB_DL_PASSWORD' => $preview['password'],
        'KBB_DL_CHROME' => $chrome,
        'KBB_DL_IDS' => implode(',', $ids),
        'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
    ];

    $prefix = '';

    foreach ($env as $k => $v) {
        $prefix .= $k.'='.escapeshellarg((string) $v).' ';
    }

    $command = $prefix.'node '.escapeshellarg(base_path('tests/browser/admin-deep-links.mjs')).' 2>/dev/null';

    // One retry, for the same reason admin-overflow.mjs has one: driving a
    // browser against a local single-host server is not perfectly
    // deterministic, and this must fail for the reason it is about.
    $decoded = null;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $decoded = json_decode((string) shell_exec($command), true);

        if (is_array($decoded) && ($decoded['ok'] ?? false)) {
            return $decoded;
        }
    }

    if (! is_array($decoded)) {
        throw new RuntimeException('deep-link walker returned no JSON');
    }

    return $decoded;
}

/** The sixteen, read out of the console rather than typed here. */
function dlLiveRenderedIds(): array
{
    $html = view('admin.app')->render();

    expect(preg_match('/const LIVE_RENDERED=new Set\(\[(.*?)\]\);/s', $html, $m))
        ->toBe(1, 'LIVE_RENDERED is no longer a literal Set, so this test cannot tell which screens it covers');

    preg_match_all("/'([^']+)'/", $m[1], $ids);

    return $ids[1];
}

it('opens every screen by its own address, in a real browser', function () {
    ['chrome' => $chrome, 'missing' => $missing] = dlPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped(
            'Needs real Chromium: '.implode('; ', $missing)
            .'. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $live = dlLiveRenderedIds();

    expect($live)->not->toBeEmpty();

    // A few that are NOT in the set, as controls: 'dash' is what an unknown
    // address falls back to, and the other three are drawn by the go() that
    // exists at boot, so they were never part of this defect and must stay out
    // of it.
    $controls = ['dash', 'modules', 'updates', 'customers'];

    $preview = bootDeepLinkPreview();

    try {
        $r = driveDeepLinks($preview, $chrome, array_merge($live, $controls));

        expect($r['ok'])->toBeTrue((string) ($r['error'] ?? ''));
        expect($r['pageErrors'] ?? [])->toBe([], 'the console threw while being driven');

        foreach ($r['rows'] as $row) {
            $id = $row['id'];

            /* ---- THE ASSERTION THAT MATTERS ---- */
            expect(str_contains($row['query'], 'could not be loaded'))
                ->toBeFalse("?go={$id} still lands on the startup card: {$row['query']}");

            expect(str_contains($row['hash'], 'could not be loaded'))
                ->toBeFalse("#{$id} still lands on the startup card: {$row['hash']}");

            /* ---- and the two addresses agree with the click ---- */
            expect($row['hash'])->toBe($row['query'], "?go={$id} and #{$id} open different screens");

            if ($row['hasRow']) {
                expect($row['query'])->toBe(
                    $row['click'],
                    "?go={$id} opens something other than what clicking the sidebar row opens"
                );
            }

            /* ---- NOTHING RENDERS TWICE ----
             * A screen mounted twice asks the server for the same thing twice.
             * That is the measurable face of the bug this console has already
             * shipped once, as a control that got two handlers and went dead.
             */
            expect($row['queryRepeatFetches'])->toBe([], "?go={$id} asked the server for the same thing twice, so the screen mounted twice");
            expect($row['hashRepeatFetches'])->toBe([], "#{$id} asked the server for the same thing twice, so the screen mounted twice");
            expect($row['clickRepeatFetches'])->toBe([], "clicking {$id} asked the server for the same thing twice");

            /*
             * And the screen is painted the same number of times either way.
             * A deep link paints ONE more time than a click, always: the
             * startup card the boot navigation puts up before the replay, which
             * is a static string with no request and no handler behind it. Two
             * more would mean the screen itself was drawn twice.
             */
            if ($row['hasRow'] && in_array($id, $live, true)) {
                expect($row['queryWrites'])->toBe(
                    $row['clickWrites'] + 1,
                    "?go={$id} painted #content {$row['queryWrites']} times against {$row['clickWrites']} for a click: "
                    .json_encode($row['queryWriteHeads'])
                );
            }
        }
    } finally {
        ($preview['stop'])();
    }
});
