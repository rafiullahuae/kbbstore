<?php

declare(strict_types=1);

use App\Services\CheckoutPage;
use App\Support\TrustClaims;

/**
 * The four field types the Checkout page screen claims to draw, and the three
 * selects that shipped before it could draw any of them.
 *
 * WHAT THIS EXISTS FOR, IN ONE SENTENCE. `fieldHTML()` handled `bool` and then
 * returned an `<input type="range">` for everything else, so `ph_tone` and
 * `ph_weight` (2.60.252) and `m_float` (2.60.253) each rendered as
 *
 *     <input type="range" min="undefined" max="undefined" value="muted">
 *
 * — a slider with no scale, showing a value it cannot represent — and the input
 * handler then stored `Number(el.value)`, which is NaN. A control that cannot be
 * read and cannot be stored is worse than one that is missing, because the
 * screen says the setting exists.
 *
 * SlimFooter's own header said "the same four the checkout screen already
 * draws". It drew two.
 *
 * MUTATION: delete the `f.type === 'select'` branch from the screen and the
 * first test is red. Delete the `kind === 'select'` arm from the input handler
 * and the second is red.
 */
$screen = fn (): string => (string) file_get_contents(
    resource_path('views/admin/partials/checkout-page-screen.blade.php'),
);

it('draws a select as a select and a text field as a text field', function () use ($screen) {
    $html = $screen();

    expect($html)->toContain("if (f.type === 'select')")
        ->and($html)->toContain('<select class="chp-sel"')
        ->and($html)->toContain("if (f.type === 'text')")
        ->and($html)->toContain('<input class="chp-text" type="text"');
});

it('reads a value by the field type and not by the DOM element type', function () use ($screen) {
    $html = $screen();

    /*
     * The old line was `else values[key] = Number(el.value)`, reached by every
     * non-checkbox. Both halves matter: the branch has to exist, and the
     * numeric one has to be the LAST resort rather than the default.
     */
    expect($html)->toContain("if (kind === 'bool') values[key] = el.checked;")
        ->and($html)->toContain("else if (kind === 'select' || kind === 'text') values[key] = String(el.value);")
        ->and($html)->toContain('else values[key] = Number(el.value);');
});

it('has a rule behind every class the two new field types wear', function () use ($screen) {
    expect($screen())->toContain('.chp-f select.chp-sel,.chp-f input.chp-text{');
});

/* ------------------------------------------------------------------------
 | The reviews line: the wording is the owner's, the figures are not
 |------------------------------------------------------------------------*/

it('refuses a wording template that carries a digit of its own', function () {
    $page = app(CheckoutPage::class);
    $shipped = CheckoutPage::SCHEMA['rating_text'][2];

    /*
     * `reassure_rating_text` was a free-text setting whose shipped default read
     * "4.8 · loved by 2,300+ UAE customers" on a shop with no reviews at all.
     * It was removed for that. Handing the WORDING back without handing the
     * FIGURE back is the only way this control can exist.
     */
    $page->save(['rating_text' => '4.9 · loved by 2,300+ UAE customers']);
    expect(app(CheckoutPage::class)->all()['rating_text'])->toBe($shipped);

    // The tokens are not digits, so a template made only of them is kept.
    $page->save(['rating_text' => '{rating} · loved by {count} UAE customers']);
    expect(app(CheckoutPage::class)->all()['rating_text'])
        ->toBe('{rating} · loved by {count} UAE customers');

    // Empty and over-long both fall back rather than storing.
    $page->save(['rating_text' => '']);
    expect(app(CheckoutPage::class)->all()['rating_text'])->toBe($shipped);

    $page->save(['rating_text' => str_repeat('a', 121)]);
    expect(app(CheckoutPage::class)->all()['rating_text'])->toBe($shipped);
});

it('says nothing at all when there are too few approved reviews', function () {
    // No reviews table rows in this test, so the line is null whatever the
    // wording says. Three "say nothing" cases answer identically on purpose.
    expect(app(CheckoutPage::class)->ratingLine())->toBeNull();
});

