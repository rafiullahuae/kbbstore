<?php

declare(strict_types=1);

/*
 * The owner, 15 November 2026: "we have added some recently few products,
 * please do translation and give me patch."
 *
 * What a defect looks like on the shop: a product page on /ar still showing
 * English -- the name in the title bar, the paragraph beside the price, the
 * description tab -- and its <title> / meta description still English to a
 * search engine (measured live on 15 November: "Biodance - Collagen Peptide
 * Eye Cream" as the /ar heading, the eos brand page reading "eos" on
 * /ar/brands/); or a translation whose markup has drifted from the English,
 * so the Arabic page loses a heading, a bullet, a blank line or one of the
 * VT page's per-phrase <span>s, or draws its blank lines in other places than
 * the English (the 2.60.671 defect, which `kbb:ar-structure` would then have
 * to repair); or the owner's own Arabic replaced by the package.
 *
 * The English each Arabic is checked against is the live page's, fetched on
 * 15 November (tests/Fixtures/new-products-15-nov/english.json).
 *
 * MUTATION, RUN: drop the published-row guard in put() -> the owner's Arabic is
 * replaced and the second case is red; drop a </li> or a "kbb-bl" spacer from
 * any description -> the tag-for-tag case is red (HtmlStructure); move the
 * Anua description's "kbb-bl" spacer above "صُنع في كوريا" -> the tag case
 * AND the lined-up case are red (the repair scan lists it as FIX); drop a size
 * ("473") from an Arabic body -> red (every number of the English must be in
 * the Arabic).
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\RecentLocalise\HtmlStructure;
use App\Services\Translation\ArabicStructureRepair;
use App\Services\Translation\TranslationStore;
use App\Support\BlockStructure;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

const LN15_FILE = 'migrations/2028_02_15_100000_arabic_new_products_15_november.php';

function ln15Migration(): object
{
    return require database_path(LN15_FILE);
}

function ln15Const(string $name): array
{
    return (new ReflectionClassConstant(ln15Migration(), $name))->getValue();
}

/** slug => [name, short_description, description] of the live English. */
function ln15English(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/new-products-15-nov/english.json')), true);
}

/** The live English as the editor STORES it: a blank line as `<div><br></div>`, not the rendered class. */
function ln15Stored(?string $html): ?string
{
    return $html === null ? null : str_replace(['<div class="kbb-bl"></div>', '<p class="kbb-bl"></p>'], ['<div><br></div>', '<p><br></p>'], $html);
}

it('carries Arabic for every product still English on /ar, tag for tag and number for number with its live English', function () {
    $data = ln15Const('PRODUCTS');
    $english = ln15English();
    expect(array_keys($data))->toBe(array_keys($english));

    foreach ($data as $slug => [$name, $short, $desc]) {
        $en = $english[$slug];

        // "<brand in Arabic> - <product>", as every Arabic product name on the shop.
        expect(preg_match('/^\p{Arabic}.* - .*\p{Arabic}/u', (string) $name))->toBe(1, $slug)
            ->and(preg_match('/[A-Za-z]/', (string) $name))->toBe(0, $slug)
            ->and(HtmlStructure::problem($en['name'], (string) $name))->toBeNull($slug);

        // A product with no short description in English gets none.
        expect($short === null)->toBe($en['short_description'] === null, $slug);

        foreach (['short_description' => $short, 'description' => $desc] as $field => $value) {
            if ($value === null) {
                continue;
            }
            expect(preg_match('/\p{Arabic}/u', $value))->toBe(1, $slug)
                // An English sentence left behind is the defect this package fixes.
                ->and(preg_match('/\b(the|and|with|your|skin|apply|helps|made|korea|size)\b/i', strip_tags($value)))->toBe(0, $slug.' '.$field)
                // Every tag of the English, where it was, and every number in it.
                ->and(HtmlStructure::problem((string) $en[$field], $value))->toBeNull($slug.' '.$field)
                // Blank lines and lists in the same places as the English (2.60.671).
                ->and(BlockStructure::shape($value))->toBe(BlockStructure::shape((string) $en[$field]), $slug.' '.$field);
        }

        expect($desc)->toContain('طريقة الاستخدام');
        if (stripos($en['description'], 'About Brand') !== false) {
            expect($desc)->toContain('عن العلامة التجارية');
        }
        if (str_contains($en['description'], 'Made in Korea')) {
            expect($desc)->toContain('صُنع في كوريا');
        } else {
            // Nothing is added that the English lacks.
            expect($desc)->not->toContain('كوريا');
        }
    }

    // eos is new and its /ar brand page read "eos": its Arabic name travels too.
    expect(ln15Const('BRANDS'))->toBe(['eos' => 'إيوس']);

    // The one product tab still English beside an Arabic description: medicube
    // AGE-R Booster Pro's "Warranty" (title and body).
    $tabs = ln15Const('TABS');
    $tabsEnglish = json_decode((string) file_get_contents(base_path('tests/Fixtures/new-products-15-nov/english-tabs.json')), true);
    expect(array_keys($tabs))->toBe(array_keys($tabsEnglish));
    foreach ($tabs as $slug => $rows) {
        foreach ($rows as [$english, $title, $body]) {
            expect(preg_match('/^\p{Arabic}[\p{Arabic}\s]*$/u', $title))->toBe(1, $slug)
                ->and(HtmlStructure::problem($tabsEnglish[$slug][$english], $body))->toBeNull($slug)
                ->and(BlockStructure::shape($body))->toBe(BlockStructure::shape($tabsEnglish[$slug][$english]), $slug)
                ->and(preg_match('/\b(the|and|warranty|product|device)\b/i', strip_tags($body)))->toBe(0, $slug);
        }
    }
});

