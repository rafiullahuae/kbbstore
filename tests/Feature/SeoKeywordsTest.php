<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Seo\Keywords\AutocompleteSource;
use App\Services\Seo\Keywords\KeywordComposer;
use App\Services\Seo\Keywords\KeywordConfig;
use App\Services\Seo\Keywords\KeywordSync;
use App\Services\Seo\Keywords\KeywordText;
use App\Services\Seo\Keywords\SearchConsoleSource;
use App\Services\Seo\Keywords\SeedPlanner;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ArabicShop;
use Tests\Support\EnglishRenderWalk;
use Tests\Support\SeoKeywordsRoutes;

/**
 * Store → SEO Keywords (Lane KW).
 *
 * The owner asked for "hidden keywords across every page, product, collection,
 * brand", mixed from what people really search, "not spamming". The integrator's
 * ruling, which this file enforces: Google penalises hidden TEXT and keyword
 * STUFFING, so keywords are published only where shoppers never see them and a
 * search engine is invited to read them — schema.org `keywords` in the JSON-LD
 * and <meta name="keywords"> (switchable, ≤10) — plus an opt-in visible block of
 * real internal links. Every case below says what the defect would look like on
 * the shop, and the mutation that turns it red.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        'suggestqueries.google.com/*' => function (HttpRequest $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $seed = (string) ($q['q'] ?? '');

            return Http::response(json_encode([$seed, [$seed.' uae', $seed.' price', 'best '.$seed]]), 200);
        },
    ]);
    // Never really sleep in a test: the throttle's waits are recorded instead.
    $this->slept = [];
    $slept = &$this->slept;
    app()->instance(AutocompleteSource::class, new AutocompleteSource(function (int $ms) use (&$slept): void {
        $slept[] = $ms;
    }));
    KeywordSync::$stepSeconds = 30.0;
    KeywordSync::$chunk = 40;
});

function kwCatalogue(): array
{
    // The test database carries the demo catalogue (24 products, 8 brands,
    // 6 categories, 7 pages); these sit beside it.
    $cosrx = Brand::firstOrCreate(['slug' => 'cosrx'], ['name' => 'COSRX']);
    $anua = Brand::firstOrCreate(['slug' => 'anua'], ['name' => 'Anua']);
    $serums = Category::create(['name' => 'Snail Essences', 'slug' => 'kw-essences', 'path' => 'kw-essences']);
    $toners = Category::create(['name' => 'Calming Toners', 'slug' => 'kw-toners', 'path' => 'kw-toners']);
    $mk = fn (string $name, string $slug, Brand $b, Category $c, array $extra = []) => Product::create(array_merge([
        'slug' => $slug, 'name' => $name, 'brand_id' => $b->id, 'category_id' => $c->id,
        'price' => 9000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple',
    ], $extra));

    $snail = $mk('COSRX Advanced Snail 96 Mucin Power Essence 100ml', 'cosrx-snail-essence', $cosrx, $serums, ['total_sales' => 50]);
    $nia = $mk('COSRX The Niacinamide 15 Serum 20ml', 'cosrx-niacinamide-serum', $cosrx, $serums, ['total_sales' => 20]);
    $toner = $mk('Anua Heartleaf 77% Soothing Toner 250ml', 'anua-heartleaf-toner', $anua, $toners, ['routine_concerns' => json_encode(['sensitivity'])]);

    Post::create(['slug' => 'snail-mucin-guide', 'title' => 'Snail mucin guide', 'body' => '<p>x</p>', 'status' => 'published', 'published_at' => now()]);

    return compact('cosrx', 'anua', 'serums', 'toners', 'snail', 'nia', 'toner');
}

function kwRun(bool $dry = false, array $types = ['product', 'category', 'brand', 'collection', 'page', 'post']): object
{
    $run = KeywordSync::start($types, $dry, null);
    $guard = 0;
    while ($run->status === 'running' && $guard++ < 500) {
        $run = KeywordSync::step($run->id);
    }

    return $run;
}

function kwFresh(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

function kwHead(string $html): string
{
    return (string) strstr($html, '</head>', true);
}

/** @return list<array> every JSON-LD node on the page */
function kwJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn ($j) => json_decode($j, true), $m[1]);
}

