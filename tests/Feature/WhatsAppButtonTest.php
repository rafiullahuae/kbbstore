<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Services\WhatsAppButton;
use App\Support\AdminCapabilities;
use App\Support\Locale;
use App\Support\SupportContact;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;
use Tests\Support\WhatsAppButtonRoutes;

/**
 * Appearance → WhatsApp button: the storefront render, the link, rule 5, the
 * Arabic shop and the endpoint.                                       (Lane WA)
 *
 * The owner's words, which every default below is checked against:
 *   "a floating whatsapp icon with outer layers animation type circle
 *    continues. on the bottom right side" · "by default it will be to our
 *    whatsapp" · "Chat with us capsule, but with 'available 24/7' text" ·
 *   "Hi there 👋 Welcome to K-Beauty Bliss / Need help choosing? Our beauty
 *    team is on WhatsApp, 24/7 ... this message will be sent to us by default"
 *    · "start from 30px overall size".
 *
 * routes/whatsapp-button-admin.php is required from routes/web.php by the
 * INTEGRATOR, so WhatsAppButtonRoutes mounts the real file with the real
 * admin-api middleware here. WhatsAppButtonScreenTest carries the `=== 1`
 * wiring pins — this file never asserts that anything is NOT wired.
 */
function waAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'WA '.$role,
        'email' => 'wa-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** Write raw rows behind the screen's back — the way an import or a hand edit would. */