/* ------------------------------------------------------------------------
 | The header band, and the notch to the left of the logo
 |------------------------------------------------------------------------*/

it('lines the phone header band up with the page, and centres it only on request', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    /*
     * Measured at 390px with the mobile header width at 280: `.in` ran 55…335,
     * the logo started at 75 and the page's own "Back to shop" started at 20 —
     * a 55px notch with nothing on the page matching it, and the badge inside
     * overflowing to 370 so the right side looked flush and the left did not.
     *
     * The default is the page's edges and the CLASS restores the centring, so
     * the default emits no class and `class="kbb-checkout"` is unchanged.
     */
    expect($css)->toContain('.kbb-checkout .co-head .in{max-width:none;margin-inline:0;padding-inline:var(--cop-m-headpadx,var(--cop-padx))}')
        ->and($css)->toContain('.kbb-checkout.cop-mhead-center .co-head .in{max-width:var(--cop-headmax);margin-inline:auto;padding-inline:var(--cop-headpadx)}')
        ->and($css)->toContain('.kbb-checkout.cop-dhead-page .co-head .in{max-width:none;margin-inline:0}');
});

it('emits no class for either alignment default', function () {
    // The two point opposite ways -- centred is the desktop default, lined up
    // is the phone's -- and both defaults still have to render nothing.
    expect(app(CheckoutPage::class)->bodyClass())->toBe('');

    app(CheckoutPage::class)->save(['m_head_align' => 'center']);
    expect(app(CheckoutPage::class)->bodyClass())->toContain('cop-mhead-center');

    app(CheckoutPage::class)->save(['d_head_align' => 'page']);
    expect(app(CheckoutPage::class)->bodyClass())->toContain('cop-dhead-page');
});

it('keeps the authenticity wording where the rest of the shop keeps its claims', function () {
    // Not moved onto the checkout screen, and the Trust & reviews tab says so
    // in words: one key with two screens writing it is two places for the shop
    // to say something different about itself.
    expect(TrustClaims::CLAIMS)->toHaveKey('reassure_auth_text')
        ->and(CheckoutPage::SCHEMA)->not->toHaveKey('reassure_auth_text')
        ->and(CheckoutPage::TABS['trust'][1])->toContain('Business Details');
});

/* ------------------------------------------------------------------------
 | The phone header band, swept rather than reasoned about
 |------------------------------------------------------------------------*/

it('takes the page\'s own side padding, so the header and the page cannot disagree', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));

    /*
     * REMOVING THE CENTRING WAS HALF THE ANSWER and the report came back
     * unchanged, so this was swept in Chromium at 390px rather than reasoned
     * about: 4 header widths x 3 header paddings x 3 page paddings = 36
     * combinations, asserting `.co-head .logo` left === `.backlink` left.
     *
     *   before this rule   several combinations misaligned, worst 40px
     *   after              0 of 36 misaligned
     *
     * The second cause was `--cop-headpadx`: a control separate from the
     * page's `--cop-padx` that happens to default to the same 20. Set the
     * header's to 40 and the page's to 8 and the logo sits 32px inside every
     * other block, with the badge still reaching the right edge because an auto
     * margin puts it there — which is the asymmetry in the photograph.
     *
     * Two numbers that must agree are one number.
     *
     * MUTATION: put --cop-headpadx back in the first rule. Red.
     */
    expect($css)->toContain('.kbb-checkout .co-head .in{max-width:none;margin-inline:0;padding-inline:var(--cop-m-headpadx,var(--cop-padx))}')
        // And the centred mode keeps its own padding, because there the band is
        // deliberately not the page's width and lining the padding up would
        // mean nothing.
        ->and($css)->toContain('.kbb-checkout.cop-mhead-center .co-head .in{max-width:var(--cop-headmax);margin-inline:auto;padding-inline:var(--cop-headpadx)}');
});