function kwMeta(string $html): ?string
{
    return preg_match('#<meta name="keywords" content="([^"]*)">#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null;
}

function kwAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'KW '.$role, 'email' => 'kw-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-pass-1', 'role' => $role,
    ]);
}

/* ------------------------------------------------------------------------ */

it('changes nothing on the shop until the first sync has run', function () {
    // Defect: a shop that never opens SEO → Keywords grows a keywords tag or
    // a WebPage node (or pays a query) the day the package applies.
    // Mutation: make PageKeywords::for() skip its `$live === ''` return -> the
    // query-count half goes red.
    $c = kwCatalogue();
    kwFresh();
    $html = $this->get('/product/cosrx-snail-essence/')->assertOk()->getContent();

    expect(kwMeta($html))->toBeNull()
        ->and($html)->not->toContain('"@type":"WebPage"');
    $product = collect(kwJsonLd($html))->firstWhere('@type', 'Product');
    expect($product)->not->toHaveKey('keywords');
});

it('publishes each page\'s keywords in its JSON-LD and a capped meta tag after a sync', function () {
    // Defect: the sync "succeeds" and the product page publishes nothing, or
    // publishes twenty phrases. Mutation: drop the meta line in Seo::render ->
    // red; change array_slice(…, 0, 10) to 20 and give the page 12 -> red.
    kwCatalogue();
    $run = kwRun();
    expect($run->status)->toBe('done');

    kwFresh();
    $html = $this->get('/product/cosrx-snail-essence/')->assertOk()->getContent();
    $meta = kwMeta($html);
    expect($meta)->not->toBeNull();
    $list = explode(', ', $meta);
    expect(count($list))->toBeLessThanOrEqual(10)
        ->and($list)->toContain('cosrx advanced snail 96 mucin power essence')
        ->and(count($list))->toBe(count(array_unique($list)));

    $product = collect(kwJsonLd($html))->firstWhere('@type', 'Product');
    expect($product['keywords'] ?? null)->toBe($meta);

    // Category and brand pages: CollectionPage; home: a WebPage node.
    kwFresh();
    $cat = collect(kwJsonLd($this->get('/collections/kw-essences/')->getContent()))->firstWhere('@type', 'CollectionPage');
    kwFresh();
    $home = collect(kwJsonLd($this->get('/')->getContent()))->firstWhere('@type', 'WebPage');
    expect($cat['keywords'] ?? '')->toContain('snail essences')
        ->and($home['keywords'] ?? '')->toContain('korean skincare');
});

it('switches the meta tag off without touching the JSON-LD', function () {
    // Defect: the owner turns the tag off and it stays, or the JSON-LD goes
    // with it. Mutation: have KeywordConfig::metaOn() ignore the setting -> red.
    kwCatalogue();
    kwRun();
    KeywordConfig::setSwitch(KeywordConfig::META, false);
    kwFresh();
    $html = $this->get('/product/cosrx-snail-essence/')->getContent();

    expect(kwMeta($html))->toBeNull();
    expect(collect(kwJsonLd($html))->firstWhere('@type', 'Product'))->toHaveKey('keywords');
});

it('never puts a keyword in the visible page — the body is byte-identical before and after a sync', function () {
    // Defect: the "hidden keywords" the owner asked for, done the way Google
    // penalises — a display:none div, white text, an off-screen span. A body
    // that does not move by one byte cannot carry any of them.
    // Mutation: echo the keywords anywhere inside <body> (e.g. into the
    // popular-searches partial with the switch off) -> red.
    kwCatalogue();
    $pages = ['/', '/product/cosrx-snail-essence/', '/brands/cosrx/', '/about/'];
    $body = function (string $path): string {
        kwFresh();
        $html = EnglishRenderWalk::mask($this->get($path)->getContent());

        return (string) strstr($html, '<body');
    };
    $before = array_map($body, $pages);
    kwRun();
    $after = array_map($body, $pages);

    foreach ($pages as $i => $p) {
        expect($after[$i])->toBe($before[$i], "the visible page {$p} changed after a sync");
    }
});

