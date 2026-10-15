<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Translation;
use App\Services\Translation\TranslationStore;
use App\Support\ArabicSearch;
use App\Support\SearchSpelling;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * The hybrid search (Lane AR round 3). The owner: "in arabic if searched in
 * english or arabic, it should give results ... it should not messed or
 * disturb anything in english version as the site is live."
 *
 * Before: an Arabic query matched nothing in the products table (English
 * names), and only found a brand when the spelling fix happened to read its
 * letters into the English one ("واقي شمس" -> nothing at all). And /ar's
 * search box served English names and unprefixed addresses, because its
 * cached answer was shared with the English box.
 */
beforeEach(function () {
    DB::table('product_set_items')->delete();
    DB::table('category_product')->delete();
    DB::table('products')->delete();
    DB::table('brands')->delete();
    DB::table('categories')->delete();
    DB::table('translations')->whereIn('group', ['brands', 'categories', 'products'])->delete();
    DB::table(ArabicSearch::TABLE)->delete();
    Cache::flush();
    SearchSpelling::flush();
});

afterEach(function () {
    app()->setLocale('en');
});

function asProduct(string $name, ?Brand $brand, array $extra = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'as-'.$n.'-'.uniqid(),
        'name' => $name,
        'brand_id' => $brand?->id,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'total_sales' => max(0, 100000 - $n),
    ], $extra));
}

function asArabic(string $group, int $id, string $value): void
{
    TranslationStore::put('ar', $group, $id, 'name', $value, Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);
}

function asSlugs(string $uri): array
{
    $html = (string) test()->get($uri)->assertOk()->getContent();
    preg_match_all('#<a class="kbb-card-shot" href="[^"]*/product/([^/"]+)/"#', $html, $m);

    return $m[1];
}

function asBox(string $uri): array
{
    return test()->getJson($uri)->assertOk()->json();
}

/** A small shop: two brands, a serum category, a sunscreen category, Arabic names published. */
function asShop(): array
{
    $anua = Brand::create(['name' => 'Anua', 'slug' => 'anua']);
    $cosrx = Brand::create(['name' => 'COSRX', 'slug' => 'cosrx']);
    $serums = Category::create(['name' => 'Serums', 'slug' => 'serums']);
    $sun = Category::create(['name' => 'Sunscreens', 'slug' => 'sunscreens']);
    asArabic('brands', $anua->id, 'أنوا');
    asArabic('categories', $serums->id, 'سيروم');
    asArabic('categories', $sun->id, 'واقيات الشمس');

    $toner = asProduct('Heartleaf 77 Soothing Toner', $anua);
    $pdrn = asProduct('PDRN Hyaluronic Capsule Serum', $anua);
    $snail = asProduct('Advanced Snail 96 Mucin Power Essence', $cosrx);
    $stick = asProduct('Matte Sun Stick 18g', $cosrx);
    $truffle = asProduct('White Truffle Ampoule', $cosrx);

    $pdrn->categories()->attach($serums->id);
    $stick->categories()->attach($sun->id);

    asArabic('products', $toner->id, 'تونر هارتليف 77 المهدّئ');
    asArabic('products', $pdrn->id, 'سيروم الكبسولات بي دي آر إن');
    asArabic('products', $truffle->id, 'أمبولة الكمأة البيضاء');

    return compact('anua', 'cosrx', 'serums', 'sun', 'toner', 'pdrn', 'snail', 'stick', 'truffle');
}

/*
 * 1. ONE SPELLING FOR BOTH SIDES. Mutation: drop the ة → ه line in normalise()
 *    and "ماسكة" stops matching "ماسكه".
 */
it('normalises Arabic the same way on both sides of the match', function () {
    expect(ArabicSearch::normalise('أَمْبُولَةُ'))->toBe('امبوله')
        ->and(ArabicSearch::normalise('سيـــروم'))->toBe('سيروم')
        ->and(ArabicSearch::normalise('إيسنس آلوفيرا'))->toBe('ايسنس الوفيرا')
        ->and(ArabicSearch::normalise('مؤكد شاطئ مستشفى'))->toBe('موكد شاطي مستشفي')
        ->and(ArabicSearch::normalise('٧٧ ۱۰'))->toBe('77 10')
        ->and(ArabicSearch::normalise('سيروم، 50%!'))->toBe('سيروم 50')
        ->and(ArabicSearch::words('الشمس واقي'))->toBe(['شمس', 'واقي']);
});

/*
 * 2. "أنوا" IS "Anua". A query that is nothing but a brand gets exactly the
 *    English answer for the brand's name -- same rows, same order -- in the box
 *    and on the results page. Mutation: remove the brandOnly() branch and the
 *    box answers from the Arabic plan instead (a different order) -- red.
 */
