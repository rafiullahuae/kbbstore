<?php

declare(strict_types=1);

/**
 * Phase 15 — "Live editing of homepage sections, reusing the settings schemas".
 * (Lane HL)
 *
 * ── WHAT WAS MISSING, AND IT WAS NEVER A CONTROL ────────────────────────────
 *
 * Two halves of this have existed for a release and nothing joined them:
 *
 *   · the PICTURE. POST /admin-api/homepage/preview renders the real storefront
 *     homepage from an arrangement nobody has saved (Lane P1).
 *   · the CONTROLS. App\Services\HomepageSections::SECTION_SCHEMA is the
 *     ModuleSchema description of the three controls a section carries, and
 *     SECTION_POLICY is how they cast — the same triple every other settings
 *     screen in this console is drawn from.
 *
 * What nobody could do was point at a section IN the picture and get THAT
 * section's controls. This file is that seam, and it asserts the three things
 * the seam can get wrong:
 *
 *   §1 the picture is still the page. The only difference between what this
 *      endpoint draws and what /homepage/preview draws is the selection hook,
 *      and the hook never reaches the shop.
 *   §2 the controls are the schema's, not a second list. A field added to
 *      SECTION_SCHEMA arrives on the screen; a section with no product grid
 *      gets no grid control; a hostile skin is the section's own default.
 *   §3 it writes nothing, it is guarded, and the map that joins a section to
 *      its wording cannot go stale in either direction.
 *
 * ── THE MUTATIONS ───────────────────────────────────────────────────────────
 *
 * Every case below carries the mutation that makes it red in its own comment,
 * and every one of them was run.
 */

use App\Http\Controllers\Admin\HomepageApiController;
use App\Models\AdminUser;
use App\Services\HomepageContent;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\GridSkins;
use Tests\Support\HomepageLiveRoutes;
use Tests\Support\HomepagePreviewRoutes;

