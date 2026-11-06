<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\Cutouts\ProductCutouts;
use App\Services\FreeGifts;
use App\Services\SettingsService;
use App\Support\GiftStrip;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\GiftStripAdminRoutes;

/**
 * Appearance -> Free gifts strip (Lane GST): the homepage bar under the main
 * banner, its admin endpoints and the promises it makes -- off is nothing,
 * on costs no query, the size is the product's real size, every href is
 * scheme-checked, every word escaped.
 *
 * routes/gift-strip-admin.php is wired by the INTEGRATOR; GiftStripAdminRoutes
 * mounts it here from the real file with the real middleware stack.
 */

const GST_DIR = 'uploads/gst-test';

function gstSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
    Setting::flushMap();
}

/** A product on a white background, as the shop's photographs are. */
function gstProduct(string $name, bool $photo = true): Product
{
    $image = null;
    if ($photo) {
        $dir = public_path(GST_DIR);
        @mkdir($dir, 0775, true);
        $file = Str::slug($name) . '-' . Str::random(5) . '.jpg';
        $im = imagecreatetruecolor(200, 300);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 70, 40, 130, 260, imagecolorallocate($im, 200, 40, 90));
        imagejpeg($im, $dir . '/' . $file, 92);
        imagedestroy($im);
        $image = '/' . GST_DIR . '/' . $file;
    }

    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(6), 'name' => $name, 'status' => 'publish',
        'is_visible' => true, 'price' => 2500, 'stock_status' => 'instock', 'manage_stock' => false, 'image' => $image,
    ]);
}

