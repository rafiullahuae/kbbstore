<?php

/**
 * Spelling mistakes in search (Lane SR, 9 October 2026).
 *
 * THE DEFECT ON THE SHOP: the owner, "if user search anything with wrong spell,
 * like Medicube > Medicob or Medicobe, the system should auto detect the small
 * miss-spellings and display the results for the corrected ones. our mostly
 * ladies are arabic non-technicals". Typing "medicob" in the header box said
 * "No matches"; pressing Enter showed "0 products". Typing the brand in Arabic
 * letters (ميديكيوب) did the same -- the catalogue is English and the search
 * is a LIKE on it. Store -> Site Search -> Spelling mistakes -> "Fix spelling
 * mistakes", on as shipped because he asked for it.
 *
 * MUTATIONS, RUN (each turns the named case red):
 *   - SearchController::suggest() back to `$this->build($q) + setCandidates($q)`
 *     (no buildCorrecting): "corrects a misspelt brand in the dropdown".
 *   - ShopController: drop the `if ($total === 0 ...)` retry: "results page".
 *   - SearchSpelling::fold() without the strtr() confusion map: the coverage
 *     dataset ("mediqube", "cosrex", "toriden", "laneg" ...).
 *   - SearchSpelling::limit() giving 1 edit under 4 letters AND token() without
 *     its `$letters < 4` guard (both stand guard): "oil stays oil".
 *   - SearchSpelling::build() without the translations read: "Arabic name".
 *   - Drop Brand::booted()'s flush: "dropped when a brand is saved".
 *   - Print {!! $searchCorrected !!} in store/shop.blade.php: "escaped".
 *   - buildCorrecting() run on every search (drop the total > 0 return): the
 *     exact query's statement count goes from 10 to 14 and "costs exactly what
 *     it cost" is red.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Translation;
use App\Services\HeaderSettings;
use App\Support\SearchSpelling;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    DB::table('product_set_items')->delete();
    DB::table('category_product')->delete();
    DB::table('products')->delete();
    DB::table('brands')->delete();
    DB::table('categories')->delete();
    DB::table('translations')->whereIn('group', ['brands', 'categories'])->delete();
    Cache::flush();
    SearchSpelling::flush();
});

function srProduct(string $name, ?Brand $brand, array $extra = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'sr-' . $n . '-' . uniqid(),
        'name' => $name,
        'brand_id' => $brand?->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'total_sales' => max(0, 100000 - $n),
    ], $extra));
}

/**
 * The demo catalogue's own eight brands (DemoCatalogueSeeder) plus Laneige
 * and Some By Mi, both stocked on the live shop, one product each.
 *
 * @return array<string, Brand>
 */
function srShop(): array
{
    $brands = [];

    foreach (['Beauty of Joseon', 'COSRX', 'Anua', 'Medicube', 'Round Lab', 'Torriden', 'Isntree', 'SKIN1004', 'Laneige', 'Some By Mi'] as $name) {
        $brands[$name] = Brand::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name)]);
    }

    srProduct('Relief Sun Rice + Probiotics SPF50+', $brands['Beauty of Joseon']);
    srProduct('Advanced Snail 96 Mucin Power Essence', $brands['COSRX']);
    srProduct('Heartleaf 77% Soothing Toner', $brands['Anua']);
    srProduct('Age-R Booster Pro Device', $brands['Medicube']);
    srProduct('Collagen Night Wrapping Mask', $brands['Medicube']);
    srProduct('1025 Dokdo Toner', $brands['Round Lab']);
    srProduct('Dive-In Low Molecular Hyaluronic Acid Serum', $brands['Torriden']);
    srProduct('Hyaluronic Acid Watery Sun Gel', $brands['Isntree']);
    srProduct('Madagascar Centella Ampoule', $brands['SKIN1004']);
    srProduct('Water Sleeping Mask', $brands['Laneige']);
    srProduct('AHA BHA PHA 30 Days Miracle Toner', $brands['Some By Mi']);
    srProduct('Gold Foil Mask', $brands['Anua']);
    srProduct('Oily Skin Balancing Toner', $brands['Anua']);

    Category::create(['name' => 'Serums', 'slug' => 'serums', 'position' => 0, 'depth' => 0, 'path' => 'serums']);

    return $brands;
}

