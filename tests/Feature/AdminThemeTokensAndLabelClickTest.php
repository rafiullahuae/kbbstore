<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompiledCaches;
use Tests\Support\PreviewPort;

/**
 * Two faults in the admin console, and the two guards that keep them fixed.
 *
 * ONE — THE THEME ONLY MOVED SOME OF THE SCREENS. The console ships five
 * themes, each redefining --accent and its relatives on :root[data-theme].
 * A colour typed into a rule cannot follow that, so the rules that carried a
 * literal stayed light while the rest went dark. The console's own furniture —
 * the icon buttons, the user chip, the ghost buttons, the modal, the sandbox
 * flag, the payment status dots — was in that group.
 *
 * WHAT THIS FILE DOES *NOT* ASSERT, DELIBERATELY: that no bare literal remains.
 * Most of them are correct and converting them is the regression:
 *
 *   - a fixed white FOREGROUND on a coloured fill (.logo, .btn, .sev.crit) —
 *     pointed at the surface token it turns near-black on green in the dark
 *     theme;
 *   - a switch THUMB (.tog::after) — white by design, sliding on a colour;
 *   - a STOREFRONT PREVIEW (.skinprev, .ppanel, .appanel) — a miniature of the
 *     customer-facing site, which has its own design and must not follow the
 *     admin's theme at all;
 *   - a screen on its OWN palette (order detail, menu manager) — moving only
 *     its whites leaves a dark panel behind light-grey borders.
 *
 * A blanket "no bare hex" regex would flag every one of those, and would also
 * flag a literal quoted inside a comment explaining the fix — a mistake already
 * made twice in this repo. So the guard below names the rules that were
 * converted and the rules that were deliberately left, and pins both.
 *
 * TWO — THE WORDS BESIDE A TICK BOX DID NOTHING. Every tick box here is a
 * <span class="cbx">, not a form control, so the <label> around it had nothing
 * to forward a click to: only the 18px box answered. LANE CJ added the keyboard
 * to those spans and reported this half as out of scope. It is fixed in the
 * same shared place, and the risk it carries is the double fire — a global
 * handler that calls .click() on a control with its own handler flips the state
 * and flips it back, leaving the control dead. The .ectog scar in the same file
 * is exactly that. The browser half below counts CLASS TRANSITIONS rather than
 * looking at the box, because the final state cannot tell one flip from two.
 */
$blade = fn (): string => (string) file_get_contents(resource_path('views/admin/app.blade.php'));

$crBlock = function () use ($blade): string {
    $source = $blade();
    $start = strpos($source, 'LANE CR · Admin · the words beside a tick box work it — BEGIN');
    $end = strpos($source, 'LANE CR · Admin · the words beside a tick box work it — END');

    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    return substr($source, $start, $end - $start);
};

/* ===================== 1. the theme ======================================= */

it('points the console chrome at a token instead of a typed-in colour', function () use ($blade) {
    $css = $blade();

    $converted = [
        '.iconbtn{width:38px;height:38px;border-radius:11px;border:1px solid var(--border);background:var(--surface);',
        '.userchip{display:flex;align-items:center;gap:9px;padding:5px 7px 5px 5px;border:1px solid var(--border);border-radius:99px;background:var(--surface)}',
        '.btn.ghost{background:var(--surface);color:var(--ink);border:1px solid var(--border);box-shadow:none}',
        '.modal{background:var(--surface);border-radius:var(--r);box-shadow:var(--sh-l);',
        '.envtog button.on[data-e="live"]{background:var(--surface);',
        '.envtog button.on[data-e="sandbox"]{background:var(--surface);',
        '.paydot.green{background:var(--green)}',
        '.paydot.amber{background:var(--amber)}',
        '.paydirty{color:var(--amber);',
        // The ring that lifts the notification dot off the button behind it is
        // that button's own fill, so it has to move with it.
        'background:var(--red);border:2px solid var(--surface)}',
        // The body wash was already half tokenised — var(--bg) in the middle,
        // a literal at each end.
        'radial-gradient(120% 80% at 50% -10%,var(--surface) 0%,var(--bg) 55%,var(--surface-3) 100%)',
        'linear-gradient(135deg,var(--violet),var(--blue))',
    ];

    foreach ($converted as $rule) {
        expect(str_contains($css, $rule))->toBeTrue(
            "This rule no longer uses its token, so it stops following the console theme: {$rule}"
        );
    }
});

