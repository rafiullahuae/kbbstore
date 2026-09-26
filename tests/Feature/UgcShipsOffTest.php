<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\UgcVideo;
use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnglishRenderWalk;

/**
 * The storefront does not know this module exists.
 *
 * ── THE REQUIREMENT ─────────────────────────────────────────────────────────
 *
 * CLAUDE.md rule 1, and Phase 20's own plan item: this ships OFF and adds
 * nothing to any page until the owner turns it on. The owner has not picked a
 * rail or a player (docs/UGC-VIDEO-PLAN.md §8, questions 1 and 2), so the
 * honest form of "off" this round is not a switch — it is that no storefront
 * code reads this table at all.
 *
 * ── WHY A RENDER COMPARISON AND NOT AN INSPECTION ───────────────────────────
 *
 * "I did not add a section" is a claim about a diff. This is a claim about what
 * a browser receives: every storefront page is rendered with the table EMPTY
 * and again with a PUBLISHED video in it, in the same process, against the same
 * database, and the two are compared byte for byte after masking the per-render
 * CSRF token. A rail that leaked onto the homepage, a query that changed an
 * ordering, a script tag added to the layout — all of them move these bytes.
 *
 * StorefrontEnglishUnchangedTest is the instrument CLAUDE.md names and it
 * compares against a pinned commit; this is the same idea aimed at the one
 * thing this lane could plausibly have got wrong, and it needs no pin to move.
 */

/** Render a page and mask what legitimately differs between two renders. */
function ugcRender(string $uri): string
{
    return EnglishRenderWalk::mask(test()->get($uri)->getContent());
}

it('renders every storefront page identically with and without a published video', function () {
    $pages = ['/', '/shop', '/cart', '/checkout', '/contact-us'];

    /*
     * The product exists BEFORE the first render, deliberately. It is the thing
     * the video will be tagged to, and the homepage prints a stocked-product
     * count — so creating it between the two passes would move those bytes and
     * this test would be measuring its own fixture rather than the module.
     * (It did, the first time it was run: 24 became 25.)
     */
    $product = Product::create([
        'slug' => 'ugc-off-'.uniqid(), 'name' => 'Snail Essence', 'status' => 'publish',
        'is_visible' => true, 'price' => 12300, 'stock_status' => 'instock',
    ]);

    $before = [];

    foreach ($pages as $uri) {
        $before[$uri] = ugcRender($uri);
    }

    /*
     * A video that passes every gate: granted rights, a poster, a clip, a
     * published status and a product tagged on it. If anything anywhere read
     * this table, THIS is the row it would draw.
     */
    $video = UgcVideo::create([
        'slug' => 'ships-off-'.uniqid(),
        'title' => 'THIS TITLE MUST NOT APPEAR ON THE SHOP',
        'caption' => 'NOR THIS CAPTION',
        'status' => 'publish',
        'rights_status' => 'granted',
        'rights_granted_at' => now(),
        'file_path' => '/uploads/ugc/clip-20270110-000000-aaaaaaaaaa.mp4',
        'poster_path' => '/uploads/ugc/poster-20270110-000000-aaaaaaaaaa.jpg',
        'teaser_path' => '/uploads/ugc/teaser-20270110-000000-aaaaaaaaaa.mp4',
        'creator_handle' => '@layla.skin',
        'published_at' => now()->subDay(),
        'width' => 360, 'height' => 640,
    ]);

    $video->products()->attach($product->id, ['position' => 0]);

    expect(UgcVideo::published()->count())->toBe(1);

    foreach ($pages as $uri) {
        expect(ugcRender($uri))->toBe($before[$uri], $uri.' changed when a published video existed');
    }

    /*
     * MUTATION NOTE. Add one line to resources/views/store/home.blade.php that
     * prints a published video's title, and this is red on '/' with the diff
     * naming it. RUN: red, and only on the page that was edited — which is what
     * makes it a usable instrument rather than a tripwire.
     */
});