it('does NOT hide the header side padding — that was the wrong call', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/checkout-page-screen.blade.php'));

    /*
     * SUPERSEDED, AND THE OLD REASONING IS KEPT because it was not silly, it
     * was incomplete. It read:
     *
     *     "A slider that moves nothing is worse than no slider: the owner
     *      drags it, watches nothing happen, and reports the control as
     *      broken."
     *
     * True — but hiding it meant the header's side padding could not be set at
     * all in the mode that ships, and that is what the owner reported instead.
     * The stylesheet now makes the slider mean something in both modes, so
     * there is nothing left to hide. See the test above for the measurement.
     */
    expect($screen)->not->toContain("if (f.key === 'm_head_pad_x') return");
});

/* ------------------------------------------------------------------------
 | The presets, and the tab they belong to
 |------------------------------------------------------------------------*/

it('squeezes the tab you are looking at and leaves the other eight alone', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/checkout-page-screen.blade.php'));

    /*
     * The owner's report, exactly: "when i click squeezed, it applies on all
     * tabs all checkout page settings, which is not correct". He is right, and
     * the reason is worth keeping: a preset that reaches past the screen
     * changes numbers nobody can see, so the only way to learn what it did is
     * to visit nine tabs.
     *
     * Driven in Chromium: on Mobile · Product rows, Squeeze took m_row_gap
     * 11 → 2 and m_tab_pad 9 → 2, while d_pad_x stayed 20 and d_max stayed
     * 1040 on the Desktop · Layout tab.
     *
     * MUTATION: put `tabs.forEach` back in place of `current.fields.forEach`
     * and the first assertion is red.
     */
    expect($screen)->toContain("var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];")
        ->and($screen)->toContain('current.fields.forEach(function (f) {')
        // The label has to say so too: a button called "Squeeze everything"
        // that squeezes one tab is the same defect wearing the other face.
        ->and($screen)->toContain('>Squeeze this tab<')
        ->and($screen)->not->toContain('>Squeeze everything<')
        ->and($screen)->toContain('<b>this tab</b>');
});

it('scopes the footer screen\'s presets the same way, without waiting for the same report twice', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/slim-footer-screen.blade.php'));

    expect($screen)->toContain("var current = tabs.filter(function (t) { return t.key === open; })[0] || tabs[0];")
        ->and($screen)->toContain('>Squeeze this tab<')
        ->and($screen)->toContain('<b>this tab</b>');
});

/* ------------------------------------------------------------------------
 | The third cause of the notch beside the logo
 |------------------------------------------------------------------------*/

it('beats the site header\'s bare .logo rule, which stretched the box and centred the text', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
    $base = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    /*
     * kbb.css:1612 carries `.logo{font-size:18px;flex:1;text-align:center}`
     * inside its own @media(max-width:900px). It is the SITE header's mobile
     * layout — the wordmark centring itself between the burger and the cart —
     * and the checkout's logo carries the same class.
     *
     * WHY TWO ROUNDS OF MEASURING MISSED IT, which is the part worth keeping:
     * `flex:1` stretches the ELEMENT and `text-align:center` moves the GLYPHS
     * inside it, so the box's left edge stays correct. Measured at 390px
     * before: .logo box left 20 — the same 20 as the page's own content — and
     * the first letter at 47.2. A getBoundingClientRect() reports 20 and calls
     * it aligned; a Range over the contents reports 47.2 and does not.
     * After: glyphs at 20 at 390px, and at 140 at 1280px, both equal to the
     * page's own left edge.
     *
     * MUTATION: remove the rule below and the glyph offset returns.
     */
    expect($base)->toContain('.logo{font-size:18px;flex:1;text-align:center}')
        ->and($css)->toContain('.kbb-checkout .co-head .logo{flex:0 0 auto;text-align:start}');
});