it('keeps the colours that are not theme colours exactly as they were', function () use ($blade) {
    $css = $blade();

    // Each of these is a literal ON PURPOSE. A later pass that "finishes the
    // job" by converting them makes the console worse, not better, so they are
    // pinned here with the reason attached.
    $keep = [
        // White ON the accent fill. var(--surface) here is near-black in the
        // dark theme: black text on a green badge.
        'color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px' => '.logo, white on accent',
        '.sev.crit{background:var(--red);color:#fff}' => 'white on red',
        '.check .ci svg{width:13px;height:13px;color:#fff}' => 'the tick glyph, white on accent',
        // The thumb of a switch, sliding over a coloured track.
        '.tog::after' => 'the switch thumb',
        // A miniature of the customer-facing store. It has its own design and
        // is not supposed to follow the admin theme.
        '/* search panel preview */' => 'the storefront previews are still marked as previews',
    ];

    foreach ($keep as $needle => $why) {
        expect(str_contains($css, $needle))->toBeTrue(
            "A colour that is deliberately not a theme colour has been changed ({$why}); "
            . 'converting it is a regression, not a fix.'
        );
    }

    // The avatar's initials stay white even though its gradient became tokens.
    expect(str_contains($css, 'linear-gradient(135deg,var(--violet),var(--blue));color:#fff;'))->toBeTrue(
        'The avatar initials must stay white; they sit on a coloured gradient.'
    );
});

it('drops the midnight override that only existed to restate a typed-in white', function () use ($blade) {
    $css = $blade();

    // Five chrome rules had a literal white, so the dark theme had to say
    // "background: the surface token" all over again for each of them. They
    // carry the token themselves now, so the override says nothing new.
    expect(str_contains($css, ':root[data-theme="midnight"] .iconbtn'))->toBeFalse(
        'The redundant midnight override is back; the base rules already resolve to the surface token.'
    );
});

it('uses this file\'s own fallback pattern for the two bare colours in the media picker', function () {
    $css = (string) file_get_contents(resource_path('views/admin/partials/media-picker.blade.php'));

    // The picker renders where the console's tokens are not always defined, so
    // every colour in it is var(--token,<fallback>). These two were the only
    // ones typed bare.
    expect(str_contains($css, '.mp-up.is-bad .mp-up-track > i{background:var(--red,#e3493f)}'))->toBeTrue(
        'The failed-upload bar no longer follows the theme.'
    );
    expect(str_contains($css, '.mp-up.is-bad .mp-up-pct{color:var(--red,#e3493f)}'))->toBeTrue(
        'The failed-upload percentage no longer follows the theme.'
    );
});

/* ===================== 2. the words beside the box ======================== */

it('adds the label click in the same shared place as the keyboard support', function () use ($blade, $crBlock) {
    $source = $blade();

    $cjEnd = strpos($source, 'LANE CJ · Admin · keyboard-operable tick boxes — END');
    $crStart = strpos($source, 'LANE CR · Admin · the words beside a tick box work it — BEGIN');

    expect($cjEnd)->not->toBeFalse();
    expect($crStart)->not->toBeFalse();
    expect($crStart)->toBeGreaterThan($cjEnd);

    $block = $crBlock();
    expect(str_contains($block, "document.addEventListener('click'"))->toBeTrue(
        'The label click is no longer wired up, so clicking the words does nothing again.'
    );
});

it('knows both shapes, the wrapping label and the sibling row', function () use ($crBlock) {
    $block = $crBlock();

    // smOpt() puts the box and its words in siblings with no <label> at all. A
    // fix that only knows the <label> shape leaves every one of those broken
    // while looking finished.
    expect(str_contains($block, "var HOST = 'label, .sm-opt';"))->toBeTrue(
        'The host list has changed; one of the two shapes the console uses may no longer be covered.'
    );
});

it('cannot double fire, which is what killed the ectog toggle', function () use ($crBlock) {
    $block = $crBlock();

    // The box has its own handler and has just run it. If this handler answers
    // that same click as well, the state flips and flips back and the control
    // is dead.
    expect(str_contains($block, '.cbx'))->toBeTrue('The box is no longer excluded from the forwarding.');
    expect(str_contains($block, 'var own = t.closest(SKIP);'))->toBeTrue(
        'The guard that stops this handler answering a click the box already handled is gone.'
    );
    expect(str_contains($block, 'if (own && host.contains(own)) return;'))->toBeTrue(
        'The double-fire guard no longer returns, so one click will toggle twice.'
    );

    // A <label> with a real control is already forwarded by the browser.
    expect(str_contains($block, "if (host.tagName === 'LABEL' && host.control) return;"))->toBeTrue(
        'A label that the browser already forwards will now be answered twice.'
    );

    // Two boxes in one row is a list, not a setting.
    expect(str_contains($block, 'if (boxes.length !== 1) return;'))->toBeTrue(
        'A row carrying more than one box will now toggle an arbitrary one of them.'
    );
});