beforeEach(function () {
    HomepageLiveRoutes::wire(app());
    HomepagePreviewRoutes::wire(app());

    $this->admin = AdminUser::create([
        'name' => 'Owner',
        'email' => 'owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
});

function hlOwner()
{
    return test()->actingAs(test()->admin, 'admin');
}

/** The console's payload: every section, in this key order, all switched on. */
function hlRows(array $tweak = []): array
{
    return array_map(fn ($key) => ($tweak[$key] ?? []) + [
        'key' => $key,
        'desktop' => true,
        'mobile' => true,
        'skin' => HomepageSections::REGISTRY[$key][3],
    ], array_keys(HomepageSections::REGISTRY));
}

function hlLive(array $rows): array
{
    SettingsService::forgetMemo();

    return hlOwner()->postJson('/admin-api/homepage/live', ['sections' => $rows])->json();
}

function hlPreview(array $rows): array
{
    SettingsService::forgetMemo();

    return hlOwner()->postJson('/admin-api/homepage/preview', ['sections' => $rows])->json();
}

/** One section out of the answer's list. */
function hlSection(array $answer, string $key): array
{
    foreach ($answer['sections'] as $row) {
        if ($row['key'] === $key) {
            return $row;
        }
    }

    test()->fail("no section {$key} in the answer");
}

/**
 * Two renders of the same page differ in the CSRF token, which is per session
 * and per request.
 */
function hlMask(string $html): string
{
    $html = preg_replace('/name="csrf-token" content="[^"]*"/', 'name="csrf-token" content="MASKED"', $html) ?? $html;

    return preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="MASKED"', $html) ?? $html;
}

/**
 * The document with its selection hooks taken back out.
 *
 * Applied to BOTH sides of the comparison in §1 rather than to one, because the
 * hook is appended inside a class attribute and the class attribute is where
 * the whitespace lives: `class="sec "` on a shop that has touched nothing
 * becomes `class="sec kbb-pvsec kbb-pvsec-categories"`, and removing the two
 * tokens without normalising leaves a string that differs from the original by
 * a space rather than by a word. Collapsing the whitespace of every class
 * attribute on both documents is what makes "the only difference is the hook" a
 * statement a diff can check instead of a claim.
 */
function hlUnhook(string $html): string
{
    return (string) preg_replace_callback('/class="([^"]*)"/', function (array $m): string {
        $kept = array_filter(
            preg_split('/\s+/', $m[1]) ?: [],
            fn ($c) => $c !== '' && $c !== HomepageSections::SELECT_CLASS
                && ! str_starts_with($c, HomepageSections::SELECT_CLASS.'-')
        );

        return 'class="'.implode(' ', $kept).'"';
    }, $html);
}

/* ===========================================================================
 | §1 · It is still the page, and the hook never reaches the shop
 |=========================================================================== */

it('marks every section in the picture with the hook the screen selects on', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT. Without a hook there is nothing in the
     * document that says which section a click landed in, and the only ways
     * left are the two this project forbids: measuring the elements, or keeping
     * a second list of what the homepage template draws in what order — the
     * fault hpWire() shipped and docs/FR-HOMEPAGE-ORDER.md costed.
     *
     * MUTATION: pass `false` for the third argument of proposing() in
     * HomepageApiController::live(), or drop the annotate branch from
     * HomepageSections::frameClass(), and this is red.
     */
    $html = hlLive(hlRows())['html'];

    foreach (['hero', 'delivery', 'ticker', 'categories', 'bundles', 'newsletter'] as $key) {
        expect($html)->toContain(HomepageSections::SELECT_CLASS.'-'.$key);
    }

    // And the class the screen is told to select on is the class the renderer
    // really emitted, rather than a string the console keeps its own copy of.
    expect(hlLive(hlRows())['select_class'])->toBe(HomepageSections::SELECT_CLASS);
});

it('draws the same document the plain preview draws, hook aside', function () {
    /*
     * The strongest claim a preview can make is that it is not a drawing of the
     * page but the page. HomepagePreviewTest §1 makes it for /homepage/preview
     * by comparing with GET /; this makes it for /homepage/live by comparing
     * with that preview, so the annotation is held to changing NOTHING but the
     * class attributes it adds to.
     *
     * MUTATION: emit the hook from anywhere but frameClass() — an extra
     * attribute, a wrapper element, a style block — and this is red while the
     * case above stays green.
     */
    $rows = hlRows();

    expect(hlUnhook(hlMask(hlLive($rows)['html'])))
        ->toBe(hlUnhook(hlMask(hlPreview($rows)['html'])));
});

it('never marks the shop itself, or the preview that promises to be byte-identical to it', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT, and it is the reason annotation is a
     * parameter rather than a property of every proposal: the hook must be
     * opt-in. A default of true would put two classes on every section of the
     * storefront — StorefrontEnglishUnchangedTest's diff — and would break the
     * preview's byte-identity promise at the same time.
     *
     * MUTATION: default `$annotate` to true in HomepageSections::proposing()
     * and this is red twice over.
     */
    expect(hlOwner()->get('/')->getContent())->not->toContain(HomepageSections::SELECT_CLASS);

    expect(hlPreview(hlRows())['html'])->not->toContain(HomepageSections::SELECT_CLASS);

    // And an ordinary reader — the one the shop resolves — cannot be made to
    // emit one at all.
    expect(app(HomepageSections::class)->classFor('categories'))
        ->not->toContain(HomepageSections::SELECT_CLASS);
});

it('reflects a change nobody has saved, in the picture and in the row it hands back', function () {
    /*
     * The "live" in live editing: the control moves, the picture follows, and
     * the shop does not. d-off is the storefront's own class and its own media
     * query decides what it means, which is why the frame is given a real
     * viewport width rather than being told which device it is.
     *
     * MUTATION: ignore the posted rows and build the reader from the stored
     * configuration, and this is red.
     */
    $before = app(SettingsService::class)->get('homepage_sections');

    $answer = hlLive(hlRows(['bundles' => ['desktop' => false]]));

    expect($answer['html'])->toMatch('/<section class="sec [^"]*\bd-off\b/');
    expect(hlSection($answer, 'bundles')['desktop'])->toBeFalse();

    SettingsService::forgetMemo();

    // ...and nothing was written. A preview that published would be the one
    // failure this feature must not have.
    expect(app(SettingsService::class)->get('homepage_sections'))->toBe($before);
});

/* ===========================================================================
 | §2 · The controls are the schema's
 |=========================================================================== */

