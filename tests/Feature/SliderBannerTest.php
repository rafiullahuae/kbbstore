<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Setting;
use App\Services\Banners;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;

/**
 * The picture slider — Phase 22 round 8, Lane BN2.
 *
 * The owner: "I want another banner type with only images slider with proper
 * beautiful left right arrows. and thin bars the bottom of the banner to
 * control all sliders."
 *
 * ── THE TWO THINGS THIS FILE EXISTS FOR ABOVE ALL OTHERS ────────────────────
 *
 * 1. NOTHING IN THE SLIDER MEASURES LAYOUT. CLAUDE.md rule 4 names the
 *    element-measuring APIs and a carousel is the single commonest place they
 *    get reached for. This section has a script — the first storefront section
 *    that does — and the first case below is what keeps that script to ONE
 *    INTEGER when somebody later decides the swipe "needs the element width".
 *
 * 2. A SHOP WITH NO SLIDER DRAWS EXACTLY WHAT IT DREW BEFORE. `banner_sets.kind`
 *    ships at `cards`, which is what every row in that table already is, so
 *    applying this package moves no pixel. The second case renders it both ways
 *    and compares the bytes.
 */

/* ───────────────────────────────── helpers ──────────────────────────────── */

/** One slider set with $n published pictures, rendered as the homepage draws it. */
function sbLoad(array $setAttributes = [], int $n = 3, array $cardOverrides = []): array
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($setAttributes + [
        'name' => 'Pictures', 'slug' => 'sb-'.uniqid(), 'status' => 'publish', 'position' => 0,
        'kind' => 'slider',
    ]);

    foreach (range(1, $n) as $i) {
        BannerCard::create(($cardOverrides[$i] ?? []) + [
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/sb-'.$i.'.webp',
            'alt' => 'Alt '.$i,
            'button_url' => '/shop/',
            'image_w' => 1600,
            'image_h' => 900,
            'position' => $i,
            'status' => 'publish',
        ]);
    }

    $loaded = app(Banners::class)->forPreview($set->id);

    expect($loaded)->not->toBeNull();

    return $loaded;
}

function sbRender(array $setAttributes = [], int $n = 3, array $cardOverrides = []): string
{
    [$set, $cards] = sbLoad($setAttributes, $n, $cardOverrides);

    return view($set->homePartial(), ['set' => $set, 'cards' => $cards])->render();
}

/**
 * The same render with the <style> AND the <script> taken out.
 *
 * ── AND BOTH, OR AN ABSENCE ASSERTION IS A TAUTOLOGY ────────────────────────
 *
 * CardsBannerSectionShapeTest's own helper strips the stylesheet for this
 * reason and this one has a second document to strip: every class the slider
 * uses is named in its stylesheet AND most of them are named in its script, so
 * `expect($html)->not->toContain('kbbs-bar')` is false for every page this
 * partial has ever drawn, including the ones that draw no bar at all.
 */
function sbBody(array $setAttributes = [], int $n = 3, array $cardOverrides = []): string
{
    $html = sbRender($setAttributes, $n, $cardOverrides);
    $html = (string) preg_replace('#<style>.*?</style>#s', '', $html);

    return (string) preg_replace('#<script>.*?</script>#s', '', $html);
}

/**
 * The same again with the `<link rel="preload">` removed as well.
 *
 * THE PRELOAD CARRIES AN `href`, so every "this picture is not a link" and
 * "there are N links" assertion counted it and was off by one — measured, and
 * the reason this is a second helper rather than one. sbBody() is what the
 * preload case itself reads.
 */
function sbMarkup(array $setAttributes = [], int $n = 3, array $cardOverrides = []): string
{
    return (string) preg_replace('#<link rel="preload".*?>#s', '', sbBody($setAttributes, $n, $cardOverrides));
}

/** The partial's source with its Blade comments removed, so what is scanned is code. */
function sbCode(): string
{
    $path = base_path('resources/views/partials/home/slider-banner.blade.php');

    expect(file_exists($path))->toBeTrue('the slider partial is gone');

    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
}

/** Just the script, with its block comments removed. */
function sbScript(): string
{
    preg_match('#<script>(.*)</script>#s', sbCode(), $m);

    expect($m)->not->toBeEmpty('the slider partial no longer carries a script');

    return (string) preg_replace('#/\*.*?\*/#s', '', $m[1]);
}

/** Just the stylesheet, with its comments removed. */
function sbCss(): string
{
    preg_match('#<style>(.*?)</style>#s', sbCode(), $m);

    expect($m)->not->toBeEmpty('the slider partial no longer carries a stylesheet');

    return (string) preg_replace('#/\*.*?\*/#s', '', $m[1]);
}

/**
 * The mirrored shop: Arabic on AND the mirrored layout on.
 *
 * Tests\Support\ArabicShop::on() sets only the first — its own header explains
 * at length why, and that "Arabic on, RTL off" is a real state — so the second
 * row is written here, which is what ArabicFaceTest and DirectionalGlyphsTest
 * do for the same reason.
 */