function waRaw(array $values): void
{
    foreach ($values as $key => $value) {
        Setting::query()->updateOrCreate(['key' => 'waf_'.$key], ['value' => $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

/** The button's block out of a rendered page: <style id="kbb-wa"> to the end of its script or div. */
function waBlock(string $html): string
{
    preg_match('#<style id="kbb-wa">.*?</div>\n(?:<script>.*?</script>\n)?#s', $html, $m);

    return $m[0] ?? '';
}

function waArabic(bool $rtl = true): void
{
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => $rtl ? '1' : '0', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

beforeEach(function () {
    WhatsAppButtonRoutes::wire($this->app);
});

/* ═════════════════════════════════════ what ships, because he asked ═══ */

it('ships ON, as design G at 60px, bottom right, on the storefront', function () {
    /*
     * Rule 1 since 30 September: a thing he asked for is the shop's new state.
     * DEFECT THIS CATCHES: a lane that ships the button OFF "to be safe", and
     * the owner applying the package and seeing nothing on his shop.
     * MUTATION: SCHEMA['enabled'] default to false -> the first assertion is red.
     */
    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, '<style id="kbb-wa">'))->toBe(1)
        ->and(substr_count($html, 'id="kbbWa"'))->toBe(1)
        ->and($html)->toContain('class="kbw kbw-G"')
        ->and($html)->toContain('--k:1;--mt:auto;--mr:16px;--mb:20px;--ml:auto;--dt:auto;--dr:24px;--db:24px;--dl:auto;--spd:14s')
        // two women and three men, man first, at equal angles
        ->and(substr_count($html, '<use href="#kbwM"/>'))->toBe(3)
        ->and(substr_count($html, '<use href="#kbwW"/>'))->toBe(2)
        ->and($html)->toContain('style="--a:72deg"')
        ->and($html)->toContain('<b>Chat with us</b><small>Available 24/7</small>')
        ->and($html)->toContain('<strong>Hi there 👋 Welcome to K-Beauty Bliss</strong><span>Need help choosing? Our beauty team is on WhatsApp, 24/7.</span>');
});

it('reaches the journal, the quiz and the review wall, which do not extend the layout', function () {
    /*
     * DEFECT: the button on every page but the four that carry their own
     * <html> — and an include placed inside one of their raw blocks renders as
     * text, not as a button. Rendered here rather than read from the source.
     */
    foreach (['/skincare-guide/', '/skin-quiz/', '/reviews/'] as $path) {
        $html = $this->followingRedirects()->get($path)->assertOk()->getContent();

        expect(substr_count($html, 'id="kbbWa"'))->toBe(1, $path)
            ->and($html)->not->toContain("@include('partials.whatsapp-button')");
    }
});

it('ships every default the owner named, and size starts at 30', function () {
    $f = WhatsAppButton::normalised();

    expect($f['enabled']['default'])->toBeTrue()
        ->and($f['design']['default'])->toBe('G')
        ->and($f['size']['default'])->toBe(60)
        ->and(WhatsAppButton::SCHEMA['size'][4]['min'])->toBe(30)
        ->and(WhatsAppButton::SCHEMA['size'][4]['max'])->toBe(110)
        ->and($f['women']['default'])->toBe('2')
        ->and($f['men']['default'])->toBe('3')
        ->and($f['speed']['default'])->toBe('calm')
        ->and($f['capsule']['default'])->toBeTrue()
        ->and($f['cap1']['default'])->toBe('Chat with us')
        ->and($f['cap2']['default'])->toBe('Available 24/7')
        ->and($f['bubble']['default'])->toBe('once')
        ->and($f['welcome']['default'])->toBe('Hi there 👋 Welcome to K-Beauty Bliss')
        ->and($f['support']['default'])->toBe('Need help choosing? Our beauty team is on WhatsApp, 24/7.')
        ->and($f['link']['default'])->toBe('')
        ->and($f['show_phone']['default'])->toBeTrue()
        ->and($f['show_desktop']['default'])->toBeTrue()
        ->and([$f['m_right']['default'], $f['m_bottom']['default'], $f['d_right']['default'], $f['d_bottom']['default']])
        ->toBe(['16', '20', '24', '24'])
        ->and([$f['m_top']['default'], $f['m_left']['default'], $f['d_top']['default'], $f['d_left']['default']])
        ->toBe(['', '', '', '']);
});

/* ═════════════════════════════════════════════════════════ the link ═══ */

it('opens our WhatsApp with the two lines typed, from SupportContact and not a hardcoded number', function () {
    /*
     * DEFECT: a number typed into this module instead of read from the one the
     * rest of the shop dials — the owner changes it in Business Details and
     * the floating button keeps calling the old one.
     * MUTATION: replace SupportContact::whatsappDigits() in link() with
     * '971585052611' -> the second half is red.
     */
    $expected = 'https://wa.me/'.SupportContact::whatsappDigits()
        .'?text=Hi%20there%20%F0%9F%91%8B%20Welcome%20to%20K-Beauty%20Bliss%0ANeed%20help%20choosing%3F%20Our%20beauty%20team%20is%20on%20WhatsApp%2C%2024%2F7.';

    $html = $this->get('/')->getContent();
    expect($html)->toContain('href="'.$expected.'" target="_blank" rel="noopener"');

    Setting::query()->updateOrCreate(['key' => 'brand_whatsapp'], ['value' => '+971 50 111 2233', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();

    expect($this->get('/')->getContent())->toContain('href="https://wa.me/971501112233?text=Hi%20there');
});

it('encodes the message so emoji, a newline and an ampersand survive the trip', function () {
    /*
     * DEFECT: urlencode() (spaces as +, which WhatsApp shows literally) or no
     * encoding at all (an & in the line ends the text parameter and the rest
     * of the message is lost).
     */
    $link = app(WhatsAppButton::class)->link(
        ['link' => ''],
        ['welcome' => 'Hi & hello 👋', 'support' => 'Line #2 = ok?', 'cap1' => '', 'cap2' => '']
    );

    expect($link)->toBe('https://wa.me/'.SupportContact::whatsappDigits()
        .'?text=Hi%20%26%20hello%20%F0%9F%91%8B%0ALine%20%232%20%3D%20ok%3F');

    // Both lines empty: no `?text=` at all, rather than an empty one.
    expect(app(WhatsAppButton::class)->link(['link' => ''], ['welcome' => '', 'support' => '', 'cap1' => '', 'cap2' => '']))
        ->toBe('https://wa.me/'.SupportContact::whatsappDigits());
});

it('uses a custom https link exactly as typed, with nothing added', function () {
    waRaw(['link' => 'https://example.com/chat?ref=site']);

    $block = waBlock($this->get('/')->getContent());

    expect($block)->toContain('href="https://example.com/chat?ref=site" target="_blank" rel="noopener"')
        ->and($block)->not->toContain('wa.me');
});

it('refuses an unsafe link on save and falls back to our WhatsApp for one stored behind its back', function () {
    /*
     * Rule 5: "A URL from a setting is scheme-checked before it becomes an
     * href." Checked twice — at the door (cleanLink) and at the page (link()).
     * DEFECT: `javascript:alert(document.cookie)` as the href of a button on
     * every page of the shop.
     * MUTATION: make isSafeLink() return true -> both halves are red.
     */
    foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,<b>x</b>', 'http://example.com',
        '//evil.example', 'wa.me/971500000000', 'https://', 'https://exa mple.com', "https://x.com/\"onmouseover=alert(1)",
        "https://x.com/\nSet-Cookie:x", 'https://user@evil.example', 'https://wa.me@evil.example', 'https://wa.me:pw@evil.example',
        'https://x.com/<script>', 'https://x.com/`'] as $bad) {
        expect(WhatsAppButton::cleanLink($bad))->toBeNull($bad);
    }

    expect(WhatsAppButton::cleanLink(''))->toBe('')
        ->and(WhatsAppButton::cleanLink('  https://wa.me/971500000000  '))->toBe('https://wa.me/971500000000');

    waRaw(['link' => 'javascript:alert(1)']);
    $block = waBlock($this->get('/')->getContent());

    expect($block)->not->toContain('javascript:')
        ->and($block)->toContain('href="https://wa.me/'.SupportContact::whatsappDigits().'?text=');
});

/* ═══════════════════════════════════════════════ rule 5: escaping ═══ */

it('prints every line the owner types escaped, never as markup', function () {
    /*
     * DEFECT: a `{!! !!}` on a line — a `<script>` typed into the welcome box
     * runs on every page of the shop.
     * MUTATION: print the welcome line with {!! !!} in the partial -> red.
     */
    waRaw([
        'welcome' => '<script>alert(1)</script>',
        'support' => '"><img src=x onerror=alert(2)>',
        'cap1' => '<b onclick=x>Chat</b>',
        'cap2' => "'quoted' & <i>",
    ]);

    $block = waBlock($this->get('/')->getContent());

    expect($block)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($block)->toContain('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;')
        ->and($block)->toContain('&lt;b onclick=x&gt;Chat&lt;/b&gt;')
        ->and($block)->not->toContain('<script>alert(1)')
        ->and($block)->not->toContain('<img src=x')
        // and in the link it is URL-encoded text, not markup
        ->and($block)->toContain('?text=%3Cscript%3Ealert%281%29%3C%2Fscript%3E%0A');

    // Exactly one <script> in the block: the bubble's own.
    expect(substr_count($block, '<script'))->toBe(1);
});

it('prints raw only the constants of WhatsAppButton', function () {
    /*
     * The partial's unescaped echoes, counted and named. A fifth one is a new
     * raw print site that has to be argued for here.
     *
     * The fourth (Lane WS) is the bubble script's guard: '' on every page but
     * the cart and the checkout, and WhatsAppButton::TAB_BUBBLE_GUARD there —
     * a constant either way.
     */
    $src = (string) file_get_contents(resource_path('views/partials/whatsapp-button.blade.php'));
    preg_match_all('/\{!!\s*(.*?)\s*!!\}/', $src, $raw);

    expect($raw[1])->toBe(["\$kbbWa['css']", "\$kbbWa['symbols']", "\$kbbWa['icon']", "\$kbbWa['icon']", "\$kbbWa['guard']"]);

    $v = app(WhatsAppButton::class)->view();
    expect($v['css'])->toBe(WhatsAppButton::css('G'))
        ->and($v['icon'])->toBe(WhatsAppButton::ICON)
        ->and($v['symbols'])->toBe(WhatsAppButton::SYMBOLS['M'].WhatsAppButton::SYMBOLS['W'])
        ->and($v['guard'])->toBe('')
        ->and(app(WhatsAppButton::class)->view(null, null, true)['guard'])->toBe(WhatsAppButton::TAB_BUBBLE_GUARD);
});

/* ═══════════════════════════════════════════════ off, and per device ═══ */

it('prints not one byte when it is switched off', function () {
    /*
     * DEFECT: an "off" that still ships the stylesheet, or an empty wrapper.
     * MUTATION: drop the enabled check from view() -> red.
     */
    waRaw(['enabled' => '0']);

    $html = $this->get('/')->getContent();

    expect($html)->not->toContain('kbb-wa')
        ->and($html)->not->toContain('kbw')
        ->and($html)->not->toContain(WhatsAppButton::BUBBLE_KEY);
});

it('hides per device with one class each, and prints nothing when both are off', function () {
    waRaw(['show_phone' => '0']);
    expect(waBlock($this->get('/')->getContent()))->toContain('class="kbw kbw-G kbw-mo"');

    waRaw(['show_phone' => '1', 'show_desktop' => '0']);
    expect(waBlock($this->get('/')->getContent()))->toContain('class="kbw kbw-G kbw-do"');

    // And the stylesheet hides each in its own media query, no wider.
    $css = WhatsAppButton::css('G');
    expect($css)->toContain('@media (max-width:900px){')
        ->and($css)->toMatch('/@media \(max-width:900px\)\{[^@]*\.kbw\.kbw-mo\{display:none\}/')
        ->and($css)->toMatch('/@media \(min-width:901px\)\{[^@]*\.kbw\.kbw-do\{display:none\}/');

    waRaw(['show_phone' => '0', 'show_desktop' => '0']);
    expect($this->get('/')->getContent())->not->toContain('kbw');
});

it('leaves the bubble and its script off the page when the bubble is off', function () {
    waRaw(['bubble' => 'off']);

    $block = waBlock($this->get('/')->getContent());

    expect($block)->toContain('id="kbbWa"')
        ->and($block)->not->toContain('class="kbw-b"')
        ->and($block)->not->toContain('.kbw-b{position')  // nor the bubble's stylesheet
        ->and($block)->not->toContain('<script');
});

/* ══════════════════════════════════════════ rule 5: selects and numbers ═══ */

it('clamps the offsets, keeps empty as auto, and refuses words', function () {
    expect(WhatsAppButton::cleanOffset('999'))->toBe('400')
        ->and(WhatsAppButton::cleanOffset('-20'))->toBe('0')
        ->and(WhatsAppButton::cleanOffset('12px'))->toBe('12')
        ->and(WhatsAppButton::cleanOffset(' 7.6 '))->toBe('8')
        ->and(WhatsAppButton::cleanOffset(''))->toBe('')
        ->and(WhatsAppButton::cleanOffset(null))->toBe('')
        ->and(WhatsAppButton::cleanOffset('auto'))->toBe('')
        ->and(WhatsAppButton::cleanOffset('10;top:0'))->toBeNull()
        ->and(WhatsAppButton::cleanOffset('calc(100vh)'))->toBeNull()
        ->and(WhatsAppButton::cleanOffset(['1']))->toBeNull();

    /*
     * And at render, for a row written behind the screen's back: a value that
     * would break out of the style attribute never reaches it.
     * MUTATION: print the stored value in placement() without cleanOffset()
     * -> the style carries `;top:0` and this is red.
     */
    waRaw(['m_right' => '9999', 'm_bottom' => '10;top:0', 'd_left' => '-5', 'd_right' => '']);

    $block = waBlock($this->get('/')->getContent());

    preg_match('/<div class="kbw[^"]*" style="([^"]*)"/', $block, $style);

    expect($style[1])->toContain('--mr:400px;--mb:20px')
        ->and($style[1])->toContain('--dr:auto;--db:24px;--dl:0px')
        ->and($style[1])->not->toContain('top:0')
        ->and($style[1])->toMatch('/^--k:[0-9.]+(;--[md][trbl]:(auto|\d+px))+;--spd:\d+s$/')
        ->and($block)->toContain('kbw-dl');
});

it('puts the button on the left when Left is filled and Right is empty, and Right wins when both are', function () {
    $p = WhatsAppButton::placement(['m_right' => '', 'm_left' => '30', 'm_bottom' => '', 'm_top' => '100',
        'd_right' => '10', 'd_left' => '50', 'd_bottom' => '', 'd_top' => ''], false);

    expect($p['m'])->toBe(['t' => 100, 'r' => null, 'b' => null, 'l' => 30, 'left' => true, 'top' => true])
        ->and($p['d'])->toBe(['t' => null, 'r' => 10, 'b' => 24, 'l' => null, 'left' => false, 'top' => false]);

    // Everything blank: the shipped bottom-right, never an unanchored button.
    $blank = WhatsAppButton::placement([], false);
    expect($blank['m'])->toBe(['t' => null, 'r' => 16, 'b' => 20, 'l' => null, 'left' => false, 'top' => false]);
});

it('refuses a design that is not one of its seven, and draws G for a stored one', function () {
    /*
     * "A select stores one of its own options or the default."
     * MUTATION: drop `'invalid' => 'reject'` from POLICY -> the endpoint
     * answers 200 and stores nothing useful; the 422 assertion is red.
     */
    $this->actingAs(waAdmin(), 'admin');

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['design' => 'Z']])
        ->assertStatus(422)
        ->assertJsonPath('rejected', ['design']);

    expect(app(WhatsAppButton::class)->all()['design'])->toBe('G');

    waRaw(['design' => '<x>', 'speed' => 'warp', 'women' => '9', 'size' => '5000']);
    $block = waBlock($this->get('/')->getContent());

    expect($block)->toContain('class="kbw kbw-G"')
        ->and($block)->toContain('--k:1.8333;')     // a range CLAMPS: 5000 is pulled to 110
        ->and($block)->toContain('--spd:14s')
        ->and(substr_count($block, '<use href="#kbwW"/>'))->toBe(2);
});

it('clamps the size bar to 30-110 and scales everything by one number', function () {
    $this->actingAs(waAdmin(), 'admin');

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['size' => 5]])->assertOk();
    expect(app(WhatsAppButton::class)->all()['size'])->toBe(30);
    expect(waBlock($this->get('/')->getContent()))->toContain('--k:0.5;');

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['size' => 500]])->assertOk();
    expect(app(WhatsAppButton::class)->all()['size'])->toBe(110);
    expect(waBlock($this->get('/')->getContent()))->toContain('--k:1.8333;');
});

