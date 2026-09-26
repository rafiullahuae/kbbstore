<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\DemoContentController;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\ModuleRegistry;

/**
 * =============================================================================
 * ONE ROW FOR THE SHOPPABLE-VIDEO FEATURE, AND SOMETHING TO LOOK AT IN IT
 * =============================================================================
 *
 * The owner, in his own words:
 *
 *   "I really don't understand the videos rail section, it's really confusing.
 *    There must be default page which will have all sections. and upon creating
 *    new section, the sections lists need to be there, and upon click on each
 *    section, inside there will be videos list, and upon on each video, edits
 *    control popup will open to controll everything for that video. also include
 *    some demo data, so i can see in action."
 *
 * ── WHAT WAS ACTUALLY WRONG, MEASURED BEFORE ANYTHING WAS CHANGED ───────────
 *
 * Every screen he describes already existed and the drill-down already worked.
 * Two things made it unreadable:
 *
 *   1. THREE SIDEBAR ROWS FOR ONE FEATURE — Content → Shoppable video (the clip
 *      library), Content → Video sections (the rails) and Appearance → Video
 *      rail (the look). Three doors, none of them saying which was the way in.
 *   2. NOTHING IN ANY OF THEM. A shop that has never used the feature draws an
 *      empty state, and an empty state cannot show a sections list, a section's
 *      clips, or the per-clip popup — which is the entire shape he was asking
 *      about. Nothing was broken; there was nothing to look at.
 *
 * So: one row, three tabs, and a Demo Content type that fills it.
 *
 * Every case below pins the FINISHED state, never the absence of one.
 */
function ugcSource(string $partial): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/'.$partial.'.blade.php'));
}

/* ─────────────────────────── one row, three tabs ─────────────────────────── */

it('registers exactly one sidebar row for the whole feature', function () {
    /*
     * kbbAddNavEntry is how a screen claims a sidebar row, and
     * AdminNavAndIdsTest discovers rows by PARSING these sources for it — not by
     * watching which calls run. That is why the two screens that lost their row
     * had the whole block DELETED rather than merely left uncalled: a
     * commented-out call still reads as a registration, and the first attempt at
     * this change failed exactly that way, with two rows labelled "Shoppable
     * video" and a duplicate-label error.
     *
     * MUTATION: put a kbbAddNavEntry({...}) block back into either the library
     * or the appearance screen and this is red — as is AdminNavAndIdsTest's
     * duplicate-label case, which is the one that caught it.
     */
    expect(substr_count(ugcSource('ugc-sections-screen'), 'window.kbbAddNavEntry('))->toBe(1);
    expect(substr_count(ugcSource('ugc-library-screen'), 'window.kbbAddNavEntry('))->toBe(0);
    expect(substr_count(ugcSource('ugc-appearance-screen'), 'window.kbbAddNavEntry('))->toBe(0);

    // And the one row is the front door, under Content, labelled for the feature
    // rather than for one of its three parts.
    expect(ugcSource('ugc-sections-screen'))->toContain("label: 'Shoppable video',");
    expect(ugcSource('ugc-sections-screen'))->toContain("group: 'Content',");
});

it('draws the same three tabs on all three screens, from one definition', function () {
    /*
     * One definition, because three copies of a tab strip drift: the day a
     * fourth tab is added, two screens get it and one does not, and the owner
     * finds a page he cannot get back from.
     *
     * MUTATION: delete the window.kbbUgcTabs call from any one screen's render()
     * and that screen becomes a dead end — reachable, with no way back to the
     * other two except the browser's Back button. This is red on all three.
     */
    expect(substr_count(ugcSource('ugc-sections-screen'), 'window.kbbUgcTabs = function'))->toBe(1);

    foreach (['ugc-sections-screen', 'ugc-library-screen', 'ugc-appearance-screen'] as $partial) {
        expect(substr_count(ugcSource($partial), 'window.kbbUgcTabs(SCREEN)'))
            ->toBe(1, $partial.' does not draw the tab strip exactly once');
    }

    // The three ids the strip routes between, and they are the three screens'
    // own SCREEN constants.
    $strip = ugcSource('ugc-sections-screen');

    foreach (['ugcsections', 'ugcvideo', 'ugcstyle'] as $id) {
        expect(substr_count($strip, "['".$id."', '"))->toBe(1, $id.' is not a tab');
    }
});

