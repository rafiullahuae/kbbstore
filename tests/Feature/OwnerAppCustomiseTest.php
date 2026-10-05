<?php

declare(strict_types=1);

/*
 * Users & Roles → Owner app → Customise app (Lane OA4).
 *
 * The owner: "i would like to control everything from the main admin for the
 * owner app, like fonts, sizes, etc etc and any function / screen turn on/off"
 * and "make sure all the things must be super light, and with blazing speed.
 * without bugs or broken stuff."
 *
 * What is pinned: every key is validated; the settings reach the phone inside
 * the answers it already gets (no request of its own, under 1 KB, one read);
 * every screen and function switched off is REFUSED by the server for every
 * member and allowed again when on, with the role still applying on top; the
 * defaults are today's app (no class, no variable, font preloaded); System font
 * means the font is never requested; the app's JS honours the switches.
 */

use App\Http\Middleware\OwnerAppUiGate;
use App\Models\Order;
use App\Models\Product;
use App\Services\OwnerApp\OwnerAppUi;
use Illuminate\Support\Facades\DB;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    OwnerAppUi::forget();
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
});

afterEach(fn () => OwnerAppUi::forget());

/** One order and one product, for the endpoints that need a record. */
function oa4Shop(): array
{
    $pid = (int) Product::query()->create(['slug' => 'oa4-p', 'name' => 'Centella Ampoule', 'sku' => 'OA4-1',
        'price' => 9900, 'manage_stock' => true, 'stock' => 20, 'stock_status' => 'instock'])->id;
    $cid = DB::table('customers')->insertGetId(['name' => 'Layla', 'email' => 'l@example.com', 'phone' => '+971500000001', 'created_at' => now(), 'updated_at' => now()]);
    $oid = (int) Order::query()->create(['order_number' => '41001', 'customer_id' => $cid, 'email' => 'l@example.com', 'status' => 'pending',
        'total' => 10000, 'subtotal' => 10000, 'payment_method' => 'cod', 'billing_address' => ['first_name' => 'Layla', 'last_name' => 'H']])->id;
    DB::table('order_items')->insert(['order_id' => $oid, 'product_id' => $pid, 'name' => 'Centella Ampoule', 'quantity' => 1,
        'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000, 'created_at' => now(), 'updated_at' => now()]);

    return ['order' => $oid, 'product' => $pid, 'customer' => $cid];
}

/** Save a few keys over the defaults, the way the admin card does (whole card at once). */
function oa4Put(array $over): void
{
    $ui = array_replace_recursive(OwnerAppUi::defaults(), $over);
    expect(OwnerAppUi::put($ui))->toBeNull();
}

/* ============================================================ defaults */

it('defaults to today\'s app in every key, and still reads the store name saved before this card', function () {
    // DEFECT: a default nobody chose — a smaller font, a screen off, a 30 s
    // poll — shipped to the owner's phone the day the package is applied.
    // MUTATION: change any entry of OwnerAppUi::defaults() or CHOICES' first
    // option and this is red.
    expect(OwnerAppUi::all())->toBe([
        'store_name' => 'K-Beauty Bliss', 'initials' => 'KB', 'accent' => '#A8475C',
        'font' => 'jakarta', 'text' => 'm', 'title' => 'm', 'figure' => 'm',
        'density' => 'comfortable', 'corners' => 'soft', 'header' => 'compact',
        'screens' => ['store' => true, 'orders' => true, 'products' => true, 'customers' => true, 'notifications' => true],
        'sections' => ['hero', 'needs', 'avg', 'returning', 'top'], 'sections_off' => [],
        'functions' => ['bulk' => true, 'mark_paid' => true, 'order_notes' => true, 'edit_price' => true, 'edit_stock' => true,
            'edit_catalogue' => true, 'contact' => true, 'fullscreen' => true, 'sync' => true, 'live' => true,
            // The three the owner DID ask for: Gross/Net off ("just the total
            // revenu should display"), the range and the top-sellers switch on.
            'gross_net' => false, 'range' => true, 'top_period' => true],
        'live_seconds' => 25,
    ]);

    // 2.60.401's own row keeps working until the card is saved.
    DB::table('settings')->insert(['key' => 'owner_app_store_name', 'value' => 'Extra Beauty', 'autoload' => false, 'created_at' => now(), 'updated_at' => now()]);
    OwnerAppUi::forget();
    expect(OwnerAppUi::storeName())->toBe('Extra Beauty');
});

it('keeps every Petal preset readable under white text, and the default is one of them', function () {
    // DEFECT: a preset the server would then refuse to save. MUTATION: add
    // '#F4A6B8' to PRESETS.
    foreach (array_keys(OwnerAppUi::PRESETS) as $hex) {
        expect(OwnerAppUi::contrast($hex))->toBeGreaterThanOrEqual(4.5, $hex);
    }
    expect(OwnerAppUi::PRESETS)->toHaveKey(OwnerAppUi::ACCENT_DEFAULT);
});

/* ========================================================== validation */

it('cleans every key: selects keep their own options, numbers clamp, text is plain, unknown keys are dropped', function () {
    // DEFECT: a value the app was never written for reaching the phone — a
    // `text: "xl"` class with no CSS, a 1 s poll, markup in the store name.
    // MUTATION: drop the in_array() check in clean() and the select line is
    // red; drop the max()/min() and the live lines are.
    expect(OwnerAppUi::put([
        'store_name' => '  <b>Extra</b>   Beauty  ', 'initials' => 'k-b!x9', 'accent' => '#0f766e',
        'font' => 'comic', 'text' => 'xl', 'title' => 'l', 'figure' => 's', 'density' => 'compact', 'corners' => 'round', 'header' => 'standard',
        'screens' => ['orders' => false, 'customers' => 'no', 'admin' => false, 'products' => 'maybe'],
        'functions' => ['live' => '0', 'sync' => false, 'delete_everything' => true],
        'sections' => ['top', 'evil', 'top', 'needs'], 'sections_off' => ['hero', 'nope', 'hero'],
        'live_seconds' => 3, 'evil' => '<script>',
    ]))->toBeNull();

    $ui = OwnerAppUi::all();
    expect($ui)->not->toHaveKey('evil')
        ->and($ui['store_name'])->toBe('Extra Beauty')
        ->and($ui['initials'])->toBe('KBX')
        ->and($ui['accent'])->toBe('#0F766E')
        ->and([$ui['font'], $ui['text'], $ui['title'], $ui['figure'], $ui['density'], $ui['corners'], $ui['header']])
        ->toBe(['jakarta', 'm', 'l', 's', 'compact', 'soft', 'standard'])
        ->and($ui['screens'])->toBe(['store' => true, 'orders' => false, 'products' => true, 'customers' => false, 'notifications' => true])
        ->and($ui['functions']['live'])->toBeFalse()->and($ui['functions']['sync'])->toBeFalse()->and($ui['functions'])->not->toHaveKey('delete_everything')
        ->and($ui['sections'])->toBe(['top', 'needs', 'hero', 'avg', 'returning'])
        ->and($ui['sections_off'])->toBe(['hero'])
        ->and($ui['live_seconds'])->toBe(15);

    foreach ([[500, 120], ['abc', 25], [60, 60], [15, 15], [120, 120]] as [$in, $out]) {
        OwnerAppUi::put(['live_seconds' => $in]);
        expect(OwnerAppUi::all()['live_seconds'])->toBe($out, (string) $in);
    }

    OwnerAppUi::put(['store_name' => str_repeat('a', 80), 'initials' => '   ']);
    expect(mb_strlen(OwnerAppUi::storeName()))->toBe(60)->and(OwnerAppUi::all()['initials'])->toBe('KB');
});

it('refuses an accent that is not #rrggbb, or that white text cannot be read on', function (mixed $accent, string $says) {
    // DEFECT: white button labels on a pale pink nobody can read. MUTATION:
    // lower MIN_CONTRAST to 1 and the pale cases are saved.
    $errors = OwnerAppUi::put(['accent' => $accent]);
    expect($errors)->toHaveKey('accent')->and($errors['accent'])->toContain($says);
    expect(DB::table('settings')->where('key', OwnerAppUi::KEY)->exists())->toBeFalse();
})->with([
    'pale pink' => ['#F4A6B8', '4.5:1'],
    'white' => ['#FFFFFF', '4.5:1'],
    'three digits' => ['#abc', 'hex colour'],
    'a name' => ['red', 'hex colour'],
    'css injection' => ['#a8475c;background:url(x)', 'hex colour'],
    'not a string' => [['#A8475C'], 'hex colour'],
]);

it('lets only a Full Admin read and save the card, writes it whole, and answers 422 with the reason', function () {
    // DEFECT: a manager restyling or switching off the owner's app. MUTATION:
    // remove admin-api/owner-app/** from AdminCapabilities::RULES.
    $owner = OA::admin();
    $manager = OA::admin('manager', 'm@example.com', 'Manager');

    $this->actingAs($manager, 'admin')->getJson('/admin-api/owner-app/ui')->assertForbidden();
    $this->actingAs($manager, 'admin')->putJson('/admin-api/owner-app/ui', ['density' => 'compact'])->assertForbidden();
    expect(DB::table('settings')->where('key', OwnerAppUi::KEY)->exists())->toBeFalse();

    $r = $this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app/ui')->assertOk();
    expect($r->json('ui'))->toBe(OwnerAppUi::defaults())
        ->and($r->json('defaults'))->toBe(OwnerAppUi::defaults())
        ->and($r->json('options.choices.font'))->toBe(['jakarta', 'system'])
        ->and($r->json('css'))->toContain('owner-app');

    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/ui', ['accent' => '#F4A6B8'])
        ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonPath('errors.accent.0', fn ($m) => str_contains($m, '4.5:1'));

    $r = $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/ui', ['density' => 'compact', 'accent' => '#0F766E'])->assertOk();
    expect($r->json('ui.density'))->toBe('compact')->and($r->json('ui.accent'))->toBe('#0F766E')->and($r->json('ui.text'))->toBe('m');
    expect(DB::table('settings')->where('key', OwnerAppUi::KEY)->count())->toBe(1);
});

/* ============================================================ delivery */

it('reaches the phone inside enrol, unlock and state — under 1 KB, one read, no request of its own', function () {
    // DEFECT: a setting that never arrives, or a payload that grows the app's
    // every sync. MUTATION: remove 'ui' from AppController::me() and the
    // first expectation is red; read the setting per key and the query pin is.
    oa4Put(['density' => 'compact', 'screens' => ['customers' => false], 'store_name' => 'Extra Beauty']);
    OA::member(OA::admin(), '482615');

    $r = $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'owner@example.com', 'pin' => '482615'])->assertOk();
    expect($r->json('ui.density'))->toBe('compact')
        ->and($r->json('ui.screens.customers'))->toBeFalse()
        ->and($r->json('ui'))->not->toHaveKey('store_name')
        ->and($r->json('store'))->toBe('Extra Beauty');
    expect(strlen((string) json_encode($r->json('ui'))))->toBeLessThan(1024);

    $c = OA::cookies($r);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $state = OA::get($this, 'state', $c)->assertOk();
    $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'settings') && in_array(OwnerAppUi::KEY, $q['bindings'], true))->count();
    DB::disableQueryLog();
    expect($state->json('ui.density'))->toBe('compact')->and($reads)->toBe(1);

    $u = OA::post($this, 'unlock', ['pin' => '482615'], $c, null)->assertOk();
    expect($u->json('ui.screens.customers'))->toBeFalse();
});