it('leaves the element, the ids and every save handler alone', function () use ($blade) {
    $source = $blade();

    // The reason the spans stayed spans: every reader asks for the class and
    // every writer toggles it, including the save handlers. Swapping in a real
    // <input> means rewriting all of those, and a dropped field there does not
    // throw — it silently blanks a stored setting on the next save.
    foreach (['seo_indexnow_cbx', 'seo_llms_cbx', 'seo_crawlclean_cbx', 'seo_merchant_cbx'] as $id) {
        expect(str_contains($source, "document.getElementById('{$id}').classList.contains('on')"))->toBeTrue(
            "The {$id} save handler no longer reads the class, so the setting may save blank."
        );
    }

    expect(str_contains($source, "function smOpt(cbxId,on,title,help){"))->toBeTrue(
        'smOpt() has changed shape; the sibling case may no longer be what the fix expects.'
    );
    expect(str_contains($source, '<span class="cbx\'+(on?\' on\':\'\')+\'" id="\'+cbxId+\'"'))->toBeTrue(
        'smOpt() no longer draws a .cbx span with its id, which every save handler depends on.'
    );
});

it('makes the sibling row look clickable now that it is', function () use ($blade) {
    $css = $blade();

    // .pdchk, .catopt and .so-col already promised a pointer while doing
    // nothing. .sm-opt was the one that did not even promise.
    $start = strpos($css, '.sm-opt{display:flex;');
    expect($start)->not->toBeFalse();
    $rule = substr($css, $start, (int) strpos($css, '.sm-opt > *', $start) - $start);

    expect(str_contains($rule, 'cursor:pointer'))->toBeTrue(
        'The sibling row no longer shows a pointer, so nothing suggests the words can be clicked.'
    );
});

/* ===================== 3. in a real browser =============================== */