it('hands back each section’s controls from its own schema, not from a second list', function () {
    /*
     * THE DEFECT THIS EXISTS FOR. A screen that describes a control a second
     * time is a screen that can come to disagree with the cast that accepts it
     * — docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured the cost of exactly that,
     * four copies of three rules and one defect surviving in all four. So the
     * panel is drawn from ModuleSchema::tabs() over SECTION_SCHEMA and this
     * case holds the payload to the constant rather than to a literal list.
     *
     * MUTATION: write the three fields out by hand in the controller and change
     * one label, or drop SECTION_POLICY from the call, and this is red.
     */
    /*
     * ▲ ONE TAB BECAME TWO, AND THREE FIELDS BECAME FIVE — Lane BG. The owner's
     * Background and Width controls are a second SECTION_TABS group ("How it
     * looks"), and this case is the proof that the claim in the controller's
     * own comment is true: "a field added to SECTION_SCHEMA appears on the
     * screen without a line of the screen changing". Not one line of the live
     * panel was touched for either of them, and they arrive here fully formed
     * — label, help, type and value — because they are drawn by the same
     * ModuleSchema::tabs() call.
     *
     * The loop below is unchanged and is what makes this worth advancing
     * rather than deleting: every field's label and help are still asserted to
     * be the CONSTANT's, so a second list written out in the controller is
     * still red.
     */
    $bundles = hlSection(hlLive(hlRows()), 'bundles');

    expect($bundles['tabs'])->toHaveCount(2);

    $fields = array_merge($bundles['tabs'][0]['fields'], $bundles['tabs'][1]['fields']);

    expect(array_column($fields, 'key'))->toBe(['desktop', 'mobile', 'skin', 'background', 'width'])
        ->and(array_column($fields, 'type'))->toBe(['bool', 'bool', 'skin', 'select', 'select']);

    foreach ($fields as $field) {
        expect($field['label'])->toBe(HomepageSections::SECTION_SCHEMA[$field['key']]['label'])
            ->and($field['help'])->toBe(HomepageSections::SECTION_SCHEMA[$field['key']]['help']);
    }

    // And the second group is the module framework's shape too.
    expect($bundles['tabs'][1]['key'])->toBe('frame')
        ->and(array_keys(HomepageSections::SECTION_TABS))->toBe(['placement', 'frame']);

    // The values are the arrangement that was posted, so the boxes open on what
    // the picture above them is of.
    expect($fields[0]['value'])->toBeTrue()
        ->and($fields[2]['value'])->toBe(HomepageSections::REGISTRY['bundles'][3]);

    // The tab is the module framework's TABS shape too, not a heading this
    // screen invented.
    expect($bundles['tabs'][0]['key'])->toBe(array_key_first(HomepageSections::SECTION_TABS));
});

it('draws no grid control for a section that has no grid', function () {
    /*
     * HomepageSections::castRow() stores `skin => null` for a section with no
     * product grid and Appearance → Homepage has never drawn a picker for one.
     * A panel that offered one would be a control whose value is discarded on
     * the way in — a box with no writer behind it, which is the shape
     * AdminConsoleWriteTokenTest was written after.
     *
     * MUTATION: remove the `unset($schema['skin'])` in
     * HomepageSections::sectionTabs() and this is red.
     */
    $quiz = hlSection(hlLive(hlRows()), 'quiz');

    expect(array_column($quiz['tabs'][0]['fields'], 'key'))->toBe(['desktop', 'mobile']);

    // Stated from the registry rather than assumed about the two keys: every
    // section with a grid gets the control and every section without does not.
    foreach (hlLive(hlRows())['sections'] as $row) {
        $keys = array_column($row['tabs'][0]['fields'], 'key');

        expect(in_array('skin', $keys, true))->toBe(
            (bool) HomepageSections::REGISTRY[$row['key']][2],
            $row['key'].' draws '.($row['has_grid'] ? 'no' : 'a').' grid control and should not'
        );
    }
});

