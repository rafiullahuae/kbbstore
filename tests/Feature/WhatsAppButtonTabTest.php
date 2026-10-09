<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CartService;
use App\Services\SettingsService;
use App\Services\WhatsAppButton;
use App\Support\Locale;
use App\Support\SupportContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WhatsAppButtonRoutes;

/**
 * Appearance → WhatsApp button → Cart & checkout · phone.            (Lane WS)
 *
 * The owner, with a picture of his phone's cart and the round button sitting on
 * the line items: "on cart and checkout mobile pages, i want the whatsapp
 * floating to move to the left side of the screen, stiky type vertical bar,
 * having 24/7 Support + whatsapp animated icon. must be size adjustable of
 * overall bar with size dragger bar, must be unique with background colors
 * grandient changing, but with light colors, as the text will be black and
 * icon will be green."
 *
 * The pictures and the measured gaps are in docs/ws-shots/. These tests pin
 * what a picture cannot: which pages get it, what every other page does NOT
 * get, what the server refuses, and that it costs no query.
 */
function wsRaw(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::query()->updateOrCreate(['key' => 'waf_'.$key], ['value' => $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function wsAdmin(): App\Models\AdminUser
{
    return App\Models\AdminUser::create([
        'name' => 'WS owner',
        'email' => 'ws-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function wsCart(): Cart
{
    $product = Product::create([
        'slug' => 'ws-tab-'.Str::random(8),
        'name' => 'Glow Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 12000,
        'stock_status' => 'instock',
    ]);

    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'last_activity_at' => now(),
    ]);

    $cart->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 12000]);

    return $cart;
}

function wsGet(string $path, ?Cart $cart = null): string
{
    $cart ??= wsCart();

    return (string) test()
        ->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($path)
        ->assertOk()
        ->getContent();
}

/** The tab's own element, or '' when the page has none. */
function wsTab(string $html): string
{
    preg_match('#<div class="kbt-z[^"]*"[^>]*>.*?</a></div>\n#s', $html, $m);

    return $m[0] ?? '';
}

/** The button's <style>. */
function wsStyle(string $html): string
{
    preg_match('#<style id="kbb-wa">([^<]*)</style>#', $html, $m);

    return $m[1] ?? '';
}

/** WCAG contrast ratio of two #RRGGBB colours. */
function wsContrast(string $a, string $b): float
{
    $x = WhatsAppButton::luminance($a);
    $y = WhatsAppButton::luminance($b);

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

beforeEach(function () {
    WhatsAppButtonRoutes::wire($this->app);

    // Lane QK8: the button and its tab are OFF on the cart and the checkout
    // now ("the whatsapp floating button should be turn off on cart and
    // checkout completely"), and CheckoutQk8Test pins that. Every
    // test here is about the tab those two pages draw once he switches them
    // back on, so they are switched on here.
    wsRaw(['show_cart' => '1', 'show_checkout' => '1']);
});

/* ═════════════════════════════════════════ where it is, and is not ═══ */

it('draws, once switched back on, the slim tab on the cart and the checkout: its label, the phone switch-over', function () {
    /*
     * DEFECT this guards: the owner's phone cart with the round button on the
     * line items. Shipped ON because he asked for it (CLAUDE.md rule 1, 30
     * September), at 26px, centred, the blush palette, animated.
     * MUTATION: delete @section('kbb-wa-tab', '1') from store/cart.blade.php
     * -> the cart half is red; change tab_on's default to false -> both are.
     */
    foreach (['/cart', '/checkout'] as $path) {
        $html = wsGet($path);
        $tab = wsTab($html);
        $css = wsStyle($html);

        expect($tab)->toContain('<div class="kbt-z" style="--q:1;--y:50;--c1:#FFE1EA;--c2:#FFF0D9;--c3:#E2F6EA">')
            ->and($tab)->toContain('<span class="kbt-l">24/7 Support</span>')
            ->and($tab)->toContain('<span class="kbt-i">'.WhatsAppButton::ICON.'</span>')
            ->and($tab)->toContain('aria-label="24/7 Support · Chat with us on WhatsApp"')
            // The phone switch-over, and the round button still on the page for
            // a laptop. 2.60.390, the owner: "i don't want a dedicated left side
            // space ... the support vatical bar will float on the left side" --
            // the tab floats, nothing moves. MUTATION: print TAB_PHONE by
            // default again -> the not->toContain is red.
            ->and($css)->toContain('@media (max-width:900px){.kbt-z{display:flex}.kbw{display:none}}')
            ->and($css)->not->toContain('#content{padding-left:var(--kbtw)}')
            ->and($css)->toEndWith(':root{--kbtw:26px}')
            ->and($html)->toContain('id="kbbWa"');
    }
});

it('gives every other page not one byte of it', function () {
    /*
     * "Laptop cart/checkout and every other page stay exactly as they are."
     * The laptop half is the media query above; this is the other half. A page
     * that does not declare the section renders exactly what view() rendered
     * before this lane, byte for byte, with no tab, no tab CSS, no --kbtw and
     * no bubble guard.
     * MUTATION: pass `true` for $tabPage in the partial -> red.
     */
    $html = $this->get('/')->assertOk()->getContent();
    $old = app(WhatsAppButton::class)->view(null, false, false);

    expect($html)->not->toContain('kbt')
        ->and($html)->not->toContain('matchMedia(\'(max-width:900px)\')')
        ->and(wsStyle($html))->toBe($old['css'])
        ->and($old['tab'])->toBeNull()
        ->and($old['guard'])->toBe('')
        ->and($old['css'])->toBe(WhatsAppButton::css('G'));

    // And exactly two templates declare the section: the cart and the
    // checkout, not the order-received page that shares the checkout's chrome.
    $declared = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), "@section('".WhatsAppButton::TAB_SECTION."', '1')")) {
            $declared[] = str_replace(resource_path('views').'/', '', $file->getPathname());
        }
    }
    sort($declared);

    expect($declared)->toBe(['store/cart.blade.php', 'store/checkout.blade.php']);
});

