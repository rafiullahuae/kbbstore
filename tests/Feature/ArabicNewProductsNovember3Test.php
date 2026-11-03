<?php

declare(strict_types=1);

/*
 * The owner, 3 November 2026: "please translate the recent added products into
 * arabic fully including everything. and give me patch".
 *
 * What a defect looks like on the shop: a product page on /ar still showing
 * English -- the name in the title bar, the paragraph beside the price, the
 * description tab -- and its <title> / meta description still English to a
 * search engine (measured live on 3 November: "الدكتورة ألثيا Dr. Althea -
 * Aqua Marine Deep Serum" as the title, the English blurb as the meta
 * description); or a translation whose markup has drifted from the English, so
 * the Arabic page loses a heading, a bullet or a blank line the English has;
 * or the owner's own Arabic replaced by the package.
 *
 * MUTATION, RUN: drop the published-row guard in put() -> the owner's Arabic is
 * replaced and the second case is red; drop a </li> or the "kbb-bl" spacer from
 * any description -> the tag-for-tag case is red; write the name field only ->
 * the /ar page still shows the English blurb and is red.
 */

use App\Models\Brand;
use App\Models\Product;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

const LT4_FILE = 'migrations/2028_01_06_100000_arabic_new_products_3_november.php';

/*
 * The tag sequence of each product's LIVE English (short description,
 * description), read off kbeautybliss.com on 3 November -- text removed, every
 * tag and attribute kept. The Arabic must carry exactly these tags, in order.
 */