it('keeps all three screens routable by id, and their titles distinct', function () {
    /*
     * ROUTABLE WITHOUT A ROW is an established shape in this console — `blog`
     * and `rev-capsule` both do it, and the TITLES comment in app.blade.php says
     * why. Every existing #ugcvideo link and ?go=ugcstyle deep link still opens
     * its screen.
     *
     * DISTINCT TITLES ARE LOAD-BEARING AND NOT COSMETIC. All three screens guard
     * their own render() on the text of #ptitle. Give two of them the same
     * title and BOTH answer a single navigation and fight over #content — which
     * is why the library screen's title became "All clips" rather than staying
     * "Shoppable video" when the sections screen took that name.
     *
     * MUTATION: set the library screen's title back to 'Shoppable video' and two
     * screens render into one container.
     */
    $titles = [];

    foreach ([
        'ugc-sections-screen' => 'ugcsections',
        'ugc-library-screen' => 'ugcvideo',
        'ugc-appearance-screen' => 'ugcstyle',
    ] as $partial => $id) {
        $source = ugcSource($partial);

        expect(substr_count($source, "var SCREEN = '".$id."';"))->toBe(1);

        expect((bool) preg_match("/if \(title\) title\.textContent = '([^']+)';/", $source, $m))
            ->toBeTrue($partial.' sets no page title');

        $titles[$partial] = $m[1];

        // The same string the screen guards its render() on, or the screen
        // renders nothing at all.
        expect(substr_count($source, "'".$m[1]."'"))
            ->toBeGreaterThanOrEqual(2, $partial.' guards render() on a different string than it sets');
    }

    expect(count(array_unique($titles)))->toBe(3, 'two screens share a page title: '.json_encode($titles));

    // The row's label and the page it opens agree, which is what
    // AdminNavAndIdsTest checks for every row in the console.
    expect($titles['ugc-sections-screen'])->toBe('Shoppable video');
});

it('points the module registry at the row that now exists', function () {
    /*
     * The registry's "where do I configure this" claim is resolved against the
     * console's real sidebar paths by ModuleRegistrySettingsPathTest. It named
     * 'Appearance → Video rail', which is no longer a row — so the claim had to
     * move with the rows, or that test goes red on a path nobody can click.
     */
    [, , , , $screen, $route] = ModuleRegistry::REGISTRY['shoppable_video'];

    expect($screen)->toBe('Content → Shoppable video');
    expect($route)->toBe('ugcsections');
});

/* ───────────────────────────── the demo data ─────────────────────────────── */

it('seeds sections, clips, products and media that are all really there', function () {
    /*
     * "also include some demo data, so i can see in action."
     *
     * Through Safety → Demo Content, as a type of its own, so the SAME Remove
     * that clears demo orders clears these — rather than a seeder that leaves
     * rows the owner has to delete one at a time.
     *
     * MUTATION: drop 'videos' from DemoContentController::TYPES and the type
     * cannot be imported or removed at all; this is red.
     */
    $reflection = new ReflectionClass(DemoContentController::class);
    $types = $reflection->getConstant('TYPES');

    expect($types)->toContain('videos');

    // Real products to tag against, so a demo tile links somewhere that exists.
    for ($i = 0; $i < 6; $i++) {
        Product::create([
            'name' => 'UGC demo target '.$i,
            'slug' => 'ugc-demo-target-'.$i,
            'status' => 'publish',
            'is_visible' => true,
            'stock_status' => 'instock',
            'price' => 5500,
        ]);
    }

    $controller = app(DemoContentController::class);

    $ensure = $reflection->getMethod('ensureTable');
    $ensure->setAccessible(true);
    $ensure->invoke($controller);

    $seed = $reflection->getMethod('seedVideos');
    $seed->setAccessible(true);
    $made = $seed->invoke($controller);

    expect($made)->toBe(8);          // six clips and two sections
    expect(UgcVideo::count())->toBe(6);
    expect(UgcSection::count())->toBe(2);

    /*
     * PUBLISHED, WHICH IS THE WHOLE POINT. UgcVideo::published() requires
     * status, granted rights, a poster AND a file — a demo that failed any of
     * those would populate the admin list and render nothing on the shop, which
     * is not "in action".
     */
    expect(UgcVideo::published()->count())->toBe(6);

    /*
     * AND `ready`, NOT `poster_only`. mediaState() answers poster_only when
     * there is no teaser, and a poster-only tile shows a STILL where a real one
     * loops — so this is the assertion that keeps the demo demonstrating the
     * 2-3 second loop the owner asked about first. It went the wrong way once:
     * the first version of the seeder set no teaser_path and every demo tile
     * came back badged "Poster only".
     */
    foreach (UgcVideo::all() as $video) {
        expect($video->mediaState())->toBe(UgcVideo::MEDIA_READY);
    }

    // The files are on disk, where a real upload would be, and not merely named
    // in a column.
    $dir = public_path(\App\Services\UgcMedia::DIR);

    expect(is_file($dir.'/demo-clip.webm'))->toBeTrue();
    expect(is_file($dir.'/demo-poster.jpg'))->toBeTrue();

    // Every clip carries products, and every section carries clips — an empty
    // one of either would demonstrate the screen and not the feature.
    foreach (UgcVideo::with('products')->get() as $video) {
        expect($video->products->count())->toBeGreaterThan(0);
    }

    foreach (UgcSection::with('videos')->get() as $section) {
        expect($section->videos->count())->toBeGreaterThan(0);
    }

    /*
     * ONE CLIP IN TWO SECTIONS. A clip belonging to several rails is a real
     * property of this data model and the least obvious one from an empty
     * screen, so the demo is built to show it rather than to avoid it.
     */
    $shared = UgcVideo::withCount('sections')->get()->filter(fn ($v) => $v->sections_count > 1);

    expect($shared->count())->toBeGreaterThan(0, 'no demo clip appears in more than one section');
});