/** @return array<string, mixed> */
function srApi(string $q): array
{
    return test()->getJson('/api/search?q=' . urlencode($q))->assertOk()->json();
}

/** @return list<string> */
function srLabels(array $json): array
{
    $products = collect($json['groups'])->firstWhere('key', 'products');

    return array_column($products['items'] ?? [], 'label');
}

function srQueries(callable $fn): int
{
    $n = 0;
    DB::listen(function () use (&$n) {
        $n++;
    });
    $fn();

    return $n;
}

it('corrects each misspelling to the real brand or word', function (string $typed, ?string $expected) {
    srShop();

    expect(SearchSpelling::correct($typed))->toBe($expected);
})->with([
    ['Medicob', 'Medicube'],
    ['Medicobe', 'Medicube'],
    ['medicub', 'Medicube'],
    ['mediqube', 'Medicube'],
    ['medico', 'Medicube'],
    ['annua', 'Anua'],
    ['cosrex', 'COSRX'],
    ['cos rx', 'COSRX'],
    ['lanage', 'Laneige'],
    ['laneg', 'Laneige'],
    ['skin 1004', 'SKIN1004'],
    ['toriden', 'Torriden'],
    ['buety of josion', 'Beauty of Joseon'],
    ['beautyofjoseon', 'Beauty of Joseon'],
    ['roundlab', 'Round Lab'],
    ['somebymi', 'Some By Mi'],
    ['isntre', 'Isntree'],
    ['medicob mask', 'Medicube mask'],
    ['snail muicn', 'snail mucin'],
    ['hyaluronik', 'hyaluronic'],
    ['serom', 'serum'],
    // Already right: nothing to correct.
    ['anua', null],
    ['cosrx', null],
    ['laneige', null],
    ['skin1004', null],
    ['torriden', null],
    ['beauty of joseon', null],
    ['round lab', null],
    ['some by mi', null],
]);

it('corrects a brand typed in Arabic letters', function (string $typed, string $expected) {
    srShop();

    expect(SearchSpelling::correct($typed))->toBe($expected);
})->with([
    ['ميديكيوب', 'Medicube'],
    ['ميديكوب', 'Medicube'],
    ['كوزركس', 'COSRX'],
    ['أنوا', 'Anua'],
    ['انوا', 'Anua'],
    ['لانيج', 'Laneige'],
    ['لانيچ', 'Laneige'],
    ['إيزنتري', 'Isntree'],
    ['سوم باي مي', 'Some By Mi'],
    ['بيوتي اوف جوسون', 'Beauty of Joseon'],
    ['سكين ١٠٠٤', 'SKIN1004'],
    ['توريدن', 'Torriden'],
]);

it('normalises Arabic spelling before reading it', function () {
    // Alef forms, taa marbuta, alef maqsura, tatweel and diacritics.
    expect(SearchSpelling::normaliseArabic('أإآ ة ى مـيـديـكـيـوب سَيْرُوم'))->toBe('ااا ه ي ميديكيوب سيروم');
});

it('finds a brand by the Arabic name the shop stores for it', function () {
    $b = srShop();
    $althea = Brand::create(['name' => 'Dr.Althea', 'slug' => 'dr-althea']);
    srProduct('345 Relief Cream', $althea);

    // Not reachable by reading the letters ("doktor althia" is far from
    // "dralthea"); only the stored Arabic name connects them.
    Translation::create(['locale' => 'ar', 'group' => 'brands', 'item_id' => $althea->id, 'field' => 'name', 'value' => 'دكتور ألثيا', 'status' => 'published']);

    expect(SearchSpelling::correct('دكتور ألثيا'))->toBe('Dr.Althea')
        ->and(SearchSpelling::correct('دكتور الثيا'))->toBe('Dr.Althea');

    $json = srApi('دكتور الثيا');
    expect($json['corrected'])->toBe('Dr.Althea')
        ->and(srLabels($json))->toContain('345 Relief Cream');
});