/* ===================================================== server refusal */

it('refuses every endpoint of a screen switched off, for everyone, and serves it again when on', function () {
    // DEFECT: "off" that only hides a tab — the data still answers to any
    // script holding the session. MUTATION: delete a line from
    // OwnerAppUiGate::ROUTES and that endpoint answers while its screen is off.
    $shop = oa4Shop();
    OA::member(OA::admin());
    [$c] = OA::enrol($this);

    $calls = [
        'store' => [['GET', 'dashboard']],
        'orders' => [['GET', 'orders'], ['GET', 'orders/'.$shop['order']], ['POST', 'orders/'.$shop['order'].'/status', ['status' => 'onhold']],
            ['POST', 'orders-bulk-status', ['ids' => [$shop['order']], 'status' => 'onhold']], ['POST', 'orders/'.$shop['order'].'/notes', ['content' => 'x']],
            ['POST', 'orders/'.$shop['order'].'/mark-paid', ['method' => 'cod']]],
        'products' => [['GET', 'products'], ['GET', 'products/'.$shop['product']], ['GET', 'categories'], ['POST', 'products/'.$shop['product'], ['stock' => 5]]],
        'customers' => [['GET', 'customers'], ['GET', 'customers/'.$shop['customer']]],
        'notifications' => [['GET', 'notifications']],
    ];
    $hit = fn (array $call) => $call[0] === 'GET' ? OA::get($this, $call[1], $c) : OA::post($this, $call[1], $call[2] ?? [], $c, \App\Services\OwnerApp\OwnerAppAuth::csrfFor($c[\App\Services\OwnerApp\OwnerAppAuth::SESSION_COOKIE]));

    foreach ($calls as $screen => $list) {
        oa4Put(['screens' => [$screen => false]]);
        foreach ($list as $call) {
            $r = $hit($call);
            expect($r->status())->toBe(403, "$screen off: {$call[1]}")->and($r->json('code'))->toBe('off', "$screen off: {$call[1]}");
        }
        oa4Put([]);
        foreach ($list as $call) {
            expect($hit($call)->json('code'))->not->toBe('off', "$screen on: {$call[1]}");
        }
    }

    // More's own endpoints are never switched off.
    oa4Put(['screens' => array_fill_keys(array_keys(OwnerAppUi::SCREENS), false)]);
    expect(OA::get($this, 'state', $c)->json('stage'))->toBe('app');
    expect(OA::post($this, 'notify', ['groups' => ['orders']], $c, \App\Services\OwnerApp\OwnerAppAuth::csrfFor($c[\App\Services\OwnerApp\OwnerAppAuth::SESSION_COOKIE]))->status())->toBe(200);
});