/* ══════════════════════════════════════════ super light, measured ═══ */

it('ships only the design in use, and the reduced-motion branch', function () {
    $html = $this->get('/')->getContent();

    expect($html)->toContain('.kbw-G .kbw-o{')
        ->and($html)->not->toContain('.kbw-B ')
        ->and($html)->not->toContain('.kbw-D ')
        ->and($html)->toContain('@media (prefers-reduced-motion:reduce){.kbw,.kbw *{animation:none!important;transition:none!important}}');

    foreach (array_keys(WhatsAppButton::DESIGNS) as $d) {
        expect(WhatsAppButton::css($d))->toContain('prefers-reduced-motion:reduce');
    }
});

it('rises above the phone bars, and names the squeezed cart only while that layout is on', function () {
    /*
     * DEFECT (measured in the first full run): the cart lift was printed on
     * every page, so the CLASSIC shop carried the words `cpg-squeeze` and
     * `cpg-docked` and CartPageSqueezeTest's "none of the squeezed furniture
     * exists" went red. And the E/G nudge was written translateX(0), which put
     * a "(0)" on every page — the string OneProductTileTest reads as an empty
     * review count.
     * MUTATION: print LIFT_CART unconditionally in css() -> the first half is red.
     */
    $html = $this->get('/')->getContent();

    expect($html)->not->toContain('cpg-')
        ->and(waBlock($html))->not->toContain('(0)')
        ->and($html)->toContain('body:has(#stickybar.show) .kbw')
        ->and($html)->toContain('body:has(.mpbar.is-on) .kbw');

    Setting::query()->updateOrCreate(['key' => 'cartpage_layout'], ['value' => 'squeeze', 'autoload' => true]);
    waRaw([]);

    expect($this->get('/')->getContent())->toContain('body:has(.cpg-squeeze .cpg-docked) .kbw{margin-bottom:106px}');
});

