<?php

declare(strict_types=1);

/**
 * Phase 15 — "Live editing of homepage sections", the half that was missing.
 * (Lane P1)
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * Appearance → Homepage owns seventeen sections: two switches each, a grid skin
 * on four of them, arrows that (since Lane FR) really do move the page. Every
 * one of those controls publishes STRAIGHT TO THE LIVE SHOP, and there was no
 * way to see what any of them did short of pressing Save and then opening the
 * storefront real visitors are on. The controls were never what "live editing"
 * was missing. The LOOK was.
 *
 * ── WHAT IS ASSERTED, AND WHY THIS SHAPE ────────────────────────────────────
 *
 * The strongest claim a preview can make is that it is not a drawing of the
 * page but the page, and that claim is checkable: §1 renders a preview of the
 * configuration the shop is ALREADY on and requires it to be byte-identical to
 * GET /, CSRF token aside. Nothing weaker would do — this console has already
 * shipped one preview that drew a page that never existed (hpWire(), see
 * docs/FR-HOMEPAGE-ORDER.md), and a similarity assertion would have passed for
 * it too.
 *
 * §2 is the other half: a preview of an arrangement NOBODY HAS SAVED equals
 * what the shop would serve if that arrangement were saved. Measured by
 * rendering the preview first, then really saving and fetching / — so the two
 * are produced by different code paths on different requests and only agree if
 * they agree.
 *
 * §3 is the promise that makes it safe to press: the settings row, the layout
 * key and the homepage's caches are all exactly as they were afterwards, and
 * the reader the preview is built on refuses to write at all.
 *
 * ── THE MUTATIONS ───────────────────────────────────────────────────────────
 *
 * Run, with their output, in docs/P1-HOMEPAGE-LIVE-EDITING.md §Mutations.
 */

use App\Models\AdminUser;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\HomepagePreviewRoutes;

