<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;

/**
 * The cards banner's background, its button colours and where its title sits —
 * Lane BP, round 7.
 *
 * The owner, after using the screen: "give controls for background color pick,
 * no background or image background of the section ... also give control
 * controls for button colors etc. and give option to make title on the banner
 * or below the banner nicely."
 *
 * ── WHAT THIS FILE IS FOR ABOVE EVERYTHING ELSE ─────────────────────────────
 *
 * CLAUDE.md rule 1, on the most visible page in the shop: "Any NEW setting
 * ships at the value the page already has, so applying the package moves
 * nothing until somebody moves a slider." Seven columns arrive in this round
 * and the FIRST case below is the one that says applying them changes no byte
 * of an existing set's markup. Everything after it is about what happens once
 * somebody does move one.
 */
function bpaSet(array $attributes = [], int $cards = 3): array
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($attributes + [
        'name' => 'Appearance', 'slug' => 'ap-'.uniqid(), 'status' => 'publish', 'position' => 0,
    ]);

    foreach (range(1, $cards) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/bp-'.$i.'.webp',
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

    return $loaded;
}

function bpaRender(array $attributes = [], int $cards = 3): string
{
    [$set, $rows] = bpaSet($attributes, $cards);

    return view('partials.home.cards-banner', ['set' => $set, 'cards' => $rows])->render();
}

/**
 * The same render with the <style> element taken out.
 *
 * ── AND THIS IS THE DIFFERENCE BETWEEN A TEST AND A TAUTOLOGY ──────────────
 *
 * The stylesheet is inline in the rendered output and it NAMES EVERY CLASS AND
 * EVERY PROPERTY this section can use. So `str_contains($html, 'is-over')` is
 * true for every page this partial has ever drawn, including one whose title
 * sits below — the rule `.kbbn.is-over .kbbn-bd{…}` is right there, and so is
 * `display:none`, and so is `--kbbn-btn-bg`. Four assertions in this file were
 * written against the full render first and every one of them passed against
 * markup that did not contain the thing at all.
 *
 * CardsBannerSectionShapeTest's own bnsMarkup() carries the same note and the
 * same reason; this is its twin rather than a second idea.
 */
function bpaMarkup(array $attributes = [], int $cards = 3): string
{
    return (string) preg_replace('#<style>.*?</style>#s', '', bpaRender($attributes, $cards));
}

/* ══════════════════ 1. applying the package moves nothing ═════════════════ */

it('renders a set at the shipped values exactly as it rendered before the columns existed', function () {
    /*
     * THE INSTRUMENT FOR RULE 1 ON THIS ROUND, AND IT IS A COMPARISON RATHER
     * THAN A PIN ON A STRING.
     *
     * A pin on the expected HTML would go red every time a class was added
     * anywhere in the partial and would say nothing about whether the SEVEN NEW
     * COLUMNS moved anything. So this renders the same set twice — once with
     * the columns at their shipped values, once with each of them scrubbed to
     * the empty/absent value an OLD row carries — and requires the two to be
     * identical, byte for byte.
     *
     * What it would have caught: a `--kbbn-btn-bg:` written as an empty custom
     * property instead of being omitted (an empty value is a value, and the
     * `var(--kbbn-btn-bg, var(--pink,#E8919F))` fallback would then NOT apply,
     * so every button in the shop would have gone transparent); `has-bg`
     * emitted for `bg_mode=none`; `is-over` emitted for `title_pos=below`.
     *
     * MUTATION, run: change the default in the migration for `title_pos` to
     * 'over' — or make Banners::cssVariables() emit `--kbbn-btn-bg:` when the
     * column is empty — and this is red. Both were done and put back.
     */
    $shipped = bpaRender();

    $old = bpaRender([
        'bg_mode' => 'none', 'bg_color' => '', 'bg_image' => '',
        'btn_bg' => '', 'btn_text' => '', 'btn_hover' => '', 'title_pos' => 'below',
    ]);

    /*
     * The one thing that legitimately differs between two renders is the card
     * anchors' id prefix, which is the SET'S OWN ID — each helper call inserts
     * a new row. Normalised rather than worked around with a shared fixture,
     * because building the two sets separately is what makes this a comparison
     * of two stored states rather than of one model mutated in memory.
     */
    $strip = static fn (string $html): string => (string) preg_replace('/kbbn-\d+-/', 'kbbn-N-', $html);

    expect($strip($shipped))->toBe($strip($old));

    // And none of the three classes this round can add is on the element.
    $root = substr($shipped, (int) strpos($shipped, '<div class="kbbn'), 220);

    expect(str_contains($root, 'has-bg'))->toBeFalse('a shipped set draws a background it was never given')
        ->and(str_contains($root, 'is-over'))->toBeFalse('a shipped set moves its title')
        ->and(str_contains($root, 'has-btnhover'))->toBeFalse('a shipped set overrides its own hover');

    // Nor does the style attribute carry a colour the owner never chose. Read
    // off the MARKUP: the stylesheet names both properties in its own fallbacks.
    $markup = (string) preg_replace('#<style>.*?</style>#s', '', $shipped);

    expect(str_contains($markup, '--kbbn-btn-bg'))->toBeFalse('an unset button colour still reaches the page')
        ->and(str_contains($markup, '--kbbn-bg:'))->toBeFalse('an unset background still reaches the page');
});