it('costs a page no query', function () {
    /*
     * Rule 4 and the "super light" rule: measured, not asserted. The same page
     * with the button on and off runs the same number of queries, because
     * every value comes out of the settings map the page has already loaded.
     * MUTATION: read one value with Setting::query() in all() -> red.
     */
    $count = function (): int {
        SettingsService::forgetMemo();
        $n = 0;
        DB::listen(function () use (&$n) { $n++; });
        $this->get('/')->assertOk();

        return $n;
    };

    waRaw(['enabled' => '0']);
    $this->get('/');
    $off = $count();

    waRaw(['enabled' => '1']);
    $this->get('/');
    $on = $count();

    expect($on)->toBe($off);
});

it('runs no request, no timer and no layout measurement in the shop script', function () {
    /*
     * The project sizes with calc() and two tests forbid the element-measuring
     * APIs by name. The only script here is the bubble's "show once".
     */
    $src = (string) file_get_contents(resource_path('views/partials/whatsapp-button.blade.php'));
    preg_match_all('#<script>(.*?)</script>#s', $src, $scripts);

    expect($scripts[1])->toHaveCount(1);
    $js = $scripts[1][0];

    foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'setInterval', 'setTimeout', 'requestAnimationFrame',
        'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'clientHeight', 'scrollHeight',
        'getComputedStyle', 'ResizeObserver', 'IntersectionObserver', 'innerHTML', 'eval('] as $banned) {
        expect(str_contains($js, $banned))->toBeFalse("the storefront script uses {$banned}");
    }

    // Every touch of localStorage is inside the try.
    $try = substr($js, (int) strpos($js, 'try{'), (int) strpos($js, '}catch(e){}') - (int) strpos($js, 'try{'));
    expect(substr_count($js, 'localStorage'))->toBe(substr_count($try, 'localStorage'))
        ->and(substr_count($js, 'localStorage'))->toBe(2);
});

