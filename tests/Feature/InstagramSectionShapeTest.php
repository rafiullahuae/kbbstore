<?php

declare(strict_types=1);

use App\Models\InstagramPost;
use App\Services\HomepageSections;
use App\Services\InstagramSettings;
use App\Services\ModuleRegistry;
use App\Services\UgcSettings;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The shape of the Instagram section, the cost of it, and the two homepage rows.
 *
 * Phase 21, Lane IG. Everything here is a property of the SOURCE or of the query
 * count rather than of one rendered page, which is why it is a file of its own:
 * these are the assertions that would still be true on a shop with different
 * content, and they are the ones a later lane is most likely to break by accident.
 */

/**
 * ── THE HELPERS ARE THIS FILE'S OWN, WITH THIS FILE'S OWN PREFIX ────────────
 *
 * InstagramProfileTest declares igModule() and igPost(), which do the same two
 * jobs. They are NOT reused here, and the duplication is deliberate: a Pest helper
 * declared in a test file is a global function whose availability depends on which
 * files the runner has loaded, so a file borrowing another file's helper passes on
 * a full-suite run and dies with "undefined function" the moment somebody runs it
 * alone to debug it. Which is exactly when it is needed.
 */
function igsShop(int $posts, array $settings = []): void
{
    InstagramPost::query()->delete();

    $service = app(\App\Services\SettingsService::class);
    $service->setModule(InstagramSettings::MODULE, true);

    foreach (array_merge(['posts' => 24, 'profile_style' => 'card'], $settings) as $key => $value) {
        $service->setModuleSetting(InstagramSettings::MODULE, $key, $value);
    }

    app(InstagramSettings::class)->saveProfile([
        'username' => 'kbeauty.bliss',
        'name' => 'K-Beauty Bliss',
        'avatar' => null,
        'followers' => 12345,
        'posts' => 148,
    ]);

    \App\Services\SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    Cache::flush();

    // The file is written and not only the row: InstagramFeed drops a tile whose
    // path fails IgPath::stored(), so a fixture of paths alone would measure a
    // section that draws nothing and report a flat slope of zero.
    @mkdir(\App\Services\Instagram\IgPath::directory(), 0775, true);

    for ($i = 0; $i < $posts; $i++) {
        $remote = 'slope-'.$posts.'-'.$i;
        $name = sha1($remote).'.jpg';

        file_put_contents(\App\Services\Instagram\IgPath::directory().'/'.$name, 'not-really-a-jpeg');

        InstagramPost::query()->create([
            'remote_id' => $remote,
            'media_type' => 'IMAGE',
            'permalink' => 'https://www.instagram.com/p/AAAA'.substr(sha1($remote), 0, 6).'/',
            'shortcode' => 'AAAA'.substr(sha1($remote), 0, 6),
            'caption' => 'A caption',
            'local_path' => '/'.\App\Services\Instagram\IgPath::ROOT.$name,
            'width' => 640,
            'height' => 640,
            'like_count' => 120,
            'comments_count' => 4,
            'posted_at' => now()->subMinutes($i),
            'seen_at' => now(),
        ]);
    }
}

/* ───────────────────────── rule 4: nothing measures anything ──────────────── */

it('names not one element-measuring API anywhere in the section it ships', function () {
    /*
     * ── RULE 4, ENFORCED RATHER THAN PROMISED ───────────────────────────────
     *
     * "No JavaScript that measures layout — this project sizes with calc() for a
     * reason, and two tests forbid the element-measuring APIs by name."
     * CheckoutFloatingBarGateTest and CartPageSqueezeTest name these five for their
     * own files; this names them for this feature's two templates, because a rule
     * enforced on three files out of the tree is a rule the fourth file breaks.
     *
     * Every size on this section is a `calc()`, a `clamp()` or an `aspect-ratio` in
     * the stylesheet, and `aspect-ratio` is specifically what keeps a tile square
     * without anybody knowing the width — so there is nothing left to measure. The
     * script does one thing: it puts a src on an iframe when a tile is tapped.
     *
     * MUTATION NOTE. Add `el.getBoundingClientRect()` anywhere in
     * resources/views/instagram/assets.blade.php and this is red, naming the file and
     * the API. RUN: red.
     */
    $files = [
        'resources/views/instagram/assets.blade.php',
        'resources/views/instagram/section.blade.php',
        'resources/views/admin/partials/instagram-screen.blade.php',
    ];

    foreach ($files as $relative) {
        $path = base_path($relative);

        expect(file_exists($path))->toBeTrue("{$relative} does not exist");

        $src = (string) file_get_contents($path);

        foreach (['getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth',
            'clientHeight', 'clientWidth', 'scrollY', 'getComputedStyle'] as $api) {
            expect(str_contains($src, $api))->toBeFalse("{$relative} measures layout with {$api}");
        }
    }
});

