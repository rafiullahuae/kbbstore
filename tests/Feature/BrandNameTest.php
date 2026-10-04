<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\Seo\BrandRename;
use App\Services\Seo\Keywords\KeywordComposer;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\BrandName;
use App\Support\Seo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * Lane BR — "Extra Beauty" → K-Beauty Bliss, and the brand search.
 *
 * The owner, 4 October: "please replace the word Extra Beauty > K-Beauty Bliss
 * everywhere ... on backend, frontend, in emails, footers ... when users leave
 * pending orders or come later, they just search kbeauty or k beauty or
 * k-beauty in google ... our competitor is there in google first ... customer
 * sent us their payment done screenshot".
 */

function brSet(array $values): void
{
    foreach ($values as $k => $v) {
        Setting::query()->updateOrCreate(['key' => $k], ['value' => (string) $v]);
    }
    Setting::flushMap();
    SettingsService::forgetMemo();
}

function brMigration(): object
{
    return require base_path('database/migrations/2027_08_09_100000_rename_brand_extra_beauty_to_k_beauty_bliss.php');
}

/** Every brand-bearing and every transactional fixture, each saying "Extra Beauty". */
function brSeedEverywhere(): array
{
    brSet([
        'store_name' => 'Extra Beauty',
        'seo_site_name' => 'ExtraBeauty',
        'org_name' => 'EXTRA BEAUTY',
        'mail_from_name' => 'Extra-Beauty',
        'seo_home_title' => 'Extra Beauty | Korean skincare in the UAE',
        'site_footer_json' => json_encode(['note' => "Thank you for shopping at Extra Beauty\nSee you soon", 'ar' => 'شكرا لتسوقكم من اكسترا بيوتي'], JSON_UNESCAPED_UNICODE),
        'announcement_ar_escaped' => json_encode(['t' => 'إكسترا بيوتي']),
        'site_url' => 'https://extrabeauty.ae',
        'mail_from_address' => 'info@extrabeauty.ae',
        'social_instagram' => 'https://instagram.com/extrabeauty',
        'invoice_business_name' => 'Extra Beauty Trading LLC',
        'promo_line' => 'Add extra beauty to your routine',
    ]);

    DB::table('translations')->insert([
        ['locale' => 'en', 'group' => 'ui', 'field' => 'br.test.sign_off', 'value' => 'Love, Extra Beauty', 'status' => 'published'],
        ['locale' => 'en', 'group' => 'products', 'field' => 'description', 'value' => 'Loved by Extra Beauty', 'status' => 'published'],
    ]);
    DB::table('email_templates')->insert(['key' => 'order_confirmation', 'locale' => 'en', 'subject' => 'Your Extra Beauty order', 'body' => 'Thanks — Extra Beauty']);
    $menu = DB::table('menus')->insertGetId(['slug' => 'br-main', 'name' => 'Extra Beauty main']);
    DB::table('menu_items')->insert(['menu_id' => $menu, 'label' => 'About Extra Beauty', 'position' => 1]);
    DB::table('pages')->insert(['slug' => 'br-about', 'title' => 'About Extra Beauty', 'content' => '<p>Extra Beauty is a UAE shop.</p>', 'status' => 'published',
        'seo' => json_encode(['title' => 'About us | Extra Beauty'])]);
    $product = DB::table('products')->insertGetId([
        'slug' => 'br-toner', 'name' => 'Extra Beauty Toner', 'description' => '<p>Sold by Extra Beauty.</p>', 'short_description' => 'Extra Beauty pick',
        'status' => 'publish', 'price' => 1000, 'seo' => json_encode(['title' => 'Toner | Extra Beauty']),
    ]);

    // Transactional: none of these may move.
    $customer = DB::table('customers')->insertGetId(['email' => 'c@example.test', 'name' => 'Extra Beauty', 'notes' => 'Paid Extra Beauty']);
    $order = DB::table('orders')->insertGetId(['order_number' => 'KBB-1', 'email' => 'c@example.test', 'customer_id' => $customer, 'customer_note' => 'Is this Extra Beauty?', 'status' => 'processing']);
    DB::table('order_notes')->insert(['order_id' => $order, 'content' => 'Customer paid Extra Beauty']);
    DB::table('reviews')->insert(['product_id' => $product, 'author_name' => 'A', 'content' => 'Extra Beauty is great', 'rating' => 5, 'status' => 'approved']);
    DB::table('import_history')->insert(['run_uid' => 'r1', 'entity' => 'products', 'notes' => 'from Extra Beauty export']);

    return compact('product', 'customer', 'order');
}