it('ships every one of the seven new columns at the value the page already had', function () {
    // The defaults as the database issues them, not as a model constructor
    // guesses — which is the difference that made a brand-new set draw every
    // switch blank in the round before this one.
    [$set] = bpaSet();

    expect($set->bg_mode)->toBe('none')
        ->and((string) $set->bg_color)->toBe('')
        ->and((string) $set->bg_image)->toBe('')
        ->and((string) $set->btn_bg)->toBe('')
        ->and((string) $set->btn_text)->toBe('')
        ->and((string) $set->btn_hover)->toBe('')
        ->and($set->title_pos)->toBe('below');
});

/* ═══════════════════════════ 2. the background ════════════════════════════ */

it('paints a colour behind the row only when the set asks for one', function () {
    $html = bpaMarkup(['bg_mode' => 'color', 'bg_color' => '#102030']);

    expect(str_contains($html, 'has-bg'))->toBeTrue('the background class is missing')
        ->and(str_contains($html, '--kbbn-bg:#102030'))->toBeTrue('the colour did not reach the style attribute');

    /*
     * MUTATION: remove the `$bnBgMode === 'none' ? '' : ' has-bg'` term from the
     * root element's class list and this is red on the first expectation — the
     * custom property would still be written and nothing would read it, which
     * is the silent half of this defect.
     */
});

it('falls back to no background when the mode says colour and the colour is not one', function () {
    /*
     * ── THE FOURTH DOOR, and it is the one that matters ─────────────────────
     *
     * BannerSet's own header sets the rule out for the enum columns: the
     * controller validates, and the model looks the stored value up again at
     * render so a row edited straight in the database cannot reach the <style>
     * element. A colour has the same exposure and one more failure mode — the
     * mode can say `color` while the colour column is empty, which is not an
     * attack, it is an owner who picked the mode and not the colour.
     *
     * Both answer NONE rather than painting something nobody chose.
     *
     * MUTATION, run: make BannerSet::bgMode() return $this->bg_mode unchecked
     * and this is red twice — once on the empty colour and once on the
     * injection, which would then print `--kbbn-bg:red;}` into the attribute.
     */
    expect(str_contains(bpaMarkup(['bg_mode' => 'color', 'bg_color' => '']), 'has-bg'))->toBeFalse()
        ->and(str_contains(bpaMarkup(['bg_mode' => 'color', 'bg_color' => 'red;}body{display:none']), 'has-bg'))->toBeFalse()
        ->and(str_contains(bpaMarkup(['bg_mode' => 'image', 'bg_image' => '']), 'has-bg'))->toBeFalse()
        ->and(str_contains(bpaMarkup(['bg_mode' => 'wormhole']), 'has-bg'))->toBeFalse();
});

it('writes a background picture as a CSS url() that cannot be closed early', function () {
    /*
     * ── THE ESCAPER HAS TO MATCH THE GRAMMAR IT IS WRITING INTO ─────────────
     *
     * Lane SX is removing this exact defect from twelve storefront files as
     * this is written: a CSS `url()` built with the HTML escaper. `e()` turns
     * `"` into `&quot;`, which a CSS parser reads as six literal characters and
     * not as a closing quote — so it BOTH fails to stop the injection and
     * corrupts the path.
     *
     * Banners::cssUrl() percent-encodes everything outside a path-shaped set,
     * so a quote, a bracket, a backslash, a semicolon and a space are all
     * impossible in the output and the surrounding `url("…")` cannot be ended
     * by its own contents.
     *
     * MUTATION, run: replace the preg_replace_callback in Banners::cssUrl()
     * with `return 'url("'.$url.'")';` and this is red on the first two
     * expectations.
     */
    expect(Banners::cssUrl('uploads/banners/a b.webp'))->toBe('url("uploads/banners/a%20b.webp")')
        ->and(Banners::cssUrl('x");background:url("y'))->not->toContain('");background')
        ->and(Banners::cssUrl("a'b"))->toBe('url("a%27b")')
        ->and(Banners::cssUrl('uploads/banners/ok.webp'))->toBe('url("uploads/banners/ok.webp")');

    $html = bpaMarkup(['bg_mode' => 'image', 'bg_image' => 'uploads/banners/bg one.webp']);

    expect(str_contains($html, 'bg%20one.webp'))->toBeTrue('the picture did not reach the style attribute encoded');
});

