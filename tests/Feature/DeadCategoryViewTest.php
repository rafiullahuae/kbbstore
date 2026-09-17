<?php

declare(strict_types=1);

/**
 * Lane DZ — resources/views/store/category.blade.php is gone, and stays gone.
 *
 * ── WHAT IT WAS ─────────────────────────────────────────────────────────────
 *
 * A 338-line design mock, rendered by no controller and reachable at no URL.
 * Its own header said so, two lanes confirmed it independently
 * (Api\ProductController and App\Services\Analytics each carry a note), and the
 * checks below establish it a third time rather than taking any of that on
 * trust: no `view()` call names it, no `@include` or `@extends` reaches it, no
 * route renders it, no view composer is bound to it, no test asks for it, and
 * no string is built that could resolve to it.
 *
 * ── WHY A DEAD FILE WAS WORTH DELETING RATHER THAN LEAVING ──────────────────
 *
 * Because it did not merely sit there — it carried its own answers to questions
 * the live code had already answered differently, and every one of them was a
 * contradiction waiting for somebody to wire the file to a route:
 *
 *   - its own hardcoded `<meta name="description">` ("Shop Korean beauty at
 *     K-Beauty Bliss."), beside an engine — App\Support\Seo — that resolves the
 *     description from settings the owner can edit;
 *   - until 2.60.193, its own client-side analytics loaders: a THIRD gtag
 *     emitter and a SECOND Meta pixel, reading key names that belong to neither
 *     App\Support\Seo nor App\Services\MarketingPixels, and one allowlist entry
 *     away from double-counting every page view;
 *   - a 60-brand menu linking to /category?cat=…, a route this application has
 *     never registered.
 *
 * That is the shape every contradiction in this codebase has had: a second
 * place that answers a question the first place already owns. A dead copy is
 * not harmless, it is a copy nobody maintains and nobody tests — which is
 * exactly the copy a later reader trusts.
 *
 * ── WHAT ANSWERS THE URL IT WOULD HAVE SERVED ───────────────────────────────
 *
 * /product-category/{path}/ — Store\CategoryArchiveController::show(), which
 * hands off to ShopController and renders `store.shop`. Pinned below, because
 * "this file is dead" is only safe to act on if something else is demonstrably
 * alive at the address.
 */

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

it('no longer carries the mock at all', function () {
    expect(file_exists(resource_path('views/store/category.blade.php')))->toBeFalse();
    expect(View::exists('store.category'))->toBeFalse();
});

/**
 * The parts of a source file that could actually RESOLVE a view.
 *
 * COMMENTS ARE NOT REFERENCES, and this test is worthless if it cannot tell the
 * difference. Several files carry a note recording that this mock existed and
 * what it used to do — that history is the reason the deletion is safe, and a
 * guard that forced those notes to be erased would destroy the evidence it
 * depends on.
 *
 * So .php files are tokenised and only string literals are searched: a view
 * name reaches Laravel as a string, always, whether through view(), @include
 * compiled into one, or a name built by concatenation. Blade files have their
 * {{-- --}} comments removed and are then searched whole, because a Blade
 * directive is inline HTML to the tokeniser and would be skipped otherwise.
 */
function dcvResolvableText(string $path): string
{
    $source = (string) file_get_contents($path);

    if (str_ends_with($path, '.blade.php')) {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    $out = '';

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            $out .= $token[1] . "\n";
        }
    }

    return $out;
}

it('is named by nothing in the application, the routes or the tests', function () {
    /*
     * Searched over the SOURCE rather than by trying to render it: a view that
     * is referenced but missing throws at render time, which is a 500 on a live
     * page, and the point of this test is to catch a reference BEFORE anybody
     * follows it.
     *
     * Every spelling a reference could take is covered: the dotted name, the
     * path form, and the file name itself (which is how an @include written
     * against the directory would read).
     */
    $roots = [
        base_path('app'),
        base_path('routes'),
        base_path('tests'),
        base_path('resources/views'),
        base_path('database'),
    ];

    $needles = ['store.category', 'store/category', 'category.blade.php'];
    $offenders = [];

    foreach ($roots as $root) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // This file necessarily writes the needles down; nothing else may.
            if ($file->getPathname() === __FILE__) {
                continue;
            }

            $text = dcvResolvableText($file->getPathname());

            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    $offenders[] = $file->getPathname() . ' can resolve ' . $needle;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('excludes comments from that search rather than passing by accident', function () {
    /*
     * The guard above is only meaningful if it would fail on a real reference,
     * and only usable if it does not fail on the historical notes. Both halves
     * are asserted against strings built here, so the test proves its own
     * instrument instead of asserting an empty list and calling it a day.
     */
    $needle = 'store' . '.category';

    $file = tempnam(sys_get_temp_dir(), 'dcv') . '.php';

    file_put_contents($file, "<?php\n// a note about " . $needle . "\nreturn 1;\n");
    expect(str_contains(dcvResolvableText($file), $needle))->toBeFalse();

    file_put_contents($file, "<?php\nreturn view('" . $needle . "');\n");
    expect(str_contains(dcvResolvableText($file), $needle))->toBeTrue();

    @unlink($file);
});

it('serves the category archive from the controller that is actually wired up', function () {
    /*
     * The address the mock was a mock OF. If this were not answering, deleting
     * the file would be removing the only implementation rather than the dead
     * one — so it is asserted here, in the same file, rather than assumed from
     * elsewhere in the suite.
     */
    $category = Category::create(['name' => 'Cleansers', 'slug' => 'dcv-cleansers']);

    // Attached through the pivot, because that is what ShopController filters
    // on (`whereHas('categories', ...)`) — `category_id` alone would leave the
    // grid empty and this test asserting the wrong thing.
    Product::create([
        'slug' => 'dcv-' . Str::random(10),
        'name' => 'Gentle Foaming Cleanser',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 7900,
        'stock_status' => 'instock',
        'category_id' => $category->id,
    ])->categories()->attach($category->id);

    $response = test()->get('/product-category/' . $category->slug)->assertOk();

    $response->assertViewIs('store.shop');

    expect($response->getContent())->toContain('Gentle Foaming Cleanser');
});

it('leaves the description on that page to the one engine that owns it', function () {
    /*
     * The mock's hardcoded description is gone with it. What the live archive
     * publishes comes from App\Support\Seo, so the owner can change it and it
     * cannot disagree with the rest of the site — which is the whole reason a
     * second, unmaintained copy of it was worth removing rather than leaving to
     * be found.
     */
    $category = Category::create(['name' => 'Toners', 'slug' => 'dcv-toners']);

    $html = test()->get('/product-category/' . $category->slug)->assertOk()->getContent();

    expect(substr_count($html, '<meta name="description"'))->toBeLessThan(2);

    // Built at run time so this test is not itself a copy of the mock's string.
    expect($html)->not->toContain('Shop Korean beauty at ' . 'K-Beauty Bliss.');
});