it('costs the cart page no query', function () {
    /*
     * Rule 4, measured: the cart page with the tab on and off runs the same
     * number of queries, because every value comes out of the settings map the
     * page has already loaded. StorefrontQueryBudgetTest holds the cart's
     * absolute number; this holds the difference at zero.
     * MUTATION: read tab_size with Setting::query() in tab() -> red.
     */
    $cart = wsCart();

    $count = function () use ($cart): int {
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        wsGet('/cart', $cart);

        return $n;
    };

    wsRaw(['tab_on' => '0']);
    wsGet('/cart', $cart);
    $off = $count();

    wsRaw(['tab_on' => '1']);
    wsGet('/cart', $cart);
    $on = $count();

    expect($on)->toBe($off);
});

/* ══════════════════════════════════════════════ the switches ═══ */

it('puts the round button back when the tab is off, and shows neither when phones are off', function () {
    /*
     * MUTATION: drop the `tab_on` check in tab() -> the first half is red;
     * drop the `show_phone` check -> the second is.
     */
    wsRaw(['tab_on' => '0']);
    $html = wsGet('/cart');

    expect(wsTab($html))->toBe('')
        ->and(wsStyle($html))->not->toContain('.kbt-z{display:flex}.kbw{display:none}')
        ->and($html)->toContain('id="kbbWa"');

    wsRaw(['tab_on' => '1', 'show_phone' => '0']);
    $html = wsGet('/checkout');

    expect(wsTab($html))->toBe('')
        ->and(wsStyle($html))->not->toContain('kbt');
});

it('opens the same chat as the round button, including the owner\'s own link', function () {
    /*
     * MUTATION: build the tab's href from a fresh wa.me URL rather than the
     * button's link() -> the custom-link half is red.
     */
    $html = wsGet('/cart');
    $expect = 'https://wa.me/'.SupportContact::whatsappDigits().'?text='
        .rawurlencode("Hi there 👋 Welcome to K-Beauty Bliss\nNeed help choosing? Our beauty team is on WhatsApp, 24/7.");

    expect(wsTab($html))->toContain('href="'.e($expect).'" target="_blank" rel="noopener"');

    wsRaw(['link' => 'https://example.test/chat?x=1&y=2']);

    expect(wsTab(wsGet('/checkout')))->toContain('href="https://example.test/chat?x=1&amp;y=2"');
});

