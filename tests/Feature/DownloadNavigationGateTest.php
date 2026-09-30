<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\AdminPathService;

/**
 * =============================================================================
 * THE NAVIGATIONS, AND THE GATE IN FRONT OF THEM
 * =============================================================================
 *
 * ── WHAT IT LOOKED LIKE ON THE SHOP ────────────────────────────────────────
 *
 * An admin-guarded address that does not carry the secret admin path answers a
 * signed-out BROWSER with a plain 404, because the 302 it used to answer with
 * named `admin_path` in a Location header to anyone who typed `admin-api`.
 *
 * Eleven such addresses are reached by NAVIGATING the browser at them rather
 * than by fetch -- ten of them from this file, one from
 * admin/partials/instagram-screen.blade.php. Four are `window.location.href`,
 * and for those the 404 REPLACES THE WHOLE CONSOLE: the owner presses Export on an
 * expired session and the sidebar, the page title and the list he was standing
 * on are gone from the document. Before, he landed on the admin login and
 * signed back in.
 *
 * MEASURED, both ways, on a preview whose admin lives at /sec-console
 * (docs/sec-shots/*-measurements.json):
 *
 *   Orders -> Export, session dead     consoleOnScreen  sidebarRows  tableRows
 *     before                                    false            0          0
 *     after                                      true           79          3
 *
 *   An order's Invoice button, session dead      modalOpen  what it said
 *     before                                         false  nothing at all
 *     after                                           true  "Your session has ended"
 *
 *   Catalog -> Products, session dead, list reloaded
 *     before   "... -> 401. If this is a fresh deployment, the Catalog ->
 *               Products routes may not be wired into routes/web.php yet."
 *     after    "Your session has ended, so this list could not be loaded.
 *               Sign in again and it loads as it did."
 *
 * ── AND SIGN OUT DID NOT SIGN ANYBODY OUT ──────────────────────────────────
 *
 * Found while measuring the above. The top-bar Sign out posted to a LITERAL
 * `/admin/logout` and then navigated to a LITERAL `/admin/login`. routes/web.php
 * answers `/admin/{any?}` with abort(404) the moment the admin path is moved --
 * which is the point of the setting. Measured on the same preview:
 *
 *   POST /admin/logout  -> 404      (swallowed by the handler's own catch)
 *   GET  /admin/login   -> 404      (a blank page saying NOT FOUND)
 *   GET  /admin-api/stats -> 200    ▲ HE WAS STILL SIGNED IN
 *
 * After: POST /sec-console/logout -> 302, the real login page, and
 * /admin-api/stats -> 401.
 *
 * ── WHY THESE ARE FINISHED-STATE PINS ──────────────────────────────────────
 *
 * resources/views/admin/app.blade.php is the integrator's file, so nine of the
 * ten cases below are RED in this lane's worktree and GREEN once
 * docs/SEC-ADMIN-APP-BLOCKS.md is applied -- and each stays a real guard
 * afterwards, because each counts an exact number. CLAUDE.md forbids the other
 * shape (`->not->toContain`), which would go red the moment the integrator did
 * the one thing this lane asked for.
 *
 * The tenth -- `it is the server that makes the literal sign-out dead` -- is
 * green both ways on purpose, and says so in its own comment.
 *
 * COUNTED, both ways, on the same tree: 9 failed / 1 passed before,
 * 10 passed (49 assertions) after.
 */
function secConsole(): string
{
    return file_get_contents(resource_path('views/admin/app.blade.php'));
}

/**
 * Every file the admin console is actually made of, labelled by its basename.
 *
 * ▲ THE PARTIALS ARE IN HERE BECAUSE LEAVING THEM OUT COST A NAVIGATION.
 * Round 3 of this lane scanned admin/app.blade.php and reported eleven
 * addresses reached by navigating the browser. There were TWELVE: Reviews ->
 * Reviews.io -> Export does it with `window.location.href` from
 * admin/partials/reviews-io-screen.blade.php -- the worst shape there is,
 * because it replaces the whole document -- and no scan that reads one file
 * was ever going to find it.
 */
function secConsoleFiles(): array
{
    $files = ['app' => resource_path('views/admin/app.blade.php')];

    foreach (glob(resource_path('views/admin/partials/*.blade.php')) as $path) {
        $files[basename($path, '.blade.php')] = $path;
    }

    ksort($files);

    return $files;
}