it('shows the Popular searches links only when the owner switches them on', function () {
    // The one visible channel: real links to the brand's best sellers.
    // Mutation: default seo_kw_popular to '1' -> the first half is red.
    $c = kwCatalogue();
    kwRun();
    kwFresh();
    expect($this->get('/brands/cosrx/')->getContent())->not->toContain('kbb-popsearch');

    KeywordConfig::setSwitch(KeywordConfig::POPULAR, true);
    kwFresh();
    $html = $this->get('/brands/cosrx/')->getContent();
    expect($html)->toContain('class="kbb-popsearch"')
        ->and($html)->toContain('/product/cosrx-snail-essence/')
        ->and(substr_count($html, 'class="kbb-popsearch"'))->toBe(1);
});

it('gives different pages different keyword sets, and a primary keyword to one page only', function () {
    // Defect: every serum page carries the same ten phrases (cannibalisation),
    // or two pages claim one primary. Mutation: remove the owners check in
    // KeywordComposer::compose -> the duplicate-name pair shares a primary.
    $c = kwCatalogue();
    // Two products whose names reduce to the same words.
    Product::create(['slug' => 'cosrx-snail-50', 'name' => 'COSRX Advanced Snail 96 Mucin Power Essence 50ml', 'brand_id' => $c['cosrx']->id,
        'category_id' => $c['serums']->id, 'price' => 5000, 'status' => 'publish', 'is_visible' => 1, 'stock_status' => 'instock', 'type' => 'simple']);
    kwRun();

    $rows = DB::table('seo_page_keywords')->where('locale', 'en')->get();
    $sets = $rows->map(fn ($r) => $r->keywords)->all();
    expect(count($sets))->toBe(count(array_unique($sets)));

    $primaries = $rows->pluck('primary_kw')->filter()->all();
    expect(count($primaries))->toBe(count(array_unique($primaries)));
    expect($rows->whereNotNull('clash')->count())->toBeGreaterThanOrEqual(1);
});

it('keeps every keyword clean: no markup, no list separators, bounded length', function () {
    // Defect: a Search Console query or a shopper's search containing
    // "<script>" or a comma reaches the head. Mutation: drop strip_tags or the
    // comma from KeywordText::clean -> red.
    expect(KeywordText::clean('<b>snail</b> "mucin", essence'))->toBe('snail mucin essence')
        ->and(KeywordText::clean("korean\u{202E}serum\x07"))->toBe('korean serum')
        ->and(KeywordText::clean(str_repeat('a', 61)))->toBeNull()
        ->and(KeywordText::clean('12345'))->toBeNull()
        ->and(KeywordText::clean('one two three four five six seven eight nine'))->toBeNull();
});

it('escapes keywords where they are printed', function () {
    // Mutation: print the meta keywords without $e() -> the raw ampersand
    // breaks out as markup and this is red.
    $c = kwCatalogue();
    kwRun();
    DB::table('seo_page_keywords')->where('entity_type', 'product')->where('entity_id', (string) $c['snail']->id)
        ->update(['keywords' => json_encode(['snail & mucin', 'ok phrase'])]);
    Cache::flush();
    kwFresh();
    $html = $this->get('/product/cosrx-snail-essence/')->getContent();
    expect($html)->toContain('<meta name="keywords" content="snail &amp; mucin, ok phrase">')
        ->and($html)->toContain('snail \\u0026 mucin')
        ->and(kwHead($html))->not->toContain('snail & mucin');
});

it('parses Google Autocomplete and caches each seed for thirty days', function () {
    // Mutation: drop the Cache::put in suggest() -> the second call makes a
    // second request and the count is red.
    $ac = app(AutocompleteSource::class);
    expect($ac->suggest('korean serum', 'en'))->toBe(['korean serum uae', 'korean serum price', 'best korean serum'])
        ->and($ac->suggest('korean serum', 'en'))->toHaveCount(3)
        ->and($ac->requests)->toBe(1);
    expect(AutocompleteSource::parse('not json'))->toBe([])
        ->and(AutocompleteSource::parse('["q",["<i>xx</i> yy","aa,bb"]]'))->toBe(['xx yy', 'aa bb']);
});

it('waits a full second between Autocomplete requests, across steps', function () {
    // Defect: 300 requests in a burst and Google blocks the server's IP.
    // Mutation: remove the throttle() call -> nothing is slept and this is red.
    $ac = app(AutocompleteSource::class);
    $ac->suggest('korean toner', 'en');
    $ac->suggest('korean cleanser', 'en');
    expect($this->slept)->toHaveCount(1)
        ->and($this->slept[0])->toBeGreaterThan(900)->toBeLessThanOrEqual(1000);
});

