<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SiteLayoutAdminRoutes;

/**
 * THE LISTING PAGER, AND HOW A LISTING LOADS MORE.               (Lane PI-B)
 *
 * Two things the owner reported on the category and listing pages:
 *
 *   (a) "the pagination arrows render giant" — the four curated listings
 *       printed Laravel's Tailwind pager on a shop with no Tailwind, so both
 *       of its layouts drew at once and its two chevrons, unsized SVGs, were
 *       170x170px at 390 and at 1280 (measured in Chromium, pager 419px tall);
 *       /shop's own pager had CSS only inside a phone query and was bare
 *       5x18px text on desktop.
 *   (b) a choice of how more products load: Arrows (as now — the default,
 *       because he did not choose one), Load more on scroll (batches of 12,
 *       15, 20 or a typed number, 4–96) or Load all (capped at 200).
 *
 * Appearance → Site layout → Loading more products.
 */

function llCategory(int $count, string $slug = 'll-sets'): Category
{
    $category = Category::create(['name' => 'Skincare sets', 'slug' => $slug]);

    for ($i = 1; $i <= $count; $i++) {
        $p = Product::create([
            'slug' => $slug.'-p'.$i,
            'name' => 'Listing Product '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
            'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
            'price' => 1000 + $i * 100, 'sale_price' => 900 + $i * 100,
            'sku' => 'SKU-SECRET-'.$i, 'wc_id' => 900000 + $i,
            'stock_status' => 'instock', 'category_id' => $category->id,
        ]);
        $p->categories()->syncWithoutDetaching([$category->id]);
    }

    return $category;
}

function llAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'LL '.$role, 'email' => 'll-'.$role.'-'.Str::random(8).'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

function llSet(array $values): void
{
    app(SiteLayout::class)->save($values);
    SettingsService::forgetMemo();
}

function llPath(Category $category): string
{
    return '/'.ltrim((string) parse_url($category->url(), PHP_URL_PATH), '/');
}