it('declares the gate exactly once', function () {
    /*
     * ONE of each, never two. Two declarations of kbbProbeDownload() is the
     * shape a lane produces by pasting a block twice, and the second would
     * silently win -- so the count is the assertion rather than presence.
     *
     * MUTATION NOTE. Drop block 1 from docs/SEC-ADMIN-APP-BLOCKS.md and all five
     * of these read 0. Apply it twice and they read 2. RUN -- both ways; the
     * second is the one that matters, and it is red.
     */
    $app = secConsole();

    foreach ([
        'function kbbAdminLoginUrl(',
        'async function kbbProbeDownload(',
        'function kbbSayDownloadRefused(',
        'async function kbbDownloadOk(',
        'function kbbTellIfDownloadRefused(',
    ] as $fn) {
        expect(substr_count($app, $fn))->toBe(1, "{$fn} is not declared exactly once");
    }
});

it('asks before every navigation that would take the whole console away', function () {
    /*
     * The four window.location.href sites -- Orders, Customers, Reviews and
     * Catalogue exports. These are the urgent half: they do not fail into a
     * dead tab, they replace the document.
     *
     * The pin is on the AWAITED form. `kbbDownloadOk(url)` called without the
     * await, or without the `return`, navigates anyway and the gate is
     * decoration.
     *
     * MUTATION NOTE. Change one of the four replacements back to the bare
     * `window.location.href = fixAdminApiUrl(...)` and this reads 3. RUN.
     */
    $app = secConsole();

    expect(substr_count($app, 'if(!(await kbbDownloadOk(url))) return;'))
        ->toBe(4, 'the four whole-console navigations are not all gated');

    // And each handler really is async, or the await is a syntax error rather
    // than a missing guard -- which the console's parse test would catch, but
    // not name.
    expect(substr_count($app, 'exportBtn.onclick = async function(){'))
        ->toBe(3, 'the three exportBtn handlers are not all async');
    expect(substr_count($app, "if(exp) exp.onclick = async function(){"))
        ->toBe(1, 'the reviews export handler is not async');
});

it('tells the console after every popup it cannot wait in front of', function () {
    /*
     * window.open MUST happen inside the click -- a popup opened from an async
     * continuation is blocked by the browser, which olPrintDocs()'s own comment
     * has said since before this lane. So these three are told AFTER the tab is
     * open, which is the honest shape and is said as such rather than contorted
     * into a pre-flight.
     *
     * Three call sites: the bulk documents, the four order documents, and the
     * Stripe Connect popup.
     *
     * MUTATION NOTE. Delete the kbbTellIfDownloadRefused line from block 7 and
     * this reads 2 calls. RUN.
     */
    $app = secConsole();

    expect(substr_count($app, 'kbbTellIfDownloadRefused(url);'))->toBe(2)
        ->and(substr_count($app, 'if(w) kbbTellIfDownloadRefused(url, w);'))->toBe(1);
});