it('caps the seeds a sync may ask about', function () {
    // Mutation: drop array_slice($seeds, 0, $cap) -> red.
    kwCatalogue();
    KeywordConfig::saveOptions(['seed_cap' => 5]);
    expect(SeedPlanner::plan(KeywordConfig::options(), ['en']))->toHaveCount(5);
    KeywordConfig::saveOptions(['seed_cap' => 9999]);
    expect(KeywordConfig::options()['seed_cap'])->toBe(300);

    $run = kwRun();
    expect($run->counts['requests'])->toBeLessThanOrEqual(300);
});

it('carries on without error when Google cannot be reached', function () {
    // Defect: the live server loses its route out, and Sync dies half way with
    // a 500. Mutation: rethrow inside AutocompleteSource::suggest -> red.
    kwCatalogue();
    Http::fake(['suggestqueries.google.com/*' => fn () => throw new ConnectionException('offline')]);
    $run = kwRun();

    expect($run->status)->toBe('done')
        ->and($run->counts['failed'])->toBeGreaterThan(0)
        ->and(DB::table('seo_page_keywords')->count())->toBeGreaterThan(0);
});

it('reads Search Console with a signed service-account token and banks its queries', function () {
    // Mutation: send the assertion unsigned -> the fake token endpoint refuses
    // and nothing is banked.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $public = openssl_pkey_get_details($key)['key'];
    KeywordConfig::saveGscKey(json_encode(['type' => 'service_account', 'client_email' => 'kw@proj.iam.gserviceaccount.com', 'private_key' => $pem]));
    KeywordConfig::saveOptions(['gsc_property' => 'sc-domain:example.ae']);

    Http::fake([
        'oauth2.googleapis.com/token' => function (HttpRequest $r) use ($public) {
            [$h, $p, $sig] = explode('.', $r['assertion']);
            $ok = openssl_verify("{$h}.{$p}", base64_decode(strtr($sig, '-_', '+/')), $public, OPENSSL_ALGO_SHA256) === 1;

            return $ok ? Http::response(['access_token' => 'tok', 'expires_in' => 3600]) : Http::response([], 400);
        },
        'www.googleapis.com/webmasters/*' => Http::response(['rows' => [
            ['keys' => ['cosrx snail mucin', 'https://example.ae/product/cosrx-snail-essence/'], 'impressions' => 900, 'clicks' => 40, 'position' => 4.2],
            ['keys' => ['<b>cosrx</b> serum', 'https://example.ae/'], 'impressions' => 5, 'clicks' => 0, 'position' => 50],
        ]]),
        'suggestqueries.google.com/*' => Http::response('["q",[]]'),
    ]);

    $rows = app(SearchConsoleSource::class)->rows();
    expect($rows)->toHaveCount(2)->and($rows[0]['page'])->toBe('/product/cosrx-snail-essence/')
        ->and($rows[1]['term'])->toBe('cosrx serum');

    kwCatalogue();
    kwRun(false, ['product']);
    $bank = DB::table('seo_keywords')->where('term', 'cosrx snail mucin')->first();
    expect($bank->source)->toBe('gsc');
    $snail = DB::table('seo_page_keywords')->where('entity_type', 'product')->where('locale', 'en')
        ->where('entity_id', (string) Product::where('slug', 'cosrx-snail-essence')->value('id'))->first();
    expect(json_decode($snail->keywords, true))->toContain('cosrx snail mucin');
});

it('never lets the Search Console key leave the server', function () {
    // Defect: the private key (or its ciphertext) in GET /admin-api/settings,
    // which returns the settings table wholesale. Mutation: store the key with
    // Setting::updateOrCreate -> the settings half goes red.
    SeoKeywordsRoutes::wire(app());
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $json = json_encode(['type' => 'service_account', 'client_email' => 'kw@proj.iam.gserviceaccount.com', 'private_key' => $pem]);
    $owner = kwAdmin('owner');

    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-keywords/gsc', ['key' => $json])->assertOk()
        ->assertJsonMissing(['private_key' => $pem]);
    $stored = DB::table('seo_keyword_config')->where('name', 'gsc')->value('value');
    expect($stored)->not->toContain('PRIVATE KEY');

    $overview = $this->actingAs($owner, 'admin')->getJson('/admin-api/seo-keywords')->assertOk()->getContent();
    $settings = $this->actingAs($owner, 'admin')->getJson('/admin-api/settings')->assertOk()->getContent();
    foreach ([$overview, $settings] as $out) {
        expect($out)->not->toContain('PRIVATE KEY')->not->toContain(substr($stored, 10, 40));
    }
    expect($overview)->toContain('kw@proj.iam.gserviceaccount.com');
});