/** One running range giving these products, and the gifts switched on. */
function gstGifts(array $products): int
{
    $id = DB::table('free_gift_ranges')->insertGetId([
        'min_fils' => 0, 'max_fils' => null, 'label_en' => 'Gifts', 'label_ar' => 'هدايا', 'sort' => 0, 'enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (array_values($products) as $i => $p) {
        DB::table('free_gift_range_items')->insert(['range_id' => $id, 'product_id' => $p->id, 'quantity' => 1, 'sort' => $i]);
    }
    app(FreeGifts::class)->compile();
    gstSet(FreeGifts::KEY, ['on' => true, 'show_value' => false, 'countries' => 'all', 'basis' => 'after_discounts']);

    return $id;
}

function gstAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'GST ' . $role, 'email' => 'gst-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

/** Save through the real endpoint, as the screen does. */
function gstSave(array $settings): \Illuminate\Testing\TestResponse
{
    $r = test()->actingAs(gstAdmin(), 'admin')->postJson('/admin-api/gift-strip', ['settings' => $settings]);
    SettingsService::forgetMemo();
    Setting::flushMap();

    return $r;
}

function gstCut(Product $p): void
{
    expect(ProductCutouts::make((string) $p->image)['ok'])->toBeTrue();
}

/** The strip's section out of a page, or '' when there is none. */
function gstSection(string $html): string
{
    return preg_match('#<section class="kgs-sec[^"]*".*?</section>#s', $html, $m) ? $m[0] : '';
}

/** Queries one homepage request spends, after a warm-up request. */
function gstQueries(string $path = '/'): int
{
    test()->get($path)->assertOk();
    SettingsService::forgetMemo();
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    test()->get($path)->assertOk();
    $count = $n;
    $n = -1000000; // a listener cannot be removed; park it

    return $count;
}

beforeEach(function () {
    GiftStripAdminRoutes::wire($this->app);
    DB::table('free_gift_ranges')->delete();
    app(FreeGifts::class)->compile();
});

afterEach(function () {
    foreach ([public_path(GST_DIR), public_path(ProductCutouts::DIR . '/240/' . GST_DIR), public_path(ProductCutouts::DIR . '/480/' . GST_DIR)] as $dir) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
});

/* ═══════════════════════════════════════════════════════ off is nothing ═══ */

it('draws nothing at all while it is off, which is how it ships', function () {
    /*
     * The defect this guards: a strip that ships ON (or prints its <style>, a
     * symbol or an empty <section> while off) changes every homepage the
     * moment the package is applied -- the owner's rule 1.
     * MUTATION: DEFAULTS['on'] => true, or print the <style> outside the
     * @if in the partial, and this is red.
     */
    $gifts = [gstProduct('Arencia Vitamin C Booster Shot')];
    gstGifts($gifts);
    gstCut($gifts[0]);

    $html = $this->get('/')->assertOk()->getContent();
    expect($html)->not->toContain('kgs-')->and(GiftStrip::normalise(null)['on'])->toBeFalse();

    // Saved with every other control set, still off: still nothing.
    gstSave(['on' => false, 'style' => 'S4']);
    expect($this->get('/')->assertOk()->getContent())->not->toContain('kgs-');
});

it('costs the homepage no query, on or off, and the same with 3 gifts as with 40', function () {
    /*
     * The defect: a strip that loads its products per page view (or one per
     * product) -- the homepage pays for every gift in the catalogue. The
     * snapshot written at save time and the compiled ranges (a setting) make
     * it zero.
     * MUTATION: make forPage() query Product for its rows and the on/off
     * counts differ; query per row and 3 vs 40 differ.
     */
    $three = array_map(fn ($i) => gstProduct('Gift ' . $i), range(1, 3));
    gstGifts($three);
    foreach ($three as $p) {
        gstCut($p);
    }
    $off = gstQueries();

    gstSave(['on' => true]);
    $on3 = gstQueries();
    expect(substr_count(gstSection($this->get('/')->getContent()), '<img '))->toBe(3);

    DB::table('free_gift_range_items')->delete();
    DB::table('free_gift_ranges')->delete();
    $forty = array_map(fn ($i) => gstProduct('Gift big ' . $i, $i <= 6), range(1, 40));
    gstGifts($forty);
    foreach (array_slice($forty, 0, 6) as $p) {
        gstCut($p);
    }
    gstSave(['on' => true]);
    $on40 = gstQueries();
    expect(substr_count(gstSection($this->get('/')->getContent()), '<img '))->toBe(6);

    expect($on3)->toBe($off)->and($on40)->toBe($off);
});

/* ═══════════════════════════════════════════════════════════ on: drawn ═══ */

it('draws up to three gifts a side at their real size, with their cut-outs and a gift icon each', function () {
    /*
     * The owner: "the size of the products are very important in real life"
     * and "a small gift icon upon each product". The defect would be a strip
     * whose products are all one height, a photo with its white box, or a
     * bare full-size <img> on the homepage (CLAUDE.md, pictures).
     * MUTATION: drop --h from the partial, or src the original photo, and
     * this is red.
     */
    $names = ['Arencia Vitamin C Booster Shot', 'AXIS-Y Dark Spot Correcting Glow Serum', 'Medicube Vanilla Deodorant',
        'Medicube PDRN Gua Sha Neck Cream', 'Medicube Collagen Jelly Eye Mask', 'Medicube Red Succinic Acid Peeling Pad', 'Seventh Gift'];
    $ps = array_map(fn ($n) => gstProduct($n), $names);
    gstGifts($ps);
    foreach ($ps as $p) {
        gstCut($p);
    }
    gstSave(['on' => true, 'style' => 'S2']);

    $s = gstSection($this->get('/')->assertOk()->getContent());
    expect(substr_count($s, '<img '))->toBe(6)                          // three a side, never seven
        ->and(substr_count($s, 'class="kgs-gi"'))->toBe(6)
        ->and(substr_count($s, '<symbol id="kgs-gift"'))->toBe(1)
        ->and(preg_match('#<div class="kgs-side kgs-l">(.*?)<div class="kgs-mid">#s', $s, $l))->toBe(1)
        ->and(substr_count($l[1], '<img '))->toBe(3);
    foreach (['--h:14"', '--h:14.5"', '--h:11.5"', '--h:12"', '--h:9"'] as $h) {
        expect($s)->toContain($h);
    }
    preg_match_all('#<img ([^>]*)>#', $s, $imgs);
    foreach ($imgs[1] as $a) {
        expect($a)->toContain('src="' . \App\Support\Url::base() . '/' . ProductCutouts::DIR . '/480/')->toMatch('#srcset="[^"]*/' . ProductCutouts::DIR . '/240/[^"]* \d+w, [^"]*/480/[^"]* \d+w"#')
            ->toMatch('/sizes="\(max-width:767px\) \d+px, \d+px"/')->toMatch('/width="\d+" height="\d+"/')
            ->toContain('loading="lazy"')->toContain('decoding="async"');
    }
    expect($s)->toContain('<h2 class="kgs-h">FREE GIFTS</h2>')->toContain('with every order')
        ->toContain('href="' . \App\Support\Url::to('/super-sale/') . '"')->toContain('See the gifts →')
        ->toContain('#e3246e'); // S2's CSS, and only S2's:
    expect($s)->not->toContain('#e3f7ec')->not->toContain('#2a0f3d')->not->toContain('<div class="kgs-fx">');
});

it('leaves out a gift without a cut-out, and draws nothing when none has one', function () {
    // A photo with its white box on a coloured bar is the "white edges" the
    // owner refused. MUTATION: skip the ProductCutouts::for() null check.
    $a = gstProduct('Has a cut');
    $b = gstProduct('Has none');
    gstGifts([$a, $b]);
    gstSave(['on' => true]);
    expect(gstSection($this->get('/')->getContent()))->toBe('');

    gstCut($a);
    $s = gstSection($this->get('/')->getContent());
    expect(substr_count($s, '<img '))->toBe(1)->and($s)->toContain('alt="Has a cut"')->not->toContain('Has none');
});

it('drops a gift the owner removes from Free gifts without this screen being saved again', function () {
    // The defect: a snapshot that is shown as saved, so a gift that is no
    // longer given keeps being advertised. MUTATION: skip the live filter.
    $a = gstProduct('Stays a gift');
    $b = gstProduct('Leaves the range');
    $range = gstGifts([$a, $b]);
    gstCut($a);
    gstCut($b);
    gstSave(['on' => true]);
    expect(substr_count(gstSection($this->get('/')->getContent()), '<img '))->toBe(2);

    DB::table('free_gift_range_items')->where('range_id', $range)->where('product_id', $b->id)->delete();
    app(FreeGifts::class)->compile();
    SettingsService::forgetMemo();
    Setting::flushMap();

    $s = gstSection($this->get('/')->getContent());
    expect(substr_count($s, '<img '))->toBe(1)->and($s)->not->toContain('Leaves the range');
});

it('stays hidden while the gifts themselves are off -- it promises gifts with every order', function () {
    $a = gstProduct('A gift');
    gstGifts([$a]);
    gstCut($a);
    gstSave(['on' => true, 'source' => 'pick', 'picks' => [$a->id]]);
    expect(gstSection($this->get('/')->getContent()))->not->toBe('');

    gstSet(FreeGifts::KEY, ['on' => false]);
    // MUTATION: drop the visibleTo() check in forPage() and this is red.
    expect(gstSection($this->get('/')->getContent()))->toBe('');
});

it('draws his picked products in his order', function () {
    $ps = array_map(fn ($n) => gstProduct($n), ['Pick one', 'Pick two', 'Pick three']);
    gstGifts([$ps[0]]);
    foreach ($ps as $p) {
        gstCut($p);
    }
    gstSave(['on' => true, 'source' => 'pick', 'picks' => [$ps[2]->id, $ps[0]->id], 'heights' => [$ps[2]->id => 7.5]]);

    $s = gstSection($this->get('/')->getContent());
    expect(substr_count($s, '<img '))->toBe(2)
        ->and(strpos($s, 'Pick three'))->toBeLessThan(strpos($s, 'Pick one'))
        ->and($s)->toContain('--h:7.5"')->not->toContain('Pick two');
});

it('shows on computer only or phone only with rules that answer at 767px', function () {
    $a = gstProduct('Device gift');
    gstGifts([$a]);
    gstCut($a);
    gstSave(['on' => true, 'mobile' => false]);
    expect(gstSection($this->get('/')->getContent()))->toContain('class="kgs-sec kgs-nm"');

    gstSave(['on' => true, 'desktop' => false, 'mobile' => false]);
    expect(gstSection($this->get('/')->getContent()))->toBe('');
});

it('speaks Arabic on /ar, right to left', function () {
    gstSet('language_ar_enabled', '1');
    gstSet(Locale::SETTING_RTL, '1');
    $a = gstProduct('Arabic gift');
    gstGifts([$a]);
    gstCut($a);
    gstSave(['on' => true]);

    $html = $this->get('/ar/')->assertOk()->getContent();
    $s = gstSection($html);
    expect($html)->toContain('dir="rtl"')
        ->and($s)->toContain('هدايا مجانية')->toContain('مع كل طلب')->toContain('شاهدي الهدايا ←')
        ->toContain('href="' . \App\Support\Url::to('/ar/super-sale/') . '"')->not->toContain('FREE GIFTS');
});

/* ═════════════════════════════════════════════════════ the CSS it prints ═══ */

it('keeps every animation to transform and opacity, every phone rule under 767px, and no newer CSS function', function () {
    /*
     * CLAUDE.md: animations transform/opacity only; no round()/color-mix()
     * next to var() (the lotus-logo landmine); phone rules only under
     * max-width:767px. MUTATION: animate `bottom` in kgsBob, or add a
     * (max-width:900px) block, and this is red.
     */
    foreach (array_keys(GiftStrip::STYLES) as $st) {
        $css = GiftStrip::css($st);
        preg_match_all('/@keyframes \w+\{((?:[^{}]*\{[^{}]*\})*)\}/', $css, $kf);
        expect($kf[1])->not->toBeEmpty();
        foreach ($kf[1] as $body) {
            preg_match_all('/([a-z-]+):/', preg_replace('/\([^)]*\)/', '', $body), $props);
            expect(array_diff(array_unique($props[1]), ['transform', 'opacity']))->toBe([]);
        }
        preg_match_all('/@media ([^{]+)\{/', $css, $mq);
        expect(array_diff(array_unique($mq[1]), ['(prefers-reduced-motion:reduce)', '(min-width:768px)', '(max-width:767px)']))->toBe([]);
        expect($css)->not->toMatch('/\b(round|color-mix|clamp\([^)]*var)\(/');
    }
});

/* ═══════════════════════════════════════════════════════════ security ═══ */

it('scheme-checks the link before it is ever an href', function () {
    // MUTATION: return $v unchecked from GiftStrip::url() and this is red.
    foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', '//evil.example/x', 'data:text/html,x', ' /a b', "/x\"onmouseover=\"1", '\\\\evil', 'ftp://x.example/'] as $bad) {
        expect(GiftStrip::url($bad))->toBe(GiftStrip::DEFAULT_URL);
    }
    expect(GiftStrip::url('/collections/gifts/'))->toBe('/collections/gifts/')
        ->and(GiftStrip::url('https://kbeautybliss.com/super-sale/'))->toBe('https://kbeautybliss.com/super-sale/');

    gstSave(['url' => 'javascript:alert(1)'])->assertStatus(422);
    expect(GiftStrip::normalise(app(SettingsService::class)->get(GiftStrip::KEY))['url'])->toBe(GiftStrip::DEFAULT_URL);
});