function sbMirrored(): void
{
    foreach ([Locale::SETTING_ENABLED, Locale::SETTING_RTL] as $key) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => '1', 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
    app()->setLocale('ar');
}

/* ══════════════════════ rule 4: nothing measures anything ═════════════════ */

it('holds one integer and reaches for no element-measuring API', function () {
    /*
     * MUTATION NOTE, run. Add `vp.offsetWidth` anywhere in the slider's script
     * and this goes red naming that API; change the swipe to compare `dx`
     * against `vp.getBoundingClientRect().width / 4` — which is the obvious way
     * to write a proportional threshold and the exact shape rule 4 forbids —
     * and it goes red on `getBoundingClientRect`. Run, red, put back.
     *
     * The list is CardsBannerSectionShapeTest's, plus the four a slider is
     * likeliest to reach for that a marquee is not: the scroll offsets and the
     * two observers. `addEventListener` is NOT on it, and that is the one
     * deliberate difference between the two sections — the cards banner has no
     * script at all, this one has arrows, and an arrow is a click handler. The
     * partial's own header argues that at length.
     */
    $script = sbScript();

    foreach ([
        'getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth', 'offsetLeft',
        'clientHeight', 'clientWidth', 'clientTop', 'clientLeft',
        'scrollWidth', 'scrollHeight', 'scrollLeft', 'scrollTop', 'scrollIntoView',
        'innerWidth', 'innerHeight', 'getComputedStyle', 'getClientRects',
        'ResizeObserver', 'IntersectionObserver', 'requestAnimationFrame',
    ] as $api) {
        expect($script)->not->toContain($api, "the slider's script reaches for {$api}");
    }

    /*
     * AND THE GEOMETRY IS ARITHMETIC THE BROWSER DOES. The track moves by the
     * container-query unit — the browser's own measurement of its own box, done
     * in the layout engine once and again by itself when the window changes
     * size, which is what a resize listener exists to do badly.
     *
     * MUTATION: replace `100cqi` with a `%` and the slides stop being one frame
     * wide, because a percentage resolves against the TRACK; delete
     * `container-type:inline-size` and `cqi` means nothing at all. Both go red
     * here.
     */
    $css = sbCss();

    expect($css)->toContain('container-type:inline-size')
        ->and($css)->toContain('transform:translateX(calc(var(--kbbs-i,0) * var(--kbbs-dirn,-1) * 100cqi))')
        ->and($css)->toContain('.kbbs-s{flex:0 0 100cqi;width:100cqi');

    // The only thing the script writes out is that integer.
    expect($script)->toContain("track.style.setProperty('--kbbs-i', at)");
});

/* ═══════════ rule 1: a shop with no slider draws what it always drew ══════ */

it('ships at cards, so every set that exists draws the same bytes', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT, and it is the one that matters on the
     * most visible page in the shop: `kind` defaulting to anything but `cards`,
     * or `homePartial()` not answering `cards` for a row whose column is null —
     * which is every row on a server where the migration has added the column
     * and no backfill has run, and every model a test builds by hand.
     *
     * MUTATION, run: change `kind()` to `return (string) $this->kind;` and the
     * null and the nonsense cases below go red; change the migration's default
     * to 'slider' and the first one does.
     */
    $migration = (string) file_get_contents(
        base_path('database/migrations/2027_04_05_000000_add_banner_kind_and_slider.php')
    );

    expect($migration)->toContain("\$t->string('kind', 16)->default('cards')");

    foreach ([null, 'cards', '', 'slider-but-not-really', '<script>'] as $stored) {
        $rogue = new BannerSet;
        $rogue->forceFill(['kind' => $stored]);

        expect($rogue->homePartial())->toBe(
            $stored === 'slider' ? 'partials.home.slider-banner' : 'partials.home.cards-banner',
            'a set whose kind is '.var_export($stored, true).' changed which section it draws'
        );
    }

    /*
     * AND THE BYTES. The same cards set, once at the shipped values and once
     * with all four new columns emptied the way a row that predates them is —
     * rendered through whatever `homePartial()` answers, which is the whole
     * path this round changed.
     */
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create(['name' => 'Cards', 'slug' => 'sb-cards', 'status' => 'publish', 'position' => 0]);

    BannerCard::create([
        'banner_set_id' => $set->id, 'image' => 'uploads/banners/sb-1.webp', 'alt' => 'A',
        'heading' => 'H', 'body' => 'B', 'button_label' => 'Shop', 'button_url' => '/shop/',
        'image_w' => 900, 'image_h' => 1200, 'position' => 1, 'status' => 'publish',
    ]);

    [$shipped, $cards] = app(Banners::class)->forPreview($set->id);

    expect($shipped->kind())->toBe('cards');

    $before = view($shipped->homePartial(), ['set' => $shipped, 'cards' => $cards])->render();

    $shipped->forceFill(['kind' => null, 'slider_style' => null, 'slider_ratio' => null, 'slider_ratio_m' => null]);

    $after = view($shipped->homePartial(), ['set' => $shipped, 'cards' => $cards])->render();

    expect($after)->toBe($before, 'the four new columns changed what a cards banner draws')
        ->and($before)->not->toContain('kbbs')
        ->and($before)->toContain('kbbn-c');
});