beforeEach(function () {
    SiteLayoutAdminRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

/* ═══════════════════ (a) the arrows ═══════════════════ */

it('draws the curated listings\' pager as ours, with text arrows, not Laravel\'s Tailwind one', function () {
    /*
     * The defect on the shop: `<svg class="w-5 h-5" viewBox="0 0 20 20">` with
     * no size, inside a pager that also printed "Showing 1 to 24 of 51
     * results" and "« Previous / Next »" because `sm:hidden` hid nothing.
     * The arrows are ‹ and › now — text, so there is no SVG to lose its size.
     *
     * MUTATION, RUN: put `{!! $products->links() !!}` back in
     * store/collection.blade.php and this is red on "w-5 h-5".
     */
    llCategory(30);

    $html = test()->get('/super-sale')->assertOk()->getContent();

    expect($html)->toContain('<nav class="kbb-pager"')
        ->and($html)->not->toContain('w-5 h-5')
        ->and($html)->not->toContain('Showing')
        ->and($html)->toContain('aria-label="Next page">›</a>');

    $pager = substr($html, (int) strpos($html, '<nav class="kbb-pager"'));
    $pager = substr($pager, 0, (int) strpos($pager, '</nav>'));

    expect($pager)->not->toContain('<svg');
});

it('sizes the pager at every width, not only inside a phone query', function () {
    /*
     * /shop's pager had `.page-numbers` rules ONLY under (max-width:900px), so
     * on desktop it was bare 5x18px text. Measured after: 40x40 links at 1280,
     * 44x44 at 390, the arrows set at 20px.
     *
     * MUTATION, RUN: wrap the `.kbb-pager .page-numbers{box-sizing:…}` rule in
     * kbb.css inside @media (max-width:900px) and this is red.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    foreach (['.kbb-pager .page-numbers{box-sizing:border-box;min-width:40px;min-height:40px',
              '.kbb-pager .page-numbers.prev,.kbb-pager .page-numbers.next{font-size:20px'] as $needle) {
        $at = strpos($rules, $needle);
        expect($at)->not->toBeFalse($needle.' is gone');

        $before = substr($rules, 0, (int) $at);
        expect(substr_count($before, '{') - substr_count($before, '}'))
            ->toBe(0, $needle.' sits inside a block (a media query), so some width draws the pager unstyled');
    }
});

/* ═══════════════════ (b) the setting ═══════════════════ */

it('ships at Load more on scroll, because the owner asked for it by name', function () {
    /*
     * It shipped at Arrows while he had not chosen. On 2 October 2026 he did:
     * "Remove pagination from the categories and brands; it should load more
     * products via scroll with grey loading stuff ... just keep this on by
     * default." (Lane PR — GridCardOwnerAsksTest has the brand page half.)
     *
     * MUTATION, RUN: `load_mode` default -> 'arrows' in SiteLayout::SCHEMA
     * and this is red twice: the mode, and one batch of 12 becoming 24.
     */
    $layout = app(SiteLayout::class);

    expect($layout->loadMode())->toBe('scroll')
        ->and($layout->perPage(24))->toBe(12);

    $category = llCategory(30);
    $html = test()->get(llPath($category))->assertOk()->getContent();

    expect(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(12)
        ->and($html)->toContain('data-load="scroll"');

    // Arrows is still one click away, and still the listing's own page size.
    llSet(['load_mode' => 'arrows']);
    $html = test()->get(llPath($category))->assertOk()->getContent();

    expect(app(SiteLayout::class)->perPage(24))->toBe(24)
        ->and(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(24)
        ->and($html)->toContain('data-load="arrows"');
});

it('pages by the batch size on scroll, and by 200 on Load all', function () {
    $category = llCategory(30);

    llSet(['load_mode' => 'scroll', 'load_batch' => '12']);
    $html = test()->get(llPath($category))->assertOk()->getContent();
    expect(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(12);

    llSet(['load_batch' => 'custom', 'load_batch_custom' => '20']);
    $html = test()->get(llPath($category))->assertOk()->getContent();
    expect(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(20);

    llSet(['load_mode' => 'all']);
    $html = test()->get(llPath($category))->assertOk()->getContent();
    expect(substr_count($html, 'class="kbb-card kbb-tile"'))->toBe(30)
        ->and($html)->not->toContain('<nav class="kbb-pager"');

    expect(app(SiteLayout::class)->perPage(24))->toBe(SiteLayout::LOAD_ALL_CAP)
        ->and(SiteLayout::LOAD_ALL_CAP)->toBe(200);
});

it('clamps a typed batch into 4–96, refuses a word, and refuses a mode it does not offer', function () {
    /*
     * The endpoint is the owner's typed box. A number outside the range is
     * pulled to the nearest end; anything that is not a whole number is a 422
     * that names the field — this screen's clamp policy alone would have saved
     * "abc" as 4 and read back "Saved".
     *
     * MUTATION, RUN: delete the `rule` override for load_batch_custom in
     * SiteLayout::overrides() and this is red at "abc" (200 instead of 422).
     */
    test()->actingAs(llAdmin(), 'admin');

    test()->postJson('/admin-api/site-layout', ['settings' => ['load_batch_custom' => '500']])->assertOk();
    expect(app(SiteLayout::class)->get('load_batch_custom'))->toBe(96);

    test()->postJson('/admin-api/site-layout', ['settings' => ['load_batch_custom' => 2]])->assertOk();
    SettingsService::forgetMemo();
    expect(app(SiteLayout::class)->get('load_batch_custom'))->toBe(4);

    foreach (['abc', '-3', '12px', '7.5', ''] as $garbage) {
        test()->postJson('/admin-api/site-layout', ['settings' => ['load_batch_custom' => $garbage]])
            ->assertStatus(422)
            ->assertJsonPath('rejected', ['load_batch_custom']);
    }

    test()->postJson('/admin-api/site-layout', ['settings' => ['load_mode' => 'infinite']])->assertStatus(422);
    test()->postJson('/admin-api/site-layout', ['settings' => ['load_batch' => '13']])->assertStatus(422);

    SettingsService::forgetMemo();
    expect(app(SiteLayout::class)->loadMode())->toBe('scroll');   // the shipped default, untouched by a refused POST
});

it('draws the Loading more products tab on Appearance → Site layout', function () {
    test()->actingAs(llAdmin(), 'admin');

    $tabs = collect(test()->getJson('/admin-api/site-layout')->assertOk()->json('tabs'))->keyBy('key');

    expect($tabs['loading']['label'])->toBe('Loading more products');

    $fields = collect($tabs['loading']['fields'])->keyBy('key');

    expect($fields->keys()->all())->toBe(['load_mode', 'load_batch', 'load_batch_custom', 'load_url'])
        ->and(array_keys($fields['load_mode']['options']))->toBe(['arrows', 'scroll', 'all'])
        ->and(array_map('strval', array_keys($fields['load_batch']['options'])))->toBe(['12', '15', '20', 'custom'])
        ->and($fields['load_mode']['value'])->toBe('scroll');
});

it('keeps the loading choice out of the stylesheet', function () {
    /*
     * The three keys are not CSS. Choosing "scroll" must not make the shop
     * start sending the site-layout <style> that only a moved slider sends.
     *
     * MUTATION, RUN: remove the LOAD_KEYS skip from isDefault() and this is
     * red — the shop gains a :root block of defaults on every page.
     */
    llSet(['load_mode' => 'scroll', 'load_batch' => 'custom', 'load_batch_custom' => 30]);

    expect(app(SiteLayout::class)->isDefault())->toBeTrue()
        ->and(app(SiteLayout::class)->css())->toBe('');
});

it('keeps a support account out of the screen, and a guest out entirely', function () {
    expect(collect(AdminCapabilities::RULES)->contains(['*', 'admin-api/site-layout', 'sitelayout.manage']))->toBeTrue();

    test()->postJson('/admin-api/site-layout', ['settings' => ['load_mode' => 'all']])->assertStatus(401);

    test()->actingAs(llAdmin('support'), 'admin')
        ->postJson('/admin-api/site-layout', ['settings' => ['load_mode' => 'all']])
        ->assertForbidden();

    SettingsService::forgetMemo();
    expect(app(SiteLayout::class)->loadMode())->toBe('scroll');
});

/* ═══════════════════ (b) the batch ═══════════════════ */

it('answers ?kbbbatch=1 with the next page\'s cards and five allowlisted keys', function () {
    /*
     * The URL is public, so the response is built from an allowlist, never a
     * model: the cards as the page draws them, two page numbers and two URLs.
     *
     * MUTATION, RUN: add `'products' => collect($products)->map(fn ($p) =>
     * $p->getAttributes())->all()` beside the html in ListingBatch::respond()
     * and this is red on the key set (and the sku and wc_id ride along).
     */
    $category = llCategory(30);
    llSet(['load_mode' => 'scroll', 'load_batch' => '12']);

    $page1 = test()->get(llPath($category))->assertOk()->getContent();
    $res = test()->get(llPath($category).'?paged=2&kbbbatch=1')->assertOk();

    expect($res->headers->get('Content-Type'))->toContain('application/json')
        ->and($res->headers->get('X-Robots-Tag'))->toBe('noindex');

    $json = $res->json();

    expect(array_keys($json))->toEqualCanonicalizing(['html', 'page', 'last', 'next', 'url'])
        ->and($json['page'])->toBe(2)
        ->and($json['last'])->toBe(3)
        ->and($json['next'])->toContain('paged=3')
        ->and($json['next'])->not->toContain('kbbbatch')
        ->and($json['url'])->toContain('paged=2')
        ->and($json['url'])->not->toContain('kbbbatch');

    expect(substr_count($json['html'], 'class="kbb-card kbb-tile"'))->toBe(12)
        ->and($json['html'])->not->toContain('SKU-SECRET')
        ->and($json['html'])->not->toContain('900001');

    // No card twice: what the batch draws is not what page one drew.
    preg_match_all('#Listing Product (\d{3})#', $page1, $a);
    preg_match_all('#Listing Product (\d{3})#', $json['html'], $b);
    expect(array_intersect(array_unique($a[1]), array_unique($b[1])))->toBe([]);

    $last = test()->get(llPath($category).'?paged=3&kbbbatch=1')->assertOk()->json();
    expect($last['next'])->toBeNull()
        ->and(substr_count($last['html'], 'class="kbb-card kbb-tile"'))->toBe(6);

    test()->get(llPath($category).'?paged=4&kbbbatch=1')->assertNotFound();
});

it('keeps the filters and the sort in every batch', function () {
    /*
     * The batch is the listing's own URL answered by the same controller, so
     * it cannot disagree with the page it continues. Sorted high-to-low, page
     * two's first card is the 13th most expensive.
     */
    $category = llCategory(30);
    llSet(['load_mode' => 'scroll', 'load_batch' => '12']);

    $json = test()->get(llPath($category).'?orderby=phigh&paged=2&kbbbatch=1')->assertOk()->json();

    preg_match_all('#Listing Product (\d{3})#', $json['html'], $m);
    expect((int) $m[1][0])->toBe(18)
        ->and($json['next'])->toContain('orderby=phigh');
});

it('serves curated-listing batches the same way, without the batch flag in their URLs', function () {
    llCategory(30);
    llSet(['load_mode' => 'scroll', 'load_batch' => '12']);

    $json = test()->get('/super-sale?page=2&kbbbatch=1')->assertOk()->json();

    expect(array_keys($json))->toEqualCanonicalizing(['html', 'page', 'last', 'next', 'url'])
        ->and($json['page'])->toBe(2)
        ->and($json['url'])->not->toContain('kbbbatch')
        ->and((string) $json['next'])->not->toContain('kbbbatch')
        ->and(substr_count($json['html'], 'class="kbb-card kbb-tile"'))->toBe(12);
});

it('costs a batch the same queries whether it carries twelve cards or six', function () {
    /*
     * An N+1 grows with the cards; a batch's honest cost does not. Page two
     * is twelve cards and page three is six, so the two counts must match.
     *
     * MUTATION, RUN: in partials/listing-batch.blade.php load each card's
     * brand afresh (`$product->brand()->first()`) and this is red — the
     * twelve-card batch costs six more queries than the six-card one.
     */
    $category = llCategory(30);
    llSet(['load_mode' => 'scroll', 'load_batch' => '12']);

    $count = function (string $url): int {
        SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        test()->get($url)->assertOk();

        return $n;
    };

    $count(llPath($category).'?paged=2&kbbbatch=1');   // warm whatever is cached once per process

    $twelve = $count(llPath($category).'?paged=2&kbbbatch=1');
    $six = $count(llPath($category).'?paged=3&kbbbatch=1');

    expect($twelve)->toBe($six, "a 12-card batch costs {$twelve} queries and a 6-card one {$six}: something is loaded per card");
});

it('keeps working links for a shopper without JavaScript in scroll mode', function () {
    /*
     * "Load more on scroll" is JavaScript; the pager underneath it is links.
     * The page must still carry rel="next" to page two, and the numbers.
     *
     * MUTATION, RUN: make the partial skip the links when the mode is scroll
     * and this is red.
     */
    $category = llCategory(30);
    llSet(['load_mode' => 'scroll', 'load_batch' => '12']);

    $html = test()->get(llPath($category))->assertOk()->getContent();

    expect($html)->toContain('data-load="scroll"')
        ->and($html)->toContain('data-batch="12"')
        ->and((bool) preg_match('#<a class="page-numbers next" rel="next" href="[^"]*paged=2#', $html))->toBeTrue()
        ->and($html)->toContain('<span class="page-numbers current" aria-current="page">1</span>');
});

it('loads with IntersectionObserver and measures nothing', function () {
    /*
     * CLAUDE.md rule 4, and two tests forbid the element-measuring APIs by
     * name. IntersectionObserver reports an intersection the browser has
     * already computed; nothing here asks an element for its size.
     *
     * MUTATION, RUN: add `pager.getBoundingClientRect()` to listing-load.js and
     * this is red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/listing-load.js'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);

    expect($code)->toContain('new IntersectionObserver(')
        ->and($code)->toContain("url.searchParams.set('kbbbatch', '1')")
        ->and($code)->toContain('window.history.replaceState(')
        ->and($code)->toContain('url.origin === window.location.origin');

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientHeight', 'clientWidth', 'getComputedStyle', 'scrollHeight'] as $api) {
        expect(str_contains($code, $api))->toBeFalse("listing-load.js measures layout: {$api}");
    }

    $app = (string) file_get_contents(resource_path('js/kbb/app.js'));
    expect(substr_count($app, "import { initListingLoad } from './listing-load.js';"))->toBe(1)
        ->and(substr_count($app, "    initListingLoad,\n"))->toBe(1);

    // And the bundle that ships carries it.
    $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);
    $bundle = (string) file_get_contents(base_path('public/build/'.$manifest['resources/js/kbb/app.js']['file']));
    expect($bundle)->toContain('kbbbatch');
});

it('fetches the next batch ahead on page open, so the grey cards stand only for a batch still on its way', function () {
    /*
     * Owner, 2 October: "on slow internet it keeps displaying the grey
     * loading stuff. i want that somehow the products path etc should pre
     * load upon page open."
     *
     * Before: the batch was not asked for until the pager came within 600px,
     * so every scroll to the end showed twelve grey cards for the round trip.
     * Measured in Chromium on DevTools "Slow 3G" (400ms, 50KB/s), /shop/,
     * scrolling to the end after reading the first row: grey cards for ~700ms
     * and the second batch in at 782-849ms, its pictures not yet started.
     * After: no grey cards at all, the second batch in at 30ms (1280) /
     * 116ms (390), and its pictures in view already decoded from the cache.
     *
     * MUTATIONS, RUN:
     *   - delete `whenQuiet(fetchAhead);` -- red (nothing fetched on open);
     *   - delete `fetchAhead();` after the insert -- red (only one batch ahead);
     *   - move `waiting = placeholders(...)` above `if (!data)` -- red (grey
     *     cards drawn even when the batch is already here);
     *   - delete the `saveData ||` guard in warm() -- red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/listing-load.js'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);

    expect($code)->toContain("const ROOT_MARGIN = '0px 0px 1200px 0px';")
        ->and($code)->toContain('whenQuiet(fetchAhead);')
        ->and($code)->toContain("window.addEventListener('load', idle, { once: true });")
        ->and($code)->toContain("priority: 'low',")
        ->and($code)->toContain('if (saveData || !html) return;')
        ->and($code)->toContain('const pic = new Image();')
        ->and($code)->toContain("parsed.content.querySelectorAll('img')");

    // Placeholders only inside the not-ready branch.
    expect($code)->toMatch('#if \(!data\) \{\s*waiting = placeholders\(grid, batch\);#')
        ->and(substr_count($code, 'placeholders(grid, batch)'))->toBe(1);

    // And the next one is fetched the moment a batch goes in.
    expect($code)->toMatch('#\} else \{\s*fetchAhead\(\);\s*\}#');

    // The shipped bundle carries it.
    $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);
    $bundle = (string) file_get_contents(base_path('public/build/'.$manifest['resources/js/kbb/app.js']['file']));
    expect($bundle)->toContain('0px 0px 1200px 0px');
});


it('keeps the address as opened while batches load, unless the owner switches the page number on', function () {
    /*
     * The owner, 2.60.405: "i don't need that the url changed from pages 1-2-3
     * etc. like this https://extrabeauty.ae/super-sale?page=2 i want the url
     * must not change, only the more products loads". DEFECT: every batch ran
     * history.replaceState(). MUTATION: drop `&& followUrl` from the
     * replaceState line -> red; ship load_url default true -> red.
     */
    $category = llCategory(30);
    llSet(['load_mode' => 'scroll', 'load_batch' => '12']);
    $html = test()->get(llPath($category))->assertOk()->getContent();
    expect($html)->toContain('data-load="scroll"')->not->toContain('data-url="follow"');

    llSet(['load_url' => '1']);
    $html = test()->get(llPath($category))->assertOk()->getContent();
    expect($html)->toContain('data-url="follow"');

    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(resource_path('js/kbb/listing-load.js')));
    expect($code)->toContain("if (here && followUrl) window.history.replaceState(")
        ->toContain("const followUrl = pager.dataset.url === 'follow';")
        // Back from a product: the same batches, then the same place, this tab only.
        ->toContain("window.addEventListener('pagehide'")
        ->toContain("nav.type === 'back_forward'")
        ->toContain('window.sessionStorage')
        ->not->toContain('localStorage')
        // Measured in Chromium: the idle prefetch fired while the first scroll
        // was still fetching ?paged=2, so batch two was requested twice.
        // MUTATION: drop `busy ||` and this is red.
        ->toContain('if (ahead || busy || !next) return;');
});