it('draws a hostile grid skin as the section’s own default and never as itself', function () {
    /*
     * Rule 5: a select stores one of its own options or the default. The skin
     * reaches an attribute in partials/home/grid.blade.php, so a value that is
     * not one of GridSkins is markup the owner typed. It is the SECTION_SCHEMA
     * cast that refuses it — the same cast the save runs — which is why the
     * panel cannot show a shop the save would not produce.
     *
     * MUTATION: drop the overrides argument from sectionTabs(), so the cast has
     * no option set to hold the value to, and this is red.
     */
    $answer = hlLive(hlRows(['bundles' => ['skin' => '" onload="alert(1)']]));

    $bundles = hlSection($answer, 'bundles');

    expect($bundles['skin'])->toBe(HomepageSections::REGISTRY['bundles'][3])
        ->and($bundles['tabs'][0]['fields'][2]['value'])->toBe(HomepageSections::REGISTRY['bundles'][3])
        ->and($answer['html'])->not->toContain('onload="alert(1)');
});

it('refuses a section key it does not know, rather than rendering something else', function () {
    $rows = hlRows();
    $rows[] = ['key' => 'evil', 'desktop' => true, 'mobile' => true, 'skin' => null];

    hlOwner()->postJson('/admin-api/homepage/live', ['sections' => $rows])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);
});

/* ===========================================================================
 | §3 · The guard, the writer, and the map that joins the two schemas
 |=========================================================================== */

it('is mounted inside the admin guard and refuses a stranger', function () {
    $routes = HomepageLiveRoutes::registered();

    expect($routes)->toHaveCount(1)
        ->and($routes[0]->uri())->toBe('admin-api/homepage/live')
        ->and($routes[0]->methods())->toContain('POST')
        ->and($routes[0]->gatherMiddleware())->toContain('auth:admin');

    test()->postJson('/admin-api/homepage/live', ['sections' => hlRows()])
        ->assertStatus(401);
});

it('is governed by the same capability as the save it previews', function () {
    /*
     * AdminCapabilities::RULES fails closed, so a route mounted outside the
     * prefixes it maps would 403 on a host with no shell to fix it from. The
     * rule is read out of the map rather than quoted here.
     */
    $matched = collect(AdminCapabilities::RULES)
        ->first(fn ($rule) => \Illuminate\Support\Str::is($rule[1], 'admin-api/homepage/live'));

    expect($matched)->not->toBeNull()
        ->and($matched[2])->toBe('content.manage');
});

it('saves through the writer the sections already had, and that writer still accepts the panel’s rows', function () {
    /*
     * THE POINT OF THE CASE. This tab adds a way to REACH the three controls,
     * not a second way to store them: Save posts the working copy to POST
     * /admin-api/homepage, which has always written them. So this drives the
     * exact payload the panel builds through that endpoint and reads the shop
     * back.
     *
     * MUTATION: have the panel post its rows anywhere else — a writer of its
     * own — and the two stores drift the first time one of them changes.
     */
    $rows = array_map(
        fn (array $r) => ['key' => $r['key'], 'desktop' => $r['desktop'], 'mobile' => $r['mobile'], 'skin' => $r['skin']],
        hlLive(hlRows(['bundles' => ['skin' => 'luxe'], 'quiz' => ['mobile' => false]]))['sections']
    );

    hlOwner()->postJson('/admin-api/homepage', ['sections' => $rows])->assertOk();

    SettingsService::forgetMemo();

    $saved = app(HomepageSections::class)->all();

    expect($saved['bundles']['skin'])->toBe('luxe')
        ->and($saved['quiz']['mobile'])->toBeFalse();
});

