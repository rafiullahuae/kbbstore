<?php

declare(strict_types=1);

/**
 * Catalog -> Translate & categorise. (Lane LT)
 *
 * What each defect would look like on the shop:
 *  - the run picking "recent" by id or including drafts, so the owner's
 *    newest products are not the ones translated;
 *  - a machine answer that dropped a </li>, renamed a class or lost "50ml"
 *    printed raw on the Arabic product page;
 *  - machine Arabic appearing on /ar/ before the owner approved it;
 *  - "Approve all" publishing another product's drafts, or overwriting a
 *    translation the owner had typed and approved;
 *  - price, SKU or a product id leaving the shop in a request;
 *  - a made-up concern slug from Claude reaching category_product, or a
 *    membership the owner set being removed;
 *  - an editor spending the owner's Claude key.
 *
 * MUTATIONS, RUN: orderByDesc('created_at') -> orderByDesc('id') in
 * RecentLocalise::scope -- "newest by date added" is red; `return null` at the
 * top of HtmlStructure::problem -- "rejects a mangled answer" is red;
 * STATUS_DRAFT -> STATUS_PUBLISHED in RecentLocalise::step -- "drafts do not
 * show" is red; drop whereIn('item_id', $ids) in approve() AND the
 * `$p === null` skip under it (two guards, either alone holds) -- "exactly
 * the scoped products" is red; drop `isset($rows[$id][$f])` in pending() -- "a
 * human translation is never re-sent" is red; add 'price' to
 * ClaudeTranslator::body's item -- "sends only index, kind and text" is red;
 * drop the two RULES rows in AdminCapabilities -- the manager case is red.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\ConcernSync\ConcernSync;
use App\Services\ConcernSync\ConcernSyncCredentials;
use App\Services\RecentLocalise\HtmlStructure;
use App\Services\RecentLocalise\RecentLocalise;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\AdminCapabilities;
use App\Support\Locale;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecentLocaliseAdminRoutes;

beforeEach(function () {
    RecentLocaliseAdminRoutes::wire(app());
    Http::preventStrayRequests();
    RecentLocalise::forgetRejected();
});

function ltAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'LT '.$role, 'email' => 'lt-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

function ltProd(array $extra = []): int
{
    $slug = $extra['slug'] ?? 'lt-'.Str::random(8);

    return (int) DB::table('products')->insertGetId($extra + ['slug' => $slug, 'name' => 'Product '.$slug, 'type' => 'simple', 'sku' => 'SKU-SECRET-'.Str::random(4),
        'status' => 'publish', 'price' => 98765, 'stock_status' => 'instock', 'is_visible' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

/** The seven concern collections, created if the seeded database lacks them. @return array<string,int> */
function ltConcerns(): array
{
    $out = [];
    foreach (ConcernSync::CONCERNS as $slug => $name) {
        $id = DB::table('categories')->where('slug', $slug)->value('id');
        $out[$slug] = (int) ($id ?? DB::table('categories')->insertGetId(['slug' => $slug, 'name' => $name, 'path' => $slug, 'created_at' => now(), 'updated_at' => now()]));
    }

    return $out;
}

function ltKey(): void
{
    ConcernSyncCredentials::saveKey('sk-ant-test-key');
}

/** A faithful fake: every run of words becomes Arabic, tags and numbers kept. */
function ltArabic(string $text): string
{
    $parts = preg_split('/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    return implode('', array_map(static function (string $p): string {
        if (str_starts_with($p, '<') || preg_match('/\p{L}/u', $p) !== 1) {
            return $p;
        }
        preg_match_all('/\d+(?:[.,]\d+)*/', $p, $m);

        return 'ترجمة'.($m[0] ? ' '.implode(' ', $m[0]) : '');
    }, $parts));
}