/** The transactional rows, verbatim. */
function brTransactional(): array
{
    return [
        DB::table('customers')->get(['name', 'notes'])->toArray(),
        DB::table('orders')->get(['customer_note'])->toArray(),
        DB::table('order_notes')->get(['content'])->toArray(),
        DB::table('reviews')->get(['content'])->toArray(),
        DB::table('import_history')->get(['notes'])->toArray(),
        DB::table('products')->get(['name', 'description', 'short_description'])->toArray(),
        DB::table('translations')->where('group', 'products')->get(['value'])->toArray(),
    ];
}

function brSetting(string $key): ?string
{
    return DB::table('settings')->where('key', $key)->value('value');
}

/* ------------------------------------------------------------ the migration */

it('replaces the old name in brand-bearing stored text only, and never in an order, a customer, a review or a product description', function () {
    // DEFECT: the live shop said "Extra Beauty" in its title, footer and email
    // From line because the STORE NAME setting did, while every code default
    // already read K-Beauty Bliss. MUTATION: add 'orders' => ['id', ['customer_note']]
    // to BrandRename::TARGETS and the transactional pin is red; drop the
    // lookbehind from BrandName::OLD and site_url becomes "https://K-Beauty Bliss.ae".
    brSeedEverywhere();
    $before = brTransactional();

    brMigration()->up();

    expect(brSetting('store_name'))->toBe('K-Beauty Bliss')
        ->and(brSetting('seo_site_name'))->toBe('K-Beauty Bliss')
        ->and(brSetting('org_name'))->toBe('K-BEAUTY BLISS')
        ->and(brSetting('mail_from_name'))->toBe('K-Beauty Bliss')
        ->and(brSetting('seo_home_title'))->toBe('K-Beauty Bliss | Korean skincare in the UAE')
        ->and(json_decode((string) brSetting('site_footer_json'), true))->toBe(['note' => "Thank you for shopping at K-Beauty Bliss\nSee you soon", 'ar' => 'شكرا لتسوقكم من K-Beauty Bliss'])
        ->and(json_decode((string) brSetting('announcement_ar_escaped'), true))->toBe(['t' => 'K-Beauty Bliss'])
        // The DOMAIN, an email, a handle, a legal name and ordinary English stay.
        ->and(brSetting('site_url'))->toBe('https://extrabeauty.ae')
        ->and(brSetting('mail_from_address'))->toBe('info@extrabeauty.ae')
        ->and(brSetting('social_instagram'))->toBe('https://instagram.com/extrabeauty')
        ->and(brSetting('invoice_business_name'))->toBe('Extra Beauty Trading LLC')
        ->and(brSetting('promo_line'))->toBe('Add extra beauty to your routine')
        ->and(DB::table('translations')->where('field', 'br.test.sign_off')->value('value'))->toBe('Love, K-Beauty Bliss')
        ->and(DB::table('email_templates')->where('key', 'order_confirmation')->where('locale', 'en')->value('subject'))->toBe('Your K-Beauty Bliss order')
        ->and(DB::table('email_templates')->where('key', 'order_confirmation')->where('locale', 'en')->value('body'))->toBe('Thanks — K-Beauty Bliss')
        ->and(DB::table('menus')->where('slug', 'br-main')->value('name'))->toBe('K-Beauty Bliss main')
        ->and(DB::table('menu_items')->where('menu_id', DB::table('menus')->where('slug', 'br-main')->value('id'))->value('label'))->toBe('About K-Beauty Bliss')
        ->and(DB::table('pages')->where('slug', 'br-about')->value('content'))->toBe('<p>K-Beauty Bliss is a UAE shop.</p>')
        ->and(json_decode((string) DB::table('pages')->where('slug', 'br-about')->value('seo'), true)['title'])->toBe('About us | K-Beauty Bliss')
        ->and(json_decode((string) DB::table('products')->where('slug', 'br-toner')->value('seo'), true)['title'])->toBe('Toner | K-Beauty Bliss')
        ->and(brTransactional())->toEqual($before)
        ->and(json_encode(brTransactional()))->toContain('Paid Extra Beauty')->toContain('Is this Extra Beauty?')
        ->toContain('Customer paid Extra Beauty')->toContain('Extra Beauty is great')->toContain('Sold by Extra Beauty');
});