it('corrects a misspelt brand in the dropdown, in the same response', function () {
    srShop();

    $json = srApi('medicob');

    expect($json['corrected'])->toBe('Medicube')
        ->and($json['query'])->toBe('medicob')
        ->and(srLabels($json))->toContain('Age-R Booster Pro Device')
        ->and(srLabels($json))->toContain('Collagen Night Wrapping Mask')
        // "View all" carries what was typed, so the page offers the way back.
        ->and($json['all_url'])->toContain('s=medicob');

    $ar = srApi('ميديكيوب');
    expect($ar['corrected'])->toBe('Medicube')->and(srLabels($ar))->toContain('Age-R Booster Pro Device');
});

it('keeps /api/search to its allowlist plus `corrected`', function () {
    srShop();

    expect(array_keys(srApi('medicob')))->toBe(['query', 'groups', 'total', 'all_url', 'corrected'])
        ->and(array_keys(srApi('medicube')))->toBe(['query', 'groups', 'total', 'all_url', 'corrected'])
        ->and(array_keys(srApi('m')))->toBe(['query', 'groups', 'total', 'corrected']);
});

it('leaves an exact search alone, and it costs exactly what it cost', function () {
    srShop();

    // Measured on the tree before this lane (claude/kind-mayer-rpqesv at
    // ced84c7a, app/ and views stashed), same fixture, same order: 10
    // statements for the dropdown, 16 for the page -- and the same 10 and 16
    // after. A correction attempted on every search would add the
    // dictionary's reads to both.
    $api = srQueries(fn () => srApi('medicube'));
    Cache::flush();
    $page = srQueries(fn () => test()->get('/shop/?s=medicube')->assertOk());

    expect(srApi('medicube')['corrected'])->toBeNull()
        ->and($api)->toBe(10)
        ->and($page)->toBe(16);
});

it('does not invent a correction for nonsense', function () {
    srShop();

    // An Arabic word that is not a brand is not guessed at from its first
    // letters: "واقي" (sun protection) once read as the start of "water".
    srProduct('Rose Water Toner', null);
    expect(SearchSpelling::correct('واقي'))->toBeNull();

    $json = srApi('xqzv');

    expect(SearchSpelling::correct('xqzv'))->toBeNull()
        ->and($json['corrected'])->toBeNull()
        ->and($json['total'])->toBe(0);
});

it('does not over-correct short words: oil stays oil', function () {
    srShop(); // has "Oily Skin ...", one edit from "oil"

    expect(SearchSpelling::correct('oil'))->toBeNull()
        ->and(SearchSpelling::correct('gel'))->toBeNull()
        ->and(srApi('oil')['corrected'])->toBeNull();
});

it('shows the correction on the results page, with the way back', function () {
    srShop();

    $html = test()->get('/shop/?s=medicob')->assertOk()->getContent();

    expect($html)->toContain('Showing results for <b>Medicube</b>')
        ->and($html)->toContain('Search instead for medicob')
        ->and($html)->toContain('?s=medicob&amp;sfix=0" rel="nofollow"')
        ->and($html)->toContain('Age-R Booster Pro Device');

    // The way back is never corrected.
    $back = test()->get('/shop/?s=medicob&sfix=0')->assertOk()->getContent();
    expect($back)->not->toContain('Showing results for')
        ->and($back)->not->toContain('Age-R Booster Pro Device');

    // And an exact search draws no line at all.
    expect(test()->get('/shop/?s=medicube')->getContent())->not->toContain('kbb-sfix');
});