it('is mounted exactly once, on the homepage and on the admin screen', function () {
    /*
     * CLAUDE.md: "Pin the FINISHED state instead, which is also the thing that
     * can actually regress." ZERO is the "built, never wired up" shape this
     * repository keeps finding; TWO draws the section twice and registers the
     * sidebar entry twice.
     *
     * The homepage's include is the SET'S OWN partial, so what is pinned is the
     * call rather than a view name — a second `@include('partials.home.
     * slider-banner')` anywhere would be a second slider on the page and is
     * caught by the same count.
     */
    $home = (string) file_get_contents(resource_path('views/store/home.blade.php'));
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($home, "@include(\$bnSection[0]->homePartial()"))->toBe(1)
        ->and(substr_count($home, "partials.home.slider-banner"))->toBe(0)
        ->and(substr_count($console, "@include('admin.partials.banners-screen')"))->toBe(1);
});

/* ═══════════════════════════ the markup, and what is in it ════════════════ */

it('draws one picture per card, at most one link each, and no text layer at all', function () {
    /*
     * "only images slider". A slider card's heading, body and button label are
     * columns it SHARES with the cards banner and this type draws none of them —
     * so a set switched over keeps its text and simply stops printing it.
     *
     * MUTATION: print `$bsCard->heading` in the partial and the first absence
     * below goes red.
     */
    $html = sbMarkup([], 4, [1 => ['heading' => 'HEADLINE-ONE', 'body' => 'BODY-ONE', 'button_label' => 'BUTTON-ONE']]);

    expect(substr_count($html, 'class="kbbs-s"'))->toBe(4)
        ->and(substr_count($html, '<img '))->toBe(4)
        ->and(substr_count($html, 'href='))->toBe(4)
        ->and($html)->not->toContain('HEADLINE-ONE')
        ->and($html)->not->toContain('BODY-ONE')
        ->and($html)->not->toContain('BUTTON-ONE')
        ->and($html)->not->toContain('kbbn-');

    // A picture with no link is still an <a>, and still not a link: no href, so
    // it is not focusable and a screen reader walks past it.
    $bare = sbMarkup([], 1, [1 => ['button_url' => '']]);

    expect(substr_count($bare, 'class="kbbs-a"'))->toBe(1)
        ->and($bare)->not->toContain('href=');
});

it('draws the arrows and the bars only when the set asks, and never with one picture', function () {
    /*
     * THE DEFECT THIS IS NAMED AFTER, and this repository has already paid for
     * it once: the cards banner shipped its arrows outside the set's own switch
     * and drew a pair on every banner, including one whose owner had left them
     * off. A previous and a next on a set of ONE are the same fault wearing
     * different clothes — two controls that cannot do anything.
     *
     * MUTATION: drop `$bsSteerable` from either condition and the one-picture
     * expectations go red.
     */
    expect(sbMarkup(['show_arrows' => true, 'show_dots' => true], 3))->toContain('kbbs-prev')
        ->and(sbMarkup(['show_arrows' => true, 'show_dots' => true], 3))->toContain('kbbs-bar')
        ->and(sbMarkup(['show_arrows' => false], 3))->not->toContain('kbbs-prev')
        ->and(sbMarkup(['show_dots' => false], 3))->not->toContain('kbbs-bar')
        ->and(sbMarkup(['show_arrows' => true, 'show_dots' => true], 1))->not->toContain('kbbs-prev')
        ->and(sbMarkup(['show_arrows' => true, 'show_dots' => true], 1))->not->toContain('kbbs-bar');

    // One bar per picture, and the first is marked before a line of script has
    // run, so the control is never blank on first paint.
    $html = sbMarkup(['show_dots' => true], 5);

    // COUNTED BY `<button class="kbbs-bar`, because `class="kbbs-bar` is also a
    // prefix of the row's own `class="kbbs-bars"` — measured, six for five bars.
    expect(substr_count($html, '<button class="kbbs-bar'))->toBe(5)
        ->and(substr_count($html, 'class="kbbs-bar is-on"'))->toBe(1)
        ->and(substr_count($html, 'data-kbbs-go="0"'))->toBe(1)
        ->and(substr_count($html, 'data-kbbs-go="4"'))->toBe(1);

    // And the bars are BUTTONS, not links and not divs. A div with a click
    // handler is not a control, which is the whole difference between these and
    // the cards banner's dots.
    expect(substr_count($html, '<button class="kbbs-bar'))->toBe(5);
});