/** @param callable(array): ?string $mangle  item => replacement Arabic, or null for the faithful one */
function ltFakeClaude(?callable $mangle = null): void
{
    Http::fake(['api.anthropic.com/*' => function (HttpRequest $req) use ($mangle) {
        $body = $req->data();
        $content = $body['messages'][0]['content'];
        $items = json_decode(substr($content, strpos($content, '{')), true)['items'];
        $out = [];
        foreach ($items as $it) {
            $ar = $mangle ? $mangle($it) : null;
            $out[] = ['i' => $it['i'], 'ar' => $ar ?? ltArabic($it['text'])];
        }

        return Http::response(['id' => 'msg', 'type' => 'message', 'role' => 'assistant', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode(['translations' => $out], JSON_UNESCAPED_UNICODE)]]]);
    }]);
}

function ltArabicOn(): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

it('scopes the newest published products by date added, then id, and by days', function () {
    // The suite's database already carries the seeded catalogue (created
    // "now"), so these sit in the future to be the newest.
    $old = ltProd(['created_at' => now()->subDays(400)]);
    $mid = ltProd(['created_at' => now()->addDays(5)]);
    $new = ltProd(['created_at' => now()->addDays(9)]);
    $sameDayLowId = ltProd(['created_at' => now()->addDays(5)]);
    $draft = ltProd(['status' => 'draft', 'created_at' => now()->addDays(20)]);

    // Same date: the higher id first, exactly as the shop's Newest sort.
    expect(RecentLocalise::scope(3))->toBe([$new, $sameDayLowId, $mid])
        ->and(RecentLocalise::scope(500, 10))->toContain($new)->toContain($mid)->not->toContain($old)->not->toContain($draft)
        ->and(RecentLocalise::scope(500))->toContain($old);
});

it('rejects a mangled HTML answer and accepts a faithful one', function () {
    $en = '<p class="lead">Use <strong>50ml</strong> daily.</p><ul><li>2.5% Niacinamide</li></ul>';

    expect(HtmlStructure::problem($en, ltArabic($en)))->toBeNull()
        // a dropped </li>
        ->and(HtmlStructure::problem($en, '<p class="lead">استخدمي <strong>50ml</strong> يوميًا.</p><ul><li>2.5% Niacinamide</ul>'))->toContain('HTML changed')
        // an attribute changed
        ->and(HtmlStructure::problem($en, '<p class="intro">استخدمي <strong>50ml</strong> يوميًا.</p><ul><li>2.5% Niacinamide</li></ul>'))->toContain('HTML changed')
        // a tag added
        ->and(HtmlStructure::problem($en, '<p class="lead">استخدمي <strong>50ml</strong> <em>يوميًا</em>.</p><ul><li>2.5% Niacinamide</li></ul>'))->toContain('HTML changed')
        // a number lost
        ->and(HtmlStructure::problem($en, '<p class="lead">استخدمي <strong>ملعقة</strong> يوميًا.</p><ul><li>2.5% Niacinamide</li></ul>'))->toContain('number 50')
        // English back
        ->and(HtmlStructure::problem('Gentle cleanser', 'Gentle cleanser'))->toContain('no Arabic');
});

it('writes Arabic DRAFTS, rejects a mangled answer, and sends only index, kind and text', function () {
    ltKey();
    $p = ltProd(['name' => 'COSRX Snail Mucin 96% Essence 100ml', 'short_description' => '<p>Hydrates in <b>one</b> step.</p>',
        'description' => '<ul><li>Soothes</li><li>Repairs</li></ul>', 'ingredients' => '<p>Snail Secretion Filtrate 96%</p>']);
    ltFakeClaude(fn (array $it) => $it['kind'] === 'full description (HTML)' ? '<ul><li>يهدئ</li></ul>' : null);

    $r = RecentLocalise::step([$p], 'sk-ant-test-key', 'claude-sonnet-5-5');

    expect($r['translated'])->toBe(3)
        ->and(array_column($r['rejected'], 'field'))->toBe(['description'])
        ->and($r['rejected'][0]['why'])->toContain('HTML changed');

    $rows = Translation::query()->where('item_id', $p)->where('group', 'products')->get()->keyBy('field');
    expect($rows->keys()->sort()->values()->all())->toBe(['ingredients', 'name', 'short_description'])
        ->and($rows->every(fn ($t) => $t->status === Translation::STATUS_DRAFT && $t->source === Translation::SOURCE_MACHINE))->toBeTrue()
        ->and($rows['name']->value)->toContain('96')->toContain('100');

    Http::assertSent(function (HttpRequest $req) {
        $b = $req->data();
        $items = json_decode(substr($b['messages'][0]['content'], strpos($b['messages'][0]['content'], '{')), true)['items'];
        foreach ($items as $it) {
            if (array_keys($it) !== ['i', 'kind', 'text']) {
                return false;
            }
        }
        $raw = json_encode($b);

        return $req->hasHeader('x-api-key', 'sk-ant-test-key')
            && ! str_contains($raw, 'SKU-SECRET') && ! str_contains($raw, '98765') && ! str_contains($raw, '987.65');
    });

    // The rejected field is remembered and not re-bought on the next step.
    expect(collect(RecentLocalise::pending([$p]))->pluck('field')->all())->toBe([]);
});

