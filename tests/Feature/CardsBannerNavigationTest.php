<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;

/**
 * How a shopper steers the cards banner: the dots, and the arrows — Lane BP.
 *
 * Lane BN's report, §7: *":target can style the card but not its dot without
 * :has() gymnastics. They navigate; they do not indicate."* The brief's answer:
 * the gymnastics may simply be the answer, and if it genuinely cannot be done
 * without JavaScript, say so — do not add JavaScript.
 *
 * It can be done. `:has()` is not a bet this shop is making for the first time:
 * `resources/css/kbb/kbb-checkout.css:359` uses it LOAD-BEARINGLY — a payment
 * method's own panel is `display:block` only through
 * `.wc_payment_method:has(input:checked) .payment_box`, so a browser without
 * `:has()` shows no payment fields at all — and kbb.css:3521 uses it as a
 * progressive nicety for the toast, with the note "where :has() is unsupported
 * the toast simply keeps today's position". This section takes the second
 * shape: with `:has()` the dot is marked, without it the dots are exactly the
 * dots that shipped.
 *
 * ── AND THE ARROWS, WHICH WERE DRAWN OVER A MARQUEE ─────────────────────────
 *
 * The other half of this file is a real defect the brief's item 3 turned up on
 * the way past. See the case that names it.
 */
function bpnRender(array $attributes = [], int $cards = 4): string
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($attributes + [
        // Dots ON, because that is what this file is about. They ship OFF —
        // CardsBannerShipsOffTest is where that is pinned — and the rules below
        // are emitted only when a set asks for them, so a set with the dots off
        // pays nothing for this feature at all.
        'name' => 'Nav', 'slug' => 'nav-'.uniqid(), 'status' => 'publish', 'position' => 0,
        'show_dots' => true,
    ]);

    foreach (range(1, $cards) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/bp-nav-'.$i.'.webp',
            'alt' => 'Alt '.$i, 'heading' => 'Heading '.$i, 'body' => 'Body '.$i,
            'button_label' => 'Shop', 'button_url' => '/shop/',
            'image_w' => 900, 'image_h' => 1200, 'position' => $i, 'status' => 'publish',
        ]);
    }

    $loaded = app(Banners::class)->forPreview($set->id);

    expect($loaded)->not->toBeNull();

    return view('partials.home.cards-banner', ['set' => $loaded[0], 'cards' => $loaded[1]])->render();
}

/** The partial's SOURCE with every comment removed — see CardsBannerSectionShapeTest. */
function bpnCss(): string
{
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(
        base_path('resources/views/partials/home/cards-banner.blade.php')
    ));

    return (string) preg_replace('#/\*.*?\*/#s', '', $src);
}

/* ═══════════════════ the dot that says where you jumped ═══════════════════ */

it('marks the dot for the card the shopper navigated to, with one rule per card', function () {
    /*
     * WHAT IT LOOKED LIKE ON THE SHOP BEFORE THIS: six identical grey dots. A
     * shopper pressed the fourth, the row scrolled, and nothing on the page
     * said which one he was on — so the dots were a set of six buttons with no
     * state, which is the shape people press twice.
     *
     * THE MECHANISM, and it is two selectors: a dot's href is its card's id, so
     * following it makes that card `:target`; `:has()` looks from the row back
     * down at which card that is; `:nth-child()` names the dot that points at
     * it. One rule per card, emitted by the template's own loop, and no script.
     *
     * MUTATION, run: delete the @foreach that emits these rules and this case
     * is red on the first expectation. Change `:nth-child($bnI + 1)` to
     * `:nth-child($bnI)` and it is red on the pairing check below, which is the
     * off-by-one that would have marked the dot before the one pressed.
     */
    $html = bpnRender([], 4);

    // Four cards, four rules, each pairing card i with dot i+1.
    preg_match_all('/\.kbbn:has\(#(kbbn-\d+)-(\d+):target\) \.kbbn-dot:nth-child\((\d+)\)/', $html, $rules, PREG_SET_ORDER);

    expect($rules)->toHaveCount(4);

    foreach ($rules as $i => $rule) {
        expect((int) $rule[2])->toBe($i, 'the rules are not in card order');
        expect((int) $rule[3])->toBe($i + 1, "card {$rule[2]} is paired with dot {$rule[3]}");
    }

    // And the id each rule names is a real element in the markup, with a dot
    // whose href points at it — the two halves the rule joins.
    $markup = (string) preg_replace('#<style>.*?</style>#s', '', $html);

    foreach ($rules as $rule) {
        expect(str_contains($markup, 'id="'.$rule[1].'-'.$rule[2].'"'))->toBeTrue("no card carries {$rule[1]}-{$rule[2]}")
            ->and(str_contains($markup, 'href="#'.$rule[1].'-'.$rule[2].'"'))->toBeTrue("no dot points at {$rule[1]}-{$rule[2]}");
    }
});