it('sets the iframe src on a tap and never in the markup', function () {
    /*
     * ── THE WHOLE PERFORMANCE DECISION OF THIS FEATURE, IN ONE ASSERTION ────
     *
     * An `<iframe src>` in the markup is a request to instagram.com for every tile
     * the moment the page paints — nine third-party requests behind a homepage a
     * shopper is waiting for, any one of which can hang. That is exactly what
     * docs/UGC-ENGAGEMENT.md's "nothing on the read path" rule exists to prevent.
     *
     * So the URL is written to a DATA ATTRIBUTE and the script creates the iframe on
     * demand, which means a shop whose visitors never tap a tile makes ZERO requests
     * to Instagram, ever.
     *
     * MUTATION NOTE. Put `<iframe src="{{ $tile['embed'] }}">` in the tile markup and
     * the first expectation is red. RUN: red.
     */
    $section = (string) file_get_contents(base_path('resources/views/instagram/section.blade.php'));
    $assets = (string) file_get_contents(base_path('resources/views/instagram/assets.blade.php'));

    expect(str_contains($section, '<iframe'))->toBeFalse('the section markup carries an iframe')
        ->and(str_contains($section, 'data-ig-embed'))->toBeTrue()
        // The script is the only thing that ever sets one, and it does it from the
        // data attribute rather than from anything it was handed.
        ->and(str_contains($assets, "createElement('iframe')"))->toBeTrue()
        ->and(str_contains($assets, 'frame.src = url'))->toBeTrue();
});

it('emits its own stylesheet rather than relying on a build nobody runs', function () {
    /*
     * `package.json` defines no `build` script (CLAUDE.md), so a rule added to
     * kbb.css ships INERT until somebody runs `npx vite build` by hand — and the
     * storefront's @vite() resolves to a hashed file under a web root that is a
     * DIFFERENT DIRECTORY from the application on the live server. A section whose
     * CSS lives in the bundle is a section that applies as unstyled markup.
     *
     * The same argument HomepageSections::orderStyle() makes for its own element.
     */
    $assets = (string) file_get_contents(base_path('resources/views/instagram/assets.blade.php'));

    expect(str_contains($assets, '<style id="kbb-ig-style">'))->toBeTrue()
        ->and(str_contains($assets, '@vite'))->toBeFalse()
        // aspect-ratio is the mechanism that replaces measuring. If it goes, the
        // no-measure assertion above becomes a rule with nothing implementing it.
        ->and(str_contains($assets, 'aspect-ratio:1/1'))->toBeTrue();
});

/* ─────────────────────────── the cost, as a slope ────────────────────────── */