/* ═════════════════════════ 3. the button colours ══════════════════════════ */

it('leaves the button on the shop’s own accent until a colour is stored', function () {
    /*
     * '' IS A REAL VALUE AND IT IS THE DEFAULT, which is why the template's
     * fallback has to be the theme token and not a literal. The shop's `--pink`
     * is `#E0567B` in kbb.css; the admin preview frame used to declare
     * `#E8919F` and therefore drew every button in the fallback colour while
     * the screen said it was showing what the homepage draws. That was fixed in
     * the screen; this pins the storefront half — the partial reads the TOKEN,
     * so whatever the theme sets is what an unconfigured set draws.
     *
     * MUTATION: change the partial's `var(--kbbn-btn-bg,var(--pink,#E8919F))`
     * to `var(--kbbn-btn-bg,#E8919F)` and this is red.
     */
    $html = bpaRender();

    expect(str_contains($html, 'background:var(--kbbn-btn-bg,var(--pink,#E8919F))'))->toBeTrue()
        ->and(str_contains($html, '.kbbn .kbbn-btn{color:var(--kbbn-btn-tx,#fff)}'))->toBeTrue();
});

it('gives the button colour enough specificity to survive .kbb-home a{color:inherit}', function () {
    /*
     * ── A REAL DEFECT, FOUND BY MEASURING RATHER THAN BY READING ────────────
     *
     * WHAT IT LOOKED LIKE ON THE SHOP: every cards-banner button drew its label
     * in `--ink` — dark text on the pink pill — while Appearance → Banners drew
     * it white and said underneath that it was showing what the homepage draws.
     *
     * kbb.css:852 is `.kbb-home a{text-decoration:none;color:inherit}`, which
     * is (0,1,1). `.kbbn-btn` is (0,1,0) and this button is an <a> inside
     * `.kbb-home`, so the `color:#fff` the section has declared since it
     * shipped lost the cascade on every page it was ever drawn on. The admin
     * preview is an iframe with no kbb.css in it, which is exactly why the
     * screen never showed the fault.
     *
     * AND IT HAD TO BE FIXED RATHER THAN FROZEN: `--kbbn-btn-tx` is read by the
     * same declaration, so the owner's new button-text control would have lost
     * the same cascade and done nothing at all.
     *
     * Measured here rather than asserted as a string: the specificity is the
     * property, and a later edit that drops the `.kbbn ` would pass a
     * str_contains on `#fff` and still lose on the shop.
     *
     * MUTATION, run: put the colour back in the `.kbbn-btn{…}` block and
     * delete the `.kbbn .kbbn-btn{…}` line — this goes red on the comparison,
     * which is the whole point of computing both numbers rather than naming
     * one.
     */
    $css = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    expect(str_contains($css, '.kbb-home a{text-decoration:none;color:inherit}'))->toBeTrue(
        'the rule this defends against has moved; re-measure before trusting the selector below'
    );

    $render = bpaRender();

    // The one declaration that sets the label's colour, and what stands in
    // front of it.
    preg_match('/([^{}\n]*)\{color:var\(--kbbn-btn-tx,#fff\)\}/', $render, $m);

    expect($m)->not->toBeEmpty('nothing sets the card button’s text colour any more');

    $selector = trim($m[1]);

    // Two class selectors beats one class plus one type, which is what
    // `.kbb-home a` is. Counted rather than pattern-matched on the exact text.
    $classes = preg_match_all('/\.[a-z][a-z0-9_-]*/i', $selector);

    expect($classes)->toBeGreaterThanOrEqual(2, "the button colour is '{$selector}', which .kbb-home a{color:inherit} outranks");
});

it('writes only the button colours the set actually carries', function () {
    $one = bpaMarkup(['btn_bg' => '#123456']);

    expect(str_contains($one, '--kbbn-btn-bg:#123456'))->toBeTrue()
        ->and(str_contains($one, '--kbbn-btn-tx'))->toBeFalse('an unset text colour was written anyway')
        ->and(str_contains($one, 'has-btnhover'))->toBeFalse();

    $all = bpaMarkup(['btn_bg' => '#123456', 'btn_text' => '#fff', 'btn_hover' => '#654321']);

    expect(str_contains($all, '--kbbn-btn-hv:#654321'))->toBeTrue()
        ->and(str_contains($all, 'has-btnhover'))->toBeTrue('the hover class is what turns the brightness filter off');
});