it('never re-sends or overwrites a translation a human made', function () {
    ltKey();
    $p = ltProd(['name' => 'Anua Heartleaf Toner', 'short_description' => 'Calming toner.']);
    TranslationStore::put('ar', 'products', $p, 'name', 'تونر أنوا', Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL);
    ltFakeClaude();

    RecentLocalise::step([$p], 'sk-ant-test-key', 'claude-sonnet-5-5');
    $r = RecentLocalise::approve([$p]);

    Http::assertSent(fn (HttpRequest $req) => ! str_contains(json_encode($req->data()), 'Anua Heartleaf Toner'));
    $name = Translation::query()->where(['item_id' => $p, 'field' => 'name'])->first();
    expect($name->value)->toBe('تونر أنوا')->and($name->source)->toBe(Translation::SOURCE_MANUAL)
        ->and($r['published'])->toBe(1);
});

it('keeps drafts off /ar/ until approved, then shows them on the product page', function () {
    ltArabicOn();
    $id = ltProd(['name' => 'Hydrating Serum LT', 'slug' => 'lt-serum', 'short_description' => 'A daily serum.']);
    TranslationStore::put('ar', 'products', $id, 'name', 'سيروم مرطب ZZLTAR', Translation::STATUS_DRAFT, Translation::SOURCE_MACHINE, englishSource: 'Hydrating Serum LT');
    TranslationStore::flush();

    $before = (string) $this->get('/ar/product/lt-serum/')->assertOk()->getContent();
    expect($before)->not->toContain('ZZLTAR');

    expect(RecentLocalise::approve([$id])['published'])->toBe(1);
    TranslationStore::flush();

    $after = (string) $this->get('/ar/product/lt-serum/')->assertOk()->getContent();
    expect($after)->toContain('ZZLTAR')
        // the <title> follows the Arabic name, which is what search shows
        ->and(preg_match('#<title>[^<]*ZZLTAR#u', $after))->toBe(1);
});

it('approve-all publishes exactly the scoped products and skips a stale draft', function () {
    $a = ltProd(['name' => 'A product']);
    $b = ltProd(['name' => 'B product']);
    $c = ltProd(['name' => 'C product', 'short_description' => 'New English']);
    TranslationStore::put('ar', 'products', $a, 'name', 'منتج أ', Translation::STATUS_DRAFT, Translation::SOURCE_MACHINE, englishSource: 'A product');
    TranslationStore::put('ar', 'products', $b, 'name', 'منتج ب', Translation::STATUS_DRAFT, Translation::SOURCE_MACHINE, englishSource: 'B product');
    TranslationStore::put('ar', 'products', $c, 'name', 'منتج ج', Translation::STATUS_DRAFT, Translation::SOURCE_MACHINE, englishSource: 'C product');
    TranslationStore::put('ar', 'products', $c, 'short_description', 'قديم', Translation::STATUS_DRAFT, Translation::SOURCE_MACHINE, englishSource: 'Old English');

    $this->actingAs(ltAdmin(), 'admin');
    $j = $this->postJson('/admin-api/recent-localise/approve', ['product_ids' => [$a, $c]])->assertOk()->json();

    $status = fn ($id, $f = 'name') => Translation::query()->where(['item_id' => $id, 'field' => $f])->value('status');
    expect($j['published'])->toBe(2)->and($j['stale'])->toBe(1)->and($j['products'])->toBe(2)
        ->and($status($a))->toBe('published')->and($status($c))->toBe('published')
        ->and($status($b))->toBe('draft')
        ->and($status($c, 'short_description'))->toBe('draft');
});