it('costs the same number of queries for 1 posts as for 20 — the slope, not the total', function () {
    /*
     * ── MEASURED AS A SLOPE AT 1, 2, 5, 10 AND 20, WHICH IS THE POINT ───────
     *
     * Rule 4: "No N+1s; StorefrontQueryBudgetTest is a budget, not a suggestion."
     * A total tells you almost nothing — an N+1 at three tiles looks like a
     * perfectly good number. The SLOPE is the whole question, and a flat slope is
     * the only answer that scales.
     *
     * It is flat because InstagramFeed::build() is ONE `SELECT ... LIMIT n` with a
     * named column list, and the profile box rides the `module_settings` snapshot the
     * module is already reading for its ten appearance settings — so it is not a
     * query at all.
     *
     * MUTATION NOTE. Give InstagramPost a `products` relation and eager-load it
     * per-tile, or move the profile read into the tile loop, and this is red with a
     * rising count. RUN: red.
     */
    /*
     * ── COUNTED WITH THE QUERY LOG, AND NOT WITH DB::listen() IN A LOOP ─────
     *
     * The first draft of this case registered a fresh DB::listen() closure on every
     * pass and reported {"1":2,"2":2,"5":3,"10":4,"20":5} — a textbook rising slope,
     * and entirely an artifact of the harness. `$queries` is ONE variable in this
     * function's scope, every closure captures it BY REFERENCE, and nothing
     * unregisters a listener — so on the fifth pass five listeners were all
     * incrementing the same counter and the number read five times the truth. The
     * real figure was flat at 1 the whole time.
     *
     * That is worth the paragraph rather than a silent fix, because the false
     * positive is far more dangerous than the false negative: a lane that trusted it
     * would have gone looking for an N+1 that does not exist, and a lane that
     * "fixed" it by loosening the assertion would have thrown away the only thing
     * measuring the real slope.
     *
     * The query log has no such accumulation — it is flushed and read, not
     * subscribed to.
     */
    $counts = [];

    foreach ([1, 2, 5, 10, 20] as $n) {
        igsShop($n);

        /*
         * Warm every snapshot a real page has already warmed before this section is
         * reached — the module toggles and the module settings. Counting their first
         * build would be measuring the cache's birth rather than this feature, and
         * on the shop it has already happened: the homepage reads the module
         * switches to decide what to draw.
         */
        app(InstagramSettings::class)->enabled();
        app(InstagramSettings::class)->all();

        /*
         * ── AND THE TRANSLATIONS SNAPSHOT, WHICH IS THE ONE WORTH NAMING ────
         *
         * Measured rather than assumed: with only the two module snapshots warmed
         * the run read {"1":2,"2":1,"5":1,"10":1,"20":1} — flat everywhere except
         * the very first pass. The extra query was not this section's at all:
         *
         *   select "group","item_id","field","value" from "translations"
         *     where "status" = ? and "locale" = ? and "field" not in (...)
         *
         * That is the bilingual layer's per-PROCESS snapshot, built by the first
         * __() call anywhere, and this section calls __() for the five words it
         * puts around Instagram's content (followers, posts, Follow, and the two
         * screen-reader labels). One query per process, not per section and
         * certainly not per tile — and on the shop it is built long before this
         * section renders, because every page calls __() hundreds of times before
         * it gets here.
         *
         * So it is warmed deliberately and named, rather than hidden behind a
         * throwaway render that would also have hidden a real first-pass cost.
         */
        __('store.instagram.follow');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $html = Shortcodes::render('[kbb_instagram]');

        $counts[$n] = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The measurement is worthless if the section did not actually draw n tiles.
        expect(substr_count($html, 'class="igp-c'))->toBe($n, "drew the wrong number of tiles for {$n}");
    }

    expect(array_values(array_unique(array_values($counts))))->toHaveCount(
        1,
        'the query count moves with the number of posts: '.json_encode($counts)
    );

    // ...and the flat number is ONE. Named rather than merely flat, so a change that
    // makes it flat at six is still a failure.
    expect($counts[20])->toBe(1, 'one SELECT and no more: '.json_encode($counts));
});

it('serves the second render of a page from its own cache', function () {
    /*
     * The one SELECT above happens once per TTL, not once per page. Asserted because
     * a cache that is never hit is a cache that is not doing anything, and the way
     * that happens is a key that varies — a timestamp, a locale, a request id — which
     * looks identical in the code and costs a query per page forever.
     */
    igsShop(6);
    app(InstagramSettings::class)->enabled();
    app(InstagramSettings::class)->all();

    Shortcodes::render('[kbb_instagram]');

    DB::enableQueryLog();
    DB::flushQueryLog();

    Shortcodes::render('[kbb_instagram]');

    expect(count(DB::getQueryLog()))->toBe(0);
    DB::disableQueryLog();
});

it('drops its own cache entries and nothing else when the admin writes', function () {
    /*
     * Cache::flush() would empty the whole store — including sessions on some
     * drivers, logging everybody out. So this class keeps an index of the keys it
     * created and forgets only those, exactly as UgcRail and Shortcodes each do.
     *
     * MUTATION NOTE. Change InstagramFeed::flush() to Cache::flush() and the second
     * expectation is red: the unrelated key is gone too. RUN: red.
     */
    igsShop(3);
    Cache::put('somebody.elses.key', 'keep me', 600);

    Shortcodes::render('[kbb_instagram]');

    \App\Services\InstagramFeed::flush();

    expect(Cache::has('kbb.ig.feed.24'))->toBeFalse()
        ->and(Cache::get('somebody.elses.key'))->toBe('keep me');
});