it('has no storefront route a stranger can reach with the module off', function () {
    /*
     * ▲ ADVANCED BY LANE V3, AND THE ORIGINAL IS QUOTED BECAUSE THE CHANGE HAS TO
     * BE CHECKABLE. It read:
     *
     *     expect(is_file(base_path('routes/ugc.php')))->toBeFalse();
     *
     * under the name 'it has no storefront route of its own', and it was right for
     * the round that shipped the library: §7's public endpoint belongs with the rail
     * that needs it, and an unauthenticated endpoint shipped ahead of its consumer
     * is a surface nobody is looking at.
     *
     * THIS IS THE ROUND THAT ADDS THE RAIL, so that file now exists — and left as
     * written this case would go red on the very thing the round was asked for, with
     * the only way to green it being to delete the feature. That is the shape
     * CLAUDE.md names, and the answer it gives is to pin the FINISHED state instead.
     *
     * The finished state is not "no public route". It is "no public route a stranger
     * can reach while the module is off", which is strictly stronger than the old
     * assertion in the direction that matters and is checked below by dispatching
     * against them rather than by looking at a filename.
     */
    expect(is_file(base_path('routes/ugc.php')))->toBeTrue();

    // Required exactly ONCE. Zero is the "built, never wired up" shape this
    // repository keeps finding; two registers both routes twice and makes route()
    // ambiguous by name.
    expect(substr_count((string) file_get_contents(base_path('routes/api.php')), "require __DIR__.'/ugc.php';"))->toBe(1);

    /*
     * AND BOTH OF THEM 404 ON THE SHIPPED SETTINGS. The module's default is false
     * and Api\UgcController checks it before anything else, so applying this
     * package adds two routes that answer 404 to everybody.
     *
     * MUTATION NOTE. Remove the enabled() check from either action and this is red
     * with a 200. RUN: red.
     */
    test()->getJson('/api/ugc/anything')->assertStatus(404);
    test()->postJson('/api/ugc/anything/like')->assertStatus(404);

    /*
     * ▲ NARROWED WHEN THE INTEGRATOR WIRED THE ADMIN ROUTES, and the original
     * is quoted because the narrowing has to be checkable.
     *
     * It read:
     *
     *     foreach (Route::getRoutes() as $route) {
     *         expect(str_contains($route->uri(), 'ugc'))->toBeFalse(...);
     *     }
     *
     * which forbids EVERY route carrying `ugc`, including the six admin-api
     * ones this lane ships. It passed in the lane's own worktree only because
     * routes/web.php did not require ugc-admin.php there -- the lane could not
     * edit that file -- so its routes were registered by a test harness and
     * never at boot. The moment the require landed, the case failed on the very
     * routes the lane built, and the only way to green it as written would have
     * been to unmount the feature.
     *
     * The claim it is making is "no STOREFRONT route", so that is what it
     * asserts now: any ugc route must be inside the admin-api group. That is
     * strictly stronger than deleting the case, and stronger than a
     * `hasNamedRoute` check would be -- it fails on a public route added later
     * under any name, which is the thing that would actually put this module in
     * front of a shopper before the owner has switched it on.
     */
    $public = [];

    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        if (! str_contains($route->uri(), 'ugc')) {
            continue;
        }

        /*
         * The two public ones are NAMED, individually, rather than covered by a
         * pattern — so a third public route added later under any name fails here,
         * which is the thing that would actually put this module in front of a
         * shopper before the owner has switched it on.
         */
        if (! str_starts_with($route->uri(), 'admin-api/')
            && ! in_array($route->uri(), ['api/ugc/{section}', 'api/ugc/{slug}/like'], true)) {
            $public[] = implode('|', $route->methods()).' '.$route->uri();
        }
    }

    expect($public)->toBe(
        [],
        'these ugc routes are neither in the admin-api group nor one of the two known public ones: '
        .implode(', ', $public)
    );

    // And the admin ones really are mounted -- a route file required by nothing
    // is the "built, never wired up" shape this repository keeps finding.
    expect(collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/ugc-videos'))
        ->count())->toBeGreaterThan(0, 'routes/ugc-admin.php is required by nothing');
});