it('marks the dot with the set’s own button colour and nothing an operator typed', function () {
    /*
     * Rule 5: "Anything printed unescaped is a constant, never a setting." The
     * generated rules carry a SELECTOR built from two integers — the set's id
     * and the loop index — and a declaration built from a custom property name
     * and the same theme fallback the button uses. Nothing from the row.
     *
     * MUTATION: print `$set->btn_bg` into the declaration instead of
     * `var(--kbbn-btn-bg,…)` and this is red — a colour would then reach the
     * stylesheet without going through Banners::hex().
     */
    $html = bpnRender(['name' => '"><style>x{y:z}</style>'], 2);

    preg_match_all('/\.kbbn-dot:nth-child\(\d+\)\{([^}]*)\}/', $html, $decls);

    expect($decls[1])->toHaveCount(2);

    foreach ($decls[1] as $declaration) {
        expect($declaration)->toBe('width:20px;border-radius:999px;background:var(--kbbn-btn-bg,var(--pink,#E8919F))');
    }
});

it('emits no dot rules at all for a set that does not show dots', function () {
    /*
     * One rule per card is cheap; N rules on a page that draws no dots is N
     * rules for nothing, on the homepage, for every shop that has this section
     * on and the dots off — which is the SHIPPED state.
     *
     * MUTATION: remove the `@if ($bnDots && count($cards) > 1)` around the loop
     * and this is red.
     */
    expect(bpnRender(['show_dots' => false], 6))->not->toContain(':target)')
        ->and(bpnRender(['show_dots' => true], 1))->not->toContain(':target)');
});

it('needs no @supports and no second code path for a browser without :has()', function () {
    /*
     * An unsupported selector makes the WHOLE RULE invalid and the browser
     * drops it, so the "no :has()" path is the dots exactly as they shipped —
     * `.kbbn-dot{width:8px;height:8px;border-radius:50%;…}` — with nothing to
     * keep in step and nothing to test separately. This asserts that the base
     * dot rule is still there and is still unconditional, which is the thing
     * that would actually break if somebody later moved it inside the :has()
     * block to save a line.
     */
    $css = bpnCss();

    expect(str_contains($css, '.kbbn-dot{width:8px;height:8px;border-radius:50%;'))->toBeTrue()
        ->and(str_contains($css, '@supports selector(:has'))->toBeFalse(
            'the dots grew a feature query, which means the fallback is no longer the rule the browser drops'
        );
});

/* ═══════════════════════════════ the arrows ═══════════════════════════════ */