it('is lined up with the stored English: kbb:ar-structure finds nothing to fix in any of them, the Warranty tab included', function () {
    $ids = [];
    foreach (ln15English() as $slug => $en) {
        $ids[] = Product::create(['slug' => $slug, 'name' => $en['name'], 'status' => 'publish', 'is_visible' => true, 'price' => 9900,
            'stock_status' => 'instock', 'short_description' => ln15Stored($en['short_description']), 'description' => ln15Stored($en['description'])])->id;
    }

    // and the AGE-R Booster Pro with its own Warranty tab
    $ager = Product::create(['slug' => 'medicube-age-r-booster-pro', 'name' => 'medicube - AGE-R Booster Pro', 'status' => 'publish', 'is_visible' => true,
        'price' => 99900, 'stock_status' => 'instock', 'description' => '<p>English</p>']);
    $warranty = json_decode((string) file_get_contents(base_path('tests/Fixtures/new-products-15-nov/english-tabs.json')), true)['medicube-age-r-booster-pro']['Warranty'];
    $tabId = DB::table('product_tabs')->insertGetId(['product_id' => $ager->id, 'title' => 'Warranty', 'body' => $warranty, 'position' => 10,
        'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $ids[] = $ager->id;

    ln15Migration()->up();
    $scan = ArabicStructureRepair::scan($ids);
    $tabRow = fn (string $f) => DB::table('translations')->where(['locale' => 'ar', 'group' => 'product_tabs', 'item_id' => $tabId, 'field' => $f])->first();
    expect($tabRow('title')->value)->toBe('الضمان')
        ->and($tabRow('title')->source_hash)->toBe(sha1('Warranty'))
        ->and($tabRow('body')->source_hash)->toBe(sha1($warranty))
        ->and($tabRow('body')->status)->toBe('published');

    expect($scan['fixable'])->toBe([])
        ->and($scan['human'])->toBe([])
        // every short description and description this package writes, each lined up already
        // (and the Warranty tab's body)
        ->and($scan['aligned'])->toBe(1 + count(array_filter(array_merge(...array_values(array_map(fn ($v) => [$v[1], $v[2]], ln15Const('PRODUCTS')))), fn ($v) => $v !== null)));

    $this->artisan('kbb:ar-structure', ['--dry-run' => true])
        ->doesntExpectOutputToContain('FIX ')
        ->assertSuccessful();
});

it('publishes by slug with the English sha1, never over Arabic the owner typed, and /ar shows Arabic -- title, meta description and brand included', function () {
    ArabicShop::on();
    $en = ln15English()['eos-shea-better-24h-moisture-body-lotion-jasmine-peach'];
    $brand = Brand::create(['slug' => 'eos', 'name' => 'eos']);
    $p = Product::create(['slug' => 'eos-shea-better-24h-moisture-body-lotion-jasmine-peach', 'name' => $en['name'], 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 6900, 'stock_status' => 'instock',
        'short_description' => $en['short_description'], 'description' => ln15Stored($en['description'])]);
    $owned = Product::create(['slug' => 'biodance-collagen-peptide-eye-cream', 'name' => 'Biodance - Collagen Peptide Eye Cream', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'short_description' => 'English short', 'description' => '<p>English</p>']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'short_description',
        'value' => 'وصف كتبه المالك', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    // A draft (unpublished) row is not the owner's word: it is filled.
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'name',
        'value' => 'مسودة', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    $before = DB::table('translations')->count();

    ln15Migration()->up();
    TranslationStore::flush();

    [$name, $short, $desc] = ln15Const('PRODUCTS')['eos-shea-better-24h-moisture-body-lotion-jasmine-peach'];
    $get = fn (string $group, int $id, string $f) => DB::table('translations')->where(['locale' => 'ar', 'group' => $group, 'item_id' => $id, 'field' => $f])->first();

    expect($get('products', $p->id, 'name')->value)->toBe($name)
        ->and($get('products', $p->id, 'name')->status)->toBe('published')
        ->and($get('products', $p->id, 'name')->source_hash)->toBe(sha1($en['name']))
        ->and($get('products', $p->id, 'short_description')->value)->toBe($short)
        ->and($get('products', $p->id, 'short_description')->source_hash)->toBe(sha1($en['short_description']))
        ->and($get('products', $p->id, 'description')->value)->toBe($desc)
        ->and($get('products', $p->id, 'description')->source_hash)->toBe(sha1(ln15Stored($en['description'])))
        ->and($get('brands', $brand->id, 'name')->value)->toBe('إيوس')
        // His own Arabic stays; the draft is replaced by the published package text.
        ->and($get('products', $owned->id, 'short_description')->value)->toBe('وصف كتبه المالك')
        ->and($get('products', $owned->id, 'name')->value)->toBe('بيودانس - كريم العين بالكولاجين والببتيد')
        ->and($get('products', $owned->id, 'name')->status)->toBe('published')
        // eos: 3 fields + its brand new; Biodance: description new, name updated in place, short his.
        ->and(DB::table('translations')->count())->toBe($before + 5);

    $html = test()->get('/ar/product/eos-shea-better-24h-moisture-body-lotion-jasmine-peach/')->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
    expect($html)->toContain(e($name))->not->toContain('Peach Extract, Glycerin')->not->toContain('lightweight, fast-absorbing')
        // SEO: the Arabic page's title and description are Arabic.
        ->and($t[1])->toContain('لوشن الجسم شيا بيتر')->not->toContain('Jasmine')
        ->and(html_entity_decode($d[1]))->toContain('ترطيبًا يدوم طويلًا')
        ->and($d[1])->not->toContain('hydration');

    // Run twice: nothing changes, nothing duplicates.
    $snapshot = DB::table('translations')->orderBy('id')->get(['id', 'value', 'status', 'source_hash'])->toArray();
    ln15Migration()->up();
    expect(DB::table('translations')->orderBy('id')->get(['id', 'value', 'status', 'source_hash'])->toArray())->toEqual($snapshot);

    // down() takes back only its own unchanged rows: what the owner edited stays.
    DB::table('translations')->where(['item_id' => $p->id, 'group' => 'products', 'field' => 'description'])->update(['value' => 'عدّله المالك']);
    ln15Migration()->down();
    expect($get('products', $p->id, 'name'))->toBeNull()
        ->and($get('brands', $brand->id, 'name'))->toBeNull()
        ->and($get('products', $p->id, 'description')->value)->toBe('عدّله المالك')
        ->and($get('products', $owned->id, 'short_description')->value)->toBe('وصف كتبه المالك');
});

it('never overwrites a brand name the owner already gave in Arabic, skips a slug the shop does not have, and is literals only', function () {
    $brand = Brand::create(['slug' => 'eos', 'name' => 'eos']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'brands', 'item_id' => $brand->id, 'field' => 'name',
        'value' => 'إي أو إس', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    $before = DB::table('translations')->count();

    ln15Migration()->up();
    expect(DB::table('translations')->count())->toBe($before)
        ->and(DB::table('translations')->where(['group' => 'brands', 'item_id' => $brand->id])->value('value'))->toBe('إي أو إس');

    $src = (string) file_get_contents(database_path(LN15_FILE));
    // No App\ class but the guarded cache flush (the 2.60.512 rule): a package
    // must apply on a shop whose classes have moved on.
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    preg_match_all('/\\\\?App\\\\[A-Za-z\\\\]+/', $code, $m);
    expect(array_values(array_unique($m[0])))->toBe(['\App\Services\Translation\TranslationStore'])
        ->and($src)->not->toContain('use App\\')
        ->and($src)->toContain('class_exists(\App\Services\Translation\TranslationStore::class)');
});