it('refuses each action switched off on its own endpoint, product fields by function, and allows the rest', function (string $fn, string $method, string $path, array $body) {
    // DEFECT: a switch that hides a button while the endpoint still writes.
    // MUTATION: drop the PRODUCT_FIELDS loop in OwnerAppUiGate and the three
    // product cases save while switched off.
    $shop = oa4Shop();
    OA::member(OA::admin());
    [$c] = OA::enrol($this);
    $csrf = \App\Services\OwnerApp\OwnerAppAuth::csrfFor($c[\App\Services\OwnerApp\OwnerAppAuth::SESSION_COOKIE]);
    $path = str_replace(['{o}', '{p}'], [$shop['order'], $shop['product']], $path);
    $send = fn () => $method === 'GET' ? OA::get($this, $path, $c) : OA::post($this, $path, $body, $c, $csrf);

    oa4Put(['functions' => [$fn => false]]);
    $r = $send();
    expect($r->status())->toBe(403)->and($r->json('code'))->toBe('off');

    // Every OTHER function off and this one on: it is allowed.
    oa4Put(['functions' => array_map(fn ($k) => $k === $fn, array_combine(array_keys(OwnerAppUi::FUNCTIONS), array_keys(OwnerAppUi::FUNCTIONS)))]);
    expect($send()->json('code'))->not->toBe('off');
})->with([
    'bulk status' => ['bulk', 'POST', 'orders-bulk-status', ['ids' => [1], 'status' => 'onhold']],
    'mark paid' => ['mark_paid', 'POST', 'orders/{o}/mark-paid', ['method' => 'cod']],
    'order notes' => ['order_notes', 'POST', 'orders/{o}/notes', ['content' => 'Called the customer']],
    'edit price' => ['edit_price', 'POST', 'products/{p}', ['price_aed' => '120']],
    'edit sale price' => ['edit_price', 'POST', 'products/{p}', ['sale_aed' => '90']],
    'edit stock' => ['edit_stock', 'POST', 'products/{p}', ['stock' => 3, 'manage_stock' => true]],
    'edit categories' => ['edit_catalogue', 'POST', 'products/{p}', ['category_ids' => []]],
    'edit visibility' => ['edit_catalogue', 'POST', 'products/{p}', ['status' => 'draft', 'is_visible' => false]],
    'live check' => ['live', 'GET', 'changes?after=0', []],
]);