it('reads the ugc tables from no storefront code at all', function () {
    /*
     * The grep, as the second lock. ModuleFrameworkGuardTest greps this tree
     * the same way for module readers, and for the same reason: an assertion
     * about behaviour plus an assertion about the source catches the case where
     * the behaviour happens not to fire on the five pages above.
     */
    $hits = [];

    foreach (['app', 'resources/views/store', 'resources/views/layouts', 'resources/views/partials'] as $dir) {
        $base = base_path($dir);

        if (! is_dir($base)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = str_replace(base_path().'/', '', $file->getPathname());

            // This lane's own files are the module; an admin controller is the
            // console. Neither is the storefront.
            if (str_contains($path, 'Ugc')
                || str_starts_with($path, 'app/Http/Controllers/Admin/')
                // The capability map names UgcVideo::toApi() in a comment
                // explaining why rights evidence is never public. It is the
                // permissions table, not a page.
                || $path === 'app/Support/AdminCapabilities.php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'UgcVideo')) {
                $hits[] = $path;
            }
        }
    }

    expect($hits)->toBe([], 'these storefront files read the shoppable-video model');
});

it('adds exactly one module switch, live, and shipped off', function () {
    /*
     * ▲ ADVANCED BY LANE V3, AND THE ORIGINAL IS QUOTED. It read:
     *
     *     foreach (array_keys(ModuleRegistry::REGISTRY) as $key) {
     *         expect(str_contains($key, 'ugc') || str_contains($key, 'shoppable'))->toBeFalse();
     *     }
     *
     * under the name 'it adds no module switch, because there is nothing for one to
     * switch', and it was right: §6 puts the master on/off beside the storefront
     * section, the section did not exist, and ModuleRegistry's status vocabulary
     * exists precisely to stop a switch being drawn for something nothing reads.
     * Its own closing sentence said "the round that draws a rail adds it, as `live`,
     * with a reader." This is that round, so the case now asserts all three of
     * those rather than the absence.
     *
     * Left as written it would go red on the row the round was asked for, and the
     * only way to green it would be to unmount the module.
     *
     * MUTATION NOTE. Change the row's default from false to true and this is red —
     * and so is StorefrontEnglishUnchangedTest, because the rail would then render
     * on any page already carrying the shortcode. Change its status from 'live' to
     * 'todo' and ModuleFrameworkGuardTest is red, because something already reads
     * the switch. RUN: both.
     */
    $keys = array_values(array_filter(
        array_keys(ModuleRegistry::REGISTRY),
        fn ($key) => str_contains($key, 'ugc') || str_contains($key, 'shoppable')
    ));

    expect($keys)->toBe(['shoppable_video']);

    [$group, $name, , $default, $screen, $route, , , , $status] = ModuleRegistry::REGISTRY['shoppable_video'];

    expect($default)->toBeFalse()                              // ships OFF: rule 1
        ->and($status)->toBe('live')                           // something reads it
        /*
         * 'Appearance → Video rail' and NOT 'Appearance → Shoppable video'.
         * AdminNavAndIdsTest refuses two sidebar entries with the same label, and
         * Content → Shoppable video is the clip library's row. So the three rows
         * read: the clips, the rails (Content → Video sections), and what a rail
         * looks like (Appearance → Video rail).
         */
        ->and($screen)->toBe('Appearance → Video rail')
        ->and($route)->toBe('ugcstyle')
        ->and($group)->toBe('store')
        ->and($name)->toBe('Shoppable video');

    // `live` is only true if a reader exists, and the reader is the ONE call.
    $reader = (string) file_get_contents(app_path('Services/UgcSettings.php'));

    /*
     * A LITERAL first argument, because ModuleFrameworkGuardTest tokenises for one:
     * `moduleEnabled(self::MODULE, false)` is invisible to that scan and reported
     * this module as live with no reader.
     */
    expect($reader)->toContain("moduleEnabled('shoppable_video', false)");

    // And with nothing saved, that is what it answers.
    expect(app(\App\Services\UgcSettings::class)->enabled())->toBeFalse();
});

it('adds no row to any table that already had one', function () {
    /*
     * The migration creates two tables and writes nothing anywhere else. A
     * `settings` or `module_toggles` row written on apply is how "applying the
     * package moves nothing" stops being true.
     */
    expect(DB::table('ugc_videos')->count())->toBe(0)
        ->and(DB::table('ugc_video_product')->count())->toBe(0)
        // Lane V3's three. `ugc_video_likes` especially: a like is a PUBLIC write,
        // and a seeded row would be a like nobody made.
        ->and(DB::table('ugc_sections')->count())->toBe(0)
        ->and(DB::table('ugc_section_video')->count())->toBe(0)
        ->and(DB::table('ugc_video_likes')->count())->toBe(0);
});
