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
    $lines = explode("\n", secConsole());

    $sites = [];

    foreach ($lines as $line) {
        if (preg_match('/window\.open\s*\(|(?:window\.)?location\.href\s*=/', $line)) {
            $sites[] = trim($line);
        }
    }

    expect($sites)->toBe([
        // ── EXEMPT ────────────────────────────────────────────────────────
        // Store -> Core Updates moving the admin path. The console is being
        // deliberately relocated and the POST that produced this address has
        // just succeeded. UpdateApiController builds it as "'/' . trim($path,
        // '/')" -- exactly one leading slash, so it cannot become a
        // protocol-relative URL pointing off-site.
        'if(r.ok && r.data?.redirect){ toast(r.data.message); setTimeout(()=>{ window.location.href = r.data.redirect; }, 1200); return; }',
        // UNDER the admin path rather than under /admin-api, so GuestRedirect
        // still answers it with the 302 to the login -- which is safe there,
        // because the URL the browser used already carries the secret.
        "window.open(kbbHealthLogUrl(), '_blank', 'noopener');",
        // The line above it awaits an authenticated POST to
        // /admin-api/import/background and returns on a refusal, so the session
        // is proven alive one statement earlier.
        "window.open(impBase()+'/import/background-page','_blank');",
        // The gate's own sign-in button: the one navigation that is SUPPOSED to
        // leave the console.
        'if(go) go.onclick = function(){ window.location.href = kbbAdminLoginUrl(); };',

        // ── TOLD AFTER THE FACT (window.open must happen inside the click) ──
        "window.open(url, '_blank', 'noopener');",          // bulk documents
        // ── GATED BEFORE (these replace the whole console) ─────────────────
        'window.location.href = url;',                      // orders export
        "window.open(url, '_blank', 'noopener');",          // the four order documents
        'window.location.href = url;',                      // customers export
        'window.location.href = url;',                      // reviews export
        'window.location.href = url;',                      // catalogue export
        // ── TOLD AFTER THE FACT, and the only one with a handle to close ───
        "try{ w=window.open(url,'kbbstripe','width=620,height=760'); }catch(e){ w=null; }",
        // ── THE SIGN OUT, after its own POST. ──────────────────────────────
        "location.href=base+'/login';",
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