it('escapes the corrected term wherever it is printed', function () {
    $brand = Brand::create(['name' => 'Rosé "Lab" <b>', 'slug' => 'rose-lab']);
    srProduct('Rose Water Toner', $brand);

    expect(SearchSpelling::correct('roselab'))->toBe('Rosé "Lab" <b>');

    $html = test()->get('/shop/?s=roselab')->assertOk()->getContent();

    expect($html)->toContain('Showing results for <b>Rosé &quot;Lab&quot; &lt;b&gt;</b>')
        ->and($html)->not->toContain('<b>Rosé "Lab" <b></b>');

    // The dropdown prints it through escapeHtml(), like every other label.
    $js = file_get_contents(resource_path('js/kbb/search.js'));
    expect($js)->toContain('${escapeHtml(data.corrected)}')
        ->and($js)->toContain("t('store.js.search_corrected', 'Showing results for')");
});

it('drops the dictionary when a brand is saved', function () {
    srShop();

    expect(SearchSpelling::correct('purrito'))->toBeNull(); // dictionary built here

    $purito = Brand::create(['name' => 'Purito', 'slug' => 'purito']);
    expect(SearchSpelling::correct('purrito'))->toBe('Purito');

    $purito->update(['name' => 'Purito Seoul']);
    expect(SearchSpelling::correct('purito seuol'))->toBe('Purito Seoul');
});

it('turns off from Store -> Site Search -> Spelling mistakes', function () {
    srShop();

    expect(HeaderSettings::SCHEMA['search_fuzzy_enabled'][2])->toBeTrue()
        ->and(\App\Http\Controllers\Admin\SiteSearchApiController::TABS['spelling'][2])->toBe(['search_fuzzy_enabled']);

    app(HeaderSettings::class)->save(['search_fuzzy_enabled' => false]);

    expect(srApi('medicob')['corrected'])->toBeNull()
        ->and(test()->get('/shop/?s=medicob')->getContent())->not->toContain('Showing results for');
});

it('counts a corrected search as the misspelling it was, not as a popular term', function () {
    srShop();

    test()->getJson('/api/search?q=medicob&log=1')->assertOk();

    expect(DB::table('search_terms')->where('term', 'medicob')->value('results'))->toBe(0);
});

it('stays small and fast on a 3,000-product catalogue', function () {
    srShop();
    mt_srand(11);
    $letters = 'abcdefghiklmnoprstuy';
    $vocab = ['Hyaluronic', 'Ceramide', 'Niacinamide', 'Centella', 'Serum', 'Toner', 'Cream', 'Cleansing', 'Foam', 'Essence', 'Ampoule', 'Sunscreen', 'Mask', 'Collagen', 'Retinol', 'Peptide'];

    for ($i = 0; $i < 1800; $i++) {
        $w = '';
        for ($j = 0, $n = mt_rand(4, 10); $j < $n; $j++) {
            $w .= $letters[mt_rand(0, strlen($letters) - 1)];
        }
        $vocab[] = ucfirst($w);
    }

    $rows = [];
    for ($i = 0; $i < 3000; $i++) {
        $words = [];
        for ($j = 0, $n = mt_rand(4, 8); $j < $n; $j++) {
            $words[] = $vocab[mt_rand(0, count($vocab) - 1)];
        }
        $rows[] = ['name' => implode(' ', $words), 'slug' => 'bulk-' . $i, 'status' => 'publish', 'is_visible' => 1, 'price' => 10, 'created_at' => now(), 'updated_at' => now()];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('products')->insert($chunk);
    }

    SearchSpelling::flush();
    $dict = SearchSpelling::dictionary();

    expect(strlen(serialize($dict)))->toBeLessThan(100 * 1024)
        ->and(SearchSpelling::correct('Medicob'))->toBe('Medicube')
        ->and(SearchSpelling::correct('ميديكيوب'))->toBe('Medicube');
});