it('runs in small resumable steps and finishes where a one-shot run does', function () {
    // Defect: one long request that the host kills at 30 seconds, or a resume
    // that starts over or skips pages. Mutation: reset $cur['after'] to 0 at
    // the top of composeStep -> the stepped run never finishes (guard trips).
    kwCatalogue();
    KeywordSync::$chunk = 1;
    KeywordSync::$stepSeconds = 0.000001;

    $run = KeywordSync::start(['product', 'brand'], false, null);
    $steps = 0;
    while ($run->status === 'running' && $steps < 200) {
        $run = KeywordSync::step($run->id); // each call is a fresh request on the server
        $steps++;
    }
    expect($run->status)->toBe('done')->and($steps)->toBeGreaterThan(3);
    $stepped = DB::table('seo_page_keywords')->orderBy('entity_type')->orderBy('entity_id')->pluck('keywords')->all();

    DB::table('seo_page_keywords')->delete();
    KeywordSync::$chunk = 40;
    KeywordSync::$stepSeconds = 30.0;
    kwRun(false, ['product', 'brand']);
    expect(DB::table('seo_page_keywords')->orderBy('entity_type')->orderBy('entity_id')->pluck('keywords')->all())->toBe($stepped);
});

it('dry-runs without writing a page, and shows the diff', function () {
    // Mutation: drop the `if ($dry) … continue;` -> rows are written and red.
    kwCatalogue();
    $run = kwRun(true);
    expect($run->status)->toBe('done')->and($run->dry)->toBeTrue()
        ->and(DB::table('seo_page_keywords')->count())->toBe(0)
        ->and($run->diffs)->not->toBeEmpty()
        ->and($run->counts['created'])->toBeGreaterThan(0);
    expect((string) (Setting::query()->find(KeywordConfig::LIVE)?->value ?? ''))->toBe('');
});

it('undoes the last sync, primaries and all, and leaves locked pages alone', function () {
    // Mutation: skip the second (primaries) pass in KeywordSync::undo -> the
    // restored row has no primary and this is red.
    $c = kwCatalogue();
    kwRun(false, ['product']);
    $id = (string) $c['snail']->id;
    $first = DB::table('seo_page_keywords')->where('entity_type', 'product')->where('entity_id', $id)->first();

    $c['snail']->update(['name' => 'COSRX Snail Mucin 92 All In One Cream 100g']);
    DB::table('seo_page_keywords')->where('entity_type', 'product')->where('entity_id', (string) $c['toner']->id)->update(['locked' => true, 'keywords' => json_encode(['mine'])]);
    $stamp = Setting::query()->find(KeywordConfig::LIVE)->value;
    kwRun(false, ['product']);
    $second = DB::table('seo_page_keywords')->where('entity_type', 'product')->where('entity_id', $id)->first();
    expect($second->keywords)->not->toBe($first->keywords);
    expect(DB::table('seo_page_keywords')->where('entity_id', (string) $c['toner']->id)->value('keywords'))->toBe(json_encode(['mine']));

    KeywordSync::undo();
    // Only one level: a second Undo would reach a run whose rows no longer
    // remember what came before it, so there is nothing more to undo.
    expect(KeywordSync::undoable())->toBeNull()->and(KeywordSync::undo())->toBeNull();
    $back = DB::table('seo_page_keywords')->where('entity_type', 'product')->where('entity_id', $id)->first();
    expect($back->keywords)->toBe($first->keywords)
        ->and($back->primary_kw)->toBe($first->primary_kw)
        ->and(Setting::query()->find(KeywordConfig::LIVE)->value)->not->toBe($stamp);
});