it('escapes every word it prints, and stores only its own options', function () {
    $a = gstProduct('<b>Bold</b> gift');
    gstGifts([$a]);
    gstCut($a);
    gstSave(['on' => true, 'style' => 'S9', 'texts' => ['h_en' => '<script>alert(1)</script>', 'sub_en' => '"><img src=x onerror=1>'],
        'heights' => [$a->id => 999]]);

    $saved = GiftStrip::normalise(app(SettingsService::class)->get(GiftStrip::KEY));
    expect($saved['style'])->toBe('S1')->and($saved['heights'][$a->id])->toBe(GiftStrip::H_MAX);

    $s = gstSection($this->get('/')->getContent());
    expect($s)->not->toContain('<script>alert')->not->toContain('<img src=x')->not->toContain('<b>Bold')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->toContain('alt="&lt;b&gt;Bold&lt;/b&gt; gift"');
});

it('refuses keys it does not know, including the snapshot the server writes', function () {
    gstSave(['on' => true, 'snap' => [['id' => 1, 'image' => '/x.jpg']]])->assertStatus(422);
    gstSave(['evil' => 1])->assertStatus(422);
});

it('gives every endpoint its own capability: editor yes, support no, a stranger nothing', function () {
    /*
     * MUTATION: remove the two admin-api/gift-strip lines from
     * AdminCapabilities and the editor gets 403 (owner-only default); add
     * 'support' to giftstrip.manage and the support case is red.
     */
    $p = gstProduct('Cap gift');
    $editor = gstAdmin('editor');
    $this->actingAs($editor, 'admin')->getJson('/admin-api/gift-strip')->assertOk();
    $this->actingAs($editor, 'admin')->getJson('/admin-api/gift-strip/products?q=Cap')->assertOk()->assertJsonPath('rows.0.id', $p->id);

    $support = gstAdmin('support');
    $this->actingAs($support, 'admin')->getJson('/admin-api/gift-strip')->assertForbidden();
    $this->actingAs($support, 'admin')->postJson('/admin-api/gift-strip', ['settings' => ['on' => true]])->assertForbidden();
    $this->actingAs($support, 'admin')->getJson('/admin-api/gift-strip/products?q=Cap')->assertForbidden();
    $this->actingAs($support, 'admin')->postJson('/admin-api/gift-strip/cutout', ['id' => $p->id])->assertForbidden();
});