it('refuses a product save that mixes an allowed field with one switched off', function () {
    // DEFECT: smuggling a price change in beside a stock change.
    $shop = oa4Shop();
    OA::member(OA::admin());
    [$c] = OA::enrol($this);
    oa4Put(['functions' => ['edit_price' => false]]);
    $r = OA::post($this, 'products/'.$shop['product'], ['stock' => 4, 'price_aed' => '1'], $c, \App\Services\OwnerApp\OwnerAppAuth::csrfFor($c[\App\Services\OwnerApp\OwnerAppAuth::SESSION_COOKIE]));
    expect($r->json('code'))->toBe('off');
    expect((int) Product::query()->find($shop['product'])->stock)->toBe(20);
});

it('still applies the member\'s role on top: a screen on does not grant what the role lacks', function () {
    // DEFECT: the switch replacing the capability instead of adding to it.
    // MUTATION: return $next() before the controller's refuse() would run —
    // i.e. let the gate answer "allowed" for the controller.
    $shop = oa4Shop();
    $staff = OA::admin('support', 'sara@example.com', 'Sara Support');
    OA::member($staff, '739152');
    [$c] = OA::enrol($this, 'sara@example.com', '739152');
    oa4Put([]);
    $r = OA::post($this, 'products/'.$shop['product'], ['stock' => 1], $c, \App\Services\OwnerApp\OwnerAppAuth::csrfFor($c[\App\Services\OwnerApp\OwnerAppAuth::SESSION_COOKIE]));
    expect($r->status())->toBe(403)->and($r->json('code'))->toBe('forbidden');
});

