<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\BrandName;
use App\Support\Seo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\ArabicShop;

/*
 * Lane ASEO — the Arabic homepage's title, descriptions and keywords.
 *
 * The owner, 17 October 2026: "i have noticed that the default title of the
 * arabic version is not there, it's still showing the english title etc on the
 * arabic homepage. it should arabic title, give me proper backend option to
 * change it also, for now translate it by default. make sure it must be as per
 * our seo etc. also update all meta keywords, description of the site etc in
 * arabic too."
 *
 * ON THE SHOP, BEFORE: with the live shop's typed English Home title, Home
 * description and Site name, /ar/ printed <title>, og:title, twitter:title,
 * the description and its og/twitter twins in English, og:site_name and the
 * WebSite/Organization names in English, and every other /ar title ended
 * "| KBeauty Bliss"; there was no keywords tag at all. The Arabic boxes are
 * Store -> SEO & Meta -> Settings -> "Arabic search appearance (/ar)".
 */

const ASEO_MIGRATION = '2027_11_10_100000_lane_aseo_arabic_seo_defaults';

const ASEO_ENGLISH = [
    'site_url' => 'https://kbeautybliss.test',
    'seo_site_name' => 'KBeauty Bliss',
    'seo_home_title' => 'K-Beauty Bliss | Authentic Korean Skincare in UAE',
    'seo_home_description' => 'Shop 100% authentic Korean skincare and K-beauty in the UAE. Fast delivery across Dubai.',
    'seo_default_description' => 'Authentic Korean skincare, delivered across the UAE by K-Beauty Bliss.',
];

const ASEO_TWINS = ['seo_site_name_ar', 'seo_home_title_ar', 'seo_home_description_ar', 'seo_default_description_ar', 'seo_home_keywords_ar'];

function aseoSet(array $values): void
{
    foreach ($values as $k => $v) {
        Setting::query()->updateOrCreate(['key' => $k], ['value' => (string) $v]);
    }
    aseoFlush();
}

function aseoFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function aseoMigration(): object
{
    return require database_path('migrations/'.ASEO_MIGRATION.'.php');
}

/** The tags a crawler reads, as plain text: title, metas, JSON-LD. */
function aseoHead(string $html): string
{
    $head = (string) strstr($html, '</head>', true);
    preg_match_all('#<title>.*?</title>|<meta (?:name|property)="(?:description|keywords|og:(?:title|description|site_name)|twitter:(?:title|description))" content="[^"]*">#s', $head, $m);
    preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $j);

    return html_entity_decode(implode("\n", $m[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')."\n".implode("\n", array_map(
        static fn (string $blob) => json_encode(json_decode($blob, true), JSON_UNESCAPED_UNICODE),
        $j[1]
    ));
}