it('is idempotent and never fails the update, even with a target table missing', function () {
    // DEFECT shape this guards: a migration that throws aborts a Core Update
    // (CLAUDE.md, the updater incident). MUTATION: remove the try/catch in
    // BrandRename::walk() and make the replacement "K-Beauty Bliss (Extra
    // Beauty)" — the second run is no longer a no-op and this is red.
    brSeedEverywhere();
    // A table that is not there, a column that is not there, and a spec that
    // throws (a string where a list belongs) — each skipped, none fatal. No DDL:
    // DROP TABLE commits the MySQL suite's transaction under the next test.
    BrandRename::$targets = ['no_such_table' => ['id', ['x']], 'tags' => ['id', ['no_such_column']], 'pages' => ['id', 'title']] + BrandRename::TARGETS;
    try {
        $first = BrandRename::apply();
    } finally {
        BrandRename::$targets = null;
    }
    expect($first['total'])->toBeGreaterThan(0)->and($first['errors'])->toHaveCount(1);

    brMigration()->up();
    $snapshot = DB::table('settings')->orderBy('key')->pluck('value', 'key')->all();

    $second = BrandRename::apply();
    brMigration()->up();

    expect($second['total'])->toBe(0)
        ->and(DB::table('settings')->orderBy('key')->pluck('value', 'key')->all())->toBe($snapshot)
        ->and(BrandRename::scan()['total'])->toBe(0);
});

it('rewrites the stored keyword sets in lower case, as every keyword is', function () {
    DB::table('seo_page_keywords')->insert([
        'entity_type' => 'page', 'entity_id' => 'home', 'locale' => 'en',
        'keywords' => json_encode(['extra beauty', 'extra beauty uae', 'korean skincare uae']),
        'primary_kw' => 'extra beauty', 'suggest' => json_encode(['title' => 'Home | Extra Beauty', 'desc' => '']),
    ]);

    BrandRename::apply();
    $row = DB::table('seo_page_keywords')->first();

    expect(json_decode($row->keywords, true))->toBe(['k-beauty bliss', 'k-beauty bliss uae', 'korean skincare uae'])
        ->and($row->primary_kw)->toBe('k-beauty bliss')
        ->and(json_decode($row->suggest, true)['title'])->toBe('Home | K-Beauty Bliss');
});

/* ------------------------------------------------------------ defaults */

it('falls back to K-Beauty Bliss, never to a stale APP_NAME', function () {
    // DEFECT: the installer writes the typed shop name into APP_NAME, and every
    // mail/SEO fallback read config('app.name'). MUTATION: make appName()
    // return config('app.name') unconditionally -> red.
    foreach (['Extra Beauty', 'ExtraBeauty', '', 'Laravel'] as $stale) {
        config(['app.name' => $stale]);
        expect(BrandName::appName())->toBe('K-Beauty Bliss');
    }
    config(['app.name' => 'KBB']);
    expect(BrandName::appName())->toBe('KBB');

    config(['app.name' => 'Extra Beauty']);
    DB::table('settings')->whereIn('key', ['store_name', 'seo_site_name'])->delete();
    Setting::flushMap();
    expect(Seo::render(['title' => 'Toners']))->toContain('<title>Toners | K-Beauty Bliss</title>')
        ->toContain('<meta property="og:site_name" content="K-Beauty Bliss">');

    $seeded = (new ReflectionClass(\Database\Seeders\SettingsSeeder::class))->getFileName();
    expect(file_get_contents($seeded))->toContain("'store_name' => 'K-Beauty Bliss'");
});