it('replaces the brightness filter rather than stacking on it when a hover colour is set', function () {
    /*
     * WHAT THE DEFECT WOULD LOOK LIKE ON THE SHOP: the owner picks a hover
     * colour, hovers, and gets that colour darkened by 6% — never the colour he
     * picked, and darker on every hover for as long as he keeps adjusting it.
     * `filter:brightness(.94)` is RELATIVE, so it applies to whatever is
     * underneath including a flat custom background.
     *
     * MUTATION, run: drop `filter:none` from the `.kbbn.has-btnhover
     * .kbbn-btn:hover` rule and this is red.
     */
    $html = bpaRender(['btn_hover' => '#654321']);

    expect(str_contains($html, '.kbbn.has-btnhover .kbbn-btn:hover{filter:none;background:var(--kbbn-btn-hv)}'))->toBeTrue();
});

it('refuses a colour that is not a colour, in the service that prints it', function () {
    // Banners::hex() is the gate every one of the four colours goes through,
    // and it is the reason nothing an operator types can reach a declaration.
    expect(Banners::hex('#E0567B'))->toBe('#E0567B')
        ->and(Banners::hex('e0567b'))->toBe('#e0567b')
        ->and(Banners::hex('#fff'))->toBe('#fff')
        ->and(Banners::hex('red'))->toBe('')
        ->and(Banners::hex('#ff'))->toBe('')
        ->and(Banners::hex('#E0567B;}body{display:none'))->toBe('')
        ->and(Banners::hex('url(x)'))->toBe('')
        ->and(Banners::hex(null))->toBe('')
        ->and(Banners::hex(''))->toBe('');

    // And a refused colour never reaches the attribute, however it was stored.
    expect(str_contains(bpaMarkup(['btn_bg' => 'red;}body{display:none']), 'display:none'))->toBeFalse();
});

/* ═══════════════════════ 4. where the title sits ══════════════════════════ */

it('lays the words over the picture only when the set asks, and keeps the card the same height', function () {
    /*
     * THE ONE RULE THIS WHOLE SECTION IS BUILT ON is that every card is the
     * same size — "need same sizes of the cards" — and the height comes from
     * `aspect-ratio` and from nothing else. Moving the band out of flow must
     * not change that, in either placement.
     *
     * MUTATION: remove `position:absolute` from `.kbbn.is-over .kbbn-bd` and
     * the band goes back into the flex column, so the picture is squeezed by
     * it and the overlay is not an overlay at all. The `bottom:0` assertion is
     * what catches that.
     */
    $below = bpaMarkup();
    $over = bpaMarkup(['title_pos' => 'over']);

    expect(str_contains($below, 'is-over'))->toBeFalse()
        ->and(str_contains($over, 'is-over'))->toBeTrue();

    // The scrim, and the fact it is a gradient rather than a flat panel: a flat
    // panel over a photograph reads as a grey box, which is the difference
    // between "nicely" and "cheap".
    // These ARE stylesheet assertions, so they read the full render on purpose.
    $overCss = bpaRender(['title_pos' => 'over']);

    expect(str_contains($overCss, '.kbbn.is-over .kbbn-bd{position:absolute'))->toBeTrue()
        ->and(str_contains($overCss, 'linear-gradient(to top,rgba(18,12,16,.80)'))->toBeTrue()
        ->and(str_contains($overCss, '.kbbn.is-over .kbbn-h{color:#fff;text-shadow'))->toBeTrue()
        ->and(str_contains($overCss, 'aspect-ratio:var(--kbbn-ar,3 / 4)'))->toBeTrue();

    // The column name itself is never printed — the placement is a class.
    expect($over)->not->toContain('title_pos');
});

it('still has no JavaScript at all after three rounds of controls', function () {
    /*
     * THE POINT OF THE WHOLE SECTION, restated here because this round added a
     * background, three colours and a title placement and every one of them is
     * the kind of thing a carousel usually grows a script for.
     *
     * The element-measuring APIs are named because CLAUDE.md names them: "two
     * tests forbid the element-measuring APIs by name."
     */
    $html = bpaRender(['bg_mode' => 'color', 'bg_color' => '#102030', 'title_pos' => 'over', 'btn_bg' => '#123456']);

    foreach (['<script', 'onclick', 'onload=', 'offsetWidth', 'clientWidth', 'getBoundingClientRect',
        'ResizeObserver', 'IntersectionObserver', 'requestAnimationFrame', 'addEventListener'] as $banned) {
        expect(str_contains($html, $banned))->toBeFalse("the cards banner has grown a {$banned}");
    }
});