it('animates by default, keeps still when asked, and always for reduced motion', function () {
    /*
     * GPU-cheap motion: a transform on a pseudo-element and the shop's kbwP
     * ring. Nothing animates a background-position or a hue on the page.
     * MUTATION: delete the prefers-reduced-motion branch from TAB_CSS -> red.
     */
    expect(WhatsAppButton::TAB_CSS)->toContain('animation:kbtG 9s ease-in-out infinite alternate')
        ->and(WhatsAppButton::TAB_CSS)->toContain('@keyframes kbtG{to{transform:translateY(-66.6667%)}}')
        ->and(WhatsAppButton::TAB_CSS)->toContain('@media (prefers-reduced-motion:reduce){.kbt::before,.kbt-i::before{animation:none!important}')
        ->and(WhatsAppButton::TAB_CSS)->not->toContain('background-position')
        ->and(WhatsAppButton::css('G'))->toContain('@keyframes kbwP');

    wsRaw(['tab_anim' => '0']);

    expect(wsTab(wsGet('/cart')))->toContain('<div class="kbt-z kbt-still"');
});

/* ═════════════════════════════════════════ size, position, colours ═══ */

it('scales by one number and clamps the size and position bars on save and again at render', function () {
    /*
     * The size bar is the tab's width, 22 to 44px; --q scales everything, and
     * --kbtw is how far the page moves over. A row written behind the screen's
     * back is clamped at render too.
     * MUTATION: remove the max()/min() on $w in tab() -> --kbtw:999px, red.
     */
    $this->actingAs(wsAdmin(), 'admin');

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['tab_size' => 999, 'tab_y' => -5]])->assertOk();

    expect(app(WhatsAppButton::class)->all()['tab_size'])->toBe(44)
        ->and(app(WhatsAppButton::class)->all()['tab_y'])->toBe(0);

    $html = wsGet('/cart');
    expect(wsTab($html))->toContain('style="--q:1.6923;--y:0;')
        ->and(wsStyle($html))->toEndWith(':root{--kbtw:44px}');

    wsRaw(['tab_size' => '999', 'tab_y' => '250']);
    $html = wsGet('/cart');
    expect(wsTab($html))->toContain('style="--q:1.6923;--y:100;')
        ->and(wsStyle($html))->toEndWith(':root{--kbtw:44px}');

    wsRaw(['tab_size' => '22']);
    expect(wsStyle(wsGet('/cart')))->toEndWith(':root{--kbtw:22px}');
});

it('keeps every palette light enough for black text and the icon green enough on its disc', function () {
    /*
     * "light colors, as the text will be black and icon will be green" —
     * computed, not eyeballed. Black on every stop of every preset is above
     * 15:1; the icon's green on its white disc is above 4.5:1; and the lightest
     * a custom colour may be keeps black text above 11:1.
     * MUTATION: put '#9C2750' into a palette -> red.
     */
    foreach (WhatsAppButton::TAB_PALETTES as $name => $stops) {
        expect($stops)->toHaveCount(3);
        foreach ($stops as $hex) {
            expect(wsContrast(WhatsAppButton::TAB_INK, $hex))->toBeGreaterThan(15.0, "{$name} {$hex}");
        }
    }

    expect(wsContrast(WhatsAppButton::TAB_GREEN, '#FFFFFF'))->toBeGreaterThan(4.5)
        ->and((WhatsAppButton::TAB_LIGHT_MIN + 0.05) / (WhatsAppButton::luminance(WhatsAppButton::TAB_INK) + 0.05))->toBeGreaterThan(11.0)
        ->and(WhatsAppButton::TAB_CSS)->toContain('color:'.WhatsAppButton::TAB_INK)
        ->and(WhatsAppButton::TAB_CSS)->toContain('background:#fff;color:'.WhatsAppButton::TAB_GREEN);

    // The select offers the presets and "custom", and nothing else.
    expect(array_keys(WhatsAppButton::SCHEMA['tab_palette'][4]))
        ->toBe([...array_keys(WhatsAppButton::TAB_PALETTES), 'custom']);
});

