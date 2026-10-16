<?php

declare(strict_types=1);

/*
 * Lane AS -- "the seo super done for arabic version too", Arabic image alt
 * text, and "browser translated the page -> open the real English page".
 *
 * Every case here pins a gap the audit (docs/lane-as-seo-audit.md) found on the
 * Arabic-ON preview, and says in its own comment what it looked like on the
 * shop. English is pinned byte-for-byte by StorefrontEnglishUnchangedTest; the
 * English halves below only pin that THIS lane's code never reaches it.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\ImageSeo\AltText;
use App\Services\ImageSeo\ImageSeoJobs;
use App\Services\SettingsService;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;

const LAS_BASE = 'https://kbeautybliss.test';

function lasSettings(array $values = []): void
{
    foreach (array_merge(['site_url' => LAS_BASE, 'seo_site_name' => 'K-Beauty Bliss'], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function lasArabicOn(array $extra = []): void
{
    lasSettings([Locale::SETTING_ENABLED => '1', Locale::SETTING_RTL => '1'] + $extra);
}

/** @return array{product: Product, brand: Brand, main: string, second: string} */
function lasCatalogue(bool $arabicName = true): array
{
    $brand = Brand::create(['slug' => 'las-anua', 'name' => 'Anua']);
    $category = Category::create(['slug' => 'las-toners', 'name' => 'Toners', 'path' => 'las-toners']);
    $main = '/uploads/products/las-pad-1.webp';
    $second = '/uploads/products/las-pad-2.webp';

    $product = Product::create([
        'slug' => 'las-soothing-pad', 'name' => 'Azelaic Soothing Pad', 'status' => 'publish',
        'brand_id' => $brand->id, 'category_id' => $category->id, 'price' => 7900,
        'image' => $main, 'images' => [$second],
        // What Catalog -> Image SEO writes: an English sentence per picture.
        'image_alts' => [$main => 'Anua Azelaic Soothing Pad toner pads in the jar', $second => 'Azelaic Soothing Pad by Anua'],
    ]);

    TranslationStore::put('ar', 'brands', $brand->id, 'name', 'أنوا', Translation::STATUS_PUBLISHED);
    TranslationStore::put('ar', 'categories', $category->id, 'name', 'تونر', Translation::STATUS_PUBLISHED);

    if ($arabicName) {
        TranslationStore::put('ar', 'products', $product->id, 'name', 'قطن أزيليك المهدّئ', Translation::STATUS_PUBLISHED);
    }

    return ['product' => $product->fresh(), 'brand' => $brand, 'main' => $main, 'second' => $second];
}

/** @return list<string> every alt on the page's <img> tags */
function lasAlts(string $html): array
{
    preg_match_all('/<img\b[^>]*\balt="([^"]*)"/u', $html, $m);

    return array_map(static fn ($a) => html_entity_decode($a, ENT_QUOTES, 'UTF-8'), $m[1]);
}

beforeEach(function () {
    lasSettings();
});

/* ------------------------------------------------------------ image alt text */

it('prints Arabic alt text on /ar even after the Image SEO tool wrote English ones', function () {
    /*
     * ON THE SHOP: as soon as Catalog -> Image SEO had written its English alt
     * sentences, /ar/product/... described its own photographs as "Anua
     * Azelaic Soothing Pad toner pads in the jar" -- English in Google Images
     * for the Arabic page, and English read aloud to an Arabic screen reader,
     * beside an Arabic h1. MUTATION: delete the `$locale !== DEFAULT` block in
     * Product::altFor() and the English sentence is back in the Arabic page.
     */
    $c = lasCatalogue();
    lasArabicOn();

    $ar = test()->get('/ar/product/las-soothing-pad/')->assertOk()->getContent();
    $arAlts = lasAlts($ar);

    expect(implode(' | ', $arAlts))->not->toContain('toner pads in the jar')
        ->and(implode(' | ', $arAlts))->not->toContain('Azelaic Soothing Pad by Anua')
        ->and(implode(' | ', $arAlts))->toContain('أنوا قطن أزيليك المهدّئ');

    // The English page keeps the owner's sentence.
    $en = test()->get('/product/las-soothing-pad/')->assertOk()->getContent();
    expect(implode(' | ', lasAlts($en)))->toContain('Anua Azelaic Soothing Pad toner pads in the jar');
});