it('leaves no navigation in the console unaccounted for', function () {
    /*
     * ▲ THE GUARD THAT MAKES THE NEXT ONE IMPOSSIBLE TO HIDE.
     *
     * Four of the nine were found by following `o.invoice_url` back to
     * Admin\InvoiceController::invoiceUrl() -- the address is never written in
     * the console at all. ServerBuiltAdminUrlsTest reads the SERVER for that
     * shape. This reads the CONSOLE for the other shape: every place the
     * browser is navigated, whatever the URL is built from.
     *
     * A TENTH AND AN ELEVENTH CAME OUT OF THIS. `payStripeOauth()` opens
     * /admin-api/payments/stripe/connect/start in a popup, and
     * instagram-screen.blade.php opens /admin-api/instagram/start in another.
     * Neither address appears in any list this lane started from.
     *
     * ── WHY THE WHOLE SET IS PINNED, RATHER THAN EACH SITE CHECKED ─────────
     *
     * The first two drafts looked for a gate call WITHIN N LINES of each
     * navigation, and both were beaten by their own mutation: an ungated
     * `window.open('/admin-api/anything')` dropped beside a gated one reads as
     * gated, because proximity is all a window can see. Tightening the window
     * only moves where the mutation has to be dropped.
     *
     * So the set is pinned outright, the way ServerBuiltAdminUrlsTest pins its
     * own. A TWELFTH navigation is a red suite wherever it is written, and a
     * lane adding one has to come here and say which of the three shapes it is:
     * gated before (window.location.href), told after (window.open), or exempt
     * with a reason.
     *
     * MUTATION NOTE. `function kbbMutant(){ window.open('/admin-api/anything'); }`
     * dropped in three places -- beside the gate's own declarations, beside
     * olDocLabel(), and beside cpSelectedIds() -- is RED at all three. RUN, all
     * three. The two earlier proximity drafts were green at the first two.
     */
    $sites = [];

    foreach (secConsoleFiles() as $label => $path) {
        foreach (explode("\n", file_get_contents($path)) as $line) {
            if (preg_match('/window\.open\s*\(|(?:window\.)?location\.(?:href\s*=|assign|replace)/', $line)) {
                $sites[] = $label.'  '.trim($line);
            }
        }
    }

    expect($sites)->toBe([
        // ── EXEMPT ────────────────────────────────────────────────────────
        // Store -> Core Updates moving the admin path. The console is being
        // deliberately relocated and the POST that produced this address has
        // just succeeded. UpdateApiController builds it as "'/' . trim($path,
        // '/')" -- exactly one leading slash, so it cannot become a
        // protocol-relative URL pointing off-site.
        'app  if(r.ok && r.data?.redirect){ toast(r.data.message); setTimeout(()=>{ window.location.href = r.data.redirect; }, 1200); return; }',
        // UNDER the admin path rather than under /admin-api, so GuestRedirect
        // still answers it with the 302 to the login -- which is safe there,
        // because the URL the browser used already carries the secret.
        "app  window.open(kbbHealthLogUrl(), '_blank', 'noopener');",
        // The line above it awaits an authenticated POST to
        // /admin-api/import/background and returns on a refusal, so the session
        // is proven alive one statement earlier.
        "app  window.open(impBase()+'/import/background-page','_blank');",
        // The gate's own sign-in button: the one navigation that is SUPPOSED to
        // leave the console.
        'app  if(go) go.onclick = function(){ window.location.href = kbbAdminLoginUrl(); };',

        // ── TOLD AFTER THE FACT (window.open must happen inside the click) ──
        "app  window.open(url, '_blank', 'noopener');",      // bulk documents
        // ── GATED BEFORE (these replace the whole console) ─────────────────
        'app  window.location.href = url;',                  // orders export
        "app  window.open(url, '_blank', 'noopener');",      // the four order documents
        'app  window.location.href = url;',                  // customers export
        'app  window.location.href = url;',                  // reviews export
        'app  window.location.href = url;',                  // catalogue export
        // ── TOLD AFTER THE FACT, and the only one with a handle to close ───
        "app  try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }",
        // ── THE SIGN OUT, after its own POST ───────────────────────────────
        "app  location.href=base+'/login';",

        // ══ AND THE THREE IN PARTIALS, WHICH NO EARLIER SCAN SAW ═══════════
        //
        // The Instagram handshake's BLOCKED-POPUP FALLBACK. This used to
        // prevent nothing, so the browser followed the anchor's href in this
        // tab and a dead session took the whole screen with it. askThenGo()
        // prevents the default, asks, and then performs the same navigation --
        // and FAILS OPEN, so only a confirmed dead session stops it. A button
        // that does nothing is the failure that screen is most careful about.
        'instagram-screen  window.location.href = url;',
        // The popup itself, told after the fact because window.open has to
        // happen inside the click. It is the one navigation in the console that
        // keeps a handle, so the dead window is closed rather than left up.
        "instagram-screen  win = window.open(url, 'kbb-instagram-oauth',",
        // ▲ THE TWELFTH, and the reason this case reads the partials at all:
        // Reviews -> Reviews.io -> Export replaced the whole console, and three
        // scans of admin/app.blade.php never saw it.
        'reviews-io-screen  window.location.href = exportUrl();',
    ], 'the set of navigations in the admin console has changed');
});

