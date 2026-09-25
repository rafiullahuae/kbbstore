<?php

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE GN — "why you mentioned number of rows to select? what's the purpose?"
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The shop owner asked that, looking at the Run row of Tools -> KBB Export. He
 * was right to. `Rows per batch` exists so that one HTTP request cannot run
 * long enough to hit a shared host's PHP time limit — but docs/GL-GROUP-DOWNLOADS.md
 * measured the real shop (671 products, 4,159 orders, 10,571 line items, 3,712
 * customers) and the slowest bounded unit at the default 200 was **293 ms**
 * against a limit that is typically 30 seconds. Two orders of magnitude of
 * headroom is not a dial. There is no value he could sensibly choose, and
 * standing it in the main flow made plumbing look like a decision.
 *
 * So it is MOVED, not removed: it is a real escape hatch for an unusually
 * strict host, and that host is real. Everything about that move is asserted
 * here, in two halves, because the two halves fail in different ways:
 *
 *   1. THE SERVER HALF, which runs everywhere. The value still reaches
 *      KBB_Export_Runner unchanged, still clamps to 10–1000, and a request that
 *      carries no batch at all still gets 200.
 *
 *   2. THE SCREEN HALF, which needs a browser. Every claim about the disclosure
 *      is JavaScript and markup, and the PHP suite is blind to both — the hole
 *      docs/GK-EXPORT-GROUPS.md §6.1 paid for, where deleting a single line of
 *      this page's script left the whole suite green. So the page is rendered
 *      by KBB_Export_Admin::screen() itself (harness/screen.php) and driven in
 *      Chromium (harness/screen-drive.mjs --plumbing).
 *
 * See docs/GN-EXPORT-SCREEN.md.
 */

/**
 * The WordPress-shaped MySQL database the harness builds its fixture shop in.
 *
 * Defined here rather than borrowed from GeWpExporterTest: that file's
 * geWpDb() is a global function, and a test file that reads another file's
 * globals passes or fatals on load order rather than on anything true. Same
 * variable, same default, same reason — harness/shop.php DROPS AND REBUILDS
 * every table it touches, so two worktrees sharing one name tear the schema
 * down under each other mid-run.
 */
function gnWpDb(): string
{
    $name = getenv('KBB_WP_DB');

    return is_string($name) && $name !== '' ? $name : 'kbb_ge_wp';
}

/**
 * Ask KBB_Export_Admin::settings_from_request() what it makes of a $_POST.
 *
 * Run in a SEPARATE PHP PROCESS, and that is not fussiness. The class is
 * WordPress code: its file begins `defined('ABSPATH') || exit`, it declares
 * add_action() callbacks, and requiring it into the Laravel test process would
 * put a second definition of the plugin's classes beside whatever the harness
 * scripts already loaded. A subprocess with the two files it actually needs is
 * the honest reproduction of what WordPress does, and it cannot contaminate the
 * suite it is run from.
 *
 * @param  array<string,mixed>  $post
 * @return array<string,mixed>
 */
function gnSettingsFromRequest(array $post): array
{
    $script = <<<'PHP'
        define("ABSPATH", "/tmp/");
        function add_action() {}
        function add_management_page() {}
        require $argv[1] . "/wordpress-plugin/kbb-exporter/includes/class-kbb-export-groups.php";
        require $argv[1] . "/wordpress-plugin/kbb-exporter/admin/class-kbb-export-admin.php";
        $_POST = json_decode($argv[2], true);
        $method = new ReflectionMethod("KBB_Export_Admin", "settings_from_request");
        $method->setAccessible(true);
        echo json_encode($method->invoke(null));
    PHP;

    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' -r '.escapeshellarg($script)
            .' '.escapeshellarg(base_path())
            .' '.escapeshellarg((string) json_encode($post))
            .' 2>&1',
        $lines,
        $status
    );

    expect($status)->toBe(0, 'the settings probe failed: '.implode("\n", $lines));

    $decoded = json_decode(implode('', $lines), true);

    expect($decoded)->toBeArray('the settings probe printed something that is not JSON: '.implode("\n", $lines));

    return $decoded;
}