it('refuses a visitor who is not signed in', function () {
    app('auth')->guard('admin')->logout();
    foreach (['/admin-api/gift-strip', '/admin-api/gift-strip/products?q=ab'] as $path) {
        $status = $this->getJson($path)->getStatusCode();
        expect($status)->toBeGreaterThanOrEqual(401)->and($status)->toBeLessThan(500);
    }
});

/* ═══════════════════════════════════════════════════════════ the admin ═══ */

it('sends the screen everything its preview is built from, and makes cut-outs one product at a time', function () {
    $ps = [gstProduct('Medicube Red Succinic Acid Peeling Pad'), gstProduct('Plain gift')];
    gstGifts($ps);
    $owner = gstAdmin();

    $body = $this->actingAs($owner, 'admin')->getJson('/admin-api/gift-strip')->assertOk()->json();
    expect(array_column($body['live'], 'id'))->toBe([$ps[0]->id, $ps[1]->id])
        ->and($body['live'][0]['cm'])->toEqual(9)->and($body['live'][1]['cm'])->toEqual(12)
        ->and($body['live'][0]['cut'])->toBeNull()
        ->and(array_keys($body['preview']['css']))->toBe(['S1', 'S2', 'S3', 'S4', 'S5'])
        ->and($body['preview']['base'])->toBe(GiftStrip::BASE_CSS)
        ->and($body['values'])->not->toHaveKey('snap')
        ->and($body['gifts_on'])->toBe('live');

    $cut = $this->actingAs($owner, 'admin')->postJson('/admin-api/gift-strip/cutout', ['id' => $ps[0]->id, 'white' => true])->assertOk()->json();
    expect($cut['cut']['srcset'])->toContain('/' . ProductCutouts::DIR . '/240/')->toContain('/' . ProductCutouts::DIR . '/480/')
        ->and(GiftStrip::normalise(app(SettingsService::class)->get(GiftStrip::KEY))['white'])->toBe([$ps[0]->id]);

    $none = gstProduct('No photo', false);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/gift-strip/cutout', ['id' => $none->id])->assertStatus(422);
});