it('derives the sign-in address from where the browser already is, never from a setting', function () {
    /*
     * CLAUDE.md rule 5: a URL from a setting is scheme-checked before it becomes
     * an href. This one is not from a setting at all -- the console is SERVED AT
     * the secret admin path, so window.location.pathname already carries it and
     * reading it back discloses nothing that the address bar does not. It is the
     * same derivation fixAdminApiUrl() has always used.
     *
     * And it is never interpolated into markup: it is assigned to
     * window.location.href and nowhere else, so no escaping question arises.
     *
     * MUTATION NOTE. Make kbbAdminLoginUrl() return a value read out of a
     * settings payload and the first expectation is red. RUN.
     */
    $app = secConsole();

    expect($app)->toContain(
        "  function kbbAdminLoginUrl(){\n"
        ."    return window.location.pathname.replace(/\\/+\$/,'') + '/login';\n"
        ."  }"
    );

    /* Declared once and CALLED once -- the sign-in button in the modal. Counted
       with the semicolon, because the declaration line contains the bare name
       too and would make a missing call read as a present one. */
    expect(substr_count($app, 'kbbAdminLoginUrl();'))->toBe(1);
});

it('signs the owner out of the console he is actually standing in', function () {
    /*
     * ▲ FOUND WHILE MEASURING THE GATE, AND THE WORST OF THE SET.
     *
     * The handler posted to a literal '/admin/logout' and navigated to a literal
     * '/admin/login'. Both are dead the moment the owner moves his admin path,
     * which routes/web.php enforces on purpose so the old address cannot
     * announce the new one -- so he was shown a blank 404 AND LEFT SIGNED IN.
     *
     * MUTATION NOTE. Put either literal back and the matching expectation is
     * red. RUN.
     */
    $app = secConsole();

    expect(substr_count($app, "fetch('/admin/logout'"))->toBe(0, 'sign out still posts to a literal /admin/logout')
        ->and(substr_count($app, "location.href='/admin/login';"))->toBe(0, 'sign out still navigates to a literal /admin/login')
        ->and(substr_count($app, "var base=window.location.pathname.replace(/\\/+\$/,'');"))->toBe(1)
        ->and(substr_count($app, "fetch(base+'/logout'"))->toBe(1)
        ->and(substr_count($app, "location.href=base+'/login';"))->toBe(1);
});