it('draws no arrows over a row that is scrolling by itself', function () {
    /*
     * ── THE DEFECT, AND IT WAS A SCREEN SAYING SOMETHING THE CODE DID NOT DO ─
     *
     * Appearance → Banners has said since the section shipped that "dots and
     * arrows only appear while the row is not scrolling by itself". Half of it
     * was true — `.kbbn.is-auto .kbbn-nav{display:none}` hides the dots.
     * NOTHING HID THE ARROWS.
     *
     * The assumption underneath was that `.kbbn.is-auto .kbbn-vp{overflow-x:
     * hidden}` would stop Chrome generating them. It does not: `overflow:
     * hidden` still makes an element a scroll container — it only stops the
     * SHOPPER scrolling it — so the scroll buttons were generated, were enabled
     * (the track is `width:max-content`, so there is scrollable overflow), and
     * pressing one scrolled a box whose contents are simultaneously being moved
     * by a CSS animation. The row ends up at an offset neither the animation
     * nor the scroll position agrees about, and the seam shows.
     *
     * WHAT IT LOOKED LIKE ON THE SHOP: a marquee with two round buttons over
     * it; pressing one made the row jump and then carry on from the wrong
     * place, and it could not be pressed back.
     *
     * MUTATION, run: remove `:not(.is-auto)` from the four selectors outside
     * the reduced-motion query and this case is red on the first expectation.
     */
    $css = bpnCss();

    // Outside the reduced-motion query, every scroll-button rule is gated on
    // the row NOT being a marquee.
    $outside = substr($css, 0, (int) strpos($css, '@media (prefers-reduced-motion: reduce)'));

    preg_match_all('/([^{},\n]*)::scroll-button/', $outside, $found);

    $selectors = array_values(array_filter($found[1], static fn (string $s) => ! str_contains($s, '@supports')));

    expect($selectors)->not->toBeEmpty();

    foreach ($selectors as $selector) {
        expect(str_contains($selector, ':not(.is-auto)'))->toBeTrue(
            "an arrow is drawn over a marquee: {$selector}"
        );
    }
});

it('brings the arrows back under reduced motion, where the row is a rail again', function () {
    /*
     * Under `prefers-reduced-motion: reduce` the animation is removed, the
     * duplicate copy leaves the layout and the scroller goes back to
     * `overflow-x:auto` — so the row IS hand-scrollable and a control that
     * scrolls it has something real to do. The dots already came back this way;
     * the arrows now do too, and the pair of rules has to be written out in
     * full because a media query cannot un-say a `:not()`.
     *
     * MUTATION: delete the @supports block inside the reduced-motion query and
     * this is red — a shopper who asked for less motion would then get the one
     * state where the row is steerable and no arrows to steer it with.
     */
    $css = bpnCss();

    $reduced = substr($css, (int) strpos($css, '@media (prefers-reduced-motion: reduce)'));

    expect(str_contains($reduced, '.kbbn.is-arrows.is-auto .kbbn-vp::scroll-button(inline-start)'))->toBeTrue()
        ->and(str_contains($reduced, '.kbbn.is-arrows.is-auto .kbbn-vp::scroll-button(inline-end)'))->toBeTrue()
        ->and(str_contains($reduced, '.kbbn.is-auto .kbbn-nav{display:flex}'))->toBeTrue();
});

it('says on the screen which browsers the arrows reach, beside the switch itself', function () {
    /*
     * ── THE ARGUED DECISION, PINNED SO IT CANNOT BE QUIETLY UNDONE ──────────
     *
     * `::scroll-button()` is the only way to move a scroll container from CSS,
     * and Safari and Firefox draw nothing. The brief: "Decide whether that is
     * honest enough ... Either make the switch say which browsers it reaches,
     * or hide it where it cannot work."
     *
     * IT CANNOT BE HIDDEN WHERE IT CANNOT WORK, and that is not a limitation of
     * effort. The server does not know the SHOPPER'S browser when it renders
     * the admin screen — it knows the OWNER'S. A control hidden by the owner's
     * user agent would appear when he logged in from Chrome and vanish when he
     * logged in from Safari, on the same shop, with the stored value unchanged;
     * that is worse than the thing it fixes, because it makes the setting look
     * broken rather than limited.
     *
     * So: the switch says it. Not a paragraph under six unrelated switches,
     * which is what shipped and is why the reach was effectively invisible —
     * the arrows have their own band with the dots, the sentence names the four
     * browsers, and it ends by pointing at the control that does work
     * everywhere.
     */
    $screen = (string) file_get_contents(base_path('resources/views/admin/partials/banners-screen.blade.php'));

    expect(str_contains($screen, 'Arrows are drawn by Chrome and Edge only.'))->toBeTrue(
        'the screen no longer names the browsers the arrows reach'
    );

    expect(str_contains($screen, 'The dots work in every browser'))->toBeTrue(
        'the screen no longer offers the control that works everywhere'
    );

    // And the claim the code now really keeps, in the same sentence.
    expect(str_contains($screen, 'while it is scrolling by itself there is nothing for them to steer'))->toBeTrue();
});