it('an inline edit is manual, keeps a draft a draft, and is sanitised', function () {
    $p = ltProd(['description' => '<p>Text</p>']);
    $this->actingAs(ltAdmin(), 'admin');

    $j = $this->postJson('/admin-api/recent-localise/save', ['product_id' => $p, 'field' => 'description', 'value' => '<p>نص</p><script>alert(1)</script>'])->assertOk()->json();
    $row = Translation::query()->where(['item_id' => $p, 'field' => 'description'])->first();

    expect($j['status'])->toBe('draft')->and($row->source)->toBe('manual')->and($row->value)->not->toContain('<script');
    $this->postJson('/admin-api/recent-localise/save', ['product_id' => $p, 'field' => 'price', 'value' => '1'])->assertStatus(422);
});

it('scan reports what is missing and estimates before spending, in flat queries', function () {
    $ids = [];
    for ($i = 0; $i < 3; $i++) {
        $ids[] = ltProd(['name' => 'Serum '.$i, 'description' => '<p>Long text '.$i.'</p>', 'created_at' => now()->addDays(30)]);
    }

    $count = function () use (&$ids) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        RecentLocalise::report($ids);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $three = $count();
    for ($i = 0; $i < 37; $i++) {
        $ids[] = ltProd(['name' => 'More '.$i, 'created_at' => now()->addDays(30)]);
    }
    expect($count())->toBe($three);

    $this->actingAs(ltAdmin(), 'admin');
    $j = $this->getJson('/admin-api/recent-localise/scan?count=40')->assertOk()->json();
    expect($j['totals']['products'])->toBe(40)
        ->and($j['totals']['missing']['name'])->toBe(40)
        ->and($j['totals']['missing']['description'])->toBe(3)
        ->and($j['totals']['no_concern'])->toBe(40)
        ->and($j['ids'])->toEqualCanonicalizing($ids)
        ->and($j['estimate']['fields'])->toBe(43)
        ->and($j['estimate']['usd'])->toBeGreaterThan(0);

    Http::assertNothingSent();
});

it('suggests skin concerns with reasons, drops invalid slugs and unasked ids, and only adds', function () {
    ltKey();
    $cat = ltConcerns();
    $serumCat = (int) DB::table('categories')->insertGetId(['slug' => 'lt-serums', 'name' => 'Serums', 'path' => 'lt-serums', 'created_at' => now(), 'updated_at' => now()]);
    $p = ltProd(['name' => 'TXA Niacinamide Serum', 'ingredients' => 'Niacinamide 10%, Tranexamic Acid']);
    DB::table('category_product')->insert(['category_id' => $serumCat, 'product_id' => $p]);
    DB::table('category_product')->insert(['category_id' => $cat['anti-aging'], 'product_id' => $p, 'category_position' => 2]);

    Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => json_encode(['products' => [
        ['id' => $p, 'concerns' => [
            ['slug' => 'pigmentation', 'reason' => 'Tranexamic acid and niacinamide fade dark spots.'],
            ['slug' => 'hair-loss', 'reason' => 'made up'],
            ['slug' => 'anti-aging', 'reason' => 'already in it'],
        ]],
        ['id' => 999999, 'concerns' => [['slug' => 'pore-care', 'reason' => 'never asked']]],
    ]])]]])]);

    $this->actingAs(ltAdmin(), 'admin');
    $j = $this->postJson('/admin-api/recent-localise/concerns/suggest', ['product_ids' => [$p]])->assertOk()->json();

    expect($j['products'])->toHaveCount(1)
        ->and($j['products'][0]['source'])->toBe('claude')
        ->and($j['products'][0]['concerns'])->toBe(['anti-aging'])
        ->and($j['products'][0]['type'])->toBe('Serums')
        ->and($j['products'][0]['suggestions'])->toBe([['slug' => 'pigmentation', 'reason' => 'Tranexamic acid and niacinamide fade dark spots.']]);

    Http::assertSent(function (HttpRequest $req) {
        $raw = json_encode($req->data());
        $user = (string) $req->data()['messages'][0]['content'];

        return str_contains($user, '"product_type":"Serums"') && ! str_contains($raw, 'SKU-SECRET') && ! str_contains($raw, '98765');
    });

    $this->postJson('/admin-api/recent-localise/concerns/apply', ['links' => [['product_id' => $p, 'concerns' => ['hair-loss']]]])->assertStatus(422);
    $k = $this->postJson('/admin-api/recent-localise/concerns/apply', ['links' => [['product_id' => $p, 'concerns' => ['pigmentation']]]])->assertOk()->json();

    expect($k['added'])->toBe(1)
        ->and(DB::table('category_product')->where(['product_id' => $p, 'category_id' => $cat['anti-aging']])->value('category_position'))->toBe(2)
        ->and(DB::table('category_product')->where('product_id', $p)->count())->toBe(3);
});