/* ------------------------------------------------------------ the head */

function brHomeSettings(array $extra = []): void
{
    brSet(array_merge(['site_url' => 'https://kbeautybliss.test', 'seo_site_name' => 'K-Beauty Bliss', 'store_name' => 'K-Beauty Bliss'], $extra));
}

/** @return array<string, array> JSON-LD nodes by @type */
function brNodes(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    $out = [];
    foreach ($m[1] as $j) {
        $n = json_decode($j, true);
        $out[$n['@type'] ?? '?'] = $n;
    }

    return $out;
}

it('names the site K-Beauty Bliss with its exact alternate names, on the WebSite and the Organization', function () {
    // MUTATION: drop 'K Beauty Bliss' from BrandName::ALTERNATES, or the
    // alternateName line on either node -> red.
    brHomeSettings();
    $nodes = brNodes(Seo::render(['type' => 'home', 'title' => 'x', 'url' => 'https://kbeautybliss.test/']));
    $alt = ['KBeauty Bliss', 'K Beauty Bliss', 'Kbeautybliss', 'K-Beauty Bliss UAE'];

    expect($nodes['WebSite']['name'])->toBe('K-Beauty Bliss')
        ->and($nodes['WebSite']['alternateName'])->toBe($alt)
        ->and($nodes['OnlineStore']['name'])->toBe('K-Beauty Bliss')
        ->and($nodes['OnlineStore']['alternateName'])->toBe($alt);

    // Off, and the nodes are exactly what they were.
    brHomeSettings([BrandName::ALTERNATES_KEY => '0']);
    $off = brNodes(Seo::render(['type' => 'home', 'title' => 'x', 'url' => 'https://kbeautybliss.test/']));
    expect($off['WebSite'])->not->toHaveKey('alternateName')
        ->and($off['OnlineStore'])->not->toHaveKey('alternateName');

    // A shop renamed to something else does not go on claiming these.
    brHomeSettings(['seo_site_name' => 'Glow House', BrandName::ALTERNATES_KEY => '1']);
    expect(brNodes(Seo::render(['type' => 'home', 'title' => 'x']))['WebSite'] ?? [])->not->toHaveKey('alternateName');
});

it('leads the home title with the brand and names it once in the description, in English and Arabic', function () {
    // BEFORE: <title>K-Beauty Bliss · Authentic Korean skincare in the UAE</title>, no description.
    // MUTATION: drop the seo_home_title-empty branch in Seo::titleOf() -> red.
    brHomeSettings();
    $html = Seo::render(['type' => 'home', 'title' => 'K-Beauty Bliss · Authentic Korean skincare in the UAE']);

    expect($html)->toContain('<title>K-Beauty Bliss — Korean Skincare &amp; K-Beauty Store in UAE</title>')
        ->and(mb_strlen(BrandName::homeTitle('en')))->toBeLessThanOrEqual(60)
        ->and(preg_match('#<meta name="description" content="([^"]*)"#', $html, $d))->toBe(1)
        ->and(substr_count($d[1], 'K-Beauty Bliss'))->toBe(1)
        ->and(substr_count(html_entity_decode((string) strstr($html, '<meta name="description"', true)), 'K-Beauty Bliss'))->toBe(1);

    app()->setLocale('ar');
    $ar = Seo::render(['type' => 'home', 'title' => 'x']);
    expect($ar)->toContain('<title>K-Beauty Bliss — متجر العناية بالبشرة الكورية وكي بيوتي في الإمارات</title>')
        ->toContain('متجر إلكتروني للعناية بالبشرة الكورية');
    app()->setLocale('en');

    // A typed home title still wins, and the switch puts the page's own back.
    brHomeSettings(['seo_home_title' => 'My own title']);
    expect(Seo::render(['type' => 'home', 'title' => 'x']))->toContain('<title>My own title</title>');
    brHomeSettings(['seo_home_title' => '', BrandName::TITLES => '0']);
    expect(Seo::render(['type' => 'home', 'title' => 'K-Beauty Bliss · Authentic Korean skincare in the UAE']))
        ->toContain('<title>K-Beauty Bliss · Authentic Korean skincare in the UAE</title>')
        ->not->toContain('is the UAE online store for authentic Korean skincare');
});