it('joins a section to its wording without growing a second box for it', function () {
    /*
     * HomepageApiController::WORDS is the one NEW fact this lane wrote down:
     * which section's words are edited where. A map like that is the fourth
     * thing on this screen that could go stale, so it is held to the two
     * constants it names, in both directions.
     *
     * MUTATION: rename a key in HomepageContent::SCHEMA, point a WORDS entry at
     * a section that is not in REGISTRY, or add a third flat setting to
     * HomepageContent::SCHEMA and forget to claim it, and this is red.
     */
    $claimed = [];

    foreach (HomepageApiController::WORDS as $section => $where) {
        expect(HomepageSections::REGISTRY)->toHaveKey($section);

        if ($where['key'] === null) {
            // The hero's words are a repeater rather than a field, so its tab
            // is the slide editor and there is no single key to focus.
            expect($where['tab'])->toBe('hero')
                ->and(HomepageContent::SLIDE_SCHEMA)->not->toBeEmpty();

            continue;
        }

        expect(HomepageContent::SCHEMA)->toHaveKey($where['key'])
            ->and(HomepageContent::TABS)->toHaveKey($where['tab']);

        // ...and the tab really carries that key, so "Edit the wording" lands
        // on a box that is on the tab it opens.
        expect(HomepageContent::TABS[$where['tab']][2])->toContain($where['key']);

        // (2.60.370) A section may own a whole tab of controls — Big savings
        // bundles owns its carousel tab — and then claims every key on it.
        if (! empty($where['whole_tab'])) {
            array_push($claimed, ...HomepageContent::TABS[$where['tab']][2]);
        } else {
            $claimed[] = $where['key'];
        }
    }

    // Every flat setting this screen owns is claimed by exactly one section.
    expect(array_values(array_unique($claimed)))->toBe($claimed)
        ->and(array_keys(HomepageContent::SCHEMA))->toBe($claimed);
});

it('costs the server no more than the page it is a picture of', function () {
    /*
     * Rule 4. A panel that re-read the settings per section, or rebuilt the
     * schema nineteen times against the database, would be a new slope on the
     * busiest page's own profile. Measured against the preview next door, which
     * renders the same document and hands back the same list without the
     * controls.
     */
    $count = function (callable $run): int {
        SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        $n = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$n) { $n++; });

        $run();

        return $n;
    };

    // Warm-up: the first request through either path pays for process-level
    // memos the second one does not.
    hlPreview(hlRows());
    hlLive(hlRows());

    $preview = $count(fn () => hlPreview(hlRows()));
    $liveRun = $count(fn () => hlLive(hlRows()));

    expect($liveRun)->toBeLessThanOrEqual($preview);
});

/* ===========================================================================
 | §4 · The screen
 |=========================================================================== */

function hlScreen(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/homepage-content-screen.blade.php'));
}

/**
 * The screen with its block comments removed.
 *
 * Risk ▒·31, and it caught this file on its first run: the sandbox case below
 * read "allow-scripts AND allow-same-origin together is no sandbox at all" as a
 * violation, because the sentence explaining why the flag is absent contains
 * the flag. Prose is the hazard a code scan has to be protected from, which is
 * why AdminWritesAreSignedTest strips comments before it scans too.
 */
function hlCode(): string
{
    return (string) preg_replace('#/\\*.*?\\*/#s', '', hlScreen());
}

it('opens the section’s controls from the picture, and draws them with the schema renderer', function () {
    $screen = hlScreen();

    // The click inside the frame selects on the CLASS the server named, and the
    // panel is drawn by field() — the renderer this screen already had — from
    // the payload's own tabs.
    expect($screen)->toContain("t.closest('.' + live.mark)")
        ->and($screen)->toContain("field(f, liveValue(s.key, f.key), 'sec.' + s.key + '.' + f.key, false)")
        // ...and the skin control's options come from the registry the payload
        // carries, because ModuleSchema emits `options: null` for a type whose
        // option set lives elsewhere. Without this branch the picker would be a
        // text box somebody could type a skin name into.
        ->and($screen)->toContain("f.type === 'skin'");
});

it('sandboxes the frame without allowing it to run the shop’s scripts', function () {
    $screen = hlCode();

    expect($screen)->toContain('sandbox="allow-same-origin"')
        // allow-scripts AND allow-same-origin together is no sandbox at all.
        ->and($screen)->not->toContain('allow-scripts');
});

it('assigns the preview document rather than building it into the paint', function () {
    expect(hlScreen())->toContain('f.srcdoc = live.html');
});

it('measures no layout to size the preview or to select a section', function () {
    /*
     * Rule 4, and it is the whole reason the selection is a class: an outline
     * drawn by a stylesheet inside the frame follows its element at either
     * viewport and after every redraw, with nothing to recompute.
     *
     * MUTATION: size the stage from an offsetWidth, or find the clicked section
     * by comparing rectangles, and this is red.
     */
    $screen = hlCode();

    foreach (['getBoundingClientRect', 'offsetWidth', 'clientWidth', 'scrollWidth', 'innerWidth', 'getComputedStyle'] as $api) {
        expect($screen)->not->toContain($api);
    }

    // Sized with calc() from one declared scale, which is what rule 4 asks for.
    expect($screen)->toContain('height:calc(520px / var(--hpe-s))');
});

