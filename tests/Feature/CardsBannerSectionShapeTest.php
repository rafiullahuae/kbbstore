<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;

/**
 * The shape of the cards banner — Lane BN.
 *
 * Everything here is a property of the SOURCE or of one rendered row rather
 * than of a whole page, which is why it is a file of its own: these are the
 * assertions that would still be true on a shop with different content, and
 * they are the ones a later lane is most likely to break by accident.
 *
 * ── THE ONE THING THIS FILE EXISTS FOR ABOVE ALL OTHERS ─────────────────────
 *
 * CLAUDE.md rule 4: "No JavaScript that measures layout — this project sizes
 * with calc() for a reason, and two tests forbid the element-measuring APIs by
 * name." A carousel is the single commonest place that rule gets broken,
 * because the obvious carousel reads offsetWidth and advances by it. This
 * section has NO SCRIPT AT ALL, and the first case below is what keeps it that
 * way when somebody later decides the arrows need "just a little JavaScript".
 */
function bnsPartial(): string
{
    $path = base_path('resources/views/partials/home/cards-banner.blade.php');

    expect(file_exists($path))->toBeTrue('the cards banner partial is gone');

    return (string) file_get_contents($path);
}

/**
 * The partial with every comment removed — Lane BP.
 *
 * ── WHY A SCAN OF THE SOURCE HAS TO DO THIS ─────────────────────────────────
 *
 * CLAUDE.md's framework guard makes the same point about tokenising app/ for
 * module readers: "a source-text search reads comments and quoted strings as
 * code ... a row could be 'proved' live by prose about it." The same thing
 * happened here, and it went the other way — the arrows check below scans for
 * every `::scroll-button` selector and demands each one be under the set's own
 * switch, and the file's own explanatory note says "`::scroll-button()` is the
 * only way to move a scroll container from CSS". That sentence is not a rule
 * and has no selector in front of it, so the check failed on a paragraph.
 *
 * Blade comments first, then CSS/PHP block comments, because a `{{-- --}}` can
 * contain either.
 */
function bnsCss(): string
{
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', bnsPartial());

    return (string) preg_replace('#/\*.*?\*/#s', '', $src);
}

/** One set with $n published cards, rendered as the homepage draws it. */
function bnsRender(array $setAttributes = [], int $n = 3, array $cardOverrides = []): string
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($setAttributes + [
        'name' => 'Shape', 'slug' => 'shape-'.uniqid(), 'status' => 'publish', 'position' => 0,
    ]);

    foreach (range(1, $n) as $i) {
        BannerCard::create(($cardOverrides[$i] ?? []) + [
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/bn-'.$i.'.webp',
            'alt' => 'Alt '.$i,
            'heading' => 'Heading '.$i,
            'body' => 'Body '.$i,
            'button_label' => 'Shop',
            'button_url' => '/shop/',
            'image_w' => 900,
            'image_h' => 1200,
            'position' => $i,
            'status' => 'publish',
        ]);
    }

    $loaded = app(Banners::class)->forPreview($set->id);

    expect($loaded)->not->toBeNull();

    return view('partials.home.cards-banner', ['set' => $loaded[0], 'cards' => $loaded[1]])->render();
}

/**
 * The same render with the <style> element taken out.
 *
 * ── AND THIS IS NOT TIDINESS, IT IS THE DIFFERENCE BETWEEN A TEST AND A
 *    TAUTOLOGY ────────────────────────────────────────────────────────────
 *
 * Every class this section uses is NAMED IN ITS OWN STYLESHEET, and the
 * stylesheet is inline in the rendered output. So `expect($html)->not->
 * toContain('kbbn-btn')` is FALSE for every page this partial has ever drawn,
 * including the ones that draw no button at all — the rule `.kbbn-btn{…}` is
 * right there. Four assertions in this file were written that way first and
 * every one of them failed against correct markup, which is the honest reason
 * this helper exists: an absence assertion has to be made against the MARKUP,
 * not against the markup plus a document that mentions every class by name.
 */
function bnsMarkup(array $setAttributes = [], int $n = 3, array $cardOverrides = []): string
{
    $html = bnsRender($setAttributes, $n, $cardOverrides);

    return (string) preg_replace('#<style>.*?</style>#s', '', $html);
}