it('fails closed: anonymous, editor and support are refused; manager and owner are let in', function () {
    foreach (RecentLocaliseAdminRoutes::registered() as $route) {
        foreach ($route->methods() as $m) {
            if ($m !== 'HEAD') {
                expect(AdminCapabilities::forPath($m, $route->uri()))->toBe('catalog.localise', $m.' '.$route->uri());
            }
        }
    }
    expect(RecentLocaliseAdminRoutes::registered())->toHaveCount(9);

    $this->getJson('/admin-api/recent-localise')->assertStatus(401);

    foreach (['editor', 'support'] as $role) {
        $this->actingAs(ltAdmin($role), 'admin');
        $this->getJson('/admin-api/recent-localise')->assertForbidden();
        $this->postJson('/admin-api/recent-localise/run', [])->assertForbidden();
        $this->postJson('/admin-api/recent-localise/approve', ['product_ids' => [1]])->assertForbidden();
    }

    $this->actingAs(ltAdmin('manager'), 'admin');
    $this->getJson('/admin-api/recent-localise')->assertOk()->assertJsonMissingPath('key');

    Http::assertNothingSent();
});

it('the run refuses without a key, and the command --dry sends nothing', function () {
    $p = ltProd(['name' => 'Dry run serum', 'created_at' => now()->addDays(40)]);

    $this->actingAs(ltAdmin(), 'admin');
    $this->postJson('/admin-api/recent-localise/run', ['count' => 5])->assertStatus(422);

    $this->artisan('kbb:recent-localise', ['--dry' => true, '--count' => 5])
        ->expectsOutputToContain('Estimate')
        ->expectsOutputToContain('would send #'.$p.' name')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(Translation::query()->where('group', 'products')->where('item_id', $p)->count())->toBe(0);
});

it('the command translates to drafts and approves only with --approve', function () {
    ltKey();
    $p = ltProd(['name' => 'Command serum 30ml', 'created_at' => now()->addDays(40)]);
    ltFakeClaude();

    $this->artisan('kbb:recent-localise', ['--translate' => true, '--count' => 5])->assertSuccessful();
    expect(Translation::query()->where('item_id', $p)->value('status'))->toBe('draft');

    $this->artisan('kbb:recent-localise', ['--approve' => true, '--count' => 5])->assertSuccessful();
    expect(Translation::query()->where('item_id', $p)->value('status'))->toBe('published');
});

it('is wired once: one sidebar row, one include, one late-rendered id', function () {
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.recent-localise-screen')"))->toBe(1)
        ->and(substr_count($app, "'recentlocalise':['Catalog','Translate & categorise']"))->toBe(1)
        ->and(preg_match("/const LATE_RENDERED=new Set\\(\\[[^\\]]*'recentlocalise'/", $app))->toBe(1)
        ->and(strpos($app, "@include('admin.partials.recent-localise-screen')"))->toBeGreaterThan(strpos($app, "@include('admin.partials.uncaptured-orders-screen')"))
        ->and(collect(\App\Support\AdminNav::rows())->where('id', 'recentlocalise')->count())->toBe(1);
});