it('talks to three endpoints and every one of them already existed', function () {
    /*
     * THE POINT OF THE CASE, and it is rule 5 as much as tidiness: a tab that
     * grew a writer of its own would be a second way to store three values that
     * already have one, and the two would drift the first time either changed.
     * So the live tab reads GET /admin-api/homepage (the section list and the
     * skin registry), draws through POST /admin-api/homepage/live, and SAVES
     * through POST /admin-api/homepage — the endpoint Appearance → Homepage has
     * always saved these three values through.
     *
     * MUTATION: add a fourth fetch anywhere in the live block and this is red.
     */
    $code = hlCode();

    expect(substr_count($code, 'fetch(liveBase()'))->toBe(
        3,
        'the live tab should reach exactly three endpoints: the section list, the live render, and the save it has always had'
    );

    expect($code)->toContain("fetch(liveBase() + '/live'")
        // The save posts the working copy to the sections endpoint itself.
        ->and($code)->toContain('body: JSON.stringify({sections: live.rows})');
});

it('signs every write it makes with the token this console issues', function () {
    /*
     * The console has no csrf-token meta tag — AdminConsoleWriteTokenTest's
     * header records the three hours that fact cost — so every POST from a
     * screen has to carry X-XSRF-TOKEN from the cookie. This screen's two new
     * POSTs are swept by AdminWritesAreSignedTest along with every other
     * partial; this states it for the two by name so a future edit that drops
     * one fails here with the reason attached.
     */
    $screen = hlScreen();

    expect(substr_count($screen, "'X-XSRF-TOKEN': window.uToken ? window.uToken() : ''"))->toBe(2);
});

it('is wired into routes/web.php, inside the group whose capability covers it', function () {
    /*
     * THE FINISHED STATE, PINNED — never the absence of the require.
     *
     * The lane that wrote this file could not edit routes/web.php (CLAUDE.md
     * makes it the integrator's), so the route ships in its own file with the
     * require line in its header. The assertion that the require is NOT there
     * would be correct in the lane's worktree and would go red the moment the
     * integrator did the one thing the lane asked for — it has cost this
     * project three rounds. So this pins the state that can actually regress:
     * required exactly once. Zero is the "built, never wired up" shape this
     * repository keeps finding; two registers the route twice.
     *
     * IT IS THEREFORE RED UNTIL THE INTEGRATOR ADDS THE LINE. That is the pin
     * doing its job, and the message below is the instruction.
     */
    $file = (string) file_get_contents(base_path('routes/homepage-live-admin.php'));

    expect($file)->toContain("Route::post('/homepage/live'");

    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/homepage-live-admin.php';"))->toBe(
        1,
        "routes/web.php does not require homepage-live-admin.php exactly once. Add\n"
        ."    require __DIR__.'/homepage-live-admin.php';\n"
        .'inside the admin-api group, directly under the homepage-preview require.'
    );
});

it('ships the cache-clearing migration the new route needs', function () {
    /*
     * On this host a package that adds a route is INERT until the compiled
     * route cache is cleared, and a package that changes a Blade partial is
     * inert until the compiled views are. One migration covers both; shipping
     * neither leaves the tab posting to a 404.
     */
    $migration = base_path('database/migrations/2027_03_21_000000_clear_caches_homepage_live.php');

    expect(is_file($migration))->toBeTrue();

    $source = (string) file_get_contents($migration);

    expect($source)->toContain('bootstrap/cache/routes-*.php')
        ->and($source)->toContain('framework/views/*.php');
});

it('offers every grid skin the registry carries, and nothing else', function () {
    // The picker is drawn from the payload's `skins`, which is
    // GET /admin-api/homepage's own list — the one place GridSkins::ALL is
    // published to a screen.
    $skins = hlOwner()->getJson('/admin-api/homepage')->json('skins');

    expect(array_column($skins, 'key'))->toBe(array_keys(GridSkins::ALL));
});
