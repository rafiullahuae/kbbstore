<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\SiteApp;
use App\Services\SiteFooter;
use App\Support\FooterPages;
use App\Support\Locale;
use App\Support\QrCode;
use App\Services\Translation\TranslationStore;
use Illuminate\Support\Facades\DB;

/*
 * THE FOOTER'S APP ROW (Lane FB, 6 October).
 *
 * The owner, choosing option D of docs/fa-preview: "write something, Get
 * orders update, restock alerts & coupons … the frosted glass design is fine,
 * but i don't want to cover the logo, it should downside the big logo, also
 * the icons i need colorful as official" — and then: "when the install app row
 * appear on screen, the floating whatsapp stuff must hide super instantly …
 * the app install content need to change continues, like a writing styline …
 * one line in english, then one line in arabic, and so on."
 *
 * Every test here names the defect it would catch and the mutation that turns
 * it red.
 */

function farSet(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? '1' : '0') : $value), 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function farFooter(array $values): void
{
    app(SiteFooter::class)->save($values);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function farJs(): string
{
    $src = (string) file_get_contents(resource_path('site-app/site-app.js'));
    $start = strpos($src, "THE FOOTER'S APP ROW");
    $end = strpos($src, "var s = document.currentScript;");

    return $start !== false && $end !== false ? substr($src, $start, $end - $start) : '';
}

function farCss(): string
{
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $start = strpos($css, 'THE APP ROW');

    return $start === false ? '' : substr($css, $start, 4000);
}

it('prints the row once, under the big name and above the © row', function () {
    /* DEFECT: the row over the wordmark (what the owner refused — "i don't want
       to cover the logo"), or under the © row, where the floating WhatsApp
       button sits on top of Install on a phone (docs/fa-preview, wa-below-390).
       MUTATION: move the @if block in footer-bliss above `<p class="kft-name"`
       or below the .kft-bot div -> the order line is red; include it twice ->
       the count is red. */
    $html = (string) $this->get('/')->assertOk()->getContent();

    $name = strpos($html, 'class="kft-name"');
    $row = strpos($html, '<section class="kfa"');
    $bot = strpos($html, '<div class="kft-bot">');

    expect(substr_count($html, '<section class="kfa'))->toBe(1)
        ->and($name)->toBeInt()->and($row)->toBeInt()->and($bot)->toBeInt()
        ->and($name < $row && $row < $bot)->toBeTrue()
        // The shipped copy, built on the owner's own words.
        ->and($html)->toContain('<b id="kfa-h">Get the K-Beauty Bliss app</b>')
        ->and($html)->toContain('lang="en" dir="ltr">Order updates, straight to your phone.</span>')
        ->and($html)->toContain('<span>Install App</span>' /* 6 Oct: the owner renamed it */)
        // The three icons, each with its own name, in their official colours.
        ->and($html)->toContain('aria-label="iPhone"')->and($html)->toContain('aria-label="Android"')->and($html)->toContain('aria-label="iPad"')
        ->and($html)->toContain('fill="#3DDC84"')->and($html)->toContain('fill="#000"');
});

it('is not printed at all when App → Site App is off, the switch is off, or the classic footer is chosen', function () {
    /* DEFECT: an Install button on a shop with no manifest and no script — a
       tap that does nothing. MUTATION: drop `! app(SiteApp::class)->on()` from
       SiteFooter::app() -> the first expectation is red; drop the
       `site_app_on` test -> the second is red. */
    app(SiteApp::class)->save(['on' => false]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    expect((string) $this->get('/')->getContent())->not->toContain('class="kfa');

    app(SiteApp::class)->save(['on' => true]);
    farFooter(['site_app_on' => false]);
    expect((string) $this->get('/')->getContent())->not->toContain('class="kfa');

    farFooter(['site_app_on' => true, 'site_design' => 'classic']);
    expect((string) $this->get('/')->getContent())->not->toContain('class="kfa');

    farFooter(['site_design' => 'bliss']);
    expect((string) $this->get('/')->getContent())->toContain('<section class="kfa"');
});

it('hides on laptops unless the switch is on, and then carries a QR of the shop that the shop drew', function () {
    /* DEFECT: the row on every laptop (the owner did not pick the QR option),
       or the switch doing nothing. MUTATION: delete the
       `@media (min-width:1024px) and (pointer:fine)` rule -> red; print
       `kfa-lt` unconditionally -> the first expectation is red. */
    $html = (string) $this->get('/')->getContent();

    expect($html)->toContain('<section class="kfa" ')
        ->and($html)->not->toContain('data-s="qr"')
        ->and(farCss())->toContain('@media (min-width:1024px) and (pointer:fine){.kfa:not(.kfa-lt){display:none}}');

    farFooter(['site_d_app_laptop' => true]);
    $html = (string) $this->get('/')->getContent();

    expect($html)->toContain('<section class="kfa kfa-lt" ')
        ->and($html)->toContain('<div data-s="qr">')
        ->and($html)->toMatch('#<span class="kfa-qr"><svg viewBox="0 0 \d+ \d+" shape-rendering="crispEdges" role="img" aria-label="Scan with your phone camera"><path fill="\#fff" d="M0 0h\d+v\d+H0z"/><path fill="\#000" d="(M\d+ \d+h\d+v1h-\d+z)+"/></svg></span>#');
});

it('escapes everything typed in the console, in the text, the data attribute and the hidden copy', function () {
    /* DEFECT: a headline or line printed raw. MUTATION: print the headline
       with {!! !!}, or the lines' JSON unescaped -> red. */
    farFooter([
        'site_app_title' => '<img src=x onerror=alert(1)>Hi',
        'site_app_button' => '"><script>alert(2)</script>',
        'site_app_lines' => "<b>Bold</b> & \"quoted\" line\n'><svg onload=alert(3)>",
    ]);

    $html = (string) $this->get('/')->getContent();
    preg_match('#<section class="kfa".*?</section>#s', $html, $m);
    $row = $m[0] ?? '';

    expect($row)->not->toBe('')
        ->and($row)->not->toContain('<img src=x')
        ->and($row)->not->toContain('<script>alert')
        ->and($row)->not->toContain('<svg onload')
        ->and($row)->not->toContain('<b>Bold')
        ->and($row)->toContain('Bold &amp; &quot;quoted&quot; line')
        ->and($row)->toContain('data-kfa="[[0,&quot;Bold &amp; \&quot;quoted\&quot; line&quot;]');
});

it('cleans the typing-line boxes: plain text, one per row, at most six of sixty characters', function () {
    /* MUTATION: return $raw from cleanLines() -> red. */
    $long = str_repeat('a', 80);
    $clean = SiteFooter::cleanLines("  one  \n\n<i>two</i>\n{$long}\n4\n5\n6\n7\n8");

    expect($clean)->toBe("one\ntwo\n".str_repeat('a', 60)."\n4\n5\n6")
        ->and(SiteFooter::cleanLines(['not', 'text']))->toBe('')
        // An emptied box is the shipped lines, not an empty row.
        ->and(SiteFooter::lines(''))->toBe([]);

    farFooter(['site_app_lines' => '', 'site_d_app_laptop' => 'yes please']);
    $c = app(SiteFooter::class)->all();
    expect($c['site_app_lines'])->toBe('')
        ->and($c['site_d_app_laptop'])->toBeBool();
});

it('alternates English and Arabic lines, starting with the page’s own language', function () {
    /* DEFECT: all English then all Arabic, or the Arabic page opening in
       English. MUTATION: build $seq as array_merge($first, $second) -> red. */
    $en = app(SiteFooter::class)->app(app(SiteFooter::class)->all());
    $langs = array_column(json_decode($en['seq'], true), 0);

    expect($langs)->toBe([0, 1, 0, 1, 0, 1, 0, 1])
        ->and($en['first'])->toBe([0, 'Order updates, straight to your phone.']);

    farSet(Locale::SETTING_ENABLED, '1');
    TranslationStore::flush();
    $html = (string) $this->get('/ar/')->getContent();

    expect($html)->toContain('lang="ar" dir="rtl">تحديثات طلبكِ مباشرةً على هاتفكِ.</span>')
        ->and($html)->toContain('data-kfa="[[1,&quot;تحديثات')
        // Screen readers get the page's own lines once, from the hidden copy.
        ->and($html)->toContain('<span class="kfa-vh">تحديثات طلبكِ');
});

/**
 * The row's script in node, with a browser stubbed around it: a fake clock,
 * a captured IntersectionObserver and a switchable document.hidden.
 */
function farRun(bool $reduce): array
{
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        test()->markTestSkipped('node is not installed here');
    }

    $src = json_encode((string) file_get_contents(resource_path('site-app/site-app.js')));
    $seq = json_encode(json_encode([[0, 'Hello'], [1, 'مرحبا'], [0, 'Glow'], [1, 'تألقي']], JSON_UNESCAPED_UNICODE));
    $reduceJs = $reduce ? 'true' : 'false';
    $js = <<<JS
const vm = require('vm');
function cl() { const s = new Set(); return { s, toggle(c, on) { if (on === undefined) on = !s.has(c); on ? s.add(c) : s.delete(c); return on; }, contains: (c) => s.has(c), remove: (c) => s.delete(c), add: (c) => s.add(c) }; }
const dirs = [];
const ty = { textContent: 'Hello', attrs: {}, setAttribute(k, v) { this.attrs[k] = v; if (k === 'dir') dirs.push(v); } };
const row = { hidden: false, classList: cl(), attrs: { 'data-kfa': {$seq} }, getAttribute(k) { return this.attrs[k]; },
  querySelector(q) { return q === '.kfa-ty' ? ty : null; } };
const html = { classList: cl(), getAttribute: () => 'ltr' };
const docOn = {};
const document = { currentScript: null, hidden: false, documentElement: html, querySelector: (q) => (q === '.kfa[data-kfa]' ? row : null),
  addEventListener(t, f) { docOn[t] = f; } };
const timers = new Map(); let tid = 0;
const setTimeout = (f, ms) => { const id = ++tid; timers.set(id, f); return id; };
const clearTimeout = (id) => { timers.delete(id); };
const tick = () => { const e = timers.entries().next().value; if (!e) return false; timers.delete(e[0]); e[1](); return true; };
let io = null;
class IntersectionObserver { constructor(cb, o) { io = cb; this.o = o; } observe() {} }
const window = { matchMedia: (q) => ({ matches: {$reduceJs} && q.indexOf('reduce') !== -1 }), addEventListener() {}, IntersectionObserver };
const navigator = { userAgent: 'x' };
vm.runInContext({$src}, vm.createContext({ document, window, navigator, setTimeout, clearTimeout, JSON, Array, String, IntersectionObserver }));
const out = { timersBeforeView: timers.size };
io([{ isIntersecting: true }]);
out.waHiddenInView = html.classList.contains('kfa-on');
out.timersInView = timers.size;
const finished = [];
for (let i = 0; i < 400 && tick(); i++) { if (finished[finished.length - 1] !== ty.textContent && ['Hello', 'مرحبا', 'Glow', 'تألقي'].includes(ty.textContent)) finished.push(ty.textContent); }
out.finished = finished; out.dirs = dirs;
io([{ isIntersecting: false }]);
out.timersOutOfView = timers.size; out.waBack = !html.classList.contains('kfa-on'); out.runClassOut = row.classList.contains('kfa-run');
io([{ isIntersecting: true }]);
document.hidden = true; docOn.visibilitychange();
out.timersTabHidden = timers.size;
console.log(JSON.stringify(out));
JS;
    $file = storage_path('framework/testing/far-'.getmypid().'-'.bin2hex(random_bytes(3)).'.cjs');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, $js);
    $raw = (string) shell_exec(escapeshellarg($node).' '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    return json_decode(trim((string) strrchr("\n".trim($raw), "\n")), true) ?? ['raw' => $raw];
}

it('types only while the row is on screen and the tab is visible, alternating the languages, and hides WhatsApp meanwhile', function () {
    /* DEFECT: a typing timer that never stops (the owner: "super light"), or
       WhatsApp sitting over the Install button. MUTATION: drop the clearTimeout
       in sync() -> timersOutOfView is 1; drop the visibilitychange listener ->
       timersTabHidden is 1; drop html.classList.toggle('kfa-on') -> red. */
    $r = farRun(false);

    expect($r)->toHaveKey('finished')
        ->and($r['timersBeforeView'])->toBe(0)
        ->and($r['waHiddenInView'])->toBeTrue()
        ->and($r['timersInView'])->toBe(1)
        ->and(array_slice($r['finished'], 0, 4))->toBe(['Hello', 'مرحبا', 'Glow', 'تألقي'])
        ->and(array_slice($r['dirs'], 0, 4))->toBe(['ltr', 'rtl', 'ltr', 'rtl'])
        ->and($r['timersOutOfView'])->toBe(0)
        ->and($r['waBack'])->toBeTrue()
        ->and($r['runClassOut'])->toBeFalse()
        ->and($r['timersTabHidden'])->toBe(0);
});

it('never types for a visitor who asks for reduced motion, and still steps WhatsApp aside', function () {
    /* MUTATION: drop `!still` from live() -> timersInView is 1. */
    $r = farRun(true);

    expect($r['timersInView'])->toBe(0)
        ->and($r['waHiddenInView'])->toBeTrue()
        ->and($r['finished'])->toBe([])
        ->and(farCss())->toContain('@media (prefers-reduced-motion:reduce){.kfa-run .kfa-ty::after{display:none}}');
});

it('hides itself in the installed app, measures nothing, listens to no scroll, and hides WhatsApp with no fade', function () {
    /* DEFECT: an Install button inside the installed app; a layout read; a
       scroll handler. MUTATION: delete the display-mode rule -> red; add a
       getBoundingClientRect to the row script -> red. */
    $js = farJs();
    $css = farCss();

    expect($js)->not->toBe('')
        ->and($css)->toContain('@media (display-mode:standalone),(display-mode:fullscreen){.kfa{display:none}}')
        ->and($css)->toContain('html.kfa-on #kbbWa{visibility:hidden!important;opacity:0!important;transition:none!important;pointer-events:none!important}')
        ->and($js)->toContain('nav.standalone === true')
        ->and(substr_count($js, 'new IntersectionObserver('))->toBe(1)
        ->and($js)->toContain("addEventListener('visibilitychange', sync)")
        ->and($js)->toContain("addEventListener('beforeinstallprompt'")
        ->and($js)->toContain('o.prompt();');

    foreach (['requestFullscreen', 'getBoundingClientRect', 'offsetHeight', 'offsetWidth', 'clientHeight', 'scrollHeight',
        'getComputedStyle', 'ResizeObserver', 'setInterval', 'requestAnimationFrame', "'scroll'", 'fetch('] as $banned) {
        expect(str_contains($js, $banned))->toBeFalse("the row script uses {$banned}");
    }
});

it('costs no query: the row reads the settings snapshot the page already has', function () {
    /* DEFECT: a page that pays a query for the row. MUTATION: read the lines
       with a fresh Setting::query() -> the counts differ. */
    $this->get('/');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/');
    $on = count(DB::getQueryLog());

    farFooter(['site_app_on' => false]);
    $this->get('/');
    DB::flushQueryLog();
    $this->get('/');
    $off = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($on)->toBe($off);
});

it('draws the laptop switch on the Desktop page only, and the rest on both', function () {
    /* MUTATION: drop the device filter from FooterPages::sections() ->
       site_d_app_laptop is on the Mobile page. */
    expect(FooterPages::keys('site-d'))->toContain('site_d_app_laptop')->toContain('site_app_lines_ar')
        ->and(FooterPages::keys('site-m'))->not->toContain('site_d_app_laptop')->toContain('site_app_on');
});

it('draws QR codes module for module as an independent encoder does', function () {
    /* The fixtures are md5s of matrices made by segno 1.6.6 (level M, byte
       mode, the mask forced), in a scratch environment — not a dependency.
       MUTATION: change 0x5412 or 0x537 in format() -> every line is red; swap
       two ALIGN entries -> red. */
    $md5 = static fn (string $t, int $mask): string => md5(implode("\n", array_map(
        static fn (array $r): string => implode('', array_map(static fn (bool $b): string => $b ? '1' : '0', $r)),
        QrCode::matrix($t, $mask) ?? [],
    )));

    expect($md5('https://extrabeauty.ae/', 0))->toBe('813347b2368902fc5dfd7bba7392f8ee')
        ->and($md5('https://extrabeauty.ae/', 7))->toBe('a125da8c723b485432f0bfc1105e4b76')
        ->and($md5('https://extrabeauty.ae/ar/', 4))->toBe('2f0940a3c84839fff6931c3744b7d0a2')
        ->and(count(QrCode::matrix('https://extrabeauty.ae/') ?? []))->toBe(25)
        ->and(QrCode::matrix(str_repeat('a', QrCode::MAX_BYTES + 1)))->toBeNull()
        ->and(count(QrCode::matrix(str_repeat('a', QrCode::MAX_BYTES)) ?? []))->toBe(57);
});