it('names every control and announces a change the shopper asked for', function () {
    /*
     * An icon-only button with no accessible name is a button a screen reader
     * reads as "button". All three of these are icon-only.
     *
     * MUTATION: delete the aria-label from the next button and the second
     * expectation goes red; delete the live region and the fourth does.
     */
    $html = sbMarkup(['autoplay' => true, 'show_arrows' => true, 'show_dots' => true], 3);

    expect($html)->toContain('aria-label="'.__('store.home.banner_slider_prev').'"')
        ->and($html)->toContain('aria-label="'.__('store.home.banner_slider_next').'"')
        ->and($html)->toContain('aria-label="'.__('store.home.banner_slider_go', ['n' => 2]).'"')
        ->and($html)->toContain('role="status" aria-live="polite"')
        ->and($html)->toContain('aria-roledescription="carousel"')
        ->and(substr_count($html, 'aria-roledescription="slide"'))->toBe(3)
        ->and($html)->toContain('aria-label="'.__('store.home.banner_slider_slide', ['n' => 1, 'total' => 3]).'"')
        // The pause button is a REAL control, because hovering is not a
        // mechanism for a shopper who is not holding a mouse (WCAG 2.2.2).
        ->and($html)->toContain('aria-label="'.__('store.home.banner_slider_pause').'"');

    /*
     * AND IT DOES NOT SPEAK FOR AUTOPLAY. A live region that announced every
     * tick would read the whole slider aloud for ever. `tell` is false on the
     * autoplay step and true for everything a shopper did.
     *
     * MUTATION: pass `true` in the setInterval below and this goes red.
     */
    expect(sbScript())->toContain('timer = setInterval(function(){ go(at + 1, false); }, dwell)')
        ->and(sbScript())->toContain("prev.addEventListener('click', function(){ go(at - 1, true); beat(); })");

    // The region is off-screen rather than display:none, because a hidden
    // element is not announced.
    expect(sbCss())->toContain('.kbbs-live{position:absolute;width:1px;height:1px');
});

/* ══════════════════ the LCP picture, and no layout shift ══════════════════ */

it('preloads exactly one picture, fetches it eagerly, and lazy-loads the rest', function () {
    /*
     * With this section on, the first picture is the largest element in its part
     * of the page and, above the fold, the homepage's LCP element. A slider that
     * lazy-loads its own first picture is a slider that made the page slower.
     *
     * ONE preload and not five: preloading all of them spends the whole
     * connection on four pictures nobody has asked to see.
     *
     * MUTATION, run: drop the `$bsI === 0` from the eager condition and the
     * counts below go to 5 and 0. Run, red, put back.
     */
    $html = sbBody([], 5);

    expect(substr_count($html, 'rel="preload" as="image"'))->toBe(1)
        ->and(substr_count($html, 'fetchpriority="high"'))->toBe(2) // the link and the first <img>
        ->and(substr_count($html, 'loading="lazy"'))->toBe(4)
        ->and(substr_count($html, 'decoding="async"'))->toBe(5)
        ->and(substr_count($html, 'width="1600" height="900"'))->toBe(5);

    expect(strpos($html, 'loading="lazy"'))->toBeGreaterThan((int) strpos($html, 'fetchpriority="high"'));

    // A picture whose dimensions are unknown prints NEITHER attribute: a guessed
    // width reserves the wrong box, and the frame's aspect-ratio is already
    // holding the space.
    $unknown = sbMarkup([], 1, [1 => ['image_w' => null, 'image_h' => null]]);

    expect($unknown)->not->toContain('width="')
        ->and($unknown)->not->toContain('height="');
});

it('reserves the frame in CSS at two shapes, so nothing shifts as a picture arrives', function () {
    /*
     * THE HEIGHT IS KNOWN FROM THE STYLESHEET AT FIRST PAINT, before any picture
     * has been fetched — which is what "no layout shift as it loads" means. It
     * comes from the SET, and from two columns rather than one, because a 21:9
     * banner is a 44px band on a 390px screen.
     *
     * MUTATION: delete the `aspect-ratio` from `.kbbs-vp` and the frame has no
     * height until a picture decodes; delete the 768px query and a phone gets
     * the desktop's shape. Both go red here.
     */
    $css = sbCss();

    expect($css)->toContain('.kbbs-vp{aspect-ratio:var(--kbbs-arm,4 / 3)')
        ->and($css)->toContain('@media (min-width:768px){.kbbs-vp{aspect-ratio:var(--kbbs-ar,16 / 9)}}');

    $html = sbRender(['slider_ratio' => '21/9', 'slider_ratio_m' => '1/1']);

    expect($html)->toContain('--kbbs-ar:21 / 9')
        ->and($html)->toContain('--kbbs-arm:1 / 1');
});