it('refuses a palette that is not its own and a custom colour that is dark or not hex', function () {
    /*
     * Rule 5: a select stores one of its own options; a colour is validated
     * hex — and here also LIGHT, because the text on it is black. Refused and
     * named back, saving nothing.
     * MUTATION: drop the luminance check from cleanLight() -> the dark half is red.
     */
    $this->actingAs(wsAdmin(), 'admin');

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['tab_palette' => 'neon']])
        ->assertStatus(422)->assertJsonPath('rejected', ['tab_palette']);
    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['tab_c1' => '#222222', 'tab_size' => 40]])
        ->assertStatus(422)->assertJsonPath('rejected', ['tab_c1']);
    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['tab_c2' => 'red;}body{display:none']])
        ->assertStatus(422)->assertJsonPath('rejected', ['tab_c2']);

    expect(app(WhatsAppButton::class)->all()['tab_size'])->toBe(26);

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['tab_palette' => 'custom', 'tab_c1' => '#fff6c7', 'tab_c2' => '#DDF3FF']])
        ->assertOk();

    expect(wsTab(wsGet('/cart')))->toContain('--c1:#FFF6C7;--c2:#DDF3FF;--c3:#FFF6C7"');

    // A dark colour written behind the screen's back falls back at render.
    wsRaw(['tab_c1' => '#000000']);
    expect(wsTab(wsGet('/cart')))->toContain('--c1:#FFE1EA;--c2:#DDF3FF;--c3:#FFE1EA"');
});

it('moves the room for the squeezed cart\'s full-bleed rail only while that layout is on', function () {
    /*
     * DEFECT (measured on the preview at 390): the squeezed cart's
     * "Recommended" rail is full bleed by 100vw, so with #content moved over
     * it started 13px from the edge — under the 26px tab — and ran off the
     * right. And on the classic shop no page may name the squeezed furniture.
     * MUTATION: print TAB_SQUEEZE unconditionally -> the classic half is red.
     */
    expect(wsStyle(wsGet('/cart')))->not->toContain('cpg-');

    Setting::query()->updateOrCreate(['key' => 'cartpage_layout'], ['value' => 'squeeze', 'autoload' => true]);
    // The rail rule belongs to "Make room beside the tab" (off by default, 2.60.390).
    wsRaw(['tab_space' => '1']);

    expect(wsStyle(wsGet('/cart')))
        ->toContain('.kbb-cartpage.cpg-squeeze .cpg-rec{margin-inline:calc(50% - 50vw + var(--kbtw)/2);width:calc(100vw - var(--kbtw))');
});

/* ═══════════════════════════════════════════ escaping and the bubble ═══ */

it('prints the label escaped, capped at 24 letters, and hides it when emptied', function () {
    /*
     * MUTATION: print the label with {!! !!} -> red.
     */
    wsRaw(['tab_label' => '<img src=x onerror=1>"']);
    $tab = wsTab(wsGet('/cart'));

    expect($tab)->toContain('<span class="kbt-l">&lt;img src=x onerror=1&gt;&quot;</span>')
        ->and($tab)->toContain('aria-label="&lt;img src=x onerror=1&gt;&quot; · Chat')
        ->and($tab)->not->toContain('<img');

    $this->actingAs(wsAdmin(), 'admin');
    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['tab_label' => str_repeat('A', 40)]])->assertOk();
    expect(app(WhatsAppButton::class)->all()['tab_label'])->toBe(str_repeat('A', 24));

    wsRaw(['tab_label' => '']);
    $tab = wsTab(wsGet('/cart'));
    expect($tab)->not->toContain('kbt-l')
        ->and($tab)->toContain('aria-label="Chat with us on WhatsApp"');
});