/**
 * Where the browser writes this test's screenshots.
 *
 * ── WHY THIS IS NOT docs/gn-screen-shots BY DEFAULT ─────────────────────
 *
 * It was, and it meant every green run left tracked PNGs modified in the working
 * tree. That cost nothing while the browser was dying at launch and writing
 * nothing; the moment the harness was repaired -- see tests/bootstrap.php on
 * Chromium and the working directory -- every suite run started dirtying the
 * checkout. That is the "tracked preview files rewritten under you by a green
 * run" incident bootstrap.php already records, arriving again through a new
 * door.
 *
 * A screenshot is not deterministic: the same page re-rendered differs by
 * kilobytes. So there is no version of this that churns only when the screen
 * actually changes.
 *
 * The run therefore writes into its own temp directory, and the committed
 * pictures are refreshed deliberately with KBB_REFRESH_SHOTS=1 -- which is what
 * a lane taking pictures for a patch wants, and what an ordinary verification
 * run does not.
 *
 * THE ASSERTIONS GOT STRICTER FOR IT, which is the part worth keeping: the fresh
 * shot must exist because the browser really rendered, AND the committed one
 * must exist because the document beside it makes a claim about a screen.
 * Before, one committed file satisfied both -- which is exactly how a browser
 * that never launched went unnoticed.
 */