/* ──────────────────────── the two homepage rows ──────────────────────────── */

it('draws every section the homepage registry claims to have', function () {
    /*
     * ── THE DEFECT THIS LANE FOUND IN ITS OWN WIP ───────────────────────────
     *
     * HomepageSections::REGISTRY had an `instagram` row and store/home.blade.php
     * never rendered it. That is a switch on Appearance → Homepage that moves a row
     * on a screen and nothing at all on the shop — exactly the fault
     * docs/FO-HOMEPAGE-INVENTORY.md was written to catalogue, and the one
     * HomepageSections::NESTED carries a paragraph about ("an ↑ on those two rows
     * would be a button that moves a row on a screen and nothing on the shop").
     *
     * It is checked for ALL of them rather than for the two this lane added, because
     * the registry is the thing that will grow again and the next row is as likely to
     * be forgotten as this one was.
     *
     * Each key must appear in a visibility gate — `hidden('key')` — AND in a class
     * call — `classFor('key')` or `bandClassFor`. One without the other is the two
     * halves of a section that renders unconditionally or renders unstyled.
     *
     * MUTATION NOTE. Delete the `@unless ($sections->hidden('instagram'))` block from
     * store/home.blade.php and this is red naming `instagram`. RUN: red — which is
     * how the missing block was found.
     */
    $home = (string) file_get_contents(base_path('resources/views/store/home.blade.php'));

    $missing = [];

    foreach (array_keys(HomepageSections::REGISTRY) as $key) {
        $gated = str_contains($home, "hidden('{$key}')")
            // The three sections drawn inside the hero band are gated by the band's
            // own union rather than one by one; NESTED is the constant that says so.
            || isset(HomepageSections::NESTED[$key])
            || str_contains($home, "shows('{$key}')");

        $classed = str_contains($home, "classFor('{$key}')")
            || str_contains($home, "bandClassFor('{$key}')")
            || isset(HomepageSections::NESTED[$key]);

        if (! $gated || ! $classed) {
            $missing[] = $key.($gated ? '' : ' (no visibility gate)').($classed ? '' : ' (no class call)');
        }
    }

    expect($missing)->toBe([], 'the homepage registry names sections home.blade.php does not draw: '
        .implode(', ', $missing));
});

it('emits not one byte for either new section until it is configured', function () {
    /*
     * ── RULE 1, AND THE REASON THE <section> IS INSIDE THE @if ─────────────
     *
     * This is the state every shop is in the day the package applies. An EMPTY
     * wrapper would still be a changed page — a new element, a new class attribute,
     * and a new divider rule above it, because SectionDividers::classFor() runs for
     * every key — and StorefrontEnglishUnchangedTest compares that page byte for
     * byte. So the element is only reached when there is content to put in it.
     *
     * Asserted on the SOURCE rather than by rendering the homepage, because what is
     * being pinned is the nesting: that the section tag sits inside the content check
     * and not around it. A rendered page is the same either way once it is empty —
     * which is exactly how the wrong nesting survives review.
     *
     * MUTATION NOTE. Move either `<section class="sec {{ $sections->classFor(...) }}">`
     * outside its `@if` and this is red. RUN: red.
     */
    $home = (string) file_get_contents(base_path('resources/views/store/home.blade.php'));

    foreach ([
        'videos' => '$videoRail',
        'instagram' => '$igSection',
    ] as $key => $variable) {
        $gate = '@if ('.$variable.' !== \'\')';
        $element = '<section class="sec {{ $sections->classFor(\''.$key.'\') }}">';

        expect(substr_count($home, $gate))->toBe(1, "{$key} has no single content gate");
        expect(substr_count($home, $element))->toBe(1, "{$key} has no single section element");
        // The element comes AFTER the gate that decides whether to draw it.
        expect(strpos($home, $element))->toBeGreaterThan(strpos($home, $gate));
    }
});