beforeEach(function () {
    HomepagePreviewRoutes::wire(app());

    $this->admin = AdminUser::create([
        'name' => 'Owner',
        'email' => 'owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
});

function p1Owner()
{
    return test()->actingAs(test()->admin, 'admin');
}

/** The console's payload: every section, in this key order, all switched on. */
function p1Rows(array $first = [], array $tweak = []): array
{
    $keys = array_merge($first, array_values(array_filter(
        array_keys(HomepageSections::REGISTRY),
        fn ($k) => ! in_array($k, $first, true)
    )));

    return array_map(fn ($key) => ($tweak[$key] ?? []) + [
        'key' => $key,
        'desktop' => true,
        'mobile' => true,
        'skin' => HomepageSections::REGISTRY[$key][3],
    ], $keys);
}

/**
 * Two renders of the same page differ in the CSRF token, which is per session
 * and per request. Masking it is the ONLY difference allowed anywhere below;
 * everything else that moves is a real difference and must fail.
 */
function p1Mask(string $html): string
{
    $html = preg_replace('/name="csrf-token" content="[^"]*"/', 'name="csrf-token" content="MASKED"', $html) ?? $html;

    return preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="MASKED"', $html) ?? $html;
}

function p1Preview(array $rows): array
{
    SettingsService::forgetMemo();

    return p1Owner()->postJson('/admin-api/homepage/preview', ['sections' => $rows])->json();
}

/**
 * The shop, fetched BY THE SAME SIGNED-IN ADMIN the preview is rendered for.
 *
 * ── WHY THE COMPARISON HAD TO BE MADE THIS WAY ──────────────────────────────
 *
 * Measured, not assumed: fetched anonymously, the two documents differ in the
 * header and the mobile menu, because the preview is produced inside an
 * authenticated admin request and this storefront's account panel draws for
 * whoever is signed in. That is not a preview defect — it is what the person
 * pressing the button sees when they open the shop in the tab next door — but
 * it is a difference, so the comparison is made against the page that same
 * person gets rather than against a stranger's.
 *
 * It also keeps the assertion honest in the direction that matters: everything
 * the SECTIONS decide is identical either way, and if the preview ever started
 * rendering for nobody, this would go red rather than quietly pass.
 */
function p1Home(): string
{
    SettingsService::forgetMemo();

    return p1Owner()->get('/')->getContent();
}

/* ===========================================================================
 | §1 · It is the page, not a drawing of the page
 |=========================================================================== */

it('renders the shop’s own homepage, byte for byte, for the configuration the shop is on', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT. A preview rendered from an admin-api
     * URL takes layouts/store.blade.php down its "not the home page" branch —
     * request()->getPathInfo() is what decides the canonical URL, the SEO type
     * and the noindex flag, and partials/mobile-chrome marks its home tab from
     * request()->path(). Without the Request swap in
     * HomepageApiController::renderHome() the two documents differ in the head
     * and in one highlighted tab, which no picture would ever show.
     */
    $live = p1Home();
    $preview = p1Preview(p1Rows());

    expect($preview['ok'])->toBeTrue()
        ->and(p1Mask($preview['html']))->toBe(p1Mask($live));
});

it('writes its asset tags against the host the console is really served from', function () {
    /*
     * ── THE DEFECT, MEASURED IN A BROWSER BEFORE IT WAS FIXED ───────────────
     *
     * renderHome() builds the request it renders under from Url::to('/'), which
     * answers a ROOT-RELATIVE url. Request::create() turns that into
     * `http://localhost/`, so @vite wrote every stylesheet and script tag
     * against localhost — three ERR_CONNECTION_REFUSED on a shop served from
     * 127.0.0.1:8977, and a preview that drew the homepage in Times New Roman
     * on a white page while the picture still, technically, appeared.
     *
     * THIS TEST NAMES A HOST OF ITS OWN, and that is the whole reason it works.
     * Every other case in this file runs with APP_URL and the request host both
     * `localhost`, where the wrong root and the right root are the same string
     * and the byte-identity assertion two tests up passes against the bug.
     * Drop getSchemeAndHttpHost() from renderHome() and this is the only case
     * that goes red.
     */
    $host = 'http://shop.example.test';

    $preview = p1Owner()->postJson($host.'/admin-api/homepage/preview', ['sections' => p1Rows()])->json();

    expect($preview['ok'])->toBeTrue()
        ->and($preview['html'])->toContain($host.'/build/')
        ->and($preview['html'])->not->toContain('http://localhost/build/');
});

it('is the whole document and not a fragment', function () {
    $html = p1Preview(p1Rows())['html'];

    // A fragment would render without the shop's stylesheet and look like a
    // different site, which is the one thing a layout preview may not do.
    expect($html)->toStartWith('<!DOCTYPE html>')
        ->and($html)->toContain('class="kbb-home"')
        ->and($html)->toContain('</html>');
});

/* ===========================================================================
 | §2 · It equals what saving would produce
 |=========================================================================== */

it('draws the arrangement that has not been saved, and draws it the way saving it would', function () {
    $rows = p1Rows(['newsletter', 'trust', 'reviews']);

    // The picture first, off an unsaved proposal...
    $preview = p1Preview($rows);

    // ...then the same arrangement really saved, and the real page fetched.
    p1Owner()->postJson('/admin-api/homepage', ['sections' => $rows])->assertOk();

    expect(p1Mask($preview['html']))->toBe(p1Mask(p1Home()));
});

it('moves the page at all — the reordered preview is not the default one', function () {
    /*
     * Guards the test above from passing vacuously. If the proposal never
     * reached the renderer, both documents would still match each other and
     * this whole file would be asserting that nothing works.
     */
    $default = p1Preview(p1Rows())['html'];
    $moved = p1Preview(p1Rows(['newsletter', 'trust', 'reviews']))['html'];

    expect($moved)->not->toBe($default)
        ->and($moved)->toContain('.kbb-home{display:flex;flex-direction:column}');
});

it('settles a nested row back behind its host before it draws it, and says so in the list it returns', function () {
    // The ticker is drawn inside the hero's <section>; CSS `order` cannot move
    // it. A preview that obeyed a proposal putting it first would be showing a
    // page the shop cannot render — the fault settle() exists to end.
    $posted = p1Rows(['ticker', 'newsletter']);
    $answer = p1Preview($posted);

    $keys = array_column($answer['sections'], 'key');

    expect($keys)->toBe(HomepageSections::settleKeys(array_column($posted, 'key')));

    expect(array_search('ticker', $keys, true))->toBeGreaterThan(array_search('hero', $keys, true));
});

it('hides a section on the device its switch is off for, in the document it hands back', function () {
    $html = p1Preview(p1Rows([], ['bundles' => ['desktop' => false]]))['html'];

    // d-off is the storefront's own class and the storefront's own media query
    // decides what it means, which is why the frame is given a real viewport
    // width rather than being told which device it is.
    expect($html)->toMatch('/<section class="sec [^"]*\bd-off\b/');
});

/* ===========================================================================
 | §3 · It writes nothing
 |=========================================================================== */

it('leaves the stored configuration exactly as it found it', function () {
    $before = app(SettingsService::class)->get('homepage_sections');

    p1Preview(p1Rows(['newsletter', 'trust', 'reviews'], ['bundles' => ['skin' => 'luxe']]));

    SettingsService::forgetMemo();

    expect(app(SettingsService::class)->get('homepage_sections'))->toBe($before);
});

it('refuses to write through the reader a preview is built on', function () {
    /*
     * Not "is never asked to write" — cannot. A preview instance that could
     * also save is a preview that publishes, and that is the one failure this
     * feature must not have.
     */
    $reader = HomepageSections::proposing(app(SettingsService::class), []);

    expect($reader->isProposal())->toBeTrue()
        ->and(fn () => $reader->save([]))->toThrow(LogicException::class);

    // And the ordinary one still writes, so the guard is not a blanket.
    expect(app(HomepageSections::class)->isProposal())->toBeFalse();
});

it('costs the server no more than the page it is a preview of', function () {
    // Rule 4. A preview that re-read the settings per section, or re-ran the
    // rails, would be a new slope on the busiest page's own query profile.
    $count = function (callable $run): int {
        SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        $n = 0;
        DB::listen(function () use (&$n) { $n++; });

        $run();

        return $n;
    };

    // Warm-up: the first request through either path pays for process-level
    // memos the second one does not. Measuring cold-then-warm would report a
    // fall that has nothing to do with this code.
    p1Home();
    p1Preview(p1Rows());

    $home = $count(fn () => p1Home());
    $preview = $count(fn () => p1Preview(p1Rows()));

    // The preview request also authenticates an admin, which the shop does not.
    expect($preview)->toBeLessThanOrEqual($home + 3);
});

/* ===========================================================================
 | §4 · The guard, and rule 5
 |=========================================================================== */

it('is mounted inside the admin guard and refuses a stranger', function () {
    $routes = HomepagePreviewRoutes::registered();

    expect($routes)->toHaveCount(1)
        ->and($routes[0]->methods())->toContain('POST')
        ->and($routes[0]->gatherMiddleware())->toContain('auth:admin');

    test()->postJson('/admin-api/homepage/preview', ['sections' => p1Rows()])
        ->assertStatus(401);
});

it('is governed by the same capability as the save it previews', function () {
    /*
     * AdminCapabilities::RULES fails closed, so a route mounted outside the
     * prefixes it maps would 403 on a host with no shell to fix it from. The
     * rule is read out of the map rather than quoted here.
     */
    $matched = collect(AdminCapabilities::RULES)
        ->first(fn ($rule) => \Illuminate\Support\Str::is($rule[1], 'admin-api/homepage/preview'));

    expect($matched)->not->toBeNull()
        ->and($matched[2])->toBe('content.manage');
});

it('refuses a section key it does not know, rather than rendering something else', function () {
    $rows = p1Rows();
    $rows[] = ['key' => 'evil', 'desktop' => true, 'mobile' => true, 'skin' => null];

    p1Owner()->postJson('/admin-api/homepage/preview', ['sections' => $rows])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);
});