it('answers an Arabic brand name exactly as the English brand name', function () {
    $s = asShop();

    $en = asBox('/api/search?q=Anua');
    $ar = asBox('/api/search?q='.rawurlencode('أنوا'));

    expect($ar['groups'])->toBe($en['groups'])
        ->and($ar['query'])->toBe('أنوا')
        ->and($ar['corrected'])->toBeNull()
        ->and(asSlugs('/shop/?s='.rawurlencode('أنوا')))->toBe(asSlugs('/shop/?s=Anua'));
});

/*
 * 3. "سيروم" FINDS SERUMS FOUR WAYS: the English shop word (serum, ampoule,
 *    essence -> the snail essence and the truffle ampoule), the category the
 *    word names (PDRN, filed under Serums), and the Arabic name. Mutation:
 *    drop the TERMS arm and the snail essence is gone.
 */
it('finds serums from the Arabic word by name, category and the English shop word', function () {
    $s = asShop();

    $slugs = asSlugs('/shop/?s='.rawurlencode('سيروم'));

    expect($slugs)->toContain($s['pdrn']->slug)
        ->toContain($s['snail']->slug)
        ->toContain($s['truffle']->slug)
        ->not->toContain($s['toner']->slug)
        ->not->toContain($s['stick']->slug);

    $box = asBox('/api/search?q='.rawurlencode('سيروم'));
    expect(collect($box['groups'])->pluck('key')->all())->toContain('products')->toContain('categories');
});

/*
 * 4. "واقي شمس" FOUND NOTHING AT ALL. The phrase names the sunscreen
 *    category through the start of its Arabic words ("واقي" → واقيات,
 *    "شمس" ← الشمس), and the category's products answer. Mutation: take the
 *    "starts with" loop out of plan() and this is empty again.
 */
it('finds sunscreens for واقي شمس through the category it names', function () {
    $s = asShop();

    expect(asSlugs('/shop/?s='.rawurlencode('واقي شمس')))->toBe([$s['stick']->slug])
        ->and(asSlugs('/shop/?s='.rawurlencode('الشمس')))->toBe([$s['stick']->slug]);
});

/*
 * 5. EVERY WORD MUST MATCH, and an Arabic product name is searched through
 *    its precomputed copy: "الكمأة البيضاء" -> the truffle, "تونر أنوا" ->
 *    Anua's toner only.
 */
it('matches every word, on Arabic names and brands together', function () {
    $s = asShop();

    expect(asSlugs('/shop/?s='.rawurlencode('الكمأة البيضاء')))->toBe([$s['truffle']->slug])
        ->and(asSlugs('/shop/?s='.rawurlencode('تونر أنوا')))->toBe([$s['toner']->slug]);
});

/*
 * 6. ENGLISH IS NOT TOUCHED. A query in Latin letters never builds the Arabic
 *    phrase map and never reads the Arabic table -- on the English shop and on
 *    /ar alike. Mutation: call ArabicSearch::plan() for every query and the
 *    map appears in the cache.
 */
it('never runs any of the Arabic path for an English query', function () {
    asShop();
    \Tests\Support\ArabicShop::on();
    $sql = [];
    DB::listen(function ($q) use (&$sql) { $sql[] = $q->sql; });

    asBox('/api/search?q=serum');
    asSlugs('/shop/?s=serum');
    // The English shop never even builds the phrase map.
    expect(Cache::has(ArabicSearch::CACHE_KEY))->toBeFalse();

    // On /ar the header's trending words are drawn in Arabic (they read the
    // map), but an English query's own statements never touch the Arabic arms.
    asBox('/ar/api/search?q=serum');
    asSlugs('/ar/shop/?s=serum');

    expect(collect($sql)->filter(fn ($s) => str_contains($s, ArabicSearch::TABLE) || str_contains($s, 'category_product\" where \"category_id\" in'))->all())->toBe([]);
});

/*
 * 7. FLAT AS THE CATALOGUE GROWS. Three products or forty, an Arabic search
 *    is the same number of queries: every arm is a sub-select of the one
 *    product statement. Mutation: look the Arabic names up per product and
 *    the 40-product count climbs.
 */
it('costs the same number of queries for 3 products as for 40', function () {
    $s = asShop();
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    $uri = '/shop/?s='.rawurlencode('سيروم');
    $measure = function () use (&$n, $uri): int {
        test()->get($uri)->assertOk();   // warm: whatever the last write flushed
        $n = 0;
        test()->get($uri)->assertOk();

        return $n;
    };

    $small = $measure();

    for ($i = 0; $i < 37; $i++) {
        $p = asProduct('Glow Serum '.$i, $s['cosrx']);
        asArabic('products', $p->id, 'سيروم توهج '.$i);
    }
    $large = $measure();

    expect($large)->toBe($small)
        ->and(count(asSlugs($uri)))->toBeGreaterThan(3);
});