it('suffixes every other title with " | K-Beauty Bliss" exactly once and sets og:site_name', function () {
    brHomeSettings();
    $html = Seo::render(['type' => 'product', 'title' => 'Anua Heartleaf Toner', 'product' => ['name' => 'Anua Heartleaf Toner']]);
    preg_match('#<title>(.*?)</title>#', $html, $t);

    expect($t[1])->toBe('Anua Heartleaf Toner | K-Beauty Bliss')
        ->and(substr_count($t[1], 'K-Beauty Bliss'))->toBe(1)
        ->and($html)->toContain('<meta property="og:site_name" content="K-Beauty Bliss">');
});

/* ------------------------------------------------------------ keywords */

function brProfiles(): array
{
    $p = static fn (string $type, string $id, array $x = []): array => array_merge([
        'type' => $type, 'id' => $id, 'locale' => 'en', 'name' => '', 'display' => '', 'brand' => '', 'core' => '', 'path' => '/'.$type.'/'.$id.'/',
        'kind' => null, 'ingredients' => [], 'concerns' => [], 'skin' => [],
    ], $x);

    return [
        $p('product', '1', ['name' => 'Anua Heartleaf 77% Soothing Toner', 'brand' => 'Anua', 'core' => 'heartleaf 77% soothing toner', 'kind' => 'toner', 'ingredients' => ['heartleaf'], 'concerns' => ['sensitivity']]),
        $p('product', '2', ['name' => 'COSRX Snail Essence', 'brand' => 'COSRX', 'core' => 'advanced snail 96 mucin power essence', 'kind' => 'essence', 'ingredients' => ['snail mucin']]),
        $p('product', '3', ['name' => 'Beauty of Joseon Relief Sun', 'brand' => 'Beauty of Joseon', 'core' => 'relief sun', 'kind' => 'sunscreen', 'concerns' => ['sun']]),
        $p('category', '5', ['name' => 'Sunscreens', 'core' => 'sunscreens', 'kind' => 'sunscreen']),
        $p('brand', '7', ['name' => 'COSRX', 'brand' => 'COSRX', 'kinds' => ['serum']]),
        $p('collection', 'best-sellers', ['name' => 'Best sellers', 'core' => 'best sellers']),
        $p('post', '9', ['name' => 'Snail mucin guide', 'core' => 'snail mucin guide', 'ingredients' => ['snail mucin']]),
        $p('page', 'home', ['name' => 'Home', 'core' => 'home']),
        $p('page', 'about-us', ['name' => 'About us', 'core' => 'about us']),
        $p('page', 'home', ['locale' => 'ar', 'name' => 'الرئيسية']),
        $p('product', '1', ['locale' => 'ar', 'name' => 'Anua Heartleaf Toner', 'brand' => 'Anua', 'kind' => 'toner']),
    ];
}