it('ships both new homepage rows drawing nothing, from the settings rather than from the switch', function () {
    /*
     * The pair of settings that make the two rows inert, asserted together because
     * that is how they have to be read: the SWITCHES on both rows ship ON, like the
     * sixteen beside them, and it is the CONTENT that ships empty.
     *
     * ── AND THAT IS A DELIBERATE READING OF "SHIPS OFF" ─────────────────────
     *
     * The brief for this lane said the homepage section ships OFF. It does, in the
     * only sense that is observable on the shop: nothing is drawn until the owner
     * picks a video section and connects an Instagram account. What was NOT done is
     * making these two rows default to desktop=false/mobile=false, and the reason is
     * that HomepageSections has no per-section default for visibility at all —
     * castRow() reads ONE field default shared by every key, so an exception would
     * mean a special case inside machinery three other lanes are reading this round.
     * The cheaper and safer answer is the one that is already true: the content gate.
     *
     * MUTATION NOTE. Change `home_section`'s default in UgcSettings::SCHEMA from ''
     * to anything, or `instagram_profile`'s default in ModuleRegistry from false to
     * true, and this is red — and so is StorefrontEnglishUnchangedTest on any shop
     * that has the matching content. RUN: red.
     */
    expect(app(UgcSettings::class)->all()['home_section'])->toBe('')
        ->and(app(UgcSettings::class)->homeShortcode())->toBe('')
        ->and(ModuleRegistry::REGISTRY['instagram_profile'][3])->toBeFalse()
        ->and(app(InstagramSettings::class)->enabled())->toBeFalse();
});

it('has a real module row behind the switch the storefront reads', function () {
    /*
     * ── THE OTHER DEFECT THIS LANE FOUND IN ITS OWN WIP ─────────────────────
     *
     * InstagramSettings::enabled() reads moduleEnabled('instagram_profile'), and
     * there was NO ROW for that key in ModuleRegistry. So no switch was drawn
     * anywhere in the console, the default was the only value it ever had, and the
     * entire feature — the screen, the shortcode, the homepage row — answered "off"
     * forever with nothing to say why. ModuleFrameworkGuardTest enforces this pairing
     * in both directions; this states it for this key so the failure names the key.
     *
     * The row's settings link is asserted too, because a row pointing at a screen id
     * the console cannot draw lands the owner on the dashboard with no error —
     * go()'s dispatch ends `|| renderDash`, so an unknown id silently becomes the
     * dashboard.
     *
     * MUTATION NOTE. Remove the `instagram_profile` row from ModuleRegistry::REGISTRY
     * and this is red on the first line. RUN: red — which is the state the WIP was
     * in.
     */
    expect(array_key_exists('instagram_profile', ModuleRegistry::REGISTRY))->toBeTrue();

    $row = ModuleRegistry::REGISTRY['instagram_profile'];

    expect($row[4])->toBe('Content → Instagram')
        ->and($row[5])->toBe('instagram')
        // `live` and not `screen`: this module draws something a SHOPPER sees.
        ->and($row[9])->toBe('live');

    $screen = (string) file_get_contents(base_path('resources/views/admin/partials/instagram-screen.blade.php'));

    expect(str_contains($screen, "var SCREEN = 'instagram'"))->toBeTrue(
        'the screen the registry row points at does not claim that id'
    );
});

it('keeps every one of the five layouts reachable from the screen and the shortcode', function () {
    /*
     * The owner asked for "multiple layouts for the instagram section, so i can
     * choose from". Five is the answer, and the thing that can regress is one of them
     * having a name on the screen and no rule in the stylesheet — a dropdown entry
     * that picks a layout the browser has never heard of, which renders as the
     * default and looks like a bug in the saving.
     *
     * MUTATION NOTE. Add a sixth key to InstagramSettings::LAYOUTS without a
     * `.is-<key>` rule and this is red naming it. RUN: red.
     */
    $assets = (string) file_get_contents(base_path('resources/views/instagram/assets.blade.php'));

    expect(count(InstagramSettings::LAYOUTS))->toBe(5);

    foreach (array_keys(InstagramSettings::LAYOUTS) as $key) {
        expect(str_contains($assets, '.is-'.$key))->toBeTrue("layout {$key} has a name but no stylesheet rule");
    }

    foreach (array_keys(InstagramSettings::PROFILE_STYLES) as $key) {
        // `off` draws nothing, so it correctly has no rule; `inline` puts the avatar
        // in the grid and so has none of its own either. The two that draw a BOX do.
        if (in_array($key, ['off', 'inline'], true)) {
            continue;
        }

        expect(str_contains($assets, '.igp-p.is-'.$key))->toBeTrue("profile style {$key} has no stylesheet rule");
    }
});