it('is the server that makes the literal sign-out dead, and this is it', function () {
    /*
     * The half of the sign-out defect that can be asserted against the
     * APPLICATION rather than against a string in a Blade file: once the admin
     * path moves, the old addresses 404 by design.
     *
     * This is what turns "the console posts to /admin/logout" from a tidiness
     * complaint into a live session nobody ended.
     *
     * ▲ AND THIS CASE IS GREEN BOTH WAYS, deliberately. It asserts nothing about
     * the console -- it is the SERVER fact that turns "the button posts to a
     * literal /admin/logout" from a tidiness complaint into a live session
     * nobody ended, and it belongs beside the case above rather than in a
     * comment.
     *
     * MUTATION NOTE, and the FIRST DRAFT OF THIS CASE FAILED IT. Written against
     * the route table it was vacuous: the suite runs on the default admin path,
     * where `/admin/logout` and `<admin path>/logout` are the same string, so
     * moving the route to a literal left the case GREEN. Rewritten to read
     * routes/web.php, three mutations are red and were run:
     *
     *   Route::post('/admin/logout', ...)  instead of '/' . $adminPath . ...
     *       -> red, "Failed asserting that 0 is greater than 0"
     *   delete the `Route::any('/admin/{any?}', fn () => abort(404))` line
     *       -> red
     *   drop the session invalidation from Admin\AdminAuthController::logout()
     *       -> red, auth('admin')->check() is still true afterwards
     */
    /*
     * READ FROM THE SOURCE, not from the route table. The suite runs on the
     * DEFAULT admin path, where '/admin/logout' and '<admin path>/logout' are
     * the same string -- so a route-table assertion here is vacuous, which is
     * not a guess: moving the route to a literal '/admin/logout' left this case
     * green until it was written this way.
     */
    $web = file_get_contents(base_path('routes/web.php'));

    foreach (['login', 'logout'] as $leg) {
        expect(substr_count($web, "'/' . \$adminPath . '/".$leg."'"))
            ->toBeGreaterThan(0, "routes/web.php does not build the admin {$leg} address from \$adminPath");
    }

    // And the old addresses are shut, which is what makes a literal fatal
    // rather than merely wrong.
    expect($web)->toContain("Route::any('/admin/{any?}', fn () => abort(404))->where('any', '.*');");

    $path = trim(AdminPathService::current(), '/');

    // And it really does end the session, which is the thing the console was
    // failing to reach.
    $admin = AdminUser::create([
        'name' => 'Signing Out', 'email' => 'signout-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');
    expect($this->getJson('/admin-api/stats')->status())->toBe(200);

    $this->post('/'.$path.'/logout');

    expect(auth('admin')->check())->toBeFalse('POST <admin path>/logout did not end the session');
});

it('tells the Catalog screen which failure it was, instead of blaming the route cache for all of them', function () {
    /*
     * ── TASK 2 ─────────────────────────────────────────────────────────────
     *
     * cpLoad()'s catch printed ONE sentence for every refusal: "If this is a
     * fresh deployment, the Catalog -> Products routes may not be wired into
     * routes/web.php yet." For an EXPIRED SESSION that sends the owner to
     * Store -> Cache to clear a route cache that is perfectly healthy, while the
     * one thing that would fix it -- signing in again -- is never mentioned.
     *
     * Every other screen in this console that carries that sentence already
     * branches on `status === 404` before printing it; this one did not. Counted
     * across resources/views/admin: 38 files carry it, 37 guard it, and the
     * Catalog list was the only hole. The next case pins that.
     *
     * MUTATION NOTE. Delete the 401/419 branch from block 9 and the first
     * expectation is red; delete the `e.status === 404` condition and the third
     * is. RUN -- both.
     */
    $app = secConsole();

    expect($app)->toContain("if(e && (e.status === 401 || e.status === 419)){")
        ->and($app)->toContain("'Your session has ended, so this list could not be loaded.</p>' +")
        ->and($app)->toContain("(e && e.status === 404")
        // and the route sentence survives, for the fault it was written for
        ->and(substr_count($app, 'Catalog → Products routes may not be wired into routes/web.php yet.'))->toBe(1);
});

it('lets no screen send the owner to clear a route cache over a dead session', function () {
    /*
     * THE SWEEP THE CATALOG HOLE ASKED FOR. Thirty-eight files under
     * resources/views/admin carry the "clear the route cache" remedy. A 401 must
     * not be able to reach any of them: a route cache is not what is wrong, and
     * Store -> Cache is a wasted trip that leaves the owner still signed out.
     *
     * The rule each one follows is the same: the sentence is printed only under
     * a test of the status against 404. Thirty-seven already did; the Catalog
     * list is the thirty-eighth and block 9 is its fix.
     *
     * NOT `->not->toContain` ANYTHING: this counts the guarded sites and pins
     * the number, so a screen added later without the guard fails here naming
     * its own file.
     *
     * MUTATION NOTE. Remove the `e.status === 404` test from any one of them --
     * banners-screen.blade.php is the shortest -- and this is red naming that
     * file. RUN.
     */
    $files = array_merge(
        glob(resource_path('views/admin/partials/*.blade.php')),
        [resource_path('views/admin/app.blade.php')]
    );

    $sentence = '/route cache|not registered on this server yet|compiled route table yet|stale compiled route cache|routes may not be wired into/i';
    $guard = '/(?:status|st|code)\s*===?\s*404|404\s*===?\s*(?:e\.status|st)/';

    $unguarded = [];

    foreach ($files as $file) {
        $lines = explode("\n", file_get_contents($file));

        foreach ($lines as $i => $line) {
            if (! preg_match($sentence, $line)) {
                continue;
            }

            // Comment prose about the remedy is not the remedy.
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '/*') || ! str_contains($line, "'")) {
                continue;
            }

            $window = implode("\n", array_slice($lines, max(0, $i - 14), 16));

            if (! preg_match($guard, $window)) {
                $unguarded[] = basename($file).':'.($i + 1).'  '.trim($line);
            }
        }
    }

    expect($unguarded)->toBe([], "a screen prints the route-cache remedy without checking for a 404:\n".implode("\n", $unguarded));
});