/* ═══════════════════════════════════════════════════ the Arabic shop ═══ */

it('speaks Arabic on the Arabic shop and mirrors to the bottom left', function () {
    /*
     * DEFECT: the English welcome on /ar/, and a button that sits over the
     * right-hand edge an Arabic reader starts from.
     * MUTATION: drop `$mirror` from placement() -> the side assertions are red.
     */
    waArabic(true);
    ArabicShop::string('store.whatsapp.capsule_title', 'تحدّث معنا');
    ArabicShop::string('store.whatsapp.open_label', 'تحدّث معنا على واتساب');
    waRaw(['welcome_ar' => 'أهلًا بك', 'support_ar' => 'فريقنا هنا']);
    TranslationStore::flush();

    $block = waBlock($this->get('/ar/')->assertOk()->getContent());

    expect($block)->toContain('<strong>أهلًا بك</strong><span>فريقنا هنا</span>')
        ->and($block)->toContain('<b>تحدّث معنا</b>')
        ->and($block)->toContain('aria-label="تحدّث معنا على واتساب"')
        ->and($block)->toContain('class="kbw kbw-G kbw-ml kbw-dl"')
        ->and($block)->toContain('--mr:auto;--mb:20px;--ml:16px')
        ->and($block)->toContain('--dr:auto;--db:24px;--dl:24px')
        // the message sent is the Arabic the page shows
        ->and($block)->toContain('?text='.rawurlencode("أهلًا بك\nفريقنا هنا").'"');

    // And the English shop is untouched by any of it.
    $en = waBlock($this->get('/')->getContent());
    expect($en)->toContain('class="kbw kbw-G"')->and($en)->toContain('Hi there 👋');
});