it('removes every demo row and both demo files again', function () {
    /*
     * Demo content that cannot be fully removed is demo content nobody dares
     * seed on a real shop. The two MEDIA FILES are the part a generic remover
     * cannot see — they are not rows — so they are deleted explicitly, through
     * the same UgcMedia::forget() a real clip uses.
     *
     * MUTATION: delete the `$type === 'videos'` block from removeType() and the
     * two file assertions below are red while every row assertion still passes,
     * which is exactly the failure that would otherwise ship unnoticed.
     */
    $reflection = new ReflectionClass(DemoContentController::class);
    $controller = app(DemoContentController::class);

    $ensure = $reflection->getMethod('ensureTable');
    $ensure->setAccessible(true);
    $ensure->invoke($controller);

    $seed = $reflection->getMethod('seedVideos');
    $seed->setAccessible(true);
    $seed->invoke($controller);

    $dir = public_path(\App\Services\UgcMedia::DIR);

    expect(UgcVideo::count())->toBe(6);
    expect(is_file($dir.'/demo-clip.webm'))->toBeTrue();

    $remove = $reflection->getMethod('removeType');
    $remove->setAccessible(true);
    $removed = $remove->invoke($controller, 'videos');

    expect($removed)->toBe(8);
    expect(UgcVideo::count())->toBe(0);
    expect(UgcSection::count())->toBe(0);

    // The pivot rows go with them, by database cascade rather than by hand.
    expect(\Illuminate\Support\Facades\DB::table('ugc_section_video')->count())->toBe(0);
    expect(\Illuminate\Support\Facades\DB::table('ugc_video_product')->count())->toBe(0);

    expect(is_file($dir.'/demo-clip.webm'))->toBeFalse();
    expect(is_file($dir.'/demo-poster.jpg'))->toBeFalse();
});

it('carries the demo media as text because a package may not carry a video', function () {
    /*
     * App\Services\Update\UpdateGuard::ALLOWED_EXTENSIONS has no video extension
     * on it, and an unlisted extension does not get skipped — it FAILS THE WHOLE
     * UPDATE. So the clip travels base64-encoded inside a .php file and is
     * written to disk at seed time.
     *
     * The alternative was to widen that allowlist, and it was refused: the
     * extension list is part of the updater's security surface, this shop has
     * already lost three hours to one updater defect, and widening it
     * permanently for every future package in order to ship a DEMO is the wrong
     * trade.
     *
     * MUTATION: add 'webm' to ALLOWED_EXTENSIONS and this is red — deliberately,
     * so that widening it is a decision somebody makes on purpose rather than a
     * line that slips in with a demo.
     */
    $guard = new ReflectionClass(\App\Services\Update\UpdateGuard::class);
    $extensions = $guard->getConstant('ALLOWED_EXTENSIONS');

    foreach (['webm', 'mp4', 'mov', 'avi'] as $video) {
        expect($extensions)->not->toContain($video);
    }

    // And the media really does round-trip to the byte, rather than to a file
    // that merely exists.
    $media = \App\Support\UgcDemoMedia::materialise();

    expect($media)->not->toBeNull();

    $dir = public_path(\App\Services\UgcMedia::DIR);

    expect(filesize($dir.'/demo-clip.webm'))->toBe(\App\Support\UgcDemoMedia::clipBytes());
    expect(filesize($dir.'/demo-poster.jpg'))->toBe(\App\Support\UgcDemoMedia::posterBytes());

    // It is a real WebM, judged by its bytes the way UgcMedia judges an upload,
    // and not a renamed anything.
    expect((new \finfo(FILEINFO_MIME_TYPE))->file($dir.'/demo-clip.webm'))->toBe('video/webm');
    expect((new \finfo(FILEINFO_MIME_TYPE))->file($dir.'/demo-poster.jpg'))->toBe('image/jpeg');
});