it('folds mark-paid and notes into the order\'s `can`, so the app hides what the server refuses', function () {
    // MUTATION: drop the functionOn() terms in OrdersController::show().
    $shop = oa4Shop();
    OA::member(OA::admin());
    [$c] = OA::enrol($this);
    expect(OA::get($this, 'orders/'.$shop['order'], $c)->json('order.can'))->toBe(['status' => true, 'note' => true, 'paid' => true]);
    oa4Put(['functions' => ['mark_paid' => false, 'order_notes' => false]]);
    expect(OA::get($this, 'orders/'.$shop['order'], $c)->json('order.can'))->toBe(['status' => true, 'note' => false, 'paid' => false]);
});

it('names every route behind the PIN in the gate, so a new endpoint cannot slip past the switches', function () {
    // DEFECT: an endpoint added later under orders/ that answers with Orders
    // switched off. MUTATION: add a route to routes/owner-app.php's api group
    // without listing it in ROUTES or ALWAYS.
    $names = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array(OwnerAppUiGate::class, $r->gatherMiddleware(), true))
        ->map(fn ($r) => substr((string) $r->getName(), strlen('owner-app.')))->values()->all();
    expect($names)->not->toBeEmpty();
    foreach ($names as $n) {
        expect(array_key_exists($n, OwnerAppUiGate::ROUTES) || in_array($n, OwnerAppUiGate::ALWAYS, true))->toBeTrue($n);
    }
    expect(substr_count((string) file_get_contents(base_path('routes/owner-app.php')), 'OwnerAppUiGate::class'))->toBe(1);
});

it('does not compute the dashboard sections switched off', function () {
    // DEFECT: the two heaviest dashboard queries run for sections nobody
    // sees. MUTATION: drop the sectionOn() terms in DashboardController.
    $shop = oa4Shop();
    Order::query()->whereKey($shop['order'])->update(['status' => 'processing']);   // counted in this month's sales
    OA::member(OA::admin());
    [$c] = OA::enrol($this);
    $count = function () use ($c) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $r = OA::get($this, 'dashboard', $c)->assertOk();
        $q = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return [$r, $q->filter(fn ($s) => str_contains($s, 'order_items'))->count(), $q->filter(fn ($s) => str_contains($s, 'EXISTS'))->count()];
    };

    [$r, $items, $exists] = $count();
    expect($r->json('top'))->not->toBeEmpty()->and($items)->toBe(1)->and($exists)->toBe(1);

    oa4Put(['sections_off' => ['top', 'avg', 'returning']]);
    [$r, $items, $exists] = $count();
    expect($r->json('top'))->toBe([])->and($r->json('tiles'))->toBe(['avg_order' => null, 'returning_pct' => null])
        ->and($items)->toBe(0)->and($exists)->toBe(0);
});

/* ================================================================ font */