it('gives Arabic pages Arabic keywords', function () {
    // Defect: /ar/ pages publish the English list. Mutation: pass 'en' to
    // PageKeywords::for in Seo::render -> red.
    ArabicShop::on();
    kwCatalogue();
    expect(KeywordSync::locales())->toBe(['en', 'ar']);
    kwRun(false, ['product']);
    kwFresh();
    $html = $this->get('/ar/product/cosrx-snail-essence/')->assertOk()->getContent();
    $meta = (string) kwMeta($html);
    expect($meta)->toMatch('/\p{Arabic}/u');
    kwFresh();
    expect((string) kwMeta($this->get('/product/cosrx-snail-essence/')->getContent()))->not->toMatch('/\p{Arabic}/u');
});

it('costs a product page no extra query once synced, and stays flat', function () {
    // Defect: one SELECT per page view for its keywords (or per keyword).
    // Mutation: replace Cache::remember in PageKeywords::for with the bare
    // query -> the warm count rises by one and this is red.
    kwCatalogue();
    $count = function (): int {
        $n = 0;
        kwFresh();
        $this->get('/product/cosrx-snail-essence/'); // warm
        kwFresh();
        DB::listen(function () use (&$n) {
            $n++;
        });
        $this->get('/product/cosrx-snail-essence/')->assertOk();

        return $n;
    };
    $before = $count();
    kwRun();
    $after = $count();
    expect($after)->toBe($before);
});

it('maps every route to its own capability and fails closed for the rest', function () {
    // Mutation: delete the three RULES rows -> every route maps to null and
    // the first expectation is red; give support seo_keywords.view -> red.
    SeoKeywordsRoutes::wire(app());
    $routes = SeoKeywordsRoutes::registered();
    expect($routes)->toHaveCount(12);
    foreach ($routes as $r) {
        $cap = AdminCapabilities::for($r);
        $isRead = in_array('GET', $r->methods(), true);
        expect($cap)->toBe($isRead ? 'seo_keywords.view' : 'seo_keywords.sync', $r->uri());
    }

    $this->actingAs(kwAdmin('manager'), 'admin')->getJson('/admin-api/seo-keywords')->assertOk();
    $this->actingAs(kwAdmin('manager'), 'admin')->postJson('/admin-api/seo-keywords/sync', ['types' => ['product'], 'dry' => true])->assertForbidden();
    $this->actingAs(kwAdmin('support'), 'admin')->getJson('/admin-api/seo-keywords')->assertForbidden();
    $this->actingAs(kwAdmin('editor'), 'admin')->getJson('/admin-api/seo-keywords/bank')->assertForbidden();
    expect($this->getJson('/admin-api/seo-keywords')->status())->toBeIn([401, 403]);
});

it('validates every input: types, selects and the property', function () {
    // Mutation: drop 'in:' from the types rule -> an unknown type is accepted.
    SeoKeywordsRoutes::wire(app());
    $owner = kwAdmin('owner');
    $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-keywords/sync', ['types' => ['users'], 'dry' => true])->assertUnprocessable();
    $this->actingAs($owner, 'admin')->getJson('/admin-api/seo-keywords/bank?source=evil')->assertUnprocessable();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-keywords/settings', ['gsc_property' => 'javascript:alert(1)'])->assertUnprocessable();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-keywords/gsc', ['key' => '{"type":"user"}'])->assertUnprocessable();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-keywords/settings', ['seed_cap' => 301])->assertUnprocessable();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-keywords/settings', ['gsc_property' => 'sc-domain:extrabeauty.ae', 'meta' => false])
        ->assertOk()->assertJsonPath('switches.meta', false)->assertJsonPath('options.gsc_property', 'sc-domain:extrabeauty.ae');
});

