<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\PaymentProvider;
use App\Services\Payments\GatewayRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompiledCaches;

/**
 * Store → Payments, one tab per gateway.
 *
 * The owner asked for tabs because the screen stacked every gateway down one
 * very long page. The risk in granting that is entirely about what a tab
 * HIDES, so this file is split accordingly:
 *
 *   - the structural half, which runs everywhere including CI, pins the things
 *     that can be read out of the rendered document: that the tab bar is built
 *     from the endpoint's gateway list rather than a list written here, that
 *     the panes are hidden rather than re-rendered, and that no Payments
 *     control carries `data-ectab`
 *   - the browser half, which needs real Chromium, drives the one thing that
 *     has no server side at all: typing into a gateway, switching tab,
 *     switching back and finding the typing still there
 *
 * WHY data-ectab IS ITS OWN ASSERTION. The tab bar reuses the .ectabs/.ectab
 * CSS from Store → Ecommerce, because this screen is already an .ecwrap and
 * inventing a third tab style was explicitly not wanted. Ecommerce also binds
 * a DOCUMENT-level click listener on [data-ectab] which sets ETAB and calls
 * paintEcom() — so a Payments tab that carried that attribute would rewrite
 * #content with the Ecommerce screen on every click. The class is shared; the
 * data-* key is owned. That is the same lesson already recorded in app.blade
 * .php beside the bare `.ectog` overlap, and it is one character away from
 * being reintroduced, so it is asserted rather than remembered.
 */