function crBrowserPrereqs(): array
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
    if (! is_file(base_path('tests/browser/admin-theme-and-labels.mjs'))) {
        $missing[] = 'tests/browser/admin-theme-and-labels.mjs is missing';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/**
 * A preview of THIS checkout, on its own database, with one admin in it.
 *
 * Its own database because the suite's connection is inside a transaction an
 * external process cannot see, so a server pointed at it finds no admin user
 * and no settings at all. Modelled on bootOverflowPreview() in
 * AdminMobileOverflowTest, with its own directory, port range and connection
 * name so the two can run at the same time.
 *
 * @return array{base:string, email:string, password:string, stop:callable}
 */
function bootCrPreview(): array
{
    $dir = storage_path('framework/testing/lane-cr-theme');
    $root = $dir . '/webroot';
    $db = $dir . '/preview.sqlite';

    @mkdir($root, 0o777, true);
    @unlink($dir . '/kbb-upgrade-app');
    @symlink(base_path(), $dir . '/kbb-upgrade-app');

    copy(base_path('public-web-root/index.php'), $root . '/index.php');

    // COPIED, never symlinked: this directory is rm -rf'd on the way out and a
    // symlink would put the repo's tracked build assets in reach of that.
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
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'file',
        'APP_KEY' => (string) config('app.key'),
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

    config()->set('database.connections.lane_cr_preview', [
        'driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);

    $email = 'theme-walker@example.test';
    $password = 'lane-cr-password';

    AdminUser::on('lane_cr_preview')->create([
        'name' => 'Theme Walker', 'email' => $email, 'password' => $password, 'role' => 'owner',
    ]);

    DB::purge('lane_cr_preview');

    $port = PreviewPort::claim(8630, 8740);
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
function runCrWalker(array $preview, string $chrome): array
{
    $env = [
        'KBB_BROWSER_BASE' => $preview['base'],
        'KBB_BROWSER_EMAIL' => $preview['email'],
        'KBB_BROWSER_PASSWORD' => $preview['password'],
        'KBB_BROWSER_CHROME' => $chrome,
        'KBB_BROWSER_SHOTS' => (string) env('KBB_BROWSER_SHOTS', ''),
        'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
    ];

    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg($v) . ' ';
    }

    $command = $prefix . 'node ' . escapeshellarg(base_path('tests/browser/admin-theme-and-labels.mjs')) . ' 2>/dev/null';

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

it('moves the console chrome with the theme, and leaves the previews where they are', function () {
    ['chrome' => $chrome, 'missing' => $missing] = crBrowserPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped(
            'Needs real Chromium: ' . implode('; ', $missing)
            . '. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $preview = bootCrPreview();

    try {
        $r = runCrWalker($preview, $chrome);
    } finally {
        $preview['stop']();
    }

    expect($r['ok'] ?? false)->toBeTrue((string) ($r['error'] ?? 'the walker failed'));

    $light = $r['themes']['aurora'];
    $dark = $r['themes']['midnight'];

    // Every converted rule must actually CHANGE between the two themes. A rule
    // that reads the same in both is still carrying a literal.
    foreach ($light['converted'] as $key => $value) {
        expect($dark['converted'][$key])->not->toBe(
            $value,
            "{$key} reads the same in both themes, so it is not following the theme."
        );
    }

    // And the control group must NOT change. A screen that looked right before
    // and looks wrong after is this lane's failure, not an acceptable cost.
    foreach ($light['leftAlone'] as $key => $value) {
        expect($dark['leftAlone'][$key])->toBe(
            $value,
            "{$key} changed with the theme; a fixed colour has been converted by mistake."
        );
    }

    // The real chrome on the page, not only the probe.
    expect($light['live']['.iconbtn'])->toBe('rgb(255, 255, 255)');
    expect($dark['live']['.iconbtn'])->toBe('rgb(20, 26, 43)');

    // Only thrown scripts. Cancelled data requests from walking one screen to
    // the next are in httpLog and are not this lane's business; a handler that
    // throws is.
    expect($r['pageErrors'])->toBe([], 'A script threw while walking the themed screens.');
});

it('sees an overflow when there is one, and reports none when there is not', function () {
    ['chrome' => $chrome, 'missing' => $missing] = crBrowserPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped(
            'Needs real Chromium: ' . implode('; ', $missing)
            . '. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $preview = bootCrPreview();

    try {
        $r = runCrWalker($preview, $chrome);
    } finally {
        $preview['stop']();
    }

    expect($r['ok'] ?? false)->toBeTrue((string) ($r['error'] ?? 'the walker failed'));

    // THE PROBE FIRST. An overflow check that cannot see an overflow reports
    // all clear forever. A deliberately oversized block has to exceed the
    // column, and #content is the only thing that sees it — the document stays
    // at the viewport width because the column, not the page, is what scrolls.
    expect($r['probe']['1280']['scrollWidth'])->toBeGreaterThan(
        1032,
        'The overflow probe did not register a 2400px block, so the measurement is not live.'
    );

    foreach ($r['overflow'] as $key => $m) {
        expect($m)->not->toBeNull("no #content on {$key}");
        expect($m['scrollWidth'])->toBeLessThanOrEqual(
            $m['clientWidth'] + 1,
            "{$key} scrolls sideways: the column is {$m['clientWidth']} wide and its content is {$m['scrollWidth']}."
        );
    }
});

it('flips the box exactly once when the words are clicked, in both shapes', function () {
    ['chrome' => $chrome, 'missing' => $missing] = crBrowserPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped(
            'Needs real Chromium: ' . implode('; ', $missing)
            . '. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $preview = bootCrPreview();

    try {
        $r = runCrWalker($preview, $chrome);
    } finally {
        $preview['stop']();
    }

    expect($r['ok'] ?? false)->toBeTrue((string) ($r['error'] ?? 'the walker failed'));

    $sib = $r['labels']['siblings'];
    expect($sib['found'])->toBeTrue('No .sm-opt row was drawn, so the sibling shape was never exercised.');

    // ONE. Not two. Two is the .ectog scar: the state flips and flips back and
    // the control is dead while looking like it was never wired up.
    expect($sib['wordsClick']['n'])->toBe(1, 'Clicking the words did not flip the box exactly once.');
    expect($sib['boxClick']['n'])->toBe(1, 'Clicking the box itself no longer flips it exactly once.');
    expect($sib['rowPaddingClick']['n'])->toBe(1, 'Clicking the row did not flip the box exactly once.');

    if (array_key_exists('helpClick', $sib)) {
        expect($sib['helpClick']['n'])->toBe(1, 'Clicking the help line did not flip the box exactly once.');
    }

    // A link in the words is still a link.
    if (array_key_exists('linkInWords', $r['labels'])) {
        expect($r['labels']['linkInWords']['n'])->toBe(
            0,
            'Clicking a link inside the words toggled the setting as well as following the link.'
        );
    }

    $lab = $r['labels']['label'];
    expect($lab['found'])->toBeTrue('No wrapping <label> was drawn, so that shape was never exercised.');
    expect($lab['wordsClick']['n'])->toBe(1, 'Clicking the words in a wrapping label did not flip the box exactly once.');
    expect($lab['boxClick']['n'])->toBe(1, 'Clicking the box inside a wrapping label no longer flips it exactly once.');
});