it('gives every page exactly one k-beauty phrase about itself, rotating the spelling, never stacked', function () {
    // DEFECT this prevents: the composer offered "k-beauty toner" and the bank
    // "anua heartleaf k beauty" to the same page — two near-identical trade-word
    // phrases, the stacking Google's spam policy describes. MUTATION: delete
    // the unset() loop in KeywordComposer::compose() -> the toner has two.
    $bank = ['terms' => ['anua heartleaf k beauty' => [500]], 'index' => ['anua' => ['anua heartleaf k beauty'], 'heartleaf' => ['anua heartleaf k beauty']], 'pages' => []];
    $spellings = [];

    foreach (brProfiles() as $p) {
        $r = KeywordComposer::compose($p, $bank, [], 'K-Beauty Bliss');
        $k = array_values(array_filter($r['keywords'], [KeywordComposer::class, 'isKbeauty']));

        expect($k)->toHaveCount(1, $p['type'].':'.$p['id'].':'.$p['locale'].' → '.implode(', ', $r['keywords']))
            ->and(count($r['keywords']))->toBeLessThanOrEqual(10);
        // The brand phrase at most once per set.
        expect(count(array_filter($r['keywords'], fn ($w) => str_contains($w, 'bliss'))))->toBeLessThanOrEqual(1);
        preg_match('/k-beauty|k beauty|kbeauty|كي بيوتي/u', $k[0], $s);
        $spellings[$s[0]] = true;
    }

    $home = KeywordComposer::compose(brProfiles()[7], $bank, [], 'K-Beauty Bliss');
    expect($home['primary'])->toBe('k-beauty bliss')
        ->and(array_keys($spellings))->toContain('k-beauty', 'k beauty', 'kbeauty', 'كي بيوتي');

    // Off: the composer is what it was — the home page stacks three again
    // ("k-beauty bliss", "k-beauty bliss uae", "k-beauty uae").
    $off = KeywordComposer::compose(brProfiles()[7], $bank, [], 'K-Beauty Bliss', false);
    expect(count(array_filter($off['keywords'], [KeywordComposer::class, 'isKbeauty'])))->toBeGreaterThan(1);
});

it('adds the brand phrases to the lexicon and nothing that favours the old name', function () {
    $lex = (new ReflectionClass(\App\Services\Seo\Keywords\Lexicon::class))->getConstants();

    expect($lex['BRAND'])->toBe(['k-beauty bliss', 'kbeauty bliss', 'k beauty bliss', 'kbeautybliss'])
        ->and(json_encode($lex, JSON_UNESCAPED_UNICODE))->not->toMatch('/extra[\s-]?beauty/i');
});

/* ------------------------------------------------------------ no hardcoded host */