/**
 * The partial's source with its Blade comments removed.
 *
 * This file's own prose names `offsetWidth` and `getBoundingClientRect` — it
 * has to, they are what it forbids — and so does the partial's header, for the
 * same reason. A raw str_contains over the source therefore reports the
 * EXPLANATION of the rule as a breach of it, which is precisely the fault
 * ModuleFrameworkGuardTest's header describes when it refuses to prove a
 * registry row `live` by prose about it. Blade comments are stripped first, so
 * what is scanned is code.
 */
function bnsCode(): string
{
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', bnsPartial());
}

/* ══════════════════════ rule 4: nothing measures anything ═════════════════ */

it('carries no script and reaches for no element-measuring API', function () {
    /*
     * MUTATION NOTE. Add `el.offsetWidth` — or any <script> block at all — to
     * resources/views/partials/home/cards-banner.blade.php and this goes red on
     * the named API and on the script check. Run, red, put back.
     *
     * The list is InstagramSectionShapeTest's, unchanged, because the rule is
     * the same rule and a shorter list here would be a quieter promise.
     */
    $code = bnsCode();

    expect($code)->not->toContain('<script');

    foreach ([
        'getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth',
        'clientHeight', 'clientWidth', 'scrollY', 'getComputedStyle',
        'ResizeObserver', 'requestAnimationFrame', 'addEventListener',
    ] as $api) {
        expect($code)->not->toContain($api);
    }

    // And nothing reaches the rendered page either — an @include of a script
    // partial would pass the source check above and fail this one.
    expect(bnsRender())->not->toContain('<script');
});

it('sizes the card from one calc over the two per-set numbers', function () {
    /*
     * THE PEEK IS ARITHMETIC, NOT A MEASUREMENT. `--per` full cards plus
     * `--peek` of the next one fill the scroller:
     *
     *     (100cqi − gap × per) / (per + peek)
     *
     * `100cqi` is a container query unit — the browser's own measurement of its
     * own box, done by the layout engine rather than by a resize listener.
     *
     * MUTATION: replace the flex-basis with a fixed px width and this goes red
     * on the calc; drop `container-type:inline-size` and it goes red on that,
     * which is the declaration that makes cqi mean anything at all.
     */
    /*
     * bnsCode(), NOT the raw source. The partial's own header EXPLAINS the
     * container-query technique in prose, so a plain str_contains over the file
     * matches the explanation and the assertion passes with the declaration
     * deleted — measured: removing `container-type:inline-size` from
     * `.kbbn-vp` left this case green until it was changed to read code.
     */
    $code = bnsCode();

    expect($code)->toContain('.kbbn-vp{container-type:inline-size;')
        ->and($code)->toContain('100cqi')
        ->and($code)->toContain('/ (var(--kbbn-per) + var(--kbbn-peek))');
});

it('lets a media query narrow the row, because the server writes the -lg names', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT, and it is subtle enough to have
     * shipped: an inline `style` declaration BEATS every stylesheet rule,
     * including one inside a media query. Had the server written `--kbbn-per`
     * directly, a phone would have been stuck on the desktop's four-across —
     * four 90px cards on a 390px screen — and no media query could have saved
     * it. The server writes `--kbbn-per-lg`; the stylesheet owns `--kbbn-per`,
     * ships it at the phone's value, and only assigns it from the `-lg`
     * property at the widest breakpoint.
     *
     * MUTATION: rename `--kbbn-per-lg` to `--kbbn-per` in
     * Banners::cssVariables() and this goes red twice — the inline style stops
     * carrying the -lg name and the desktop rule stops referring to it.
     */
    $html = bnsRender(['per_view' => 5, 'peek' => 30]);

    expect($html)->toContain('--kbbn-per-lg:5')
        ->and($html)->toContain('--kbbn-peek-lg:0.30')
        // The phone's numbers are the stylesheet's, not the server's.
        ->and($html)->toContain('.kbbn-vp{--kbbn-per:1;--kbbn-peek:.5;')
        ->and($html)->toContain('@media (min-width:1024px){.kbbn-vp{--kbbn-per:var(--kbbn-per-lg,4);--kbbn-peek:var(--kbbn-peek-lg,.38)}}');
});

/* ═══════════════════════ the motion, and stopping it ══════════════════════ */