it('keeps the handover document and the applied console in step', function () {
    /*
     * ▲ THE GUARD ON THE HANDOVER ITSELF.
     *
     * docs/SEC-ADMIN-APP-BLOCKS.md is what the integrator applies BY HAND, and
     * the failure it can produce is silent: another lane edits one of these
     * lines, the document still quotes the old one, and the integrator applies
     * a block that no longer matches — or worse, matches something adjacent.
     *
     * So every REPLACEMENT the document quotes must appear in the console
     * exactly once once it is applied. This is red in this lane's worktree,
     * green afterwards, and stays a real guard: it fails the day the console and
     * the document disagree.
     *
     * `python3 tools/sec-apply-blocks.py --check` is the other half — it proves
     * the document and the script carry the same strings, and refuses if any
     * ANCHOR is not unique in the console as it stands today.
     *
     * MUTATION NOTE. Change one character inside any ``` Replacement ``` fence
     * in the document and this is red naming that block. RUN.
     */
    $doc = file_get_contents(base_path('docs/SEC-ADMIN-APP-BLOCKS.md'));
    $app = secConsole();

    // Every fence that follows a "**Replacement:**" heading.
    preg_match_all(
        "/\\*\\*Replacement:\\*\\*\n\n```\n(.*?)\n```\n/s",
        $doc,
        $matches
    );

    expect($matches[1])->toHaveCount(10, 'the document no longer carries ten replacements');

    foreach ($matches[1] as $i => $replacement) {
        expect(substr_count($app, $replacement))->toBe(
            1,
            'block '.($i + 1)." of docs/SEC-ADMIN-APP-BLOCKS.md is not in the console exactly once"
        );
    }
});

it('gates the two navigations in partials, each in the shape its own screen needs', function () {
    /*
     * ── THE TWELFTH NAVIGATION, AND THE TWO IN THE INSTAGRAM HANDSHAKE ─────
     *
     * These are NOT delivered as blocks: neither file is the integrator's, so
     * this lane writes them and these assertions are green here and stay green.
     *
     * REVIEWS -> REVIEWS.IO -> EXPORT is the same shape as the four in
     * app.blade.php -- `window.location.href` replaces the whole document -- so
     * it is gated BEFORE, with an await, and the navigation itself is unchanged.
     *
     * THE INSTAGRAM HANDSHAKE has two, and they need different answers:
     *
     *   the popup           told AFTER the fact, because window.open must
     *                       happen inside the click; the handle is kept, so the
     *                       dead window is closed rather than left up.
     *   the FALLBACK        window.open returned null, so the browser was about
     *                       to follow the anchor's href IN THIS TAB. The default
     *                       is prevented and askThenGo() performs the same
     *                       navigation after asking.
     *
     * MUTATION NOTE. Drop the `await downloadOk()` guard from
     * reviews-io-screen.blade.php and the first expectation is red; delete the
     * `askThenGo(href)` line from instagram-screen.blade.php and the third is.
     * RUN -- both.
     */
    $rio = file_get_contents(resource_path('views/admin/partials/reviews-io-screen.blade.php'));
    $ig = file_get_contents(resource_path('views/admin/partials/instagram-screen.blade.php'));

    expect(substr_count($rio, 'if (!(await downloadOk())) return;'))->toBe(1)
        ->and(substr_count($rio, 'x.onclick = async function(){'))->toBe(1)
        ->and(substr_count($rio, 'async function downloadOk(){'))->toBe(1);

    expect(substr_count($ig, 'askThenGo(href);'))->toBe(1)
        ->and(substr_count($ig, 'tellIfSessionEnded(href, win);'))->toBe(1)
        ->and(substr_count($ig, 'async function sessionVerdict(url) {'))->toBe(1);
});