const LT4_ENGLISH_TAGS = [
    'dr-althea-aqua-marine-deep-serum' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'dr-althea-aqua-marine-jelly-mist' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'dr-althea-pore-refresh-grinding-cleansing-balm' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'medicube-coconut-rose-deodrant-83g' => ['<p></p>', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div><b><br></b></div>'],
];

function lt4Migration(): object
{
    return require database_path(LT4_FILE);
}

function lt4Const(string $name): array
{
    return (new ReflectionClassConstant(lt4Migration(), $name))->getValue();
}

function lt4Tags(?string $html): string
{
    preg_match_all('/<[^>]+>/', (string) $html, $m);

    return implode('', $m[0]);
}

it('carries Arabic for the four products still English on /ar, all three fields, tag for tag with the English', function () {
    $data = lt4Const('PRODUCTS');
    expect(array_keys($data))->toBe(array_keys(LT4_ENGLISH_TAGS));

    foreach ($data as $slug => [$name, $short, $desc]) {
        // "<brand in Arabic> - <product>", as every Arabic product name on the shop.
        expect(preg_match('/^\p{Arabic}.* - .*\p{Arabic}/u', (string) $name))->toBe(1, $slug)
            ->and(preg_match('/[A-Za-z]/', (string) $name))->toBe(0, $slug);

        foreach ([[$short, 0], [$desc, 1]] as [$field, $i]) {
            expect($field)->toBeString($slug)
                ->and(preg_match('/\p{Arabic}/u', $field))->toBe(1, $slug)
                // An English sentence left behind is the defect this package fixes.
                ->and(preg_match('/\b(the|and|with|your|skin|apply|helps|made|korea|size)\b/i', strip_tags($field)))->toBe(0, $slug)
                // Every tag of the English, where it was: a heading, a bullet, a spacer.
                ->and(lt4Tags($field))->toBe(LT4_ENGLISH_TAGS[$slug][$i], $slug);
        }

        expect($desc)->toContain('صُنع في كوريا')->toContain('طريقة الاستخدام')->toContain('عن العلامة التجارية');
    }

    // Every brand on /ar already reads Arabic: none is written.
    expect(lt4Const('BRANDS'))->toBe([]);
});

it('publishes by slug with the English sha1, never over Arabic the owner typed, and /ar shows Arabic -- title and meta description included', function () {
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'dr-althea-lt4', 'name' => 'Dr.Althea']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'brands', 'item_id' => $brand->id, 'field' => 'name',
        'value' => 'الدكتورة ألثيا', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    $p = Product::create(['slug' => 'dr-althea-aqua-marine-deep-serum', 'name' => 'Dr. Althea - Aqua Marine Deep Serum', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 8700, 'stock_status' => 'instock',
        'short_description' => 'Having one of those days where your skin is in desperate need of hydration?',
        'description' => '<div><b>Key Benefits:</b></div><div>English body text.</div>']);
    $owned = Product::create(['slug' => 'medicube-coconut-rose-deodrant-83g', 'name' => 'Medicube - Coconut & Rose Deodorant', 'status' => 'publish', 'is_visible' => true,
        'price' => 10900, 'stock_status' => 'instock', 'short_description' => 'English short', 'description' => '<p>English</p>']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'short_description',
        'value' => 'وصف كتبه المالك', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    // A draft (unpublished) row is not the owner's word: it is filled.
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'name',
        'value' => 'مسودة', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    $before = DB::table('translations')->count();

    lt4Migration()->up();
    TranslationStore::flush();

    [$name, $short, $desc] = lt4Const('PRODUCTS')['dr-althea-aqua-marine-deep-serum'];
    $get = fn (int $id, string $f) => DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $id, 'field' => $f])->first();

    expect($get($p->id, 'name')->value)->toBe($name)
        ->and($get($p->id, 'name')->status)->toBe('published')
        ->and($get($p->id, 'name')->source_hash)->toBe(sha1('Dr. Althea - Aqua Marine Deep Serum'))
        ->and($get($p->id, 'short_description')->value)->toBe($short)
        ->and($get($p->id, 'short_description')->source_hash)->toBe(sha1('Having one of those days where your skin is in desperate need of hydration?'))
        ->and($get($p->id, 'description')->value)->toBe($desc)
        // His own Arabic stays; the draft is replaced by the published package text.
        ->and($get($owned->id, 'short_description')->value)->toBe('وصف كتبه المالك')
        ->and($get($owned->id, 'name')->value)->toBe('ميديكوب - مزيل العرق بجوز الهند والورد')
        ->and($get($owned->id, 'name')->status)->toBe('published')
        // Dr.Althea: 3 new; Medicube: description new, name updated in place, short his.
        ->and(DB::table('translations')->count())->toBe($before + 4);

    $html = test()->get('/ar/product/dr-althea-aqua-marine-deep-serum/')->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
    expect($html)->toContain(e($name))->not->toContain('English body text')->not->toContain('desperate need of hydration')
        // SEO: the Arabic page's title and description are Arabic.
        ->and($t[1])->toContain('سيروم أكوا مارين ديب')->not->toContain('Aqua Marine')
        ->and(html_entity_decode($d[1]))->toContain('بشرتكِ في أمسّ الحاجة إلى الترطيب')
        ->and($d[1])->not->toContain('desperate');

    // Run twice: nothing changes, nothing duplicates.
    $snapshot = DB::table('translations')->orderBy('id')->get(['id', 'value', 'status', 'source_hash'])->toArray();
    lt4Migration()->up();
    expect(DB::table('translations')->orderBy('id')->get(['id', 'value', 'status', 'source_hash'])->toArray())->toEqual($snapshot);

    // down() takes back only its own unchanged rows: what the owner edited stays.
    DB::table('translations')->where(['item_id' => $p->id, 'field' => 'description'])->update(['value' => 'عدّله المالك']);
    lt4Migration()->down();
    expect($get($p->id, 'name'))->toBeNull()
        ->and($get($p->id, 'description')->value)->toBe('عدّله المالك')
        ->and($get($owned->id, 'short_description')->value)->toBe('وصف كتبه المالك');
});

it('skips a slug the shop does not have, and is literals only', function () {
    $before = DB::table('translations')->count();
    lt4Migration()->up();
    expect(DB::table('translations')->count())->toBe($before);

    $src = (string) file_get_contents(database_path(LT4_FILE));
    // No App\ class but the guarded cache flush (the 2.60.512 rule): a package
    // must apply on a shop whose classes have moved on.
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    preg_match_all('/\\\\?App\\\\[A-Za-z\\\\]+/', $code, $m);
    expect(array_values(array_unique($m[0])))->toBe(['\App\Services\Translation\TranslationStore'])
        ->and($src)->not->toContain('use App\\')
        ->and($src)->toContain('class_exists(\App\Services\Translation\TranslationStore::class)');
});