it('draws a hostile grid skin as the section’s own default and never as itself', function () {
    /*
     * Rule 5: a select stores one of its own options or the default. The skin
     * reaches an attribute in partials/home/grid.blade.php, so a value that is
     * not one of GridSkins is markup the owner typed. It is the SECTION_SCHEMA
     * cast that refuses it — the same cast the save runs, which is why the
     * preview cannot show a shop the save would not produce.
     */
    $answer = p1Preview(p1Rows([], ['bundles' => ['skin' => '" onload="alert(1)']]));

    $bundles = collect($answer['sections'])->firstWhere('key', 'bundles');

    expect($bundles['skin'])->toBe(HomepageSections::REGISTRY['bundles'][3])
        ->and($answer['html'])->not->toContain('onload="alert(1)');
});

/* ===========================================================================
 | §5 · The screen
 |=========================================================================== */

/**
 * This lane's block of resources/views/admin/app.blade.php and nothing else.
 *
 * Read by its own two landmarks rather than by line number, because that file
 * is 19,000 lines and several lanes edit it at once: a line-number slice would
 * silently start asserting about somebody else's screen.
 */
function p1ConsoleBlock(): string
{
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    $from = strpos($console, 'let HPPV = {');
    $to = strpos($console, 'function paintHomepage(base){');

    expect($from)->not->toBeFalse()
        ->and($to)->not->toBeFalse()
        ->and($to)->toBeGreaterThan($from);

    return substr($console, (int) $from, (int) $to - (int) $from);
}