/** The payments screen's slice of the console document. */
function paytabsScreenSource(): string
{
    $html = view('admin.app')->render();

    $start = strpos($html, 'Store → Payments (gateway credentials)');
    expect($start)->not->toBeFalse('the payments screen block moved or was renamed');

    $end = strpos($html, 'Route interception', (int) $start);
    expect($end)->not->toBeFalse('the payments screen block has no end marker');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/* ------------------------------------------------- the structural half ---- */

it('builds the tab bar from the gateway list the endpoint returns, not a list written into the page', function () {
    $screen = paytabsScreenSource();

    // The bar is a map over PAYG, which renderPayments() fills from
    // /admin-api/payments -> GatewayRegistry::all().
    expect($screen)->toContain('function payTabBar()')
        ->and($screen)->toContain('PAYG.map(function(g){');

    /*
     * And it names no gateway. A tab bar with 'stripe' spelled into it would
     * work perfectly today and silently skip the fifth gateway somebody adds
     * to GatewayRegistry, which is exactly the failure the owner would not see
     * until a payment method was missing at checkout.
     *
     * PAY_MODE_HELP is the one place ids legitimately appear -- it is per
     * gateway prose about what the Mode switch does -- so it is cut out before
     * looking. Nothing else on the screen may mention one.
     */
    $withoutModeHelp = preg_replace('/var PAY_MODE_HELP=\{.*?\n  \};/s', '', $screen);

    expect($withoutModeHelp)->toBeString();

    foreach (app(GatewayRegistry::class)->all() as $gateway) {
        expect($withoutModeHelp)->not->toContain("'" . $gateway->id() . "'");
    }
});

it('gives every gateway the registry ships a tab, a pane and a save button', function () {
    $admin = AdminUser::create([
        'name' => 'Tabs Admin',
        'email' => 'tabs-admin@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');

    $ids = collect($this->getJson('/admin-api/payments')->assertOk()->json('gateways'))
        ->pluck('id')
        ->all();

    // The endpoint and the registry are the same list; the tab bar renders one
    // button per entry of it, so this is what "a gateway added later gets a
    // tab" reduces to on the server side.
    expect($ids)->toBe(app(GatewayRegistry::class)->all()->map(fn ($g) => $g->id())->all())
        ->and($ids)->not->toBeEmpty();

    $screen = paytabsScreenSource();

    // One button, one pane, one panel relationship, all keyed by g.id.
    expect($screen)->toContain('data-paytab="\'+sesc(g.id)+\'"')
        ->and($screen)->toContain('id="pay_pane_\'+sesc(g.id)+\'"')
        ->and($screen)->toContain('aria-controls="pay_pane_\'+sesc(g.id)+\'"');
});

it('never puts the attribute Ecommerce listens for on a payments control', function () {
    $screen = paytabsScreenSource();

    /*
     * The hazard, stated as the test: Ecommerce's document-level handler is
     * `e.target.closest('[data-ectab]')` and it repaints #content. Payments
     * must not emit that attribute anywhere.
     */
    expect($screen)->not->toContain('data-ectab')
        ->and($screen)->toContain('data-paytab');

    // And the handler that reads it is bound to this screen's own wrapper, not
    // to document, so it cannot reach another screen either.
    expect($screen)->toContain("document.querySelector('[data-payscreen]')")
        ->and($screen)->toContain("wrap.addEventListener('click'");
});

it('switches tabs by hiding panes rather than re-rendering them', function () {
    $screen = paytabsScreenSource();

    /*
     * This is the unsaved-changes guarantee expressed structurally. paySave()
     * reads the live DOM for one gateway, so the edits only exist as DOM state
     * -- if payShowTab() called paintPayments() the typing would be gone. It
     * must only move the `hidden` attribute.
     */
    expect($screen)->toContain('function payShowTab(')
        ->and($screen)->toContain("pane.removeAttribute('hidden')")
        ->and($screen)->toContain("pane.setAttribute('hidden','')");

    $body = substr($screen, (int) strpos($screen, 'function payShowTab('));
    $body = substr($body, 0, (int) strpos($body, "\n  }\n") + 4);

    foreach (['paintPayments(', 'renderPayments(', 'innerHTML'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('keeps a pending edit in another gateway when one gateway is saved', function () {
    $screen = paytabsScreenSource();

    /*
     * A successful save calls renderPayments(), which rebuilds every pane from
     * the endpoint. On the old long page an edit lost that way was at least on
     * screen; behind tabs it would vanish silently. The capture/restore pair
     * either side of the repaint is what stops it.
     */
    expect($screen)->toContain('function payCapturePending()')
        ->and($screen)->toContain('function payRestorePending(')
        ->and($screen)->toContain('var pending=payCapturePending();')
        ->and($screen)->toContain('await renderPayments();')
        ->and($screen)->toContain('payRestorePending(pending);');

    // The gateway that was just saved is dropped from the restore set, or its
    // freshly stored values would be painted over with the pre-save ones.
    expect($screen)->toContain('delete pending[id];');
});

it('shows each gateway state on its own tab', function () {
    $screen = paytabsScreenSource();

    /*
     * Enabled / configured / off comes from payStatus(), the SAME function the
     * card header uses -- the tab and the card cannot drift apart because
     * there is only one of it.
     */
    expect($screen)->toContain("'<span class=\"paydot '+sesc(payStatus(g)[0])+'\"")
        // Live vs sandbox: the one piece of state here that moves real money.
        ->and($screen)->toContain('function payIsLive(g)')
        ->and($screen)->toContain('payIsLive(g)?\'<span class="paylive">LIVE</span>\'')
        // Unsaved work, so a collapsed tab holding an edit says so.
        ->and($screen)->toContain('id="pay_tabdirty_\'+sesc(g.id)+\'"')
        ->and($screen)->toContain('function payIsDirty(');

    // A gateway with no credential fields (COD) has no mode select, so it must
    // not be labelled live or sandbox at all.
    expect($screen)->toContain("g.mode==='live' && g.fields.length>0");
});

it('leaves the warnings about gateways you are not looking at outside the tabs', function () {
    $screen = paytabsScreenSource();

    /*
     * The one thing tabs must not hide. Both banners are emitted before
     * payTabBar() in the template string, so a gateway that is switched on
     * with no credentials is reported whichever tab is open.
     */
    $warn = strpos($screen, 'switched on but missing credentials');
    $bar = strpos($screen, 'payTabBar()+');

    expect($warn)->not->toBeFalse()
        ->and($bar)->not->toBeFalse()
        ->and($warn)->toBeLessThan($bar);
});

it('addresses a gateway tab the way the console addresses a screen', function () {
    $screen = paytabsScreenSource();

    // '#payments/<id>' alongside the console's own '#<id>', plus the '?go='
    // form it also accepts, plus the remembered tab for a plain visit.
    expect($screen)->toContain('function payHashTab()')
        ->and($screen)->toContain("'#payments/'+id")
        ->and($screen)->toContain("q.get('go')==='payments'")
        ->and($screen)->toContain('PAY_TAB_KEY')
        // replaceState, so a tab click does not push a history entry and make
        // Back walk the tab bar instead of leaving the screen.
        ->and($screen)->toContain('history.replaceState');

    // The hash is only ever matched against ids the endpoint returned.
    expect($screen)->toContain('function payKnown(id)')
        ->and($screen)->toContain('if(payKnown(want)) return want;');
});

it('still keeps every gateway secret out of the document now that it has tabs', function () {
    $admin = AdminUser::create([
        'name' => 'Tabs Admin 2',
        'email' => 'tabs-admin-2@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');

    $canary = 'sk_live_KBBTABSCANARY000000001';

    $this->postJson('/admin-api/payments', [
        'id' => 'stripe',
        'enabled' => true,
        'mode' => 'live',
        'settings' => ['publishable_key' => 'pk_live_shown', 'secret_key' => $canary],
    ])->assertOk();

    // The tab bar renders a gateway's title and status, never a field value,
    // so nothing about it can put a credential on screen.
    expect(view('admin.app')->render())->not->toContain($canary);

    $stored = DB::table('payment_providers')->where('id', 'stripe')->value('config');
    expect($stored)->toBeString()->and($stored)->not->toContain($canary);
});

/* ---------------------------------------------------- the browser half ---- */

/** Where node, playwright and Chromium have to be for the browser half to mean anything. */
function paytabsPrereqs(): array
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

    if (! is_file(base_path('tests/browser/payments-tabs.mjs'))) {
        $missing[] = 'tests/browser/payments-tabs.mjs is missing';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/**
 * A preview of THIS checkout on its own database, with one admin and four
 * gateways in deliberately different states.
 *
 * Its own database because the suite's connection is inside a transaction an
 * external process cannot see. Its own directory because AdminMobileOverflow
 * Test boots one too and both may run in the same session.
 *
 * @return array{base:string, email:string, password:string, stop:callable}
 */
function bootPaytabsPreview(): array
{
    $dir = storage_path('framework/testing/lane-az-paytabs');
    $root = $dir . '/webroot';
    $db = $dir . '/preview.sqlite';

    @mkdir($root, 0o777, true);
    @unlink($dir . '/kbb-upgrade-app');
    @symlink(base_path(), $dir . '/kbb-upgrade-app');

    copy(base_path('public-web-root/index.php'), $root . '/index.php');

    /*
     * COPIED, never symlinked: this directory is rm -rf'd on the way out and a
     * symlink would put the repo's tracked build assets in reach of that.
     *
     * Guarded on the source existing, and it often does not: migration
     * 2026_08_28_183000_relocate_public_assets removes public/build, and the
     * suite has already run the migration set against base_path() by the time
     * this boots. It costs nothing here — the admin console is one
     * self-contained document with no @vite directive and no reference to
     * public/build at all, so the screen under measurement renders identically
     * either way.
     */
    if (! is_dir($root . '/build') && is_dir(base_path('public/build'))) {
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
        // The suite's .env has both as `array`, and an array session does not
        // survive the redirect after a login POST.
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

    config()->set('database.connections.lane_az_preview', [
        'driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);

    $email = 'paytabs-walker@example.test';
    $password = 'lane-az-password';

    AdminUser::on('lane_az_preview')->create([
        'name' => 'Paytabs Walker', 'email' => $email, 'password' => $password, 'role' => 'owner',
    ]);

    /*
     * Four gateways, four different states, so the tab indicators have
     * something to be right or wrong about:
     *
     *   stripe  enabled, configured, LIVE   -> green dot + LIVE chip
     *   tabby   enabled, NOT configured     -> amber dot, and the banner
     *   tamara  configured, switched off    -> grey dot
     *   cod     enabled, nothing to set up  -> green dot, no mode, no chip
     */
    $seed = [
        ['id' => 'stripe', 'enabled' => true, 'mode' => 'live', 'position' => 0,
            'config' => ['publishable_key' => 'pk_live_preview', 'secret_key' => 'sk_live_preview']],
        ['id' => 'tabby', 'enabled' => true, 'mode' => 'test', 'position' => 1, 'config' => []],
        ['id' => 'tamara', 'enabled' => false, 'mode' => 'test', 'position' => 2,
            'config' => ['api_token' => 'tamara_preview', 'notification_token' => 'tamara_notify_preview']],
        ['id' => 'cod', 'enabled' => true, 'mode' => 'test', 'position' => 3, 'config' => []],
    ];

    foreach ($seed as $row) {
        PaymentProvider::on('lane_az_preview')->create($row);
    }

    DB::purge('lane_az_preview');

    $port = 8600 + random_int(30, 120);
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
function drivePaytabs(array $preview, string $chrome, array $opts = []): array
{
    $env = [
        'KBB_PT_BASE' => $preview['base'],
        'KBB_PT_EMAIL' => $preview['email'],
        'KBB_PT_PASSWORD' => $preview['password'],
        'KBB_PT_CHROME' => $chrome,
        'KBB_PT_GATEWAYS' => implode(',', app(GatewayRegistry::class)->all()->map(fn ($g) => $g->id())->all()),
        'KBB_PT_WIDTHS' => $opts['widths'] ?? '1920,1280,390',
        'KBB_PT_PROBE' => ($opts['probe'] ?? false) ? '1' : '0',
        'KBB_PT_PROBE_W' => (string) ($opts['probeWidth'] ?? 1672),
        'KBB_PT_SHOTS' => $opts['shots'] ?? '',
        'KBB_PT_SHOT_TAG' => $opts['tag'] ?? 'after',
        'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
    ];

    $prefix = '';

    foreach ($env as $k => $v) {
        $prefix .= $k . '=' . escapeshellarg((string) $v) . ' ';
    }

    $command = $prefix . 'node ' . escapeshellarg(base_path('tests/browser/payments-tabs.mjs')) . ' 2>/dev/null';

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
        throw new RuntimeException('payments-tabs walker returned no JSON');
    }

    return $decoded;
}

it('carries an unsaved edit across a tab switch, in a real browser', function () {
    ['chrome' => $chrome, 'missing' => $missing] = paytabsPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped(
            'Needs real Chromium: ' . implode('; ', $missing)
            . '. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $preview = bootPaytabsPreview();

    try {
        $r = drivePaytabs($preview, $chrome, ['widths' => '1920']);

        expect($r['ok'])->toBeTrue($r['error'] ?? '');

        /* ---- one tab per gateway, from the server's own list ---- */
        $expected = app(GatewayRegistry::class)->all()->map(fn ($g) => $g->id())->all();

        expect(array_column($r['tabs'], 'id'))->toBe($expected)
            ->and($r['gateways'])->toBe($expected);

        /* ---- exactly one pane open ---- */
        expect($r['checks']['visiblePanes'])->toBe(1);

        /* ---- and no Ecommerce attribute anywhere on it ---- */
        expect($r['checks']['ectabInPayments'])->toBe(0);

        /* ---- THE ASSERTION THAT MATTERS ---- */
        expect($r['checks']['survivedRoundTrip'])
            ->toBeTrue('typing was lost across a tab switch: ' . json_encode($r['checks']['valueAfterRoundTrip']));

        expect($r['checks']['hiddenAfterSwitch'])->toBeTrue('the pane was not actually hidden, so the test proved nothing');

        // Saving from the tab the operator came back to really stores it.
        expect($r['checks']['persistedMatches'])
            ->toBeTrue('the value did not reach the database: ' . json_encode($r['checks']['persisted']));

        // And the screen stays where the operator was, rather than snapping
        // back to the first gateway on every save.
        expect($r['checks']['tabAfterSave'])->toBe($r['tabs'][0]['id']);

        /* ---- an edit waiting in another tab survives a foreign save ---- */
        expect($r['checks']['otherTabSurvivedForeignSave'])
            ->toBeTrue('saving one gateway ate a pending edit in another: ' . json_encode($r['checks']['otherTabAfterForeignSave']));

        /* ---- the state on the tabs ---- */
        $byId = collect($r['tabs'])->keyBy('id');

        // stripe: enabled, configured, live.
        expect($byId['stripe']['dot'])->toBe('green')
            ->and($byId['stripe']['live'])->toBeTrue();

        // tabby: switched on with no credentials — the case a tab must never
        // hide, so it is amber and the banner names it as well.
        expect($byId['tabby']['dot'])->toBe('amber')
            ->and($byId['tabby']['live'])->toBeFalse();

        // tamara: configured but switched off.
        expect($byId['tamara']['dot'])->toBe('grey');

        // cod: ready, and never labelled live or sandbox because it has neither.
        expect($byId['cod']['dot'])->toBe('green')
            ->and($byId['cod']['live'])->toBeFalse();

        // The unsaved marker is visible while the tab holding the edit is the
        // open one AND while it is hidden — the second is the one that matters.
        expect($r['checks']['dirtyMarkWhileOpen'])->toBeTrue()
            ->and($r['checks']['dirtyMarkWhileHidden'])->toBeTrue();

        /* ---- deep link and memory ----
         * The `navigate` assertion is load-bearing, not decoration. Going from
         * /admin to /admin#payments/x is a SAME-DOCUMENT fragment navigation:
         * Chromium does not reload and no script re-runs, so the first version
         * of this check was reading whichever tab was already open and passing
         * without the deep link working at all. It only means something if the
         * document really was loaded fresh.
         */
        expect($r['checks']['deepLinkWasFreshLoad'])->toBe('navigate');

        expect($r['checks']['hashAfterSwitch'])->toBe('#payments/' . $r['tabs'][1]['id'])
            ->and($r['checks']['deepLinkTab'])->toBe($r['tabs'][1]['id'])
            ->and($r['checks']['queryLinkTab'])->toBe($r['tabs'][0]['id'])
            ->and($r['checks']['rememberedTab'])->toBe($r['checks']['rememberedExpected']);

        // And the same address pasted into an already-open console, which is a
        // fragment-only change the boot pass can never see.
        expect($r['checks']['hashChangeTab'])->toBe($r['tabs'][0]['id']);

        /* ---- nothing threw ---- */
        expect($r['pageErrors'])->toBe([]);
    } finally {
        $preview['stop']();
    }
});

it('does not overflow the content column at any width, with any gateway tab open', function () {
    ['chrome' => $chrome, 'missing' => $missing] = paytabsPrereqs();

    if ($missing !== []) {
        test()->markTestSkipped(
            'Needs real Chromium: ' . implode('; ', $missing)
            . '. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $preview = bootPaytabsPreview();

    try {
        /*
         * The harness has to be shown capable of failing before a clean result
         * from it means anything. A block wider than the content column must
         * make EVERY tab report an overflow. 1672px is the column's own width
         * at a 1920 viewport, so nothing narrower would prove it.
         */
        $probe = drivePaytabs($preview, $chrome, ['widths' => '1920', 'probe' => true, 'probeWidth' => 1672]);

        expect($probe['ok'])->toBeTrue($probe['error'] ?? '');

        $probed = array_values(array_filter($probe['overflow'], fn ($r) => isset($r['scrollWidth'])));
        $caught = array_filter($probed, fn ($r) => $r['scrollWidth'] > $r['clientWidth']);

        expect($probed)->not->toBeEmpty()
            ->and(count($caught))->toBe(count($probed), 'the 1672px probe did not register on every tab');

        // Again at 1280, where the column is narrower, with a probe sized to it.
        $probe2 = drivePaytabs($preview, $chrome, ['widths' => '1280', 'probe' => true, 'probeWidth' => 1032]);

        expect($probe2['ok'])->toBeTrue($probe2['error'] ?? '');

        $probed2 = array_values(array_filter($probe2['overflow'], fn ($r) => isset($r['scrollWidth'])));
        $caught2 = array_filter($probed2, fn ($r) => $r['scrollWidth'] > $r['clientWidth']);

        expect($probed2)->not->toBeEmpty()
            ->and(count($caught2))->toBe(count($probed2), 'the 1032px probe did not register on every tab at 1280');

        /* ---- now the real measurement, every width, every tab ---- */
        $walk = drivePaytabs($preview, $chrome, ['widths' => '1920,1280,390']);

        expect($walk['ok'])->toBeTrue($walk['error'] ?? '');

        $rows = array_values(array_filter($walk['overflow'], fn ($r) => isset($r['scrollWidth'])));

        expect($rows)->not->toBeEmpty();

        $over = [];

        foreach ($rows as $row) {
            if ($row['scrollWidth'] > $row['clientWidth']) {
                $over[] = sprintf(
                    '%dpx / tab %s: #content %d wide in a %d column, widest child %s',
                    $row['width'],
                    $row['tab'],
                    $row['scrollWidth'],
                    $row['clientWidth'],
                    $row['worst'] ?? '?'
                );
            }
        }

        expect($over)->toBe([], "Payments overflows its content column:\n" . implode("\n", $over));

        // Every gateway really was opened at every width, or the walk proved
        // nothing about the tabs it skipped.
        $gateways = app(GatewayRegistry::class)->all()->count();

        expect(count($rows))->toBe($gateways * 3);

        expect($walk['pageErrors'])->toBe([]);
    } finally {
        $preview['stop']();
    }
});