it('preloads the font at the default, and with System font neither preloads, precaches nor names it', function () {
    // DEFECT: "System font (fastest)" that still downloads the 27 KB file.
    // MUTATION: drop the @unless around the preload in shell.blade.php, or
    // the systemFont() term in AppController::worker().
    $html = $this->get(OA::base())->assertOk()->getContent();
    expect($html)->toContain('dir="ltr">')->not->toContain('oa-sys')
        ->toMatch('#crossorigin>\n<link rel="stylesheet"#')
        ->and(substr_count($html, 'plus-jakarta-sans'))->toBe(1);
    expect($this->get(OA::base().'/sw.js')->getContent())->toContain('plus-jakarta-sans');

    oa4Put(['font' => 'system']);
    $html = $this->get(OA::base())->assertOk()->getContent();
    expect($html)->not->toContain('plus-jakarta-sans')->not->toContain('rel="preload"')
        ->toContain('dir="ltr" class="oa-sys">');
    expect($this->get(OA::base().'/sw.js')->getContent())->not->toContain('plus-jakarta-sans');

    // The class's --font names no web face, so nothing in the page asks for it.
    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    preg_match('/html\.oa-sys\s*\{([^}]*)\}/', $css, $m);
    expect($m[1] ?? '')->toContain('--font:')->not->toContain('Jakarta');
    expect(substr_count($css, "'Plus Jakarta Sans'"))->toBe(2);   // the @font-face and :root --font, nothing else
});

/* ================================================================= css */

it('hangs every customise rule off an html.oa-* class, so at the defaults nothing in it applies', function () {
    // DEFECT: a "customise" rule that changes the app for everybody — the
    // defaults would no longer be today's look. MUTATION: add `.card { padding:
    // 12px; }` to the block without an html.oa- prefix.
    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    $block = substr($css, (int) strrpos(substr($css, 0, (int) strpos($css, 'Customise app (Lane OA4)')), '/*'));
    $block = (string) preg_replace('#/\*.*?\*/#s', '', $block);
    preg_match_all('/([^{}]+)\{/', $block, $m);
    foreach ($m[1] as $sel) {
        foreach (explode(',', preg_replace('/:is\([^)]*\)/', 'IS', trim($sel))) as $one) {
            $one = trim($one);
            expect(str_starts_with($one, 'html.oa-') || str_starts_with($one, 'IS') || $one === '.tiles.solo')->toBeTrue($one);
        }
    }

    // Every class the app can set has CSS behind it, and vice versa.
    $core = (string) file_get_contents(resource_path('js/owner-app/core.js'));
    preg_match('/const LOOK = \[([^\]]*)\]/', $core, $look);
    foreach (array_map(fn ($s) => trim($s, " '"), explode(',', $look[1])) as $class) {
        expect($css)->toContain('html.'.$class, $class);
    }
    expect($css)->toContain('--acc-grad: linear-gradient(135deg, color-mix(in srgb, var(--acc)');
});

/* ================================================================== js */