/* ═══════════════ rule 5: escaping, schemes, and printed constants ═════════ */

it('escapes every string an operator typed', function () {
    /*
     * The set's NAME reaches the page on this banner type, which it does not on
     * the cards banner: it is the region's accessible label. So it is a box an
     * operator types into that is printed into an attribute, and printed raw it
     * is stored cross-site scripting on the front page of the shop.
     *
     * MUTATION: change either {{ }} to {!! !!} and this goes red.
     */
    $html = sbMarkup(['name' => '"><script>alert(1)</script>'], 1, [1 => [
        'alt' => '"><img src=x onerror=alert(2)>',
    ]]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<img src=x onerror')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('refuses a javascript: URL however it is spelled, and draws no link for it', function () {
    /*
     * The same three-step check the cards banner makes and the same reason it is
     * ordered that way: a browser resolves `jav&#x09;ascript:alert(1)` to a
     * javascript URL, and a `str_starts_with($url, 'javascript:')` sees a string
     * starting "jav&" and waves it through.
     *
     * A refused URL draws NO href rather than a dead one — the picture is simply
     * not a link.
     */
    $html = sbMarkup([], 3, [
        1 => ['button_url' => 'javascript:alert(1)'],
        2 => ['button_url' => "jav&#x09;ascript:alert(1)"],
        3 => ['button_url' => '//evil.test/x'],
    ]);

    expect($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('//evil.test')
        ->and($html)->not->toContain('href=');
});

it('prints nothing but constants and clamped integers into the style attribute', function () {
    /*
     * THE THIRD DOOR, the one BannerSet's enum header describes: a row edited
     * straight in the database to a style, a ratio or a radius nobody issued
     * still cannot reach the `<style>` or the `style` attribute.
     *
     * MUTATION: make `sliderRatioCss()` return `$this->slider_ratio` and the
     * first expectation below carries `; } body{display:none}` into a
     * declaration. Run, red, put back.
     */
    $rogue = new BannerSet;
    $rogue->forceFill([
        'slider_ratio' => '; } body{display:none}',
        'slider_ratio_m' => 'url(//evil.test)',
        'slider_style' => 'inset" onload="alert(1)',
        'shadow' => 'x',
        'card_radius' => 9999,
        'speed_ms' => -5,
    ]);

    expect($rogue->sliderRatioCss())->toBe('16 / 9')
        ->and($rogue->sliderRatioMobileCss())->toBe('4 / 3')
        ->and($rogue->sliderStyle())->toBe('inset');

    $vars = Banners::sliderVariables($rogue, [], false);

    expect($vars)->toBe(
        '--kbbs-ar:16 / 9;--kbbs-arm:4 / 3;--kbbs-r:40px;'
        .'--kbbs-sh:'.BannerSet::SHADOWS['soft'][1].';--kbbs-dwell:0.60s;--kbbs-dirn:-1;--kbbs-flip:1'
    );

    // And the class the token becomes is the default's, not the operator's.
    $html = sbMarkup(['slider_style' => 'inset" onload="alert(1)'], 2);

    expect($html)->toContain('class="kbbs is-inset')
        ->and($html)->not->toContain('onload=');
});

/* ══════════════════════════ autoplay, and stopping it ═════════════════════ */

it('does not move at all without autoplay, or with one picture', function () {
    /*
     * `0` is the SINGLE spelling of "there is no autoplay", so the stylesheet's
     * `is-auto` class and the script's timer cannot disagree about it. One
     * picture is the second way to say it: there is nowhere to go.
     *
     * MUTATION: drop the `count($cards) < 2` from Banners::sliderDwell() and
     * the one-picture case below gets a pause button for a slideshow that
     * cannot advance.
     */
    $set = new BannerSet;
    $set->forceFill(['autoplay' => true, 'speed_ms' => 4000]);

    $three = [new BannerCard, new BannerCard, new BannerCard];

    expect(Banners::sliderDwell($set, $three))->toBe(4000)
        ->and(Banners::sliderDwell($set, [new BannerCard]))->toBe(0);

    $set->forceFill(['autoplay' => false]);

    expect(Banners::sliderDwell($set, $three))->toBe(0);

    expect(sbMarkup(['autoplay' => true, 'speed_ms' => 4000], 3))->toContain('data-dwell="4000"')
        ->and(sbMarkup(['autoplay' => true], 3))->toContain('is-auto')
        ->and(sbMarkup(['autoplay' => false], 3))->toContain('data-dwell="0"')
        ->and(sbMarkup(['autoplay' => false], 3))->not->toContain('is-auto')
        ->and(sbMarkup(['autoplay' => false], 3))->not->toContain('kbbs-pp')
        ->and(sbMarkup(['autoplay' => true], 1))->not->toContain('kbbs-pp');
});

it('stops for hover, for focus, for reduced motion, for a hidden tab and for the button', function () {
    /*
     * WCAG 2.2.2, and the first three are FLOORS rather than settings. The cards
     * banner's `pause_on_hover` column is deliberately not read here: a
     * slideshow whose owner can turn the stop off is a slideshow that cannot be
     * stopped, and a shopper with a tremor or a screen reader is the one who
     * pays.
     *
     * ONE FUNCTION DECIDES IT, so the progress fill and the picture cannot
     * disagree about whether the slider is moving.
     *
     * MUTATION, run: remove `!hovered` from running() and the hover probe in
     * tests/browser/lane-bn2-slider.mjs reports `after` one ahead of `before`;
     * remove `!quiet()` and the reduced-motion probe does. Both were run, and
     * both are in docs/lane-bn2-shots.
     */
    $script = sbScript();

    expect($script)->toContain('return dwell > 0 && !stopped && !hovered && !focused && !quiet() && !document.hidden;')
        ->and($script)->toContain("window.matchMedia('(prefers-reduced-motion: reduce)')")
        ->and($script)->toContain("root.addEventListener('mouseenter'")
        ->and($script)->toContain("root.addEventListener('focusin'")
        ->and($script)->toContain("document.addEventListener('visibilitychange', beat)")
        // The set's hover switch is the cards banner's and is not consulted.
        ->and($script)->not->toContain('pause_on_hover')
        ->and($script)->not->toContain('is-hoverpause');

    /*
     * AND THE CONTROLS STAY under reduced motion, which is the opposite of what
     * the cards banner does with its dots — there, the section becomes a
     * hand-scrolled rail and the dots come back. Here the arrows and the bars
     * are the ONLY way to move it, so hiding them would leave a shopper who
     * asked for less motion with a slider he cannot move at all.
     */
    $css = sbCss();
    $block = (string) preg_replace('/^.*@media \(prefers-reduced-motion: reduce\)\{/s', '', $css);
    $block = substr($block, 0, (int) strpos($block, "\n}"));

    expect($block)->toContain('.kbbs.is-js .kbbs-tr{transition:none}')
        ->and($block)->toContain('.kbbs .kbbs-fill{animation:none}')
        ->and($block)->not->toContain('.kbbs-nav{display:none')
        ->and($block)->not->toContain('.kbbs-bars{display:none');
});

it('is a plain scroll-snap rail until its script has run, and draws no dead control', function () {
    /*
     * "a button that looks like a control and is not one" is what this
     * repository's own note on the cards banner's arrows refuses, and it applies
     * to a script that has not run as much as to a browser that has no
     * ::scroll-button(). Every control is behind `.is-js`, which the script adds
     * as its LAST act — so a script that threw halfway leaves a working,
     * swipeable, keyboard-reachable rail rather than a dead one.
     *
     * MUTATION: drop `.is-js` from the two display rules and a page with
     * JavaScript off draws arrows that do nothing.
     */
    $css = sbCss();
    $script = sbScript();

    expect($css)->toContain('.kbbs.is-js.is-arrows .kbbs-nav{display:inline-flex}')
        ->and($css)->toContain('.kbbs.is-js.is-bars .kbbs-bars{display:flex}')
        ->and($css)->toContain('.kbbs.is-js.is-auto .kbbs-pp{display:inline-flex}')
        // The no-script state: a real scroll container with snap points.
        ->and($css)->toContain('scroll-snap-type:x mandatory')
        ->and($css)->toContain('.kbbs-s{flex:0 0 100cqi;width:100cqi;height:100%;scroll-snap-align:start}')
        // …and `pan-y`, which is what stops a swipe fighting the page.
        ->and($css)->toContain('touch-action:pan-y');

    expect(strpos($script, "root.classList.add('is-js')"))
        ->toBeGreaterThan((int) strpos($script, "vp.addEventListener('pointerdown'"));
});

/* ═════════════════════════════════ Arabic ════════════════════════════════ */

it('runs the other way on /ar, with a number and not a [dir] rule', function () {
    /*
     * `transform` is PHYSICAL — there is no logical transform — so a slider
     * moved with translateX walks the wrong way in Arabic unless something
     * flips its sign. The three answers are a `[dir="rtl"]` rule, a `:dir(rtl)`
     * rule, or a number, and this is the number: written by the server from
     * `Locale::isRtl()`, which is the same source `<html dir>` is written from,
     * so the stylesheet cannot disagree with the document.
     *
     * MUTATION, run: hard-code `--kbbs-dirn:-1` in Banners::sliderVariables()
     * and the Arabic expectation below goes red — and the browser harness
     * reports a swipe that goes to picture 1 instead of picture 5.
     *
     * EVERYTHING ELSE MIRRORS BY ITSELF, which is the other half and is asserted
     * as an ABSENCE: there is no `[dir` anywhere in this partial, because every
     * offset in it is a logical property and the two flex rows reverse with the
     * document.
     */
    /*
     * SCANNED WITHOUT COMMENTS, which is the difference between a test and a
     * tautology here: the partial's own header EXPLAINS why there is no
     * `[dir="rtl"]` rule, in those words, so a search over the raw file reports
     * the explanation as a breach. The same fault CardsBannerSectionShapeTest's
     * bnsCss() was written to fix, and it failed here first.
     */
    expect(sbCss().sbScript())->not->toContain('[dir')
        ->and(sbCss().sbScript())->not->toContain(':dir(')
        // The bar's progress grows by an INLINE SIZE, which fills from the
        // inline start in both directions with no transform-origin to set.
        ->and(sbCss())->toContain('@keyframes kbbs-fill{from{inline-size:0}to{inline-size:100%}}')
        ->and(sbCss())->toContain('inset-inline-start:var(--kbbs-navx,12px)')
        ->and(sbCss())->toContain('inset-inline-end:var(--kbbs-navx,12px)');

    // English first, so the Arabic assertion is a difference and not a value.
    expect(sbRender([], 3))->toContain('--kbbs-dirn:-1;--kbbs-flip:1')
        ->and(sbRender([], 3))->toContain('data-flip="1"');

    sbMirrored();

    expect(Locale::isRtl())->toBeTrue();

    $arabic = sbRender([], 3);

    expect($arabic)->toContain('--kbbs-dirn:1;--kbbs-flip:-1')
        ->and($arabic)->toContain('data-flip="-1"')
        // The chevron is drawn once and turned round by that sign.
        ->and(sbCss())->toContain('.kbbs-prev svg{transform:scaleX(var(--kbbs-flip,1))}')
        ->and(sbCss())->toContain('.kbbs-next svg{transform:scaleX(calc(var(--kbbs-flip,1) * -1))}');

    // And the arrow keys follow the same sign, so left is "the picture on the
    // left" in both languages.
    expect(sbScript())->toContain("if (ev.key === 'ArrowLeft') { go(at - flip, true)");
});

/* ═══════════════════════════════ the cost ════════════════════════════════ */

it('costs the homepage exactly what the cards banner costs, however many pictures', function () {
    /*
     * The slider reads through `Banners::forHome()` — the same single JOIN, the
     * same models — so it can only be flat if that query is, and it is the same
     * query. What could regress is the four new columns being fetched with a
     * second read, or the partial reaching for a relation.
     *
     * MUTATION: put `$set->cards` anywhere in the slider partial and the 3 and
     * 24 counts below diverge by one. Run, red, put back.
     */
    $settings = app(SettingsService::class);

    $count = function (): int {
        test()->get('/');

        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->get('/')->assertOk();

        $n = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    };

    $bare = $count();

    [$set] = sbLoad([], 3);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $three = $count();

    [$big] = sbLoad([], 24);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $big->id);

    expect($three)->toBe($bare + 1, 'the slider costs the homepage more than one query')
        ->and($count())->toBe($three, 'the slider is not flat in the number of pictures');
});

/* ═══════════════════════ /api/* never sees a banner ═══════════════════════ */

it('adds no public endpoint and serialises no banner column', function () {
    /*
     * CLAUDE.md: "/api/* is unauthenticated. Every endpoint there is public."
     * The safest allowlist for a model nothing public needs is the empty one,
     * which is the decision BannerCard's own header records — and the second
     * banner type does not change it. Asserted rather than assumed, because a
     * later round adding a `toApi()` here is exactly the shape that leaks.
     */
    expect(method_exists(BannerSet::class, 'toApi'))->toBeFalse()
        ->and(method_exists(BannerCard::class, 'toApi'))->toBeFalse();

    foreach (glob(app_path('Http/Controllers/Api/*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        expect($source)->not->toContain('BannerSet')
            ->and($source)->not->toContain('BannerCard');
    }

    /*
     * COMMENTS STRIPPED FIRST. That file's header documents its paths as
     * `/admin-api/banners/...`, and `/admin-api/` CONTAINS `/api/` — so the raw
     * search reports the guarded prefix as an unguarded one. Measured.
     */
    $routes = (string) preg_replace(
        '#/\*.*?\*/#s', '', (string) file_get_contents(base_path('routes/banners-admin.php'))
    );

    // Every banner path is under the guarded admin-api prefix, and this round
    // adds none — the second type is a column, not an endpoint.
    expect($routes)->not->toContain("'/api/")
        ->and(substr_count($routes, 'Route::'))->toBe(14);
});

it('cannot hold a URL in the column its <img src> is built from', function () {
    /*
     * ── WHERE THE SCHEME CHECK ON A PICTURE ACTUALLY IS ────────────────────
     *
     * A card's LINK is scheme-checked at render, by Banners::safeUrl(), because
     * it is a box an operator types a URL into. Its PICTURE is not, and that is
     * a different guarantee rather than a missing one: `banner_cards.image` is
     * written only through BannerApiController::storedPath(), which hands the
     * value to `MediaRegistrar::normalise()` — an allowlist that refuses a
     * scheme, a host, a protocol-relative path, a traversal, a backslash, a NUL
     * and anything outside the two upload roots. The column therefore cannot
     * hold a URL, and `<img src>` is built from a path this shop wrote.
     *
     * This is the SHARED posture of both banner types and predates this round;
     * it is asserted here rather than assumed because the second type doubled
     * the number of templates that print this column.
     *
     * MUTATION: drop the `MediaRegistrar::normalise()` call from storedPath()
     * and the first four cases below round-trip the operator's string.
     */
    \App\Models\AdminUser::query()->where('email', 'sb-guard@example.test')->delete();

    $owner = \App\Models\AdminUser::create([
        'name' => 'SB Guard', 'email' => 'sb-guard@example.test',
        'password' => 'sb-guard-password', 'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    $set = BannerSet::create(['name' => 'Guard', 'slug' => 'sb-guard', 'status' => 'publish', 'kind' => 'slider']);
    $card = BannerCard::create(['banner_set_id' => $set->id, 'image' => '', 'status' => 'publish']);

    foreach ([
        'https://evil.test/x.jpg',
        '//evil.test/x.jpg',
        'javascript:alert(1)',
        '../../.env',
        'etc/wp-content/uploads/x.jpg',
    ] as $bad) {
        test()->putJson('/admin-api/banners/cards/'.$card->id, ['image' => $bad])->assertOk();

        expect((string) $card->fresh()->image)->toBe('', $bad.' reached banner_cards.image');
    }

    // …and the shape it IS for still round-trips, so this is a gate and not a
    // wall: the picker hands over a URL and the controller cuts it to a path.
    test()->putJson('/admin-api/banners/cards/'.$card->id, [
        'image' => 'http://shop.test/kbb-upgrade/uploads/banners/sb-1.webp',
    ])->assertOk();

    expect((string) $card->fresh()->image)->toBe('uploads/banners/sb-1.webp');
});

it('normalises a picture path the same way for both banner types', function () {
    /*
     * The half of the case above that does not need a mounted route: the
     * allowlist itself, which is the thing that makes `<img src>` safe.
     *
     * MUTATION, run: change `MediaRegistrar::normalise()` to return its argument
     * and every expectation here goes red.
     */
    foreach ([
        'https://evil.test/uploads/x.jpg',
        '//evil.test/uploads/x.jpg',
        'javascript:alert(1)',
        '../../.env',
        'etc/wp-content/uploads/x.jpg',
        "uploads/x\0.jpg",
        'uploads/../../secret.env',
    ] as $bad) {
        expect(\App\Support\MediaRegistrar::normalise($bad))->toBeNull($bad.' is accepted as a picture path');
    }

    expect(\App\Support\MediaRegistrar::normalise('uploads/banners/sb-1.webp'))->toBe('uploads/banners/sb-1.webp');
});

/* ══════════════════════════ the admin preview frame ═══════════════════════ */

it('runs the slider inside the preview frame without giving it the console', function () {
    /*
     * THE DEFECT THIS FIXES, found by driving the screen: the preview iframe was
     * `sandbox="allow-same-origin"` with no `allow-scripts`, so the slider's
     * script could not run in it and the owner choosing between four treatments
     * would have been shown the no-script fallback — a plain rail with no arrows
     * and no bars, which is exactly what he is choosing between.
     *
     * AND THE FIX TIGHTENS THE SANDBOX RATHER THAN LOOSENING IT.
     * `allow-scripts` together with `allow-same-origin` is the documented escape
     * hatch: a frame with both can reach the parent document and its cookies, so
     * the sandbox buys nothing. With `allow-scripts` alone the frame is a unique
     * opaque origin — the script runs and can touch nothing outside its own
     * document, which is all this section needs.
     *
     * MUTATION: put `allow-same-origin` back and this goes red; take
     * `allow-scripts` away and the browser harness reports `previewIsJs: 0`.
     */
    $raw = (string) file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));

    /*
     * COMMENTS STRIPPED, for the third time in this file and for the third time
     * because the code's own explanation names the thing it forbids: the note
     * beside this iframe says in as many words why `allow-same-origin` is gone.
     */
    $screen = (string) preg_replace('#/\*.*?\*/#s', '', $raw);

    expect(substr_count($screen, 'sandbox="allow-scripts"'))->toBe(1)
        ->and($screen)->not->toContain('allow-same-origin');

    // The screen sends the four new columns, or they are controls that save
    // nothing. CardsBannerEditorTest holds the full parity; these are named
    // here so a rename is readable.
    foreach (['kind', 'slider_style', 'slider_ratio', 'slider_ratio_m'] as $key) {
        expect($raw)->toContain("'".$key."'")
            ->and($raw)->toContain("pick('".$key."'");
    }
});