it('lets the Instagram fallback fail open, so a probe cannot make a dead button', function () {
    /*
     * ▲ THE ONE THING THAT MUST NOT GO WRONG HERE, and the screen's own comment
     * says why: "Written the other way round -- preventDefault() first, then try
     * to open -- a blocked popup is a button that does nothing at all, with no
     * way for the owner to tell that from a broken one."
     *
     * This lane now DOES prevent the default on that path -- it has to, or the
     * question cannot be asked in front of the navigation -- so the guarantee
     * has to come from somewhere else. It comes from askThenGo() navigating on
     * EVERYTHING except a positively identified dead session: a 403, a 500, a
     * dropped connection and a probe that never answers all still go exactly
     * where the anchor pointed.
     *
     * Asserted on the shape of the function, because the alternative is a
     * browser test of a blocked popup, which no driver will reliably produce.
     *
     * MUTATION NOTE. Change askThenGo()'s test to `!== 'ok'` -- the obvious
     * fail-CLOSED spelling -- and this is red on the first expectation. RUN.
     */
    $ig = file_get_contents(resource_path('views/admin/partials/instagram-screen.blade.php'));

    // The refusal is the narrow case; the navigation is the default.
    expect($ig)->toContain(
        "    try {\n"
        ."      if (await sessionVerdict(url) === 'signedout') {\n"
        ."        banner = { ok: false, text: OAUTH_SESSION_GONE };\n"
        ."        render();\n"
        ."        return;\n"
        ."      }\n"
        ."    } catch (e) { /* fall through to the navigation the anchor would have made */ }\n"
        ."\n"
        ."    window.location.href = url;"
    );

    /*
     * And sessionVerdict() never throws, or askThenGo() would never reach the
     * navigation at all on a network failure.
     *
     * ▲ THIS NEEDLE WAS `"    } catch (e) {\n"` AND IT ASSERTED NOTHING.
     * tools/plc-needle-scan.sh counted it SIX TIMES in this one file: the
     * screen is full of `} catch (e) {`, so the expectation was green whether
     * or not sessionVerdict() had a catch at all, and deleting the one it was
     * written about would not have moved it. Found by running Lane PLC's survey
     * over this lane's own files, which is round 4's third task.
     *
     * The whole catch BLOCK, counted, is the honest form: it names the comment
     * inside it, so nothing else in the file can satisfy it.
     */
    expect(substr_count($ig,
        "    } catch (e) {\n"
        ."      /* The request never reached a server. Reported as 'other' and not as a\n"
        ."         dead session: \"sign in again\" is the wrong remedy for a dropped\n"
        ."         connection. */\n"
        ."      return 'other';\n"
        ."    }\n"
    ))->toBe(1, 'sessionVerdict() no longer swallows a network failure');
});

it('does not let the gate closing the popup read as the owner abandoning the handshake', function () {
    /*
     * ▲ FOUND BY MEASURING THE POPUP PATH, NOT BY READING IT.
     *
     * The gate closes the handshake window when the session is dead. That close
     * is indistinguishable, to watchPopup(), from the owner closing it himself
     * — so afterOauth() ran, called load(), was refused with the same 401, and
     * the sentence left on screen was "Content → Instagram could not be loaded"
     * instead of the one naming the fault.
     *
     * MEASURED, on the preview, with the session dropped and the popup allowed
     * to open (storage/sec-logs/ig-popup-probe.cjs):
     *
     *   before this flag   tabsAtPeak 1 -> 1, stillOnConsole true, said: NULL
     *                      — no .igs-note on the screen at all
     *   after              said: "Your session has ended, so Instagram could not
     *                      be opened. Sign in again and press Configure now …"
     *
     * THE ORDER MATTERS AND IS ASSERTED: watchPopup() polls every 700ms, so the
     * flag has to be up BEFORE win.close(), not after.
     *
     * MUTATION NOTE. Delete the `if (oauthClosedBySessionGate)` early return
     * from watchPopup() and the second expectation is red; move the flag to
     * after `win.close()` and the third is. RUN — both, and the first was run
     * as the real defect above.
     */
    $ig = file_get_contents(resource_path('views/admin/partials/instagram-screen.blade.php'));

    expect(substr_count($ig, 'var oauthClosedBySessionGate = false;'))->toBe(1);

    expect($ig)->toContain(
        "        if (oauthClosedBySessionGate) {\n"
        ."          oauthClosedBySessionGate = false;\n"
        ."          return;\n"
        ."        }\n"
        ."\n"
        ."        afterOauth();"
    );

    expect($ig)->toContain(
        "      oauthClosedBySessionGate = true;\n"
        ."      if (win) { try { win.close(); } catch (e) {} }"
    );
});