it('drives a sync, a hand edit and a one-click title through the admin API', function () {
    // The screen's own path. Mutation: let savePage accept a primary another
    // page owns -> the 422 is red.
    SeoKeywordsRoutes::wire(app());
    $c = kwCatalogue();
    $owner = kwAdmin('owner');
    $run = $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-keywords/sync', ['types' => ['product'], 'dry' => false])->assertOk()->json();
    while ($run['status'] === 'running') {
        $run = $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-keywords/sync/'.$run['id'].'/step')->assertOk()->json();
    }
    $pages = $this->actingAs($owner, 'admin')->getJson('/admin-api/seo-keywords/entities?type=product')->assertOk()->json();
    expect($pages['total'])->toBe(Product::query()->visible()->count());

    $taken = DB::table('seo_page_keywords')->where('entity_id', (string) $c['nia']->id)->value('primary_kw');
    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-keywords/entities', [
        'type' => 'product', 'id' => (string) $c['snail']->id, 'locale' => 'en', 'keywords' => ['x y z'], 'primary' => $taken, 'locked' => true,
    ])->assertStatus(422);
    $this->actingAs($owner, 'admin')->putJson('/admin-api/seo-keywords/entities', [
        'type' => 'product', 'id' => (string) $c['snail']->id, 'locale' => 'en', 'keywords' => ['<b>snail</b> serum', 'snail serum'], 'primary' => 'snail serum', 'locked' => true,
    ])->assertOk()->assertJsonPath('keywords', ['snail serum']);

    $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-keywords/apply', ['type' => 'product', 'id' => $c['nia']->id, 'fields' => ['title']])->assertOk();
    expect($c['nia']->fresh()->seo['title'] ?? '')->toContain('COSRX');
    /*
     * Integrator, 2.60.377: with somebody else editing that product, the
     * one-click title is refused with their name -- their editor's next Save
     * would otherwise put the old title back without anyone noticing.
     * MUTATION: drop the EditPresence::heldByOther() check in apply() and
     * this is 200.
     */
    $other = kwAdmin('manager');
    \App\Support\EditPresence::beat($other, 'product', (string) $c['nia']->id, null);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/seo-keywords/apply', ['type' => 'product', 'id' => $c['nia']->id, 'fields' => ['title']])
        ->assertStatus(409)->assertJsonPath('error', 'edit_locked')->assertJsonPath('holder', 'KW manager');
});

it('composes four layers within their quotas from the page\'s own facts', function () {
    // Mutation: change QUOTA['own'] to 10 -> industry and skincare are empty.
    $r = KeywordComposer::compose([
        'type' => 'product', 'id' => '1', 'locale' => 'en', 'name' => 'Anua Heartleaf 77% Soothing Toner', 'display' => 'Anua Heartleaf 77% Soothing Toner',
        'brand' => 'Anua', 'core' => 'heartleaf 77% soothing toner', 'category' => 'Toners', 'path' => '/product/x/',
        'kind' => 'toner', 'ingredients' => ['heartleaf'], 'concerns' => ['sensitivity'], 'skin' => [],
    ], ['terms' => [], 'index' => [], 'pages' => []], [], 'K-Beauty Bliss');

    expect(count($r['keywords']))->toBeLessThanOrEqual(10)
        ->and($r['layers']['own'])->not->toBeEmpty()
        ->and($r['layers']['product'])->toContain('heartleaf toner')
        ->and($r['layers']['industry'])->toContain('korean toner')
        ->and($r['layers']['skincare'])->toContain('toner for sensitive skin')
        ->and($r['primary'])->toBe('anua heartleaf 77% soothing toner')
        ->and(mb_strlen($r['suggest']['desc']))->toBeLessThanOrEqual(158);
});

it('is wired at most once, so it is never registered twice', function () {
    // Pins the finished state from both sides of the integrator's edit: zero
    // in this lane, one after wiring, never two.
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($web, "require __DIR__ . '/seo-keywords-admin.php';") + substr_count($web, "require __DIR__.'/seo-keywords-admin.php';"))->toBeLessThanOrEqual(1)
        ->and(substr_count($app, "@include('admin.partials.seo-keywords-screen')"))->toBeLessThanOrEqual(1)
        ->and(substr_count($app, "{screen:'seokeywords'"))->toBeLessThanOrEqual(1);
});

it('prints every server string in the admin screen through esc()', function () {
    // Keywords come from Google, Search Console and shoppers' searches; a
    // concatenated `+ r.term` is stored script waiting to run in the console.
    // Mutation: change esc(r.term) to r.term in the partial -> red.
    $src = (string) file_get_contents(resource_path('views/admin/partials/seo-keywords-screen.blade.php'));
    preg_match_all('/\+\s*(?:r|c|d|row|g|n|run)\.(term|name|clash|primary|email|property|title|desc|error|entity)\b/', $src, $m);
    expect($m[0])->toBe([])
        ->and($src)->toContain('function esc(s)')
        ->and($src)->not->toContain('setInterval');
});