function aseoMeta(string $html, string $attr): ?string
{
    return preg_match('#<meta (?:name|property)="'.preg_quote($attr, '#').'" content="([^"]*)">#', $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
        : null;
}

afterEach(function () {
    app()->setLocale('en');
});

/*
 * 1. THE ARABIC HOMEPAGE IS ARABIC, with the live shop's typed English in place.
 *    MUTATION: in Seo::titleOf() read 'seo_home_title' on /ar again (drop the
 *    Locale check) -> the English title is back and this is red; drop the
 *    $arabic arm in describe() -> the English description is back.
 */
it('prints the Arabic homepage title, description, keywords, cards and names in Arabic, with English typed', function () {
    aseoSet(ASEO_ENGLISH);
    ArabicShop::on();

    $html = $this->get('/ar/')->assertOk()->getContent();
    $head = aseoHead($html);
    $values = (new ReflectionClassConstant(aseoMigration(), 'VALUES'))->getValue();

    expect($html)->toContain('<html lang="ar"')
        ->toContain('<title>'.e($values['seo_home_title_ar']).'</title>')
        ->and(aseoMeta($html, 'description'))->toBe($values['seo_home_description_ar'])
        ->and(aseoMeta($html, 'og:title'))->toBe($values['seo_home_title_ar'])
        ->and(aseoMeta($html, 'twitter:title'))->toBe($values['seo_home_title_ar'])
        ->and(aseoMeta($html, 'og:description'))->toBe($values['seo_home_description_ar'])
        ->and(aseoMeta($html, 'og:site_name'))->toBe('كي بيوتي بليس')
        ->and(aseoMeta($html, 'og:locale'))->toBe('ar_AE')
        ->and(aseoMeta($html, 'keywords'))->toStartWith('العناية الكورية بالبشرة, منتجات كورية أصلية');

    // None of his English anywhere a crawler reads -- the alternate names
    // aside, which are the Latin spellings a shopper types, Latin on purpose.
    $read = (string) preg_replace('/"alternateName":\[[^\]]*\]/', '', $head);
    foreach (ASEO_ENGLISH as $key => $english) {
        expect($read)->not->toContain($english);
    }
    // And not one Latin word in the title and the meta tags.
    $tags = (string) preg_replace('/<meta (?:name|property)="[a-z:_]+" content="|">|<\/?title>/', '', (string) strstr($head, "\n{", true) ?: $head);
    expect(preg_match('/[A-Za-z]/', $tags))->toBe(0, $tags);

    // The Arabic WebSite and Organization are the brand in Arabic, tied to the Latin name.
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $nodes = [];
    foreach ($m[1] as $blob) {
        $n = json_decode($blob, true);
        $nodes[$n['@type'] ?? '?'] = $n;
    }
    expect($nodes['WebSite']['name'])->toBe('كي بيوتي بليس')
        ->and($nodes['OnlineStore']['name'])->toBe('كي بيوتي بليس')
        ->and($nodes['WebSite']['alternateName'])->toContain('K-Beauty Bliss')->toContain('KBeauty Bliss')
        ->and($nodes['WebPage']['keywords'])->toContain('واقي شمس كوري');

    // Every other Arabic title ends in Arabic, not "| KBeauty Bliss".
    $shop = $this->get('/ar/shop/')->assertOk()->getContent();
    preg_match('#<title>(.*?)</title>#', $shop, $t);
    expect($t[1])->toEndWith('كي بيوتي بليس')->not->toContain('KBeauty');
});

/*
 * 2. HIS ARABIC WINS. MUTATION: read BrandName::homeTitle('ar') before the
 *    twin in titleOf(), or drop the 'seo_home_keywords_ar' branch in
 *    pageKeywords() -> red.
 */
it('uses the Arabic boxes he typed over every default', function () {
    aseoSet(ASEO_ENGLISH + [
        'seo_site_name_ar' => 'متجر الجمال',
        'seo_home_title_ar' => 'عنوان الصفحة الرئيسية',
        'seo_home_description_ar' => 'وصف الصفحة الرئيسية بالعربية',
        'seo_default_description_ar' => 'وصف عام بالعربية',
        'seo_home_keywords_ar' => "كلمة أولى، كلمة ثانية,كلمة أولى\nكلمة ثالثة",
    ]);
    app()->setLocale('ar');

    $home = Seo::render(['type' => 'home', 'title' => 'x', 'url' => 'https://kbeautybliss.test/ar/']);
    expect($home)->toContain('<title>عنوان الصفحة الرئيسية</title>')
        ->and(aseoMeta($home, 'description'))->toBe('وصف الصفحة الرئيسية بالعربية')
        ->and(aseoMeta($home, 'keywords'))->toBe('كلمة أولى, كلمة ثانية, كلمة ثالثة')
        ->and(aseoMeta($home, 'og:site_name'))->toBe('متجر الجمال');

    $page = Seo::render(['type' => 'website', 'title' => 'صفحة']);
    expect($page)->toContain('<title>صفحة | متجر الجمال</title>')
        ->and(aseoMeta($page, 'description'))->toBe('وصف عام بالعربية')
        // Keywords are the homepage's only.
        ->and(aseoMeta($page, 'keywords'))->toBeNull();
});

/*
 * 3. A BLANK ARABIC BOX IS THE SHOP'S ARABIC, NEVER HIS ENGLISH.
 *    MUTATION: make arabicDefaultDescription() return ArabicWording::of() of
 *    the English (the old line) -> his English default description is back;
 *    drop the NAME_AR arm of siteNameOf() -> "| KBeauty Bliss" is back.
 */
it('falls back to the shop\'s Arabic defaults, never the English, when an Arabic box is blank', function () {
    aseoSet(ASEO_ENGLISH + array_fill_keys(ASEO_TWINS, ''));
    app()->setLocale('ar');

    $home = Seo::render(['type' => 'home', 'title' => 'x']);
    expect($home)->toContain('<title>'.e(BrandName::homeTitle('ar')).'</title>')
        ->and(aseoMeta($home, 'description'))->toBe(BrandName::homeDescription('ar'))
        ->and(aseoMeta($home, 'og:site_name'))->toBe(BrandName::NAME_AR)
        // No keyword sync and no Arabic keywords: no tag, as on English.
        ->and(aseoMeta($home, 'keywords'))->toBeNull();

    $page = Seo::render(['type' => 'website', 'title' => 'صفحة']);
    expect($page)->toContain('<title>صفحة | '.BrandName::NAME_AR.'</title>')
        ->and(aseoMeta($page, 'description'))->toBe((string) __('store.wording.seo_default'))
        ->and(aseoMeta($page, 'description'))->not->toBe(ASEO_ENGLISH['seo_default_description'])
        ->and(preg_match('/[A-Za-z]/', (string) aseoMeta($page, 'description')))->toBe(0);

    // The brand switch off: the page's own (Arabic) title, still never the English one.
    aseoSet([BrandName::TITLES => '0']);
    $off = Seo::render(['type' => 'home', 'title' => 'الصفحة الرئيسية']);
    expect($off)->toContain('<title>الصفحة الرئيسية</title>')->not->toContain(ASEO_ENGLISH['seo_home_title']);

    // A shop renamed to a name with no Arabic keeps the name it typed.
    aseoSet(['seo_site_name' => 'Glow House', BrandName::TITLES => '1']);
    expect(aseoMeta(Seo::render(['type' => 'website', 'title' => 'x']), 'og:site_name'))->toBe('Glow House');
});

/*
 * 4. ENGLISH IS BYTE-IDENTICAL. The Arabic boxes filled or absent, the English
 *    <head> is the same string. MUTATION: drop the Locale check in
 *    siteNameOf() (read seo_site_name_ar everywhere) -> red.
 */
it('leaves every English page byte-identical whatever the Arabic boxes hold', function () {
    $ctxs = [
        ['type' => 'home', 'title' => 'K-Beauty Bliss · Authentic Korean skincare in the UAE', 'url' => 'https://kbeautybliss.test/'],
        ['type' => 'website', 'title' => 'Shop', 'url' => 'https://kbeautybliss.test/shop/'],
        ['type' => 'product', 'title' => 'Anua Heartleaf Toner', 'product' => ['name' => 'Anua Heartleaf Toner']],
    ];

    DB::table('settings')->whereIn('key', ASEO_TWINS)->delete();
    aseoSet(ASEO_ENGLISH);
    $without = array_map(static fn (array $c) => Seo::render($c), $ctxs);

    aseoSet(array_combine(ASEO_TWINS, ['اسم', 'عنوان', 'وصف', 'وصف عام', 'كلمة']));
    $with = array_map(static fn (array $c) => Seo::render($c), $ctxs);

    expect($with)->toBe($without)
        ->and($with[0])->toContain('<title>K-Beauty Bliss | Authentic Korean Skincare in UAE</title>')
        ->and($with[0])->not->toContain('عنوان')->not->toContain('كلمة');
});

/*
 * 5. ESCAPED, AND ALLOWLISTED. A <script> typed into an Arabic box is text in
 *    every place it is printed. MUTATION: print $title raw in render() -> red.
 */
it('prints a script typed into an Arabic box as text, and saves the boxes through the allowlist', function () {
    aseoSet(ASEO_ENGLISH + [
        'seo_site_name_ar' => '</script><script>alert(1)</script>',
        'seo_home_title_ar' => '<script>alert(2)</script>',
        'seo_home_description_ar' => '"><img src=x onerror=alert(3)>',
        'seo_home_keywords_ar' => '<b>كلمة</b>، "قوس"',
    ]);
    app()->setLocale('ar');
    $html = Seo::render(['type' => 'home', 'title' => 'x', 'url' => 'https://kbeautybliss.test/ar/']);

    expect($html)->not->toContain('<script>alert')->not->toContain('<img src=x')->not->toContain('</script><script>')
        ->toContain('<title>&lt;script&gt;alert(2)&lt;/script&gt;</title>')
        ->and(aseoMeta($html, 'keywords'))->toBe('كلمة, "قوس"');

    $owner = AdminUser::create(['name' => 'ASEO', 'email' => 'aseo@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    $this->actingAs($owner, 'admin');
    $this->putJson('/admin-api/settings', ['settings' => array_combine(ASEO_TWINS, ['أ', 'ب', 'ج', 'د', 'هـ'])])->assertOk();
    expect(DB::table('settings')->whereIn('key', ASEO_TWINS)->pluck('value', 'key')->all())
        ->toEqual(array_combine(ASEO_TWINS, ['أ', 'ب', 'ج', 'د', 'هـ']));

    // The screen draws each box once and posts each key once.
    $screen = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    foreach (['seo_sitename_ar', 'seo_home_t_ar', 'seo_home_d_ar', 'seo_def_d_ar', 'seo_home_kw_ar'] as $id) {
        expect(substr_count($screen, "smField('{$id}'"))->toBe(1, $id);
    }
    foreach (ASEO_TWINS as $key) {
        expect(substr_count($screen, $key.':sval('))->toBe(1, $key);
    }
});

/*
 * 6. THE MIGRATION: literals only, and only into an empty box. MUTATION: drop
 *    the `trim(...) === ''` test -> his Arabic is overwritten; drop
 *    isThisBrand() -> a renamed shop is given K-Beauty Bliss text.
 */
it('fills only the empty Arabic boxes, with literals, and never for a renamed shop', function () {
    $code = '';
    foreach (token_get_all((string) file_get_contents(database_path('migrations/'.ASEO_MIGRATION.'.php'))) as $t) {
        $code .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
    }
    expect(str_contains($code, 'App\\'))->toBeFalse()
        ->and(preg_match('/\buse\s+(?!Illuminate\\\\)[A-Z]/', $code))->toBe(0);

    $values = (new ReflectionClassConstant(aseoMigration(), 'VALUES'))->getValue();
    expect(array_keys($values))->toBe(ASEO_TWINS)
        ->and(mb_strlen($values['seo_home_title_ar']))->toBeLessThanOrEqual(60)
        ->and(mb_strlen($values['seo_home_description_ar']))->toBeGreaterThanOrEqual(120)->toBeLessThanOrEqual(160)
        ->and(mb_strlen($values['seo_default_description_ar']))->toBeLessThanOrEqual(160)
        ->and(count(preg_split('/،/u', $values['seo_home_keywords_ar'])))->toBeGreaterThanOrEqual(10);
    foreach ($values as $key => $arabic) {
        expect(preg_match('/[A-Za-z]/', $arabic))->toBe(0, "{$key} carries Latin");
    }

    // Absent, empty, his own.
    DB::table('settings')->whereIn('key', ASEO_TWINS)->delete();
    aseoSet(['seo_site_name' => 'K-Beauty Bliss', 'seo_home_title_ar' => '   ', 'seo_home_description_ar' => 'وصفي أنا']);
    aseoMigration()->up();
    $rows = DB::table('settings')->whereIn('key', ASEO_TWINS)->pluck('value', 'key')->all();
    expect($rows['seo_home_title_ar'])->toBe($values['seo_home_title_ar'])
        ->and($rows['seo_home_description_ar'])->toBe('وصفي أنا')
        ->and($rows['seo_site_name_ar'])->toBe($values['seo_site_name_ar'])
        ->and($rows['seo_home_keywords_ar'])->toBe($values['seo_home_keywords_ar']);

    // Down takes back only its own text.
    aseoMigration()->down();
    expect(DB::table('settings')->whereIn('key', ASEO_TWINS)->pluck('value', 'key')->all())
        ->toBe(['seo_home_description_ar' => 'وصفي أنا']);

    // A renamed shop is given nothing.
    DB::table('settings')->whereIn('key', ASEO_TWINS)->delete();
    aseoSet(['seo_site_name' => 'Glow House']);
    aseoMigration()->up();
    expect(DB::table('settings')->whereIn('key', ASEO_TWINS)->count())->toBe(0);
});