it('doubles the track and translates it by half, so the loop has no seam', function () {
    /*
     * MUTATION: drop the second copy (`$bnCopy < ($bnAnimates ? 2 : 1)` → `< 1`)
     * and the card count halves and this goes red; change the keyframe's -50%
     * and the loop-shape assertion goes red.
     *
     * AND THE GAP IS A MARGIN, NOT `gap` ON THE TRACK. A flex `gap` puts
     * (2n − 1) gaps in a track of 2n cards, so half the track is n cards and
     * n − ½ gaps while one copy is n cards and n gaps: the row jumps half a gap
     * every cycle, which reads as a stutter nobody can place. As a
     * margin-inline-end the halves are equal and -50% lands on the seam.
     */
    $html = bnsMarkup([], 4);
    $partial = bnsPartial();

    expect(substr_count($html, 'class="kbbn-c'))->toBe(8)
        ->and(substr_count($html, 'kbbn-dup'))->toBe(4)
        ->and($partial)->toContain('to{transform:translate3d(-50%,0,0)}')
        ->and($partial)->toContain('margin-inline-end:var(--kbbn-gap,16px)')
        ->and($partial)->not->toContain('.kbbn-tr{display:flex;gap:');
});

it('times one loop by the card count, so speed does not change with length', function () {
    /*
     * `speed_ms` is milliseconds PER CARD. Stored as a loop time instead, a set
     * of four and a set of thirty would move at wildly different speeds under
     * the same number and the owner would re-tune it every time he added a card.
     *
     * MUTATION: drop the `* $count` in Banners::cssVariables() and the two
     * durations below become the same number. Run, red, put back.
     */
    expect(bnsRender(['speed_ms' => 4000], 3))->toContain('--kbbn-dur:12.00s')
        ->and(bnsRender(['speed_ms' => 4000], 6))->toContain('--kbbn-dur:24.00s');
});

it('stops dead under reduced motion instead of slowing down', function () {
    /*
     * "prefers-reduced-motion: reduce stops it dead. Not slower — stopped, with
     * the cards laid out as a static row the shopper can still scroll by hand."
     *
     * All five halves of that are one block and all five matter: the animation
     * goes, the transform goes with it (or the track stays parked mid-slide),
     * the duplicate copy leaves the layout (or the shopper hand-scrolls through
     * the same cards twice), the scroller becomes scrollable again, and the
     * dots come back because now there is something for them to do.
     *
     * MUTATION: change `animation:none` to a longer duration — the "slower, not
     * stopped" answer this case is named after — and it goes red.
     */
    $partial = bnsPartial();

    $block = (string) preg_replace('/^.*@media \(prefers-reduced-motion: reduce\)\{/s', '', $partial);
    $block = substr($block, 0, (int) strpos($block, "\n}"));

    expect($block)->toContain('.kbbn-tr{animation:none;transform:none}')
        ->and($block)->toContain('.kbbn-dup{display:none}')
        ->and($block)->toContain('overflow-x:auto')
        ->and($block)->toContain('.kbbn.is-auto .kbbn-nav{display:flex}');
});

it('pauses on hover only when the set asks, and on focus-within always', function () {
    /*
     * focus-within is NOT the set's choice, and that is deliberate: a keyboard
     * user tabbing to a card's button cannot use a control that is sliding out
     * from under them, so it is an accessibility floor rather than a preference.
     * Hover is the preference.
     *
     * MUTATION: put `is-hoverpause` on the focus-within rule as well and the
     * second expectation goes red — a set with pause-on-hover switched off
     * would stop honouring the keyboard.
     */
    /*
     * The focus rule is matched WITH ITS LEADING NEWLINE, so the selector has
     * to start there. Without that anchor the assertion is a substring of the
     * gated form — `.kbbn.is-hoverpause .kbbn-vp:focus-within …` contains
     * `.kbbn-vp:focus-within …` — and the mutation this case is named after
     * stayed green. Measured, then fixed.
     */
    $partial = bnsPartial();

    expect($partial)->toContain("\n.kbbn-vp:focus-within .kbbn-tr{animation-play-state:paused}")
        ->and($partial)->toContain('.kbbn.is-hoverpause .kbbn-vp:hover .kbbn-tr{animation-play-state:paused}')
        ->and(bnsMarkup(['pause_on_hover' => true]))->toContain('is-hoverpause')
        ->and(bnsMarkup(['pause_on_hover' => false]))->not->toContain('is-hoverpause');
});