/* ═══════════════════════════════════════════════════ wired exactly once ═══ */

it('sits directly under the main banner, one branch per kind of banner, and its route file declares its four endpoints', function () {
    // The finished state (CLAUDE.md: pin the wiring, never its absence). Three
    // include sites, one per place the main banner can be (picture banner, a
    // standalone occasion banner, the hero), chosen once by $kgsAt -- so the
    // page draws it once. MUTATION: drop a branch's @if and the page draws two.
    $home = file_get_contents(resource_path('views/store/home.blade.php'));
    expect(substr_count($home, "@include('partials.home.gift-strip'"))->toBe(3)
        ->and(substr_count($home, '$kgsAt = '))->toBe(1)
        ->and(substr_count($home, "@if (\$kgsAt === 'solo')"))->toBe(1)
        ->and(substr_count($home, "@if ((\$kgsAt ?? 'hero') === 'hero')"))->toBe(1);

    $uris = array_map(fn ($r) => implode('|', $r->methods()) . ' ' . $r->uri(), GiftStripAdminRoutes::registered());
    sort($uris);
    expect($uris)->toBe(['GET|HEAD admin-api/gift-strip', 'GET|HEAD admin-api/gift-strip/products', 'POST admin-api/gift-strip', 'POST admin-api/gift-strip/cutout']);
});

it('stays directly under the banner on a shop with its own section order', function () {
    /*
     * The live shop has its own section order, which makes .kbb-home a flex
     * column where every section carries kbb-ord-N. A strip with no order
     * class is order 0 and jumps ABOVE the banner -- the integrator caught it
     * before 2.60.649 shipped. The strip now carries the order class of the
     * section it sits under (here the hero, as there is no picture banner).
     * MUTATION: drop the kgsOrd class from the partial and this is red.
     */
    $ps = [gstProduct('Order Gift A'), gstProduct('Order Gift B')];
    gstGifts($ps);
    foreach ($ps as $p) {
        gstCut($p);
    }
    gstSave(['on' => true]);

    $payload = [];
    $order = 0;
    foreach (['categories', 'hero', 'delivery', 'ticker'] as $key) {
        $payload[$key] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => null];
    }
    foreach (\App\Services\HomepageSections::REGISTRY as $key => $row) {
        if (! isset($payload[$key])) {
            $payload[$key] = ['desktop' => true, 'mobile' => true, 'order' => $order++, 'skin' => $row[3]];
        }
    }
    app(\App\Services\HomepageSections::class)->save($payload);
    \App\Services\SettingsService::forgetMemo();

    $html = $this->get('/')->assertOk()->getContent();
    $heroOrd = 'kbb-ord-' . app(\App\Services\HomepageSections::class)->all()['hero']['order'];
    expect(preg_match('/<section class="kgs-sec[^"]*\b' . preg_quote($heroOrd, '/') . '\b/', $html))->toBe(1);
});