it('keeps the same side on the Arabic shop when asked to, or while the mirrored layout is off', function () {
    waArabic(true);
    waRaw(['ar_side' => 'same']);
    expect(waBlock($this->get('/ar/')->getContent()))->toContain('class="kbw kbw-G"');

    waRaw(['ar_side' => 'mirror']);
    waArabic(false);
    expect(waBlock($this->get('/ar/')->getContent()))->toContain('class="kbw kbw-G"');
});

it('hides a line on the Arabic shop that the owner emptied in English', function () {
    waArabic(true);
    waRaw(['cap2' => '', 'support' => '']);

    $block = waBlock($this->get('/ar/')->getContent());

    expect($block)->not->toContain('<small>')
        ->and($block)->not->toContain('<span>Need help');
});

it('ships every fixed string with an Arabic draft', function () {
    $drafts = \App\Services\Translation\ArabicInterfaceDrafts::all();
    $english = \App\Services\Translation\InterfaceStrings::flat();

    foreach (WhatsAppButton::KEYS as $key) {
        expect($english)->toHaveKey($key)
            ->and($drafts)->toHaveKey($key)
            ->and(preg_match('/\p{Arabic}/u', $drafts[$key]))->toBe(1, $key);
    }
});

/* ══════════════════════════════════════════════════════ the endpoint ═══ */