it('draws a still row with no duplicate when the set does not animate', function () {
    // `animation = off` and `autoplay = false` are two ways to say the same
    // thing and BannerSet::animates() is the one place that decides it, so the
    // template, the preview and this test cannot disagree about which won.
    foreach ([['animation' => 'off'], ['autoplay' => false]] as $attributes) {
        $html = bnsMarkup($attributes, 3);

        expect(substr_count($html, 'class="kbbn-c'))->toBe(3)
            ->and($html)->not->toContain('kbbn-dup')
            ->and($html)->not->toContain('class="kbbn is-auto');
    }
});

/* ═══════════════════════════ the LCP image ════════════════════════════════ */

it('makes the first card eager and high priority and every other one lazy', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT, on the shop: with this section on, the
     * first card's picture is the largest element above the fold on the
     * homepage — the LCP element. A carousel that lazy-loads its own first
     * image defers the page's headline paint behind the lazy-loading heuristic
     * and makes the homepage measurably slower, which is the single commonest
     * way a hero rail regresses LCP.
     *
     * The DUPLICATE's first card is lazy too: it is the same file, in cache by
     * the time it is reached, and a second fetchpriority="high" would compete
     * with the real one for the same bandwidth.
     *
     * MUTATION: drop the `&& ! $bnDup` from the eager condition and the
     * "exactly one" counts below go to two. Run, red, put back.
     */
    $html = bnsMarkup([], 3);

    expect(substr_count($html, 'fetchpriority="high"'))->toBe(1)
        ->and(substr_count($html, 'loading="lazy"'))->toBe(5)
        ->and(substr_count($html, 'decoding="async"'))->toBe(6)
        // Explicit box, so the picture cannot shift the page as it arrives.
        ->and(substr_count($html, 'width="900" height="1200"'))->toBe(6);

    // The eager one is the FIRST one, not merely one of them.
    expect(strpos($html, 'fetchpriority="high"'))->toBeLessThan((int) strpos($html, 'loading="lazy"'));
});

it('prints no width or height at all when the dimensions are unknown', function () {
    // A guessed width reserves the wrong box and shifts the page anyway, which
    // is worse than leaving the card's own aspect-ratio to hold the space.
    $html = bnsMarkup([], 1, [1 => ['image_w' => null, 'image_h' => null]]);

    expect($html)->not->toContain('width="')
        ->and($html)->not->toContain('height="');
});

/* ═══════════════════ rule 5: escaping and the scheme check ════════════════ */

it('escapes every string an operator typed', function () {
    /*
     * The heading, the body, the alt text and the button label are boxes on
     * Appearance → Banners. Printed unescaped, any one of them is stored
     * cross-site scripting on the front page of the shop — which is the exact
     * defect Lane FO fixed in the hero slider's headline, in this same file's
     * neighbourhood, when `home_banners` stopped being a literal in a
     * controller and became a box an owner types into.
     *
     * MUTATION: change any of the four {{ }} to {!! !!} and this goes red.
     */
    $html = bnsMarkup([], 1, [1 => [
        'heading' => '<script>alert(1)</script>',
        'body' => '"><img src=x onerror=alert(2)>',
        'alt' => '"><b>alt</b>',
        'button_label' => '<b>go</b>',
    ]]);

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&lt;b&gt;go&lt;/b&gt;');
});

