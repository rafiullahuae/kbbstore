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

it('has no storefront route of its own', function () {
    /*
     * A route is the other way a module reaches a shopper. routes/ugc-admin.php
     * is the only file this lane adds, it is required inside the admin-api
     * group, and there is no public counterpart at all this round — §7's
     * /api/ugc-videos belongs with the rail that needs it, and an
     * unauthenticated endpoint shipped ahead of its consumer is a surface
     * nobody is looking at.
     */
    expect(is_file(base_path('routes/ugc.php')))->toBeFalse();

    foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
        expect(str_contains($route->uri(), 'ugc'))->toBeFalse($route->uri().' is a live ugc route');
    }
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

it('adds no module switch, because there is nothing for one to switch', function () {
    /*
     * §6 puts the master on/off beside the storefront section, and the section
     * does not exist yet. ModuleRegistry's own status vocabulary exists exactly
     * to stop a switch being drawn for something nothing reads — `live` means
     * "the storefront reads moduleEnabled() for this key", and
     * ModuleFrameworkGuardTest fails a `live` row with no reader AND a `todo`
     * row that something already gates on.
     *
     * A row here now could only be a lie in one of those two directions. The
     * round that draws a rail adds it, as `live`, with a reader.
     */
    foreach (array_keys(ModuleRegistry::REGISTRY) as $key) {
        expect(str_contains($key, 'ugc') || str_contains($key, 'shoppable'))->toBeFalse();
    }
});

it('adds no row to any table that already had one', function () {
    /*
     * The migration creates two tables and writes nothing anywhere else. A
     * `settings` or `module_toggles` row written on apply is how "applying the
     * package moves nothing" stops being true.
     */
    expect(DB::table('ugc_videos')->count())->toBe(0)
        ->and(DB::table('ugc_video_product')->count())->toBe(0);
});