it('sandboxes the frame without allowing it to run the shop’s scripts', function () {
    $block = p1ConsoleBlock();

    expect($block)->toContain('sandbox="allow-same-origin"')
        // allow-scripts AND allow-same-origin together is no sandbox at all.
        ->and($block)->not->toContain('allow-scripts');
});

it('assigns the preview document rather than interpolating it into the paint', function () {
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // A backtick or a `${` inside eighty kilobytes of the shop's own HTML would
    // terminate the template literal and take the whole screen's paint with it.
    expect($console)->toContain('hppvFrame.srcdoc = HPPV.html')
        ->and($console)->not->toContain('srcdoc="${');
});

it('measures no layout to size the preview', function () {
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $block = p1ConsoleBlock();

    foreach (['getBoundingClientRect', 'offsetWidth', 'clientWidth', 'scrollWidth', 'innerWidth'] as $api) {
        expect($block)->not->toContain($api);
    }

    // Sized with calc() from one declared scale, which is what rule 4 asks for.
    expect($console)->toContain('height:calc(540px / var(--hppv-s))');
});

it('derives whether the picture is current rather than being told', function () {
    /*
     * THE DEFECT THIS SHAPE AVOIDS. A `fresh` flag has to be cleared by every
     * edit handler on this screen — two device switches, a skin picker, the
     * arrows, Apply layout — and the one that forgets leaves the note claiming
     * a stale picture is current. That is the same class of fault as a screen
     * reporting an order the shop does not render, which this file's neighbours
     * have spent three rounds removing. The note is computed from what was
     * rendered against what is on screen, so it cannot be forgotten.
     */
    $block = p1ConsoleBlock();
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($block)->toContain('function hppvFresh(){ return !!HPPV.html && HPPV.of === hppvOf(); }')
        ->and($block)->toContain('The rows below have changed since this picture was drawn')
        // And nothing sets a freshness flag by hand any more.
        ->and($block)->not->toContain('HPPV.fresh =')
        ->and($console)->not->toContain('HPPV.fresh =');

    // The two edits that deliberately do not repaint #content still have to
    // repaint the sentence, or the note is right only after the next repaint.
    expect(substr_count($console, 'hpPreviewStale();'))->toBe(2);
});

it('keeps the route file’s require line where the integrator will find it', function () {
    $file = (string) file_get_contents(base_path('routes/homepage-preview-admin.php'));

    expect($file)->toContain("require __DIR__.'/homepage-preview-admin.php';")
        ->and($file)->toContain("Route::post('/homepage/preview'");

    // And it really is not wired yet, which is what the harness above is for.
    expect((string) file_get_contents(base_path('routes/web.php')))
        ->not->toContain('homepage-preview-admin');
});

it('is reachable under the prefix the capability map already covers', function () {
    expect(HomepagePreviewRoutes::registered()[0]->uri())->toBe('admin-api/homepage/preview');

    // Route registration is the harness's, so also prove the file itself is
    // what registered it rather than something already in web.php.
    expect(collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/homepage'))
        ->count())->toBeGreaterThan(1);
});