it('does not treat the back-to-top arrow as a ruled row', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    /*
     * A regression I shipped in 2.60.255. The `rows` shape gave every direct
     * child of .sf-in a min-height and `display:flex`, and a top border plus
     * row padding to every child after the first — and .sf-top is a direct
     * child. That overrode the button's own `place-items:center` and added 7px
     * above it. Measured at 390 before: button centre x 355 y 2312.6, glyph
     * centre x 348.5 y 2316.1 — off in both axes, which is the report.
     * After: button centre and glyph centre equal.
     *
     * MUTATION: drop either `:not(.sf-top)` and the glyph goes off centre again.
     */
    expect($partial)->toContain('.kbb-slimfoot.sf-rows .sf-in > *:not(.sf-top){min-height:var(--sf-rowh)')
        ->and($partial)->toContain('.kbb-slimfoot.sf-rows .sf-in > *:not(.sf-top) + *:not(.sf-top){')
        ->and($partial)->toContain('.kbb-slimfoot.sf-msplit.sf-m-rows .sf-in > *:not(.sf-top){min-height:var(--sf-rowh)');
});

it('hides the arrow until the page has been scrolled, and hides it by default', function () {
    $partial = (string) file_get_contents(resource_path('views/partials/slim-footer.blade.php'));

    /*
     * The RESTING state is hidden and the class is what shows it, so a page
     * whose script never runs draws no arrow rather than a dead one. Width and
     * border collapse too: an invisible 30px box at the end of a one-line bar
     * would still push everything before it.
     *
     * An observer on a 1px sentinel at the top of the document, not a scroll
     * handler — this project does not write layout-measuring JavaScript, and a
     * scroll handler would read scrollY on every frame to answer a question the
     * browser already knows.
     *
     * Measured at 390: at the top, sf-up-on absent and visibility hidden; after
     * scrolling 1200px, present and visible.
     *
     * MUTATION: flip the two rules so .sf-top is visible by default and the
     * "hidden at the top" assertion is red.
     */
    expect($partial)->toContain('opacity:0;visibility:hidden;pointer-events:none;')
        ->and($partial)->toContain('.kbb-slimfoot.sf-up-on .sf-top{')
        ->and($partial)->toContain("bar.classList.toggle('sf-up-on', !entries[entries.length - 1].isIntersecting);")
        ->and($partial)->not->toContain('window.addEventListener(\'scroll\'')
        ->and($partial)->toContain('@media(prefers-reduced-motion:reduce){');
});

/* ------------------------------------------------------------------------
 | The header's side padding, which I had hidden
 |------------------------------------------------------------------------*/

it('keeps the header side-padding slider, and lets the page be its default', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-checkout.css'));
    $screen = (string) file_get_contents(resource_path('views/admin/partials/checkout-page-screen.blade.php'));

    /*
     * I hid this slider when "lined up with the page" became the phone's
     * default, reasoning that the header read the page's padding there so the
     * slider would move nothing — and that a slider which moves nothing is
     * worse than no slider. The reasoning was fine; the outcome was that the
     * owner had no way to set the header's side padding at all in the mode
     * that ships, which is what he reported.
     *
     * `--cop-m-headpadx` is the RAW source property and cssVariables() emits it
     * only when it differs from its default, so the fallback chain does both
     * jobs with one declaration: untouched, the header takes the page's
     * padding; moved, it wins.
     *
     * Measured at 390px:
     *   untouched            glyph 20, page 20          (aligned)
     *   page padding -> 8    glyph  8, page  8          (still aligned)
     *   header slider -> 34  glyph 34, page  8          (the slider wins)
     *
     * MUTATION: put the bare `padding-inline:var(--cop-padx)` back and the
     * third row goes back to 8 — the slider stops doing anything.
     */
    expect($css)->toContain('padding-inline:var(--cop-m-headpadx,var(--cop-padx))')
        ->and($screen)->not->toContain("if (f.key === 'm_head_pad_x') return")
        // And it is still on the tab it belongs to.
        ->and(CheckoutPage::TABS['mobile_head'][2])->toContain('m_head_pad_x');
});
