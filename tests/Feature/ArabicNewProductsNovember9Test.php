<?php

declare(strict_types=1);

/*
 * The owner, 9 November 2026: "i have added some recent products. please
 * translate them properly like before you always do for me. and give me the
 * patch."
 *
 * What a defect looks like on the shop: a product page on /ar still showing
 * English -- the name in the title bar, the paragraph beside the price, the
 * description tab -- and its <title> / meta description still English to a
 * search engine (measured live on 9 November: "Dr. Althea - Aqua Glowing
 * Sunscreen" as the /ar heading, the English blurb as the meta description);
 * or a translation whose markup has drifted from the English, so the Arabic
 * page loses a heading, a bullet, a blank line or one of the per-word <span>s
 * the English has; or the owner's own Arabic replaced by the package.
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

const LT5_FILE = 'migrations/2028_01_16_100000_arabic_new_products_9_november.php';

/*
 * The tag sequence of each product's LIVE English (short description,
 * description), read off kbeautybliss.com on 9 November -- text removed, every
 * tag and attribute kept. The Arabic must carry exactly these tags, in order.
 */
const LT5_ENGLISH_TAGS = [
    'arencia-red-collagen-jello-mask-set' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b></div><div class="kbb-bl"></div><div><b></b></div>'],
    'd-alba-white-truffle-first-spray-serum' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'dr-althea-15-niacinamide-purity-serum' => ['<div class="dKBM_W_productInfoBox"><section><div></div></section></div>', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b></div><div class="kbb-bl"></div><div><b></b></div>'],
    'dr-althea-aqua-glowing-sunscreen' => ['<div></div>', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div></div><div class="kbb-bl"></div><div><b></b></div>'],
    'dr-althea-green-relief-amino-gel-cleanser' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div><div></div><div class="kbb-bl"></div></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'dr-althea-green-tea-fresh-sunscreen' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'medicube-azelaic-acid-niacinamide-deep-clean-foam-cleanser' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'medicube-egf-nad-firming-glow-set' => ['<p></p>', '<div><b></b></div><ul><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li><li><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></li></ul><p class="kbb-bl"></p><p><b></b><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></p><p class="kbb-bl"></p><p><b></b></p><ul><li><div><div><p><b></b></p></div></div></li><li><div><div><p><b></b></p></div></div></li><li><div><div><p><b></b></p></div></div></li></ul>'],
    'medicube-egf-nad-firming-multi-balm' => ['<p class="p1"><span></span><b></b></p>', '<b><br></b><ul><li><div><div><p><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span></p></div></div></li><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li></ul><div class="kbb-bl"></div><div><p><strong></strong></p><p class="kbb-bl"></p><p><strong></strong></p><p class="kbb-bl"></p><p><b></b><span></span></p></div>'],
    'medicube-kojic-acid-turmeric-vita-jelly-eye-mask-28-pairs' => ['<div><div><div><div><div><div><div><div><p></p></div></div></div></div></div></div></div></div>', '<p><span><b></b></span></p><ul><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li><li><div><div><p></p></div></div></li></ul><p><span><b></b></span></p><p></p><p><span><b></b></span></p><p></p><p><b></b></p>'],
    'medicube-pdrn-pink-collagen-jelly-eye-mask-28-pairs' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'medicube-retinol-boosting-serum' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'medicube-rosemary-pdrn-silk-hair-oil' => ['<p><span></span><span></span></p>', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
    'numbuzin-no-9-nad-peptides-dewy-sun-essence' => ['', '<div><b></b></div><div><ul><li></li><li></li><li></li><li></li><li></li></ul></div><div></div><div class="kbb-bl"></div><div><b></b></div><div><ul><li></li><li></li></ul><div class="kbb-bl"></div></div><div><b></b></div><div><ul><li></li><li></li><li></li></ul><div class="kbb-bl"></div></div><div></div><div class="kbb-bl"></div><div><b></b><span></span></div><div class="kbb-bl"></div><div><b></b></div>'],
];

const LT5_NO_BRAND_LINE = ['medicube-egf-nad-firming-glow-set', 'medicube-egf-nad-firming-multi-balm', 'medicube-kojic-acid-turmeric-vita-jelly-eye-mask-28-pairs'];

function lt5Migration(): object
{
    return require database_path(LT5_FILE);
}

function lt5Const(string $name): array
{
    return (new ReflectionClassConstant(lt5Migration(), $name))->getValue();
}

function lt5Tags(?string $html): string
{
    preg_match_all('/<[^>]+>/', (string) $html, $m);

    return implode('', $m[0]);
}

it('carries Arabic for the fourteen products still English on /ar, all three fields, tag for tag with the English', function () {
    $data = lt5Const('PRODUCTS');
    expect(array_keys($data))->toBe(array_keys(LT5_ENGLISH_TAGS));

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
                ->and(lt5Tags($field))->toBe(LT5_ENGLISH_TAGS[$slug][$i], $slug);
        }

        expect($desc)->toContain('طريقة الاستخدام');
        // The three Medicube pages whose live English has no "Made in Korea" /
        // "About Brand" line get none either: nothing is added that the English lacks.
        if (! in_array($slug, LT5_NO_BRAND_LINE, true)) {
            expect($desc)->toContain('صُنع في كوريا')->toContain('عن العلامة التجارية');
        }
    }

    // Every brand on /ar already reads Arabic: none is written.
    expect(lt5Const('BRANDS'))->toBe([]);
});

it('publishes by slug with the English sha1, never over Arabic the owner typed, and /ar shows Arabic -- title and meta description included', function () {
    ArabicShop::on();
    $brand = Brand::create(['slug' => 'dr-althea-lt5', 'name' => 'Dr.Althea']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'brands', 'item_id' => $brand->id, 'field' => 'name',
        'value' => 'الدكتورة ألثيا', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    $p = Product::create(['slug' => 'dr-althea-aqua-glowing-sunscreen', 'name' => 'Dr. Althea - Aqua Glowing Sunscreen', 'brand_id' => $brand->id,
        'status' => 'publish', 'is_visible' => true, 'price' => 12900, 'stock_status' => 'instock',
        'short_description' => 'This chemical sunscreen fitted with SPF50+ PA++++ defends skin against harmful UV rays while boosting skin moisture.',
        'description' => '<div><b>Key Benefits:</b></div><div>English body text.</div>']);
    $owned = Product::create(['slug' => 'medicube-retinol-boosting-serum', 'name' => 'medicube - Retinol Boosting Serum', 'status' => 'publish', 'is_visible' => true,
        'price' => 9900, 'stock_status' => 'instock', 'short_description' => 'English short', 'description' => '<p>English</p>']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'short_description',
        'value' => 'وصف كتبه المالك', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
    // A draft (unpublished) row is not the owner's word: it is filled.
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $owned->id, 'field' => 'name',
        'value' => 'مسودة', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    $before = DB::table('translations')->count();

    lt5Migration()->up();
    TranslationStore::flush();

    [$name, $short, $desc] = lt5Const('PRODUCTS')['dr-althea-aqua-glowing-sunscreen'];
    $get = fn (int $id, string $f) => DB::table('translations')->where(['locale' => 'ar', 'group' => 'products', 'item_id' => $id, 'field' => $f])->first();

    expect($get($p->id, 'name')->value)->toBe($name)
        ->and($get($p->id, 'name')->status)->toBe('published')
        ->and($get($p->id, 'name')->source_hash)->toBe(sha1('Dr. Althea - Aqua Glowing Sunscreen'))
        ->and($get($p->id, 'short_description')->value)->toBe($short)
        ->and($get($p->id, 'short_description')->source_hash)->toBe(sha1('This chemical sunscreen fitted with SPF50+ PA++++ defends skin against harmful UV rays while boosting skin moisture.'))
        ->and($get($p->id, 'description')->value)->toBe($desc)
        // His own Arabic stays; the draft is replaced by the published package text.
        ->and($get($owned->id, 'short_description')->value)->toBe('وصف كتبه المالك')
        ->and($get($owned->id, 'name')->value)->toBe('ميديكوب - سيروم الريتينول المعزّز')
        ->and($get($owned->id, 'name')->status)->toBe('published')
        // Dr.Althea: 3 new; Medicube: description new, name updated in place, short his.
        ->and(DB::table('translations')->count())->toBe($before + 4);

    $html = test()->get('/ar/product/dr-althea-aqua-glowing-sunscreen/')->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
    expect($html)->toContain(e($name))->not->toContain('English body text')->not->toContain('harmful UV rays')
        // SEO: the Arabic page's title and description are Arabic.
        ->and($t[1])->toContain('واقي الشمس أكوا غلوينغ')->toContain('الدكتورة ألثيا')->not->toContain('Aqua Glowing')
        ->and(html_entity_decode($d[1]))->toContain('من الأشعة فوق البنفسجية الضارة')
        ->and($d[1])->not->toContain('harmful');

    // Run twice: nothing changes, nothing duplicates.
    $snapshot = DB::table('translations')->orderBy('id')->get(['id', 'value', 'status', 'source_hash'])->toArray();
    lt5Migration()->up();
    expect(DB::table('translations')->orderBy('id')->get(['id', 'value', 'status', 'source_hash'])->toArray())->toEqual($snapshot);

    // down() takes back only its own unchanged rows: what the owner edited stays.
    DB::table('translations')->where(['item_id' => $p->id, 'field' => 'description'])->update(['value' => 'عدّله المالك']);
    lt5Migration()->down();
    expect($get($p->id, 'name'))->toBeNull()
        ->and($get($p->id, 'description')->value)->toBe('عدّله المالك')
        ->and($get($owned->id, 'short_description')->value)->toBe('وصف كتبه المالك');
});

it('skips a slug the shop does not have, and is literals only', function () {
    $before = DB::table('translations')->count();
    lt5Migration()->up();
    expect(DB::table('translations')->count())->toBe($before);

    $src = (string) file_get_contents(database_path(LT5_FILE));
    // No App\ class but the guarded cache flush (the 2.60.512 rule): a package
    // must apply on a shop whose classes have moved on.
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    preg_match_all('/\\\\?App\\\\[A-Za-z\\\\]+/', $code, $m);
    expect(array_values(array_unique($m[0])))->toBe(['\App\Services\Translation\TranslationStore'])
        ->and($src)->not->toContain('use App\\')
        ->and($src)->toContain('class_exists(\App\Services\Translation\TranslationStore::class)');
});