it('maps both verbs to its own capability, held by the Appearance roles', function () {
    foreach ([['GET', 'admin-api/whatsapp-button'], ['POST', 'admin-api/whatsapp-button']] as [$verb, $path]) {
        expect(AdminCapabilities::forPath($verb, $path))->toBe('wabutton.manage', $verb.' '.$path);
    }

    expect(AdminCapabilities::CAPABILITIES['wabutton.manage'])
        ->toBe(AdminCapabilities::CAPABILITIES['pagewash.manage']);
});

it('refuses both verbs to an account without the capability, and writes nothing', function () {
    $this->actingAs(waAdmin('support'), 'admin');

    $this->getJson('/admin-api/whatsapp-button')->assertStatus(403);
    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['enabled' => false]])->assertStatus(403);

    expect(app(WhatsAppButton::class)->all()['enabled'])->toBeTrue();
});

it('answers the screen with five tabs, every field, and what the preview is built from', function () {
    // Lane WS added the fifth, "Cart & checkout · phone" (the side tab).
    $this->actingAs(waAdmin(), 'admin');

    $body = $this->getJson('/admin-api/whatsapp-button')->assertOk()->json();

    expect(array_column($body['tabs'], 'key'))->toBe(['design', 'position', 'message', 'capsule', 'tab'])
        ->and(array_column($body['tabs'], 'label'))->toBe(['Design', 'Position', 'Message', 'Capsule & bubble', 'Cart & checkout · phone']);

    $keys = [];
    foreach ($body['tabs'] as $tab) {
        foreach ($tab['fields'] as $field) {
            $keys[] = $field['key'];
        }
    }
    sort($keys);
    $schema = array_keys(WhatsAppButton::SCHEMA);
    sort($schema);
    expect($keys)->toBe($schema);

    expect($body['preview']['css'])->toBe(WhatsAppButton::cssAll())
        ->and($body['preview']['icon'])->toBe(WhatsAppButton::ICON)
        ->and($body['preview']['digits'])->toBe(SupportContact::whatsappDigits())
        ->and($body['preview']['standard']['en']['welcome'])->toBe('Hi there 👋 Welcome to K-Beauty Bliss');
});