/** core.js in node with a <html> stub; returns what $body returns. */
function oa4Node(string $body): mixed
{
    $core = 'file://'.resource_path('js/owner-app/core.js');
    $harness = <<<JS
const mem = () => { const m = {}; return { getItem: (k) => (k in m ? m[k] : null), setItem: (k, v) => { m[k] = String(v); }, removeItem: (k) => { delete m[k]; } }; };
globalThis.window = { sessionStorage: mem(), localStorage: mem(), matchMedia: () => ({ matches: false, addEventListener() {} }) };
Object.defineProperty(globalThis, 'navigator', { value: { userAgent: 'node', platform: 'node', maxTouchPoints: 0 }, configurable: true });
const cls = new Set(), props = {}, writes = [];
const html = { classList: { add: (c) => { writes.push('+' + c); cls.add(c); }, remove: (c) => { writes.push('-' + c); cls.delete(c); }, contains: (c) => cls.has(c) },
  style: { setProperty: (k, v) => { writes.push(k); props[k] = v; }, removeProperty: (k) => { writes.push('rm' + k); delete props[k]; }, getPropertyValue: (k) => props[k] || '' } };
globalThis.document = { documentElement: html, body: { getAttribute: () => '/oa' }, addEventListener() {}, querySelectorAll: () => [], querySelector: () => null };
const core = await import('{$core}');
const out = await (async () => { {$body} })();
console.log(JSON.stringify(out));
JS;
    $file = storage_path('framework/testing/oa4-core-'.getmypid().'-'.bin2hex(random_bytes(3)).'.mjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $harness);
    $raw = (string) shell_exec('node '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    return json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true) ?? $raw;
}

it('applies the defaults by writing nothing at all to <html>, and a customised look as classes and one variable', function () {
    // DEFECT: the defaults not being today's app — a stray class or an
    // inline --acc at the default accent. MUTATION: drop the
    // `!== '#A8475C'` test in applyUi() and the first expectation is red.
    $defaults = json_encode(OwnerAppUi::forApp());
    $out = oa4Node("core.applyUi($defaults); return { writes, logo: core.logo(), scr: core.scr('orders'), fn: core.fn('bulk') };");
    expect($out)->toBe(['writes' => [], 'logo' => '<i class="logo " aria-hidden="true">KB</i>', 'scr' => true, 'fn' => true]);

    $custom = json_encode(array_replace_recursive(OwnerAppUi::forApp(), [
        'accent' => '#0F766E', 'initials' => 'EB', 'text' => 'l', 'title' => 's', 'figure' => 'l', 'density' => 'compact', 'corners' => 'square', 'header' => 'standard',
        'screens' => ['customers' => false], 'functions' => ['bulk' => false, 'sync' => false, 'contact' => false, 'fullscreen' => false],
    ]));
    $out = oa4Node("core.applyUi($custom); const a = [...cls].sort(), acc = props['--acc']; core.applyUi($defaults); return { a, acc, after: [...cls], accAfter: props['--acc'] || null, logo: core.logo('sm'), cust: core.scr('customers') };");
    expect($out['a'])->toBe(['oa-acc', 'oa-compact', 'oa-fg-l', 'oa-hd-std', 'oa-nobulk', 'oa-nocontact', 'oa-nofs', 'oa-nosync', 'oa-rd-sq', 'oa-tt-s', 'oa-tx-l'])
        ->and($out['acc'])->toBe('#0F766E')
        ->and($out['after'])->toBe([])->and($out['accAfter'])->toBeNull()
        ->and($out['logo'])->toContain('>KB<')
        ->and($out['cust'])->toBeTrue();

    $out = oa4Node('core.applyUi({ initials: "<b>", screens: { orders: false }, functions: { live: false } }); return [core.logo(), core.scr("orders"), core.scr("products"), core.fn("live"), core.fn("sync")];');
    expect($out)->toBe(['<i class="logo " aria-hidden="true">&lt;b&gt;</i>', false, true, false, true]);
});

/** Code only: the comments explain the rules in words the pins would match. */
function oa4Code(string $file): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#(^|\s)//[^\n]*#'], ['', '$1'], (string) file_get_contents(resource_path('js/owner-app/'.$file)));
}

it('asks for nothing a switch has turned off, and runs the one live timer at the chosen pace or not at all', function () {
    // DEFECT: the app polling a refused endpoint every sync (a toast every
    // time), or a second timer. MUTATION: remove the fn('live') guard in
    // startPolling() or the scr() terms in sync().
    $app = oa4Code('owner-app.js');
    foreach (["if (fn('live')) jobs.push(catchUp(", "if (scr('notifications')) jobs.push(fetchNotifications(", "can.orders && scr('store')) jobs.push(fetchDashboard(",
        "can.orders && scr('orders')) jobs.push(fetchOrders(", "can.products && scr('products')) jobs.push(fetchProducts(", "can.customers && scr('customers')) jobs.push(fetchCustomers("] as $guard) {
        expect($app)->toContain($guard);
    }
    expect($app)->toContain("S.stage !== 'app' || !fn('live')) return;")
        ->toContain("if (!first && !pollT && fn('live')) startPolling();")
        ->toContain('}, pollMs());')
        ->toContain("Math.max(15, Math.min(120, +(S.ui && S.ui.live_seconds) || 25)) * 1000")
        ->not->toContain('setInterval')
        ->and(substr_count($app, 'setTimeout('))->toBe(2);   // the poll chain and the sync icon's last turn, as before

    // An address for a screen that is off falls through to the first tab still on.
    expect($app)->toContain("if (offHere(route.name)) { const t = tabsOn()[0][0];");
    // The tabs follow the switches and the role; More is always there.
    expect($app)->toMatch("/const tabsOn = \\(\\) => TABS\\.filter\\(\\(\\[k\\]\\) => \\(k !== '' \\|\\| scr\\('store'\\)\\)/");
});