it('prints the stored Arabic alt for a picture when there is one, and the English when the product has no Arabic name', function () {
    $c = lasCatalogue();
    TranslationStore::put('ar', 'products', $c['product']->id, Product::LOCAL_ALTS_FIELD,
        json_encode([$c['main'] => 'وسادات أزيليك داخل العلبة'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), Translation::STATUS_PUBLISHED);
    lasArabicOn();
    app()->setLocale('ar');

    $p = Product::query()->find($c['product']->id);
    expect($p->altFor($c['main'], 0, 2))->toBe('وسادات أزيليك داخل العلبة')
        ->and($p->altFor($c['second'], 1, 2))->not->toContain('by Anua');

    // No Arabic name: the whole card is English on /ar, so is its alt -- unchanged.
    $bare = Product::create(['slug' => 'las-bare', 'name' => 'Bare Toner', 'status' => 'publish', 'price' => 100,
        'image' => '/uploads/products/las-bare.webp', 'image_alts' => ['/uploads/products/las-bare.webp' => 'Bare Toner bottle on a shelf']]);
    expect($bare->altFor('/uploads/products/las-bare.webp'))->toBe('Bare Toner bottle on a shelf');

    app()->setLocale('en');
    expect($p->altFor($c['main'], 0, 2))->toBe('Anua Azelaic Soothing Pad toner pads in the jar');
});

it('words Arabic alt proposals in Arabic, all different, and none for a product with no Arabic name', function () {
    expect(AltText::proposeArabic('أنوا', 'قطن أزيليك المهدّئ', 'تونر', 4))->toBe([
        'أنوا قطن أزيليك المهدّئ',
        'قطن أزيليك المهدّئ من أنوا',
        'أنوا قطن أزيليك المهدّئ – تونر',
        'أنوا قطن أزيليك المهدّئ – صورة 4',
    ])->and(AltText::proposeArabic('Anua', 'Azelaic Soothing Pad', null, 2))->toBe([]);
});

it('writes the Arabic alt text in the same Image SEO run, and undoes both languages together', function () {
    /*
     * The owner's brief: "the Image SEO tool must be able to write alt text for
     * Arabic too". One run, both languages; Undo puts both back. MUTATION:
     * drop the arabicAlts() call in ImageSeoJobs::altOne() and the Arabic map
     * stays empty after the run.
     */
    $c = lasCatalogue();
    $c['product']->update(['image_alts' => null]);

    $job = ImageSeoJobs::start('alt', 'a-'.bin2hex(random_bytes(10)), [['p' => $c['product']->id, 'alts' => [
        $c['main'] => 'Anua Azelaic Soothing Pad', $c['second'] => 'Azelaic Soothing Pad by Anua',
    ]]], [], null);
    ImageSeoJobs::step((int) $job->id, null);

    $p = Product::query()->find($c['product']->id);
    expect($p->image_alts)->toBe([$c['main'] => 'Anua Azelaic Soothing Pad', $c['second'] => 'Azelaic Soothing Pad by Anua'])
        ->and($p->localAlts('ar'))->toBe([
            $c['main'] => 'أنوا قطن أزيليك المهدّئ',
            $c['second'] => 'قطن أزيليك المهدّئ من أنوا',
        ]);

    $undo = ImageSeoJobs::undo((int) $job->id, null);
    ImageSeoJobs::step((int) $undo->id, null);

    $p = Product::query()->find($c['product']->id);
    expect($p->image_alts)->toBe([])
        ->and($p->localAlts('ar'))->toBe([])
        ->and(Translation::query()->where('field', Product::LOCAL_ALTS_FIELD)->count())->toBe(0);
});

it('shows the Arabic line the run will write beside each English proposal on the ALT tab', function () {
    // Catalog -> Image SEO -> ALT text -> Preview alt text: read-only Arabic under each editable English line.
    $c = lasCatalogue();
    test()->actingAs(AdminUser::create(['name' => 'AS', 'email' => 'as-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    $items = test()->postJson('/admin-api/image-seo/alt-preview', ['selection' => ['mode' => 'ids', 'ids' => [$c['product']->id]], 'offset' => 0])
        ->assertOk()->json('items');

    expect(array_column($items[0]['images'], 'proposed_ar'))->toBe(['أنوا قطن أزيليك المهدّئ', 'قطن أزيليك المهدّئ من أنوا']);

    $screen = file_get_contents(resource_path('views/admin/partials/image-seo-screen.blade.php'));
    expect(substr_count($screen, 'data-isx-ar'))->toBe(1)
        ->and($screen)->not->toContain('<b>English only:</b>');
});

it('writes no Arabic alt for a product with no Arabic name', function () {
    $c = lasCatalogue(arabicName: false);
    $c['product']->update(['image_alts' => null]);

    $job = ImageSeoJobs::start('alt', 'a-'.bin2hex(random_bytes(10)), [['p' => $c['product']->id, 'alts' => [$c['main'] => 'Anua Azelaic Soothing Pad']]], [], null);
    ImageSeoJobs::step((int) $job->id, null);

    expect(Translation::query()->where('field', Product::LOCAL_ALTS_FIELD)->count())->toBe(0);
});

it('does not count the Arabic alt row as a translated product field', function () {
    /*
     * Translation -> Progress counts published rows per area against the
     * fields that have English. The alt map is one more row per product, and
     * counted it would push "Products" toward 100% with nothing translated.
     */
    $c = lasCatalogue(arabicName: false);
    $before = TranslationEstimate::progress('ar')['areas']['products']['translated'];

    TranslationStore::put('ar', 'products', $c['product']->id, Product::LOCAL_ALTS_FIELD, '{"\/x.webp":"صورة"}', Translation::STATUS_PUBLISHED);

    expect(TranslationEstimate::progress('ar')['areas']['products']['translated'])->toBe($before);
});

/* ----------------------------------------------------------- head and schema */

it('names the Arabic page ar_AE in og:locale, and leaves the English head without one', function () {
    /*
     * ON THE SHOP: no og:locale anywhere, so WhatsApp, Facebook and LinkedIn
     * read every Arabic link card as en_US. MUTATION: return [] from
     * Seo::ogLocaleTags() and the Arabic tags are gone.
     */
    lasCatalogue();
    lasArabicOn();

    $ar = test()->get('/ar/product/las-soothing-pad/')->assertOk()->getContent();
    $en = test()->get('/product/las-soothing-pad/')->assertOk()->getContent();

    expect(substr_count($ar, '<meta property="og:locale" content="ar_AE">'))->toBe(1)
        ->and(substr_count($ar, '<meta property="og:locale:alternate" content="en_AE">'))->toBe(1)
        ->and($en)->not->toContain('og:locale');
});

it('names the Arabic brand page in Arabic in its CollectionPage', function () {
    /*
     * ON THE SHOP: /ar/brands/anua/ said "أنوا" in its title, h1 and
     * breadcrumb, and "Anua" as the CollectionPage name Google reads.
     */
    lasCatalogue();
    lasArabicOn();

    $ar = test()->get('/ar/brands/las-anua/')->assertOk()->getContent();
    $en = test()->get('/brands/las-anua/')->assertOk()->getContent();

    $node = static function (string $html): array {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        foreach ($m[1] as $raw) {
            $n = json_decode($raw, true);

            if (($n['@type'] ?? '') === 'CollectionPage') {
                return $n;
            }
        }

        return [];
    };

    expect($node($ar)['name'] ?? null)->toBe('أنوا')
        ->and($node($ar)['inLanguage'] ?? null)->toBe('ar')
        ->and($node($en)['name'] ?? null)->toBe('Anua');
});

/* ------------------------------------- browser translation -> English page */

it('tells the Arabic page it may hand a translated browser to English, ON by default, and never the English page', function () {
    /*
     * Translation -> Language settings -> "Open English when the browser
     * translates the page". The owner asked for it, so it is ON when never
     * saved. The flag rides in window.KBB on ARABIC pages only; the English
     * page's window.KBB is the bytes it always was.
     */
    lasCatalogue();
    lasArabicOn();

    $ar = test()->get('/ar/product/las-soothing-pad/')->assertOk()->getContent();
    $en = test()->get('/product/las-soothing-pad/')->assertOk()->getContent();

    expect($ar)->toContain('"trEn":1')
        ->and($en)->not->toContain('trEn');

    lasArabicOn([Locale::SETTING_TRANSLATE_OPENS_ENGLISH => '0']);
    expect(test()->get('/ar/product/las-soothing-pad/')->assertOk()->getContent())->not->toContain('trEn');
});

it('saves the switch from Translation -> Language settings, owner only', function () {
    test()->getJson('/admin-api/translations/settings')->assertUnauthorized();

    test()->actingAs(AdminUser::create(['name' => 'AS', 'email' => 'as-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => 'owner']), 'admin');

    expect(test()->getJson('/admin-api/translations/settings')->assertOk()->json('translate_opens_english'))->toBeTrue();

    test()->postJson('/admin-api/translations/settings', ['translate_opens_english' => false])->assertOk()
        ->assertJsonPath('translate_opens_english', false);
    expect(Setting::query()->where('key', Locale::SETTING_TRANSLATE_OPENS_ENGLISH)->value('value'))->toBe('0');

    test()->postJson('/admin-api/translations/settings', ['translate_opens_english' => 'yes please'])->assertStatus(422);

    $screen = file_get_contents(resource_path('views/admin/partials/translation-screens.blade.php'));
    expect(substr_count($screen, "togHTML('translate_opens_english'"))->toBe(1);
});

it('ships the hand-off as one observer in the deferred bundle: no timer, no layout read, Arabic pages only', function () {
    $js = file_get_contents(resource_path('js/kbb/translate-away.js'));
    $app = file_get_contents(resource_path('js/kbb/app.js'));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);

    expect(substr_count($app, "import { initTranslateAway } from './translate-away.js';"))->toBe(1)
        ->and(substr_count($app, '    initTranslateAway,'))->toBe(1)
        // One attribute observer on <html>, and a replace() so Back does not return to the machine translation.
        ->and($code)->toContain("attributeFilter: ['class', 'lang']")
        ->and($code)->toContain('location.replace(target)')
        ->and($code)->toContain('window.KBB.trEn')
        ->and($code)->toContain("SameSite=Lax")
        ->and($code)->toContain('15552000');

    foreach (['setTimeout', 'setInterval', 'requestAnimationFrame', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'getComputedStyle', 'fetch(', 'XMLHttpRequest'] as $banned) {
        expect($code)->not->toContain($banned);
    }

    // Never a crawler: the bot check stands before anything navigates.
    expect(strpos($code, 'BOT.test('))->toBeLessThan(strpos($code, 'location.replace('));
});