it('hardcodes extrabeauty.ae in no customer-facing template, email or storefront code path', function () {
    // The domain moves at the cutover; anything a shopper sees must come from
    // site_url. Blade comments ({{-- --}}) and @php comments never reach the
    // page, so they are stripped before looking. MUTATION: put
    // "https://extrabeauty.ae/" in the footer partial -> red.
    $hits = [];
    foreach (['views/store', 'views/partials', 'views/layouts', 'views/components', 'views/emails', 'views/mail', 'views/errors'] as $dir) {
        if (! is_dir(resource_path($dir))) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $src = (string) file_get_contents($f->getPathname());
            $src = (string) preg_replace(['/\{\{--.*?--\}\}/s', '#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $src);
            if (preg_match('/extra[\s\-]?beauty/i', $src)) {
                $hits[] = $f->getPathname();
            }
        }
    }

    expect($hits)->toBe([]);
})->skip(fn () => ! is_dir(resource_path('views/store')), 'no views');

/* ------------------------------------------------------------ admin */

function brAdmin(string $role): AdminUser
{
    return AdminUser::create(['name' => 'BR '.$role, 'email' => 'br-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-pass-123', 'role' => $role]);
}

function brWire(): void
{
    \Tests\Support\SeoKeywordsRoutes::wire(app());
}

it('maps the brand check to its own capabilities and fails closed', function () {
    // MUTATION: delete the two seo-brand rows from AdminCapabilities::RULES ->
    // the map answers null and the first expectation is red.
    brWire();
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/seo-brand'))->values();
    expect($routes)->toHaveCount(3);
    foreach ($routes as $r) {
        expect(AdminCapabilities::for($r))->toBe(in_array('GET', $r->methods(), true) ? 'seo_brand.view' : 'seo_brand.manage');
    }

    $this->actingAs(brAdmin('manager'), 'admin')->getJson('/admin-api/seo-brand')->assertOk();
    $this->actingAs(brAdmin('manager'), 'admin')->postJson('/admin-api/seo-brand/replace', ['expect' => 1])->assertForbidden();
    $this->actingAs(brAdmin('manager'), 'admin')->putJson('/admin-api/seo-brand/switches', ['titles' => false])->assertForbidden();
    $this->actingAs(brAdmin('support'), 'admin')->getJson('/admin-api/seo-brand')->assertForbidden();
    $this->actingAs(brAdmin('editor'), 'admin')->getJson('/admin-api/seo-brand')->assertForbidden();
});

it('dry-runs first, replaces only the count it showed, and saves the switches', function () {
    // MUTATION: drop the `$now !== expect` check in BrandNameApiController ->
    // the stale-count request replaces and returns 200.
    brWire();
    brSet(['store_name' => 'Extra Beauty', 'seo_site_name' => 'Extra Beauty']);
    $owner = brAdmin('owner');

    $dry = $this->actingAs($owner, 'admin')->getJson('/admin-api/seo-brand')->assertOk()->json();
    expect($dry['scan']['total'])->toBe(2)
        ->and(brSetting('store_name'))->toBe('Extra Beauty');   // a dry run writes nothing

    $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-brand/replace', ['expect' => 5])->assertStatus(409);
    expect(brSetting('store_name'))->toBe('Extra Beauty');

    $done = $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-brand/replace', ['expect' => 2])->assertOk()->json();
    expect($done['replaced'])->toBe(2)->and($done['scan']['total'])->toBe(0)
        ->and(brSetting('store_name'))->toBe('K-Beauty Bliss');

    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-brand/switches', ['titles' => false, 'alternates' => true, 'kbeauty_one' => false])
        ->assertOk()->assertJsonPath('switches', ['titles' => false, 'alternates' => true, 'kbeauty_one' => false]);
    expect(brSetting(BrandName::TITLES))->toBe('0')->and(brSetting(BrandName::KBEAUTY_ONE))->toBe('0');

    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-brand/switches', ['titles' => 'yes please'])->assertStatus(422);
});

it('never sends a credential-shaped setting back to the screen, even when it mentions the old name', function () {
    brWire();
    brSet(['smtp_password' => 'extrabeauty-secret-99']);

    $json = $this->actingAs(brAdmin('owner'), 'admin')->getJson('/admin-api/seo-brand')->assertOk()->getContent();

    expect($json)->not->toContain('extrabeauty-secret-99')->and(brSetting('smtp_password'))->toBe('extrabeauty-secret-99');
});

it('draws the Brand name tab once, escapes what the server sends, and is in the admin search', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/seo-keywords-screen.blade.php'));
    $routes = (string) file_get_contents(base_path('routes/seo-keywords-admin.php'));
    $index = \App\Support\AdminSearchIndex::build();

    expect(substr_count($src, "['brand', 'Brand name']"))->toBe(1)
        ->and(substr_count($routes, "'/seo-brand'"))->toBe(1)
        ->and(substr_count((string) file_get_contents(base_path('routes/web.php')), "require __DIR__.'/seo-keywords-admin.php';"))->toBe(1)
        ->and($src)->toContain("esc(r.before)")->toContain("esc(r.after)")->toContain("esc(r.label)")
        ->and(json_encode($index['seokeywords'] ?? []))->toContain('Brand name check');
});

it('adds no visible text to a storefront page body', function () {
    // The brand work lives in <head> only: title, description, og and JSON-LD.
    // MUTATION: echo BrandName::homeDescription() into the home h1 -> red.
    brHomeSettings();
    $html = $this->get('/')->getContent();
    $body = (string) strstr((string) $html, '</head>');
    $body = (string) preg_replace('#<script\b.*?</script>#s', '', $body);

    expect($body)->not->toContain('Korean Skincare & K-Beauty Store in UAE')
        ->not->toContain('Korean Skincare &amp; K-Beauty Store in UAE')
        ->not->toContain('KBeauty Bliss')->not->toContain('Kbeautybliss');
});