it('hides each switched-off action in the app: product rows by field, bulk selection, the bell and More\'s rows', function () {
    // MUTATION: map 'inv' to 'edit_price' in EDIT_FN, or drop the bulk guard
    // in ordersClick, or the scr('notifications') term on the bell.
    $p = oa4Code('products.js');
    expect($p)->toContain("const EDIT_FN = { price: 'edit_price', inv: 'edit_stock', cat: 'edit_catalogue', vis: 'edit_catalogue' };")
        ->toContain("const open = (k) => k === 'desc' || k === 'short' || (can && fn(EDIT_FN[k]));");
    expect(oa4Code('orders.js'))->toContain("if (!fn('bulk') && ['sel', 'selall', 'selnone', 'bulk', 'bulk-more'].indexOf(act) !== -1) return true;");
    $s = oa4Code('store.js');
    expect($s)->toContain("(scr('notifications') ? '<a class=\"ib\" href=\"#/notifications\"")
        ->toContain("me.can.customers && scr('customers') ? r('users', 'Customers'")
        ->toContain("me.can.products && scr('products') ? r('stack', 'Low stock'")
        // Dashboard links into a screen that is off become plain rows, not dead ends.
        ->toContain("const goes = (href) => { const m = /^#\\/(orders|products|customers|notifications)/.exec(href || ''); return !m || scr(m[1]); };")
        ->toContain("(goes(n.href) ? '<a class=\"row\" href=\"' + esc(n.href) + '\">' : '<div class=\"row\">')")
        ->toContain("(scr('products') ? '<a class=\"row\" href=\"' + (t.id ? '#/products/' + t.id : '#/products') + '\">' : '<div class=\"row\">')");
});

it('lays the dashboard out in the chosen order, leaves out what is off, and keeps today\'s markup at the defaults', function () {
    // DEFECT: the default dashboard changing shape (the two tiles split), or
    // a reordered one losing a section. MUTATION: drop the `pair` branch in
    // arrange() and the default case is red.
    $code = oa4Code('store.js');
    preg_match("/const SECTIONS = .*?\n\\}/s", $code, $m);
    $src = json_encode($m[0] ?? '');
    $run = fn (string $ui) => oa4Node("const S = { ui: $ui }; const f = new Function('S', $src + '; return arrange;')(S);"
        ."return f({ hero: '[H]', needs: '[N]', avg: '[A]', returning: '[R]', top: '[T]' });");

    expect($run('null'))->toBe('[H][N]<div class="tiles">[A][R]</div>[T]')
        ->and($run(json_encode(OwnerAppUi::forApp())))->toBe('[H][N]<div class="tiles">[A][R]</div>[T]')
        ->and($run('{"sections":["top","avg","hero","returning","needs"],"sections_off":["needs"]}'))
        ->toBe('[T]<div class="tiles solo">[A]</div>[H]<div class="tiles solo">[R]</div>')
        ->and($run('{"sections":["returning","avg","top","hero","needs"],"sections_off":["hero","needs"]}'))
        ->toBe('<div class="tiles">[R][A]</div>[T]');
});

/* =============================================================== admin */

it('puts the card in its own partial, included once, under Users & Roles → Owner app', function () {
    // The FINISHED state (CLAUDE.md): exactly one include, exactly one route block.
    $access = (string) file_get_contents(resource_path('views/admin/partials/owner-app-access.blade.php'));
    expect(substr_count($access, "@include('admin.partials.owner-app-customise')"))->toBe(1);
    $routes = (string) file_get_contents(base_path('routes/owner-app-admin.php'));
    expect(substr_count($routes, "Route::get('/owner-app/ui'"))->toBe(1)->and(substr_count($routes, "Route::put('/owner-app/ui'"))->toBe(1);

    $card = (string) file_get_contents(resource_path('views/admin/partials/owner-app-customise.blade.php'));
    foreach (['Customise app', 'Branding', 'Store name in the header', 'Header initials', 'Accent colour', 'Type', 'Font', 'Text size', 'Title size', 'Dashboard big numbers',
        'Layout', 'Density', 'Corner roundness', 'My store header', 'Screens', 'My store sections', 'Functions', 'Live check every', 'Reset to defaults', 'Save',
        'System font (fastest — skips the 27 KB font download)'] as $label) {
        expect($card)->toContain($label);
    }
    // The preview is a static mock: no request per change, no frame of the real app, nothing measured.
    expect($card)->not->toContain('getBoundingClientRect')->not->toContain('offsetWidth')->not->toContain('setInterval')
        ->and(substr_count($card, 'fetch('))->toBe(1)
        ->and($card)->toContain('frame.srcdoc =')->not->toMatch('/iframe[^>]*\ssrc=/');
});