it('saves and reads back every field it drew', function () {
    $this->actingAs(waAdmin(), 'admin');

    $values = [
        'enabled' => true, 'show_phone' => false, 'show_desktop' => true, 'design' => 'B', 'size' => 44,
        'women' => '3', 'men' => '0', 'speed' => 'lively',
        'm_top' => '', 'm_right' => '', 'm_bottom' => '90', 'm_left' => '12',
        'd_top' => '40', 'd_right' => '30', 'd_bottom' => '', 'd_left' => '',
        'ar_side' => 'same', 'link' => 'https://wa.me/971500000000',
        'welcome' => 'Hello', 'welcome_ar' => 'مرحبا', 'support' => 'Ask us', 'support_ar' => 'اسألنا',
        'capsule' => false, 'cap1' => 'Talk', 'cap1_ar' => 'تحدث', 'cap2' => '', 'cap2_ar' => '', 'bubble' => 'off',
        // Lane WS: the side tab.
        'tab_on' => false, 'tab_size' => 34, 'tab_y' => 30, 'tab_label' => 'Help', 'tab_label_ar' => 'مساعدة',
        'tab_palette' => 'custom', 'tab_c1' => '#FFF6C7', 'tab_c2' => '#DDF3FF', 'tab_anim' => false,
    ];

    $this->postJson('/admin-api/whatsapp-button', ['settings' => $values])->assertOk()->assertJsonPath('saved', count($values));

    expect(app(WhatsAppButton::class)->all())->toEqual($values);

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['nonsense' => 1]])->assertStatus(422);
    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['link' => 'javascript:alert(1)']])
        ->assertStatus(422)->assertJsonPath('rejected', ['link']);
    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['m_top' => 'abc']])
        ->assertStatus(422)->assertJsonPath('rejected', ['m_top']);
});

it('saves nothing at all when one box is refused', function () {
    /*
     * DEFECT: the first draft wrote every good box and THEN reported "Not
     * saved — check: Your own link" — so the owner's new size was live on the
     * shop under a message telling him nothing had been saved.
     * MUTATION: write inside the cast loop again -> size reads 44 and this is red.
     */
    $this->actingAs(waAdmin(), 'admin');

    $this->postJson('/admin-api/whatsapp-button', ['settings' => ['size' => 44, 'link' => 'javascript:alert(1)']])
        ->assertStatus(422)->assertJsonPath('rejected', ['link']);

    expect(app(WhatsAppButton::class)->all()['size'])->toBe(60)
        ->and(Setting::query()->where('key', 'waf_size')->exists())->toBeFalse();
});

it('is enrolled on the shared module schema without a field the endpoint would drop', function () {
    // Every TABS key is a SCHEMA key and every SCHEMA key is in exactly one tab.
    $inTabs = [];
    foreach (WhatsAppButton::TABS as [, , $keys]) {
        array_push($inTabs, ...$keys);
    }

    expect(count($inTabs))->toBe(count(array_unique($inTabs)))
        ->and(array_diff(array_keys(WhatsAppButton::SCHEMA), $inTabs))->toBe([])
        ->and(array_diff($inTabs, array_keys(WhatsAppButton::SCHEMA)))->toBe([]);

    // Every default casts to itself, so "Back to defaults" saves cleanly.
    foreach (WhatsAppButton::normalised() as $key => $field) {
        expect(ModuleSchema::cast($field, $field['default']))->toBe($field['default'], $key);
    }
});
