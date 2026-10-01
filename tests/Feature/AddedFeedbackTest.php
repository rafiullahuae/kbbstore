<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\CartPanel;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Str;

/**
 * WHAT PRESSING "ADD TO CART" SHOWS.                              (Lane PI-B)
 *
 * It opened the cart panel AND put a dark "Added to bag" pill at the top of
 * the screen — on a phone, across the panel's own tab row (measured at 390:
 * pill x 134–255, y 14–60; panel from x 90, tabs y 0–45). The owner asked for
 * a choice of feedback and for one in particular: an animated tick, grey to
 * green, super fast, gone at once, with the panel opening exactly as now.
 *
 * Appearance → Cart panel → Behaviour → "When something is added":
 * Animated tick (default — he asked for it) / Text pill / None.
 *
 * Measured in Chromium (storage/pib-logs/addcart.cjs): click → panel open
 * 0.9ms at 390 and 0.7ms at 1280 with the tick, 0.8ms / 0.8ms with the pill —
 * the panel opens before the request is even sent, and the tick follows the
 * server's answer (70–85ms here). The tick sits 12px outside the panel's
 * leading edge (x 34–78 at 390 beside a panel from x 90; x 844–888 at 1280
 * beside a panel from x 900) and is gone by 450ms.
 */

function afOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'AF '.$role, 'email' => 'af-'.$role.'-'.Str::random(8).'@example.test',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

function afCss(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb.css')));
}

function afJs(string $file): string
{
    return (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(resource_path('js/kbb/'.$file)));
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('ships the animated tick, because he asked for it', function () {
    /*
     * CLAUDE.md's 30-September reversal: what he asked for is the shop's new
     * state. The pill is one press away.
     *
     * MUTATION, RUN: `add_feedback` default -> 'pill' in CartPanel::SCHEMA and
     * this is red, and the page's data-cp says "pill".
     */
    $panel = app(CartPanel::class);

    expect($panel->all()['add_feedback'])->toBe('tick')
        ->and($panel->jsConfig()['feedback'])->toBe('tick');

    $html = test()->get('/')->assertOk()->getContent();

    // Printed inside the panel's data-cp JSON, which Blade escapes.
    expect($html)->toContain('&quot;feedback&quot;:&quot;tick&quot;')
        ->and(substr_count($html, '<div class="kbb-tick" id="kbbTick">'))->toBe(1);
});

it('stores one of its three options or the default, never what it was sent', function () {
    /*
     * MUTATION, RUN: store `$value` instead of `$this->cast($key, $value)` in
     * CartPanel::save() and this is red — the shop would print the posted
     * string into data-cp.
     */
    $cast = fn (mixed $v) => \App\Services\ModuleSchema::cast(
        \App\Services\ModuleSchema::field('add_feedback', CartPanel::SCHEMA['add_feedback'], CartPanel::POLICY),
        $v,
    );

    expect($cast('pill'))->toBe('pill')
        ->and($cast('none'))->toBe('none')
        ->and($cast('<script>'))->toBe('tick')
        ->and($cast(''))->toBe('tick')
        ->and($cast(null))->toBe('tick');

    test()->actingAs(afOwner(), 'admin')
        ->postJson('/admin-api/cart-panel', ['settings' => ['add_feedback' => '"><script>x</script>']])
        ->assertOk();

    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->all()['add_feedback'])->toBe('tick')
        ->and(app(SettingsService::class)->get('cartpanel_add_feedback'))->toBe('tick');

    test()->postJson('/admin-api/cart-panel', ['settings' => ['add_feedback' => 'pill']])->assertOk();

    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->jsConfig()['feedback'])->toBe('pill');
});

it('puts the control on Appearance → Cart panel → Behaviour, beside "Open the panel"', function () {
    test()->actingAs(afOwner(), 'admin');

    $tabs = collect(test()->getJson('/admin-api/cart-panel')->assertOk()->json('tabs'))->keyBy('key');
    $fields = collect($tabs['behaviour']['fields']);

    expect($fields->pluck('key')->all())->toBe(['open_on_add', 'add_feedback', 'added_note', 'note_ms']);

    $f = $fields->firstWhere('key', 'add_feedback');

    expect($f['label'])->toBe('When something is added')
        ->and($f['type'])->toBe('select')
        ->and(array_keys($f['options']))->toBe(['tick', 'pill', 'none'])
        ->and($f['value'])->toBe('tick');
});

it('keeps a support account out of the screen', function () {
    expect(collect(AdminCapabilities::RULES)->contains(['*', 'admin-api/cart-panel', 'content.manage']))->toBeTrue();

    test()->postJson('/admin-api/cart-panel', ['settings' => ['add_feedback' => 'none']])->assertStatus(401);

    test()->actingAs(afOwner('support'), 'admin')
        ->postJson('/admin-api/cart-panel', ['settings' => ['add_feedback' => 'none']])
        ->assertForbidden();

    SettingsService::forgetMemo();
    expect(app(CartPanel::class)->all()['add_feedback'])->toBe('tick');
});