/*
 * 8. A WILDCARD IS A LETTER. "%" and "_" typed in Arabic search find only
 *    themselves. Mutation: build the LIKE without SearchTerms::like() and
 *    "س%" finds every name with a سين in it.
 */
it('treats LIKE wildcards in an Arabic query as plain text', function () {
    asShop();

    expect(asSlugs('/shop/?s='.rawurlencode('س%')))->toBe([])
        ->and(asSlugs('/shop/?s='.rawurlencode('_ين')))->toBe([]);
});

/*
 * 9. THE BOX WAS ONE CACHE FOR BOTH LANGUAGES. /ar's answer (Arabic names,
 *    /ar addresses) was served to the English box for five minutes, and the
 *    reverse. English keeps its key; any other language has its own.
 *    Mutation: drop the language from the key and the /ar rows below are the
 *    English ones.
 */
it('keeps the English and the Arabic box answers apart', function () {
    $s = asShop();
    \Tests\Support\ArabicShop::on();

    $en = asBox('/api/search?q=anua');
    $ar = asBox('/ar/api/search?q=anua');

    expect($en['groups'][0]['items'][0]['url'])->not->toContain('/ar/')
        ->and($ar['groups'][0]['items'][0]['url'])->toContain('/ar/')
        ->and(collect($ar['groups'][0]['items'])->pluck('meta')->filter()->unique()->values()->all())->toBe(['أنوا']);
});

/*
 * 10. KEPT FRESH BY THE TRANSLATION ITSELF. Publishing an Arabic product name
 *     indexes it; a draft, an empty value or a deleted row takes it out.
 */
it('indexes a product name when it is published and drops it otherwise', function () {
    $p = asProduct('Rice Toner', null);
    $row = fn () => DB::table(ArabicSearch::TABLE)->where('product_id', $p->id)->value('text');

    asArabic('products', $p->id, 'تونر الأرز');
    expect($row())->toBe('تونر الارز');

    TranslationStore::put('ar', 'products', $p->id, 'name', 'تونر الأرز', Translation::STATUS_DRAFT);
    expect($row())->toBeNull();

    asArabic('products', $p->id, 'تونر الأرز');
    Translation::query()->where('group', 'products')->where('item_id', $p->id)->first()->delete();
    expect($row())->toBeNull();
});

/*
 * 11. A REQUEST DURING AN UPDATE, BEFORE THE TABLE EXISTS, does not 500: the
 *     Arabic-name arm is off and the rest still answers.
 */
it('still answers an Arabic search while its table is missing', function () {
    $s = asShop();
    \Illuminate\Support\Facades\Schema::drop(ArabicSearch::TABLE);
    Cache::forget('kbb.search.ar.ready');

    expect(asSlugs('/shop/?s='.rawurlencode('سيروم')))->toContain($s['snail']->slug)
        ->and(asBox('/api/search?q='.rawurlencode('سيروم'))['total'])->toBeGreaterThan(0);
});

/*
 * 12. THE PANEL BEFORE TYPING, IN ARABIC. /ar's trending words are the same
 *     words in Arabic, each one a search the hybrid reads back; English keeps
 *     its words and its cache keys. Mutation: drop arabicFor() in starter()
 *     and the /ar chips are English.
 */
it('offers the trending words in Arabic on /ar and leaves English as it was', function () {
    $s = asShop();
    \Tests\Support\ArabicShop::on();
    app(\App\Services\HeaderSettings::class)->save(['trending_words' => 'Anua, Serum, Sunscreen, Zzqq']);

    $en = asBox('/api/search/starter');
    $ar = asBox('/ar/api/search/starter');

    expect($en['trending'])->toBe(['Anua', 'Serum', 'Sunscreen', 'Zzqq'])
        ->and($ar['trending'])->toBe(['أنوا', 'سيروم', 'واقي شمس', 'Zzqq']);

    foreach (['أنوا', 'سيروم', 'واقي شمس'] as $word) {
        expect(asSlugs('/ar/shop/?s='.rawurlencode($word)))->not->toBe([], $word);
    }
});

/*
 * 13. THE MIGRATION AND THE CLASS SPELL ARABIC THE SAME WAY. The migration may
 *     not call ArabicSearch (old classes can be in opcache while it runs), so
 *     it carries a copy of normalise(); this holds the two together.
 *     Mutation: change one mapping in either and this is red.
 */
it('fills the table with exactly the spelling the search reads', function () {
    $s = asShop();
    $before = DB::table(ArabicSearch::TABLE)->orderBy('product_id')->pluck('text', 'product_id')->all();

    DB::table(ArabicSearch::TABLE)->delete();
    (require base_path('database/migrations/2027_11_02_100300_create_search_texts_ar.php'))->up();

    expect(DB::table(ArabicSearch::TABLE)->orderBy('product_id')->pluck('text', 'product_id')->all())->toBe($before);
});