function gnShotsDir(string $out): string
{
    if (getenv('KBB_REFRESH_SHOTS')) {
        return base_path('docs/gn-screen-shots');
    }

    $dir = $out.'/shots';

    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

it('still hands the batch size to the runner unchanged, clamped at both ends and defaulted', function () {
    /*
     * THE MOVE MUST NOT HAVE CHANGED ANY OF THIS. A disclosure that quietly
     * stopped the value arriving, or stopped it being bounded, would look
     * identical on the screen — which is exactly why it is measured on the
     * server side rather than inferred from the markup.
     *
     * The bounds are the reason the field can be offered to a non-technical
     * owner at all: below 10 a 4,000-order shop needs hundreds of round trips,
     * and above 1,000 one batch outlives the request limit the batching exists
     * to stay inside. So both ends are asserted AT the boundary and OUTSIDE it,
     * not merely "something sensible comes back".
     */
    expect(gnSettingsFromRequest(['batch' => 200])['batch'])->toBe(200, 'the default value no longer survives the round trip');
    expect(gnSettingsFromRequest(['batch' => 50])['batch'])->toBe(50, 'a lowered value — the whole point of the escape hatch — did not reach the runner');

    // The bottom, and below it.
    expect(gnSettingsFromRequest(['batch' => 10])['batch'])->toBe(10);
    expect(gnSettingsFromRequest(['batch' => 9])['batch'])->toBe(10);
    expect(gnSettingsFromRequest(['batch' => 0])['batch'])->toBe(10);
    expect(gnSettingsFromRequest(['batch' => -500])['batch'])->toBe(10);

    // The top, and above it.
    expect(gnSettingsFromRequest(['batch' => 1000])['batch'])->toBe(1000);
    expect(gnSettingsFromRequest(['batch' => 1001])['batch'])->toBe(1000);
    expect(gnSettingsFromRequest(['batch' => 999999])['batch'])->toBe(1000);

    /*
     * AND A REQUEST WITH NO BATCH IN IT AT ALL gets 200 — which is not a
     * hypothetical. It is what a tab that was open before this change posts,
     * and what anything driving the endpoint without this screen posts. The
     * same reasoning the `groups` field already carries: an older request
     * should produce what the plugin has always produced.
     */
    expect(gnSettingsFromRequest([])['batch'])->toBe(200, 'a request carrying no batch no longer defaults to 200');

    // A non-numeric value is a cast, not a crash, and lands on the floor.
    expect(gnSettingsFromRequest(['batch' => 'nonsense'])['batch'])->toBe(10);
});

it('moves the batch setting behind a disclosure that starts shut, and still posts it when it is never opened', function () {
    /*
     * ════════════════════════════════════════════════════════════════════════
     * THIS IS THE ONE THAT CANNOT BE DONE IN PHP
     * ════════════════════════════════════════════════════════════════════════
     *
     * Four claims, and a PHP test can see none of them:
     *
     *   1. The disclosure starts CLOSED, and the input is really INSIDE it —
     *      so nothing was merely duplicated somewhere out of view.
     *   2. Closed means not visible. Otherwise nothing moved out of his way.
     *   3. An export started WITHOUT THE DISCLOSURE EVER BEING OPENED still
     *      posts batch=200. This is the load-bearing one: a <details> keeps its
     *      contents in the DOM, which is the entire reason it is the cheapest
     *      correct answer here, and post() reads the value exactly as it always
     *      did. A move that silently stopped sending the field would look the
     *      same on screen and produce a 200-row batch by accident rather than
     *      by design.
     *   4. Opened, the escape hatch WORKS: a changed value reaches the next
     *      request, which is how a stricter host is survived — lower it, press
     *      Resume, carry on from the last completed batch.
     *
     * And the trashed checkbox is asserted the other way round. It is a genuine
     * decision — he knows whether his trash holds anything he wants — so it had
     * to STAY in the main flow. A change that swept the whole Run row behind
     * the disclosure fails here instead of passing.
     */
    if (! is_dir(base_path('node_modules/playwright'))) {
        $this->markTestSkipped('playwright is not installed here');
    }

    $chrome = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

    if (! is_file($chrome)) {
        $this->markTestSkipped('no Chromium at '.$chrome);
    }

    $out = sys_get_temp_dir().'/kbb-gn-screen-'.bin2hex(random_bytes(4));

    mkdir($out, 0755, true);

    $render = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/screen.php'))
            .' --db='.gnWpDb().' > '.escapeshellarg($out.'/screen.html').' 2>&1',
        $render,
        $renderStatus
    );

    if (3 === $renderStatus) {
        $this->markTestSkipped('no MySQL here: '.implode(' ', $render));
    }

    expect($renderStatus)->toBe(0, implode("\n", $render));

    /*
     * ── EXACTLY ONE INPUT, read from the markup before the browser sees it ──
     *
     * "Moved" and "copied" are the same page to a driver that only ever finds
     * the first match. A second `id="kbb-batch"` left behind in the Run row
     * would satisfy every browser assertion below — querySelector returns the
     * first one, which would be the visible one — while the owner still stared
     * at the field he asked about. Counted, and counted with the closing quote
     * so that `id="kbb-batch-details"` is not mistaken for it.
     */
    $html = (string) file_get_contents($out.'/screen.html');

    expect(substr_count($html, 'id="kbb-batch"'))
        ->toBe(1, 'the batch input is on the screen more than once, or not at all');
    expect(substr_count($html, 'id="kbb-batch-details"'))
        ->toBe(1, 'the disclosure is on the screen more than once, or not at all');

    $lines = [];

    // cwd is the repository root so node resolves `playwright` by walking up
    // from the script, which is how ESM resolution works — NODE_PATH does not
    // apply to it.
    exec(
        'cd '.escapeshellarg(base_path()).' && node '
            .escapeshellarg(base_path('wordpress-plugin/harness/screen-drive.mjs'))
            .' --page='.escapeshellarg($out.'/screen.html')
            .' --plumbing'
            .' --shots='.escapeshellarg(gnShotsDir($out))
            .' --out='.escapeshellarg($out.'/findings.json')
            .' --chrome='.escapeshellarg($chrome)
            .' > /dev/null 2>&1',
        $lines,
        $status
    );

    if (127 === $status) {
        $this->markTestSkipped('no node here');
    }

    expect($status)->toBe(0, 'the browser run failed: '.implode("\n", $lines));

    /*
     * THE BROWSER REALLY RENDERED, asserted here because this is where $out is.
     *
     * This is the check that was missing when Chromium was dying at launch: the
     * run's output goes to /dev/null, so the only signal was the exec() status,
     * and the committed pictures in docs/ satisfied the file-exists case further
     * down on their own. A shot taken THIS RUN cannot be satisfied by a file
     * somebody committed last year.
     */
    foreach (['01-at-rest-collapsed.png', '02-disclosure-opened.png'] as $shot) {
        $fresh = gnShotsDir($out).'/'.$shot;

        expect(is_file($fresh))->toBeTrue("the browser run produced no {$shot}");
        expect(filesize($fresh))->toBeGreaterThan(1000, "the browser run produced an empty {$shot}");
    }

    $found = json_decode((string) file_get_contents($out.'/findings.json'), true);

    expect($found['errors'])->toBe([], 'the screen threw in the browser: '.implode(' | ', $found['errors']));

    $p = $found['plumbing'];

    // ── 1. The disclosure is there, it is shut, and the input is inside it ──
    expect($p['details_present'])->toBeTrue('there is no disclosure on the screen');
    expect($p['details_open_at_rest'])->toBeFalse('the disclosure is open when he arrives, which is not out of his way');
    expect($p['batch_inside_details'])->toBeTrue('the batch input is not inside the disclosure');

    // The trashed tick is a DECISION and stays where he can see it.
    expect($p['trashed_inside_details'])->toBeFalse('the trashed-products decision was swept behind the disclosure with the plumbing');
    expect($p['trashed_visible_at_rest'])->toBeTrue('the trashed-products decision is not visible at rest');

    /*
     * ── 2. Shut means not visible, on two independent mechanisms ────────────
     *
     * Both, because the obvious probe is WRONG here and reported the opposite
     * of the truth: this Chromium closes a <details> with
     * `content-visibility: hidden` rather than `display: none`, so the input
     * still has a layout box while the disclosure is shut — getClientRects()
     * returns 1 and offsetParent is non-null. checkVisibility() understands
     * content-visibility; Playwright's isVisible() is a second opinion arrived
     * at by a different route. One of them alone would have passed this
     * assertion vacuously.
     */
    expect($p['batch_visible_at_rest'])->toBeFalse('the batch input is on screen at rest, so it was not moved out of his way');
    expect($p['batch_visible_at_rest_playwright'])->toBeFalse('the batch input is on screen at rest (playwright)');

    // ── 3. Started without ever opening it, and it still posts 200 ──────────
    expect($p['details_open_after_start'])
        ->toBeFalse('the disclosure was opened during the run, so the assertion below proves nothing');

    expect($p['batches_never_opened'])->not->toBe([], 'the page made no request at all');

    $wrong = array_values(array_diff($p['batches_never_opened'], ['200']));

    expect($wrong)->toBe(
        [],
        'a request went out with a batch that is not the default 200 while the disclosure '
            .'had never been opened: '.json_encode($p['batches_never_opened'])
    );

    expect($p['batch_value_at_rest'])->toBe('200');
    expect($p['batch_min'])->toBe('10', 'the lower bound is no longer on the input');
    expect($p['batch_max'])->toBe('1000', 'the upper bound is no longer on the input');

    // ── 4. Opened, it is reachable and it still works ───────────────────────
    expect($p['details_open_after_click'])->toBeTrue('the disclosure did not open');
    expect($p['batch_visible_after_open'])->toBeTrue('the escape hatch is unreachable: opening the disclosure does not reveal the input');
    expect($p['batch_visible_after_open_playwright'])->toBeTrue('the escape hatch is unreachable (playwright)');

    $changed = array_values(array_diff($p['batches_after_change'], ['50']));

    expect($changed)->toBe(
        [],
        'a lowered batch size did not reach every request, so the escape hatch does not work: '
            .json_encode($p['batches_after_change'])
    );

    expect($p['batches_after_change'])->not->toBe([], 'no request went out after the value was changed');

    // ── The wording is for him, not for us ──────────────────────────────────
    // Asserted because the whole point of the change is what the sentence says.
    // str_contains rather than ->not->toContain(), which is variadic and passes
    // vacuously on a single needle.
    expect(strtolower($p['summary']))->toContain('export');
    expect(str_contains(strtolower($p['summary']), 'batch'))
        ->toBeFalse('the summary names the plumbing he asked about instead of the symptom he would recognise');
});

it('leaves the screenshots this change is accounted for with', function () {
    /*
     * The account in docs/GN-EXPORT-SCREEN.md is about what he SEES, and a
     * written claim about a screen is worth what the picture beside it is
     * worth. Both are regenerated by the browser run above; this fails if that
     * run was skipped everywhere AND nobody ever committed them, which is the
     * state in which the document is describing a screen nothing has looked at.
     */
    foreach (['01-at-rest-collapsed.png', '02-disclosure-opened.png'] as $shot) {
        $path = base_path('docs/gn-screen-shots/'.$shot);

        expect(is_file($path))->toBeTrue("docs/gn-screen-shots/{$shot} is missing");
        expect(filesize($path))->toBeGreaterThan(1000, "docs/gn-screen-shots/{$shot} is empty");
    }
});