it('marks only the plain "it went in" confirmation as `added`', function () {
    /*
     * The setting governs that one message. Everything else the cart says —
     * "Removed", a coupon, a set that took the last jar — still says it.
     *
     * MUTATION, RUN: pass `true` for $added from update() as well and this is
     * red on the update.
     */
    $p = Product::create([
        'slug' => 'af-one', 'name' => 'Feedback Product', 'type' => 'simple', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ]);

    $add = test()->postJson('/api/cart/add', ['product_id' => $p->id, 'quantity' => 1])->assertOk()->json();

    expect($add['added'])->toBeTrue()
        ->and($add['toast'])->toBe('Added to bag');

    $itemId = \App\Models\CartItem::query()->latest('id')->value('id');

    $update = test()->postJson('/api/cart/update', ['item_id' => $itemId, 'quantity' => 2])->assertOk()->json();
    expect($update['added'])->toBeFalse();

    $remove = test()->postJson('/api/cart/remove', ['item_id' => $itemId])->assertOk()->json();
    expect($remove['added'])->toBeFalse()
        ->and($remove['toast'])->toBe('Removed');
});

it('chooses the feedback in cart.js from the server-printed mode, and only for `added`', function () {
    /*
     * MUTATION, RUN: replace `if (data.added && data.toast)` with
     * `if (data.toast)` in cart.js and this is red — a coupon message would
     * become a silent tick.
     */
    $cart = afJs('cart.js');

    expect($cart)->toContain("import { toast, tick } from './toast.js';")
        ->and($cart)->toContain('if (data.added && data.toast) {')
        ->and($cart)->toContain("const mode = cp.feedback === 'tick' || cp.feedback === 'none' ? cp.feedback : 'pill';")
        ->and($cart)->toContain("if (mode === 'tick' && cp.openOnAdd !== false) tick(data.toast);")
        ->and($cart)->toContain("else if (mode !== 'none') toast(data.toast);");

    // The panel still opens first, before the request — untouched.
    expect($cart)->toMatch('/if \(config\(\)\.openOnAdd !== false\) \{\s*skeleton\(\);\s*open\(\'cart\'\);\s*\}\s*return post\(\'\/add\', body\);/');
});

it('animates in CSS only, in 450ms, and restarts without reading layout', function () {
    /*
     * MUTATION, RUN: restart the animation with `void el.offsetWidth` in
     * toast.js (the usual trick) and this is red.
     */
    $toast = afJs('toast.js');

    expect($toast)->toContain('export const tick = (message) => {')
        ->and($toast)->toContain('requestAnimationFrame(() => requestAnimationFrame(() => {');

    foreach (['offsetWidth', 'offsetHeight', 'getBoundingClientRect', 'getComputedStyle', 'clientWidth'] as $api) {
        expect(str_contains($toast, $api))->toBeFalse("toast.js reads layout: {$api}");
    }

    $css = afCss();

    expect($css)->toContain('.kbb-tick.on{animation:kbbTick 450ms')
        ->and($css)->toContain('.kbb-tick.on path{animation:kbbTickDraw 190ms 60ms')
        ->and($css)->toContain('stroke-dasharray:1;stroke-dashoffset:1')
        ->and($css)->toMatch('/@keyframes kbbTick\{\s*0%\{opacity:0;transform:scale\(\.55\);background:#B9BEC6\}/')
        ->and($css)->toContain('background:#1F9D55');
});

it('shows the green tick without motion when the shopper asks for reduced motion', function () {
    /*
     * MUTATION, RUN: delete the prefers-reduced-motion block and this is red.
     */
    $css = afCss();

    expect((bool) preg_match('/@media \(prefers-reduced-motion:reduce\)\{\s*\.kbb-tick\.on\{animation:kbbTickStill 600ms step-end both;background:#1F9D55\}\s*\.kbb-tick\.on path\{animation:none;stroke-dashoffset:0\}\s*\}/', $css))
        ->toBeTrue('no reduced-motion tick: the drawing and the pop still play for a shopper who asked for none');

    expect($css)->toContain('@keyframes kbbTickStill{0%{opacity:1}100%{opacity:0}}');
});

it('places the tick beside the panel, on the right side in Arabic, never over its content', function () {
    /*
     * Inside the panel (a position:fixed box, so its containing block) and
     * 12px past its leading edge. inset-inline-end is what flips it when the
     * panel opens from the left on /ar.
     */
    expect(afCss())->toContain('.kbb-tick{position:absolute;top:14px;inset-inline-end:calc(100% + 12px);width:44px;height:44px;');

    $drawers = (string) file_get_contents(resource_path('views/partials/drawers.blade.php'));
    $aside = substr($drawers, (int) strpos($drawers, '<aside class="drawer'), (int) strpos($drawers, '</aside>') - (int) strpos($drawers, '<aside class="drawer'));

    expect($aside)->toContain('<div class="kbb-tick" id="kbbTick"><span class="kbb-tick-msg" role="status" aria-live="polite"></span>');
});