it('refuses a javascript: URL however it is spelled, and draws no button for it', function () {
    /*
     * MUTATION NOTE, with the exact defect. Replace Banners::safeUrl() with a
     * `str_starts_with($url, 'javascript:')` test and the SECOND card below
     * ships a working javascript: href: a browser resolves
     * `jav&#x09;ascript:alert(1)` to a javascript URL and that test sees a
     * string starting "jav&" and waves it through. The order of the three steps
     * in safeUrl() — decode entities, strip control characters, THEN read the
     * scheme — is what makes the check run on the same string the browser will.
     *
     * A refused URL draws NO BUTTON rather than a dead one, which is the other
     * half: a lozenge that does nothing is worse than no lozenge.
     */
    $html = bnsMarkup([], 3, [
        1 => ['button_url' => 'javascript:alert(1)'],
        2 => ['button_url' => "jav&#x09;ascript:alert(1)"],
        3 => ['button_url' => '//evil.test/x'],
    ]);

    expect($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('//evil.test')
        ->and($html)->not->toContain('kbbn-btn');

    // And the safe shapes still work, including the two non-http schemes.
    foreach (['/shop/' => '/shop/', 'https://a.test/x' => 'https://a.test/x',
        'mailto:a@b.test' => 'mailto:a@b.test', 'tel:+97141234567' => 'tel:+97141234567'] as $raw => $expected) {
        expect(Banners::safeUrl($raw))->toBe($expected);
    }

    foreach (['javascript:alert(1)', "jav\tascript:alert(1)", '//evil.test', 'data:text/html;base64,x',
        "jav&#x09;ascript:alert(1)", ' JAVASCRIPT:alert(1)'] as $bad) {
        expect(Banners::safeUrl($bad))->toBe('');
    }
});

it('puts exactly one anchor on a card, and never nests two', function () {
    /*
     * A card wrapped in a link that also contains a link is invalid markup and
     * unusable with a screen reader. The anchor is placed once: on the BUTTON
     * when there is one, on the PICTURE when there is not.
     *
     * MUTATION: give `.kbbn-im` an href unconditionally and the first count
     * below doubles.
     */
    /*
     * COUNTED BY `href=`, NOT BY `<a `. The picture's element is an anchor
     * whether or not it carries an href — an <a> with no href is not a link,
     * which is exactly why the partial can use one element for both shapes —
     * so `<a ` counts elements and `href=` counts LINKS, and it is links that
     * must not nest or double.
     *
     * A still row (`animation => off`) so the doubled track does not multiply
     * the count and hide the thing being measured.
     */
    $withButton = bnsMarkup(['animation' => 'off'], 1);

    expect(substr_count($withButton, 'href='))->toBe(1)
        ->and($withButton)->toContain('class="kbbn-btn"');

    // Band off: the button follows it, and the picture takes the link instead,
    // so an image-only card is still clickable.
    $bandOff = bnsMarkup(['animation' => 'off', 'show_text' => false], 1);

    expect(substr_count($bandOff, 'href='))->toBe(1)
        ->and($bandOff)->not->toContain('kbbn-btn')
        ->and($bandOff)->not->toContain('kbbn-bd')
        ->and($bandOff)->toContain('class="kbbn-im" href=');
});

/* ══════════════════ "same sizes of the cards", as a property ══════════════ */

it('fixes every card to the set ratio and the band to a fixed height', function () {
    /*
     * The owner: "need same sizes of the cards". This is the rule that makes it
     * true — the card's height comes from `aspect-ratio` and from NOTHING else,
     * and the band inside it is `flex:0 0 <fixed>` with the copy clamped. A
     * card with two lines and a card with none are then identical, and so are a
     * card holding a portrait photograph and one holding a landscape one.
     *
     * MUTATION: change `.kbbn-bd{flex:0 0 var(--kbbn-band…)}` to `flex:1 1
     * auto` and this goes red; delete the `max-height` behind the line clamp
     * and the fallback assertion goes red — that declaration is the real guard,
     * because a browser that ignored -webkit-line-clamp would let a three-line
     * heading push the band open and break the whole rule.
     */
    $partial = bnsPartial();

    expect($partial)->toContain('aspect-ratio:var(--kbbn-ar,3 / 4)')
        ->and($partial)->toContain('.kbbn-bd{flex:0 0 var(--kbbn-band,58px);height:var(--kbbn-band,58px)')
        ->and($partial)->toContain('-webkit-line-clamp:1;max-height:var(--kbbn-lh,17px)')
        ->and($partial)->toContain('.kbbn-tx{min-width:0;flex:1 1 auto;display:flex;flex-direction:column;justify-content:center;')
        ->and($partial)->toContain('max-height:calc(var(--kbbn-lh,17px) * 2)')
        ->and($partial)->toContain('object-fit:cover');

    // The ratio really is the set's, and a ratio nobody issued falls back.
    expect(bnsRender(['ratio' => '16/9']))->toContain('--kbbn-ar:16 / 9');

    $rogue = new BannerSet;
    $rogue->forceFill(['ratio' => '; } body{display:none}', 'shadow' => 'x', 'animation' => 'x']);

    expect($rogue->ratioCss())->toBe('3 / 4')
        ->and($rogue->shadowCss())->toBe(BannerSet::SHADOWS['soft'][1])
        ->and($rogue->animationCss())->toBe('kbbn-slide');
});

it('leaves no gap where a missing heading, body or button would have been', function () {
    // A card with no body must not leave a gap and a card with no button must
    // not leave a hole. The band's height is fixed, so this is laid out inside
    // it rather than by changing it: the elements are simply not drawn.
    $html = bnsMarkup([], 1, [1 => ['body' => '', 'button_label' => '']]);

    expect($html)->toContain('kbbn-bd')
        ->and($html)->toContain('kbbn-h')
        ->and($html)->not->toContain('kbbn-b"')
        ->and($html)->not->toContain('kbbn-btn');

    // And a card with nothing at all in the band draws no band element, so the
    // picture fills the whole of the card's fixed box.
    $bare = bnsMarkup([], 1, [1 => ['heading' => '', 'body' => '', 'button_label' => '']]);

    expect($bare)->not->toContain('kbbn-bd');
});

it('draws the dots only when the set asks, and the arrows never as a fake control', function () {
    expect(bnsMarkup(['show_dots' => false]))->not->toContain('kbbn-nav')
        ->and(bnsMarkup(['show_dots' => true]))->toContain('kbbn-dot');

    /*
     * ARROWS ARE THE BROWSER'S OWN ::scroll-button() OR THEY ARE NOTHING.
     *
     * It is the only way to move a scroll container from CSS, and this section
     * has no script to do it with. Where the browser implements it the arrows
     * are real; where it does not, @supports fails and NO arrow is drawn —
     * rather than a button that looks like a control and is not one. The
     * screen's help text says so in as many words, which is the half of this
     * that is not a code property.
     */
    $partial = bnsPartial();

    expect($partial)->toContain('@supports selector(::scroll-button(inline-start))')
        ->and($partial)->toContain('::scroll-button(inline-end)');

    /*
     * ── THE DEFECT THIS CAUGHT, and a screenshot found it rather than a test ─
     *
     * Every ::scroll-button rule was written against `.kbbn-vp` with nothing
     * in front of it, so a browser that implements them drew a pair of arrows
     * on EVERY cards banner — including one whose owner had left "Show arrows"
     * off. The brief is explicit: "Arrows and dots are optional and off unless
     * the set asks for them", and an option that ships on is a default nobody
     * chose. The first picture of this section at 1280 had two arrows in the
     * corner of it and that is how it was found.
     *
     * MUTATION: drop `.kbbn.is-arrows` from the scroll-button selectors and
     * this goes red on the first expectation. Run, red, put back.
     *
     * ── ADVANCED BY LANE BP, AND THE PIN MOVED FOR A REASON ─────────────────
     *
     * Every one of these selectors now also carries `:not(.is-auto)` (outside
     * the reduced-motion query) or `.is-auto` (inside it), because the arrows
     * were being drawn over a marquee — see CardsBannerNavigationTest, which
     * holds that defect and its own mutation note. What this assertion is for
     * is unchanged and is checked the same way: EVERY scroll-button selector in
     * the file is under the set's own `is-arrows` switch, none is bare.
     */
    // The whole selector, not its last compound: a rule is a comma-separated
    // list and what matters is what stands in front of `::scroll-button` all
    // the way back to the start of that selector.
    preg_match_all('/([^{},\n]*)::scroll-button/', bnsCss(), $bnsButtons);

    expect($bnsButtons[1])->not->toBeEmpty();

    foreach ($bnsButtons[1] as $bnsSelector) {
        // The @supports test itself names the pseudo-element with no selector
        // in front of it; that is the feature detection, not a rule.
        if (str_contains($bnsSelector, '@supports')) {
            continue;
        }

        expect(str_contains($bnsSelector, '.is-arrows'))->toBeTrue(
            "a ::scroll-button rule is not under the set's own switch: {$bnsSelector}"
        );
    }

    expect(bnsMarkup(['show_arrows' => false]))->not->toContain('is-arrows')
        ->and(bnsMarkup(['show_arrows' => true]))->toContain('is-arrows');
});