it('keeps the bubble\'s "show once" from spending itself on a phone cart where it is hidden', function () {
    /*
     * The round button — and its welcome bubble — are hidden on a phone on
     * these two pages. Without the guard a visitor who lands on the cart first
     * would never see the bubble anywhere: it would be "shown" here, unseen.
     * The guard is a constant, and other pages' script is unchanged.
     * MUTATION: set 'guard' to '' for tab pages -> red.
     */
    $guarded = "if(!b)return;".WhatsAppButton::TAB_BUBBLE_GUARD.'try{';

    expect(wsGet('/cart'))->toContain($guarded)
        ->and($this->get('/')->getContent())->toContain('if(!b)return;try{')
        ->and(WhatsAppButton::TAB_BUBBLE_GUARD)->toBe("if(matchMedia('(max-width:900px)').matches)return;");
});

/* ═══════════════════════════════════════════════════ the Arabic shop ═══ */

it('speaks Arabic and sits on the right, with the room on the right, on the mirrored Arabic shop', function () {
    /*
     * MUTATION: hard-code 'left' in the TAB_PHONE sprintf -> red.
     */
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
    wsRaw(['tab_label_ar' => 'دعم واتساب', 'tab_space' => '1']);

    $html = wsGet('/ar/cart');

    expect(wsTab($html))->toContain('<div class="kbt-z kbt-rt"')
        ->and(wsTab($html))->toContain('<span class="kbt-l">دعم واتساب</span>')
        ->and(wsStyle($html))->toContain('#content{padding-right:var(--kbtw)}.kbb-checkout .co-head{margin-right:calc(var(--kbtw)*-1)}');
});

/* ═══════════════════════════════════════════════════════ the screen ═══ */

it('draws the tab in the admin preview with the shop\'s classes and moves it without a request', function () {
    /*
     * DEFECT this guards: a preview that drifts from the shop. Every kbt class
     * the partial prints, build() prints too; the size and position bars move
     * custom properties on the drawn tab instead of rebuilding or asking.
     * MUTATION: rename `kbt-l` in the partial -> red.
     */
    $partial = (string) file_get_contents(resource_path('views/partials/whatsapp-button.blade.php'));
    $screen = (string) file_get_contents(resource_path('views/admin/partials/whatsapp-button-screen.blade.php'));

    preg_match_all('/\bkbt(?:-[a-z]{1,5})?\b/', $partial, $m);
    $classes = array_values(array_unique($m[0]));
    expect($classes)->toContain('kbt', 'kbt-i', 'kbt-l');

    foreach ([...$classes, 'kbt-z', 'kbt-rt', 'kbt-still'] as $class) {
        expect(str_contains($screen, $class))->toBeTrue("the preview never prints {$class}");
    }

    expect(WhatsAppButton::cssAll())->toContain(WhatsAppButton::TAB_CSS)
        ->and($screen)->toContain("tabEl.style.setProperty('--q'")
        ->and($screen)->toContain("stage.style.setProperty('--kbtw'")
        ->and(substr_count($screen, 'fetch('))->toBe(1);

    $this->actingAs(wsAdmin(), 'admin');
    $body = $this->getJson('/admin-api/whatsapp-button')->assertOk()->json();
    $tab = collect($body['tabs'])->firstWhere('key', 'tab');

    expect($tab['label'])->toBe('Cart & checkout · phone')
        ->and(array_column($tab['fields'], 'key'))->toBe(['tab_on', 'tab_size', 'tab_y', 'tab_label', 'tab_label_ar', 'tab_palette', 'tab_c1', 'tab_c2', 'tab_space', 'tab_anim'])
        ->and($body['preview']['tab']['palettes'])->toBe(WhatsAppButton::TAB_PALETTES)
        ->and($body['preview']['tab']['base'])->toBe(26)
        ->and($body['preview']['standard']['en']['tab_label'])->toBe('24/7 Support');
});
