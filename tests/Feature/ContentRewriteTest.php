<?php

declare(strict_types=1);

/*
 * Lane DS: Platform -> Domain switch -> 6b "Old links in the shop's text".
 *
 * Before this, an absolute https://extrabeauty.ae/... link in a product
 * description, a page, the footer or an email template could only be found
 * (kbb:domain-check listed it as RISK) and fixed by hand, one screen at a
 * time -- and after step 11 removes the old address, every one of them is a
 * dead link or a missing picture. These pin the one-click fix: preview first,
 * content only, never orders or payment settings, and undoable.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DomainMove\ContentRewrite;
use App\Services\DomainMove\DomainReadiness;
use App\Services\SettingsService;
use App\Support\SiteHost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\DomainSwitchRoutes;

function crOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'CR '.$role, 'email' => 'cr-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

/** Main address kbeautybliss.com, old extrabeauty.ae; APP_URL on the new one only once switched. */
function crShop(bool $switched): void
{
    config(['app.url' => $switched ? 'https://kbeautybliss.com' : 'https://extrabeauty.ae']);

    foreach ([SiteHost::KEY_CANONICAL => 'kbeautybliss.com', SiteHost::KEY_ALIASES => 'extrabeauty.ae', SiteHost::KEY_REDIRECT => '0',
        SiteHost::KEY_VISIBILITY => 'public', 'site_url' => 'https://extrabeauty.ae'] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    app(SettingsService::class)->flush();
    Setting::flushMap();
    SiteHost::forget();
}

/** The shop's content as the move finds it, and the things that must never move. */
function crSeed(): array
{
    $product = Product::query()->create(['name' => 'CR serum', 'slug' => 'cr-serum', 'price' => 1000, 'status' => 'published',
        'description' => '<p>See <a href="https://extrabeauty.ae/collections/toners/">toners</a> and '
            .'<a href="http://www.extrabeauty.ae/blog/routine/?a=1#x">the routine</a>. '
            .'<img src="//extrabeauty.ae/storage/p/serum.jpg"> Write to info@extrabeauty.ae. '
            .'Staging: https://staging.extrabeauty.ae/x/ and https://extrabeauty.aero/y/</p>']);

    DB::table('pages')->insert(['slug' => 'cr-about', 'title' => 'About', 'status' => 'published',
        'doc_json' => json_encode(['href' => 'https://extrabeauty.ae/about/']), 'created_at' => now(), 'updated_at' => now()]);

    foreach ([
        'footer_copy' => 'Shop at <a href="https://extrabeauty.ae/shop/">our shop</a>',
        'stripe_return_note' => 'https://extrabeauty.ae/api/payments/webhook/stripe/abc',  // a payment setting: never touched
        SiteHost::KEY_ALIASES => 'extrabeauty.ae',                                          // the forwarding needs it
    ] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    $order = Order::create(['order_number' => 'CR-'.uniqid(), 'email' => 'shopper@example.test', 'status' => 'processing',
        'subtotal' => 1000, 'total' => 1000, 'customer_note' => 'Saw it on https://extrabeauty.ae/product/cr-serum/']);

    PaymentProvider::query()->updateOrCreate(['id' => 'tabby'], ['title' => 'Tabby', 'enabled' => false, 'mode' => 'test', 'position' => 3,
        'config' => ['secret_key' => 'sk_test_cr', 'note' => 'https://extrabeauty.ae/api/payments/webhook/tabby/zzz']]);

    Setting::flushMap();

    return [$product, $order];
}

/** Everything the rewrite must never write, as bytes. */
function crUntouchable(): array
{
    return [
        'orders' => DB::table('orders')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'payment_providers' => DB::table('payment_providers')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        'protected' => DB::table('settings')->whereIn('key', ['stripe_return_note', SiteHost::KEY_ALIASES, SiteHost::KEY_CANONICAL, 'site_url'])->orderBy('key')->pluck('value', 'key')->all(),
    ];
}

beforeEach(function () {
    DomainSwitchRoutes::wire($this->app);
    Cache::flush();
    crShop(false);
});

it('rewrites only absolute links on the old domain and its www twin, nothing else in the text', function () {
    /* MUTATION: drop the `(?![A-Za-z0-9\-]|\.[A-Za-z0-9])` look-ahead ->
       extrabeauty.aero is rewritten; drop the `//` requirement -> the email
       address is rewritten to a mailbox that does not exist. */
    [$out, $n] = ContentRewrite::rewrite(
        'a https://extrabeauty.ae/x/ b http://www.EXTRABEAUTY.ae/y?z#q c //extrabeauty.ae/i.jpg d https:\/\/extrabeauty.ae\/j '
        .'e info@extrabeauty.ae f https://staging.extrabeauty.ae/s g https://extrabeauty.aero/h i extrabeauty.ae in a sentence',
        ['extrabeauty.ae'], 'kbeautybliss.com');

    expect($n)->toBe(4)->and($out)->toBe(
        'a https://kbeautybliss.com/x/ b https://kbeautybliss.com/y?z#q c //kbeautybliss.com/i.jpg d https:\/\/kbeautybliss.com\/j '
        .'e info@extrabeauty.ae f https://staging.extrabeauty.ae/s g https://extrabeauty.aero/h i extrabeauty.ae in a sentence');
});

it('previews the change with counts and samples, and the preview writes nothing', function () {
    [$product] = crSeed();
    $before = $product->fresh()->description;
    $this->actingAs(crOwner(), 'admin');

    $p = $this->getJson('https://extrabeauty.ae/admin-api/domain-switch/rewrite')->assertOk()->json();

    $places = collect($p['places'])->keyBy(fn ($x) => $x['table'].'.'.$x['column']);
    expect($p['old'])->toBe(['extrabeauty.ae'])
        ->and($p['new'])->toBe('kbeautybliss.com')
        ->and($p['can_apply'])->toBeFalse()
        ->and($places['products.description']['links'])->toBe(3)
        ->and($places['pages.doc_json']['links'])->toBe(1)
        ->and($places['settings.value']['links'])->toBe(1)
        ->and($places)->not->toHaveKey('orders.customer_note')
        ->and($p['links'])->toBe(5)
        ->and($p['skipped']['protected_settings'])->toBeGreaterThanOrEqual(2)
        ->and($places['products.description']['samples'][0]['after'])->toContain('https://kbeautybliss.com/collections/toners/')
        ->and($product->fresh()->description)->toBe($before);
});

it('refuses to apply before the shop has switched, or against a stale preview', function () {
    crSeed();
    $this->actingAs(crOwner(), 'admin');

    /* MUTATION: drop the addressSwitched() refusal in rewriteContent() ->
       links point at kbeautybliss.com while it still opens WordPress. */
    $this->postJson('https://extrabeauty.ae/admin-api/domain-switch/run', ['action' => 'rewrite_content', 'confirm' => '5'])->assertStatus(409);

    crShop(true);
    $this->postJson('https://kbeautybliss.com/admin-api/domain-switch/run', ['action' => 'rewrite_content', 'confirm' => '4'])->assertStatus(409);
    expect(DB::table(ContentRewrite::LEDGER)->count())->toBe(0);
});

it('applies to content only, never orders or payment settings, and Undo puts every cell back', function () {
    [$product, $order] = crSeed();
    crShop(true);
    $this->actingAs(crOwner(), 'admin');
    $untouchable = crUntouchable();
    $original = $product->fresh()->description;

    // RISK lines the rewrite is responsible for. Two stay on purpose and are
    // not counted: the staging subdomain (another site) and the payment
    // setting (never touched); the owner sees both in step 1.
    $risk = fn () => collect((new DomainReadiness('kbeautybliss.com', ['extrabeauty.ae']))->references(20))
        ->where('level', DomainReadiness::RISK)->whereIn('table', ['products', 'pages', 'settings'])
        ->flatMap(fn ($r) => $r['samples'])
        ->reject(fn ($s) => str_contains($s, 'staging.extrabeauty.ae') || str_contains($s, 'stripe_return_note'))
        ->values()->all();
    expect($risk())->toHaveCount(5);

    $r = $this->postJson('https://kbeautybliss.com/admin-api/domain-switch/run', ['action' => 'rewrite_content', 'confirm' => '5'])->assertOk();
    expect($r->json('rewrite.links'))->toBe(5)->and($r->json('message'))->toContain('Orders, customers and payment settings were not touched');

    $now = $product->fresh()->description;
    expect($now)->toContain('href="https://kbeautybliss.com/collections/toners/"')
        ->toContain('href="https://kbeautybliss.com/blog/routine/?a=1#x"')
        ->toContain('src="//kbeautybliss.com/storage/p/serum.jpg"')
        ->toContain('info@extrabeauty.ae')
        ->toContain('https://staging.extrabeauty.ae/x/')
        ->and(Setting::map()['footer_copy'])->toBe('Shop at <a href="https://kbeautybliss.com/shop/">our shop</a>')
        ->and(json_decode((string) DB::table('pages')->where('slug', 'cr-about')->value('doc_json'), true)['href'])->toBe('https://kbeautybliss.com/about/');

    /* MUTATION: add 'orders' to ContentRewrite::TABLES, or drop the
       protectedSetting() skip -> red. */
    expect(crUntouchable())->toBe($untouchable)
        ->and($risk())->toBe([])
        // the ledger is history, never a finding of its own
        ->and(collect((new DomainReadiness('kbeautybliss.com', ['extrabeauty.ae']))->references())->where('table', ContentRewrite::LEDGER)->where('level', DomainReadiness::RISK)->count())->toBe(0);

    // The owner edits the page after the rewrite; Undo must keep his edit.
    DB::table('pages')->where('slug', 'cr-about')->update(['doc_json' => '{"href":"/about-us/"}']);

    /* MUTATION: drop the `(string) $current === (string) $row->after` guard
       in undo() -> the page edit above is overwritten with the old link. */
    $u = $this->postJson('https://kbeautybliss.com/admin-api/domain-switch/run', ['action' => 'undo_rewrite'])->assertOk();
    expect($u->json('rewrite.restored'))->toBe(2)->and($u->json('rewrite.kept'))->toBe(1)
        ->and($product->fresh()->description)->toBe($original)
        ->and(Setting::map()['footer_copy'])->toContain('https://extrabeauty.ae/shop/')
        ->and(DB::table('pages')->where('slug', 'cr-about')->value('doc_json'))->toBe('{"href":"/about-us/"}')
        ->and(crUntouchable())->toBe($untouchable);

    // A second Undo has nothing left to do.
    $this->postJson('https://kbeautybliss.com/admin-api/domain-switch/run', ['action' => 'undo_rewrite'])->assertOk()->assertJsonPath('message', 'Nothing to undo.');
});

it('is the owner’s alone, like every other step of the switch', function () {
    crSeed();
    crShop(true);
    $this->actingAs(crOwner('manager'), 'admin');

    $this->getJson('https://kbeautybliss.com/admin-api/domain-switch/rewrite')->assertForbidden();
    $this->postJson('https://kbeautybliss.com/admin-api/domain-switch/run', ['action' => 'rewrite_content', 'confirm' => '5'])->assertForbidden();
    expect(DB::table(ContentRewrite::LEDGER)->count())->toBe(0);
});

it('kbb:domain-check now also reads the web root, which the database scan cannot see', function () {
    /* Before Lane DS a static robots.txt naming extrabeauty.ae in
       public_html -- served by Apache instead of the shop's own -- was
       invisible to the check. MUTATION: return [] from webRootFiles() -> red. */
    $root = sys_get_temp_dir().'/kbb-ds-root-'.bin2hex(random_bytes(4));
    mkdir($root);
    file_put_contents($root.'/robots.txt', "User-agent: *\nSitemap: https://extrabeauty.ae/sitemap.xml\n");
    file_put_contents($root.'/.htaccess', "RewriteCond %{HTTP_HOST} ^www\\.extrabeauty\\.ae$ [NC]\n");
    file_put_contents($root.'/index.php', '<?php // extrabeauty.ae');      // code, not read
    file_put_contents($root.'/clean.txt', 'kbeautybliss.com only');

    $lines = collect((new DomainReadiness('kbeautybliss.com', ['extrabeauty.ae']))->webRootFiles($root))->keyBy(fn ($l) => strtok($l['detail'], ' '));

    expect($lines->keys()->sort()->values()->all())->toBe(['.htaccess', 'robots.txt'])
        ->and($lines['robots.txt']['level'])->toBe(DomainReadiness::RISK)
        ->and($lines['.htaccess']['level'])->toBe(DomainReadiness::TODO)
        ->and($lines['.htaccess']['detail'])->toContain('www.extrabeauty.ae');

    foreach (['robots.txt', '.htaccess', 'index.php', 'clean.txt'] as $f) {
        @unlink($root.'/'.$f);
    }
    @rmdir($root);
});
