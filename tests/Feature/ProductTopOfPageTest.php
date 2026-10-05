<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\ProductLayout;
use App\Services\SettingsService;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * The top of the product page, as the owner reported it on a phone. (Lane PV)
 * =============================================================================
 *
 *   1. "The short description on mobile for this product is not showing by
 *       default, and it shows after pressing Read more but with upper space."
 *   2. "The thumbnail icons please bring down beneath the image, I don't like
 *       the overlapping style ... keep it turned off by default."
 *   3. "The top space I want to remove completely or give option to reduce."
 *   4. "Between rating, title etc, I don't have enough controls ..."
 *   5. "The discount label has a white box and white text ... It should be a
 *       green box with white text."
 *
 * Every case here goes red on the shop as it was, and says what it looked like.
 * The measured numbers behind each are docs/lane-pv-shots/{before,after}-
 * measure.json, from tools/pv-shots.cjs against tools/pv-seed.php.
 */
function pvProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'pv-'.Str::random(8),
        'name' => 'Madagascar Centella Hyalu-Cica Water-Fit Sun Serum (50ml x 2) Twin Pack',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 15000,
        'stock_status' => 'instock',
        'short_description' => 'A watery, weightless sun serum.',
        'description' => '<p>Built around a single idea.</p>',
    ], $overrides));
}

/** The blurb element's inner HTML on the rendered page, or null when none is drawn. */
function pvBlurb(string $html): ?string
{
    return preg_match('#<(p|div) class="[^"]*\bbb-desc">(.*?)</\1><label class="bb-more"#s', $html, $m) === 1
        ? $m[2]
        : null;
}

/** A stylesheet's source and the bundle the manifest serves, both flattened. */
function pvSheets(): array
{
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $file = $manifest['resources/css/kbb/kbb-product.css']['file'] ?? null;

    expect($file)->not->toBeNull();

    $flat = static fn (string $css): string => str_replace(['"', "'"], '', (string) preg_replace('/\s+/', '', $css));

    return [
        'source' => $flat((string) file_get_contents(resource_path('css/kbb/kbb-product.css'))),
        'built bundle' => $flat((string) file_get_contents(public_path('build/'.$file))),
    ];
}

/* ═════════════════ 1. the short description that was not there ═════════════ */

it('draws the blurb from its first word when the import opened it with empty paragraphs', function () {
    /*
     * THE OWNER'S PRODUCT, REPRODUCED. A WooCommerce post_excerpt whose first
     * two lines are `<p>&nbsp;</p>` -- what the classic editor saves for a
     * blank line typed above the copy. The blurb is capped at three lines with
     * a fade, and those two empty paragraphs are 60px of the 86px cap: measured
     * in Chromium, the copy began 81.9px down an 85.6px box, under the fade, so
     * the page read title, blank band, "Read more". After the fix it begins at
     * 22px -- the hairline's own padding -- exactly where the control
     * product's blurb begins.
     *
     * MUTATION NOTE, RUN: change `RichText::forShortDescription(` back to
     * `RichText::forStorefront(` in resources/views/store/product.blade.php and
     * this is red on the first expectation, with the two empty paragraphs at the
     * head of the blurb.
     */
    $product = pvProduct([
        'short_description' => "<p>&nbsp;</p>\r\n<p>&nbsp;</p>\r\n<p>A watery, weightless SPF50+ sun serum.</p>\r\n<p>&nbsp;</p>",
    ]);

    $blurb = pvBlurb($this->get('/product/'.$product->slug)->assertOk()->getContent());

    expect($blurb)->not->toBeNull('the page drew no blurb at all');
    expect($blurb)->toBe('<p>A watery, weightless SPF50+ sun serum.</p>');
});

it('trims every empty shape an imported blurb arrives in, at both ends', function (string $raw, string $expected) {
    /*
     * The other ways "a blank line" is spelt in imported copy. Each of these
     * left a band above the words for the same reason the owner's did.
     *
     * MUTATION NOTE, RUN: make trimEdge() return false at its first line and
     * every row here is red; drop the zero-width characters from BLANK and the
     * `&#8203;` row alone is red.
     */
    expect(RichText::forShortDescription($raw))->toBe($expected);
})->with([
    'nbsp paragraphs' => ["<p>&nbsp;</p><p>&nbsp;</p><p>Copy</p>", '<p>Copy</p>'],
    'leading <br>s' => ['<br><br>Copy', 'Copy'],
    '<br> and nbsp inside the first paragraph' => ['<p><br>&nbsp;Copy</p>', '<p>Copy</p>'],
    'empty div, span and bold nbsp' => ['<div></div><span> </span><p><strong>&nbsp;</strong></p><p>Copy</p>', '<p>Copy</p>'],
    'bare-newline copy with nbsp lines' => ["\n\n&nbsp;\n\nCopy line one\nline two\n\n&nbsp;", "<p>Copy line one<br>\nline two</p>"],
    'nbsp lines inside one autop paragraph' => ["&nbsp;\n&nbsp;\nCopy", '<p>Copy</p>'],
    'an empty heading before a list' => ['<h2>&nbsp;</h2><ul><li>One</li></ul>', '<ul><li>One</li></ul>'],
    'a zero-width space' => ['<p>&#8203;</p><p>Copy</p>', '<p>Copy</p>'],
    'trailing empties' => ['<p>Copy</p><p>&nbsp;</p><br>', '<p>Copy</p>'],
    'a section that draws nothing' => ['<p>[rey_global_section id="999999"]</p><p>&nbsp;</p><p>Copy</p>', '<p>Copy</p>'],
]);

it('keeps the author\'s spacing between paragraphs and anything visible at an edge', function () {
    // Only the EDGES go. A blank line between two paragraphs is spacing the
    // author typed, and an image with no text is still something to look at.
    expect(RichText::forShortDescription('<p>One</p><p>&nbsp;</p><p>Two</p>'))
        ->toBe("<p>One</p><p>\u{00A0}</p><p>Two</p>");
    expect(RichText::forShortDescription('<p><img src="/a.png" alt=""></p><p>Copy</p>'))
        ->toBe('<p><img src="/a.png" alt=""></p><p>Copy</p>');
});

it('hands back a blurb with nothing to trim as the very same string', function (string $raw) {
    /*
     * CLAUDE.md rule 1. Every other product page in the shop must not move by
     * a byte, so a blurb with no empty edge is NOT re-serialised -- the
     * DOMDocument round trip would re-spell entities and attribute quoting.
     *
     * MUTATION NOTE, RUN: make trimBlankEdges() always serialise (drop the
     * `if (! $changed) return $html;`) and the two layout-whitespace rows are
     * red -- the newline and the space inside the <p> are trimmed although the
     * page drew nothing for them.
     */
    expect(RichText::forShortDescription($raw))->toBe(RichText::forStorefront($raw));
})->with([
    'plain text' => ['A plain blurb with no tags.'],
    'one paragraph' => ['<p>Copy here.</p>'],
    'entities and a link' => ['<p>Salt &amp; pepper <a href="/x">here</a></p>'],
    'a newline inside the first paragraph' => ["<p>\nCopy</p>"],
    'a space inside the first paragraph' => ['<p> Copy</p>'],
]);

it('draws no box and no Read more for a blurb that is nothing but empty lines', function () {
    /*
     * The same defect at its limit: `<p>&nbsp;</p>` alone printed an empty
     * clamped box under a hairline with "Read more ↓" under it, and pressing
     * it revealed nothing.
     *
     * MUTATION NOTE, RUN: delete `&& $kbbBlurb !== ''` from the @if above the
     * buy form and this is red on the `bbMore` count.
     */
    $product = pvProduct(['short_description' => "<p>&nbsp;</p>\r\n<p>&nbsp;</p>"]);

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    expect(substr_count($html, 'class="bb-head"'))->toBe(1);
    expect(substr_count($html, 'id="bbMore"'))->toBe(0);
    expect($html)->not->toContain('bb-desc"');
});

/* ═════════════════ 5. the discount badge that was white on white ════════════ */

it('paints the photo\'s discount badge green with white text', function () {
    /*
     * THE DEFECT, MEASURED: `background-color: rgba(0, 0, 0, 0)` and `color:
     * rgb(255, 255, 255)` on the "-16%" over a white photograph -- a white box
     * (the shadow drew its edge) with white text. Of the gallery's three badge
     * branches only this one wrote no colour, and `.lbl` sets none. After:
     * rgb(31, 157, 85), the shop's #1F9D55.
     *
     * MUTATION NOTE, RUN: take `lbl-off` off the span in
     * partials/product-gallery.blade.php and the first expectation is red; take
     * the `.gmain .lbl-off` rule out of kbb-product.css and rebuild, and the
     * sheet loop is red on both halves.
     */
    $product = pvProduct(['sale_price' => 12600]);

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    expect($html)->toMatch('#<span class="[^"]*\blbl lbl-off" style="top:14px;left:14px">-16%</span>#');

    foreach (pvSheets() as $where => $css) {
        expect(str_contains($css, '.gmain.lbl-off{background:var(--pl-badge-bg,#1A7F45);color:var(--pl-badge-fg,#FFFFFF)}')
            || str_contains($css, '.gmain .lbl-off{background:var(--pl-badge-bg,#1A7F45);color:var(--pl-badge-fg,#FFFFFF)}'))
            ->toBeTrue("the discount badge has no colour in the {$where}");
    }
});

it('leaves a Product Labels badge in the colour it was given', function () {
    // The class is on the third branch only: with Growth & Marketing → Product
    // Labels on, the sale badge is that module's, in that module's colour.
    app(SettingsService::class)->setModule('product_labels', true);

    $product = pvProduct(['sale_price' => 12600]);

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    expect($html)->toMatch('#<span class="[^"]*\blbl" style="top:14px;left:14px;background:\#E23A4E">-16% OFF</span>#');
    expect($html)->not->toContain('lbl-off');
});

it('refuses anything but a hex colour on its way into the stylesheet', function () {
    // Rule 5. The cast refuses it first; hex() in vars() is the second lock,
    // for a row written by hand on the live box.
    $vars = ProductLayout::vars(array_merge(ProductLayout::defaults(), [
        'badge_bg' => 'red;}body{display:none', 'badge_fg' => 'url(javascript:1)',
    ]));

    expect($vars['--pl-badge-bg'])->toBe('#1A7F45');   // Lane CT: the approved contrast green
    expect($vars['--pl-badge-fg'])->toBe('#FFFFFF');

    app(ProductLayout::class)->save(['badge_bg' => '#c13e63']);
    expect(app(ProductLayout::class)->all()['badge_bg'])->toBe('#C13E63');
});

/* ═════════════════ 2 + 3. the thumbnails, and the space above the photo ════ */

it('sits the thumbnails under the photo on a phone, with the overlap a switch away', function () {
    /*
     * MEASURED: the strip's top was 30px INSIDE the photograph (-30px margin);
     * it is now 10px below it. `calc(10px - n * 40px)` with n = 1 is exactly
     * the old -30px, so "On" is the old look and not an approximation of it.
     *
     * MUTATION NOTE, RUN: put `margin-block-start:-30px` back in the phone rule
     * and this is red in the source; leave the source and skip `npx vite build`
     * and it is red in the built bundle only.
     */
    foreach (pvSheets() as $where => $css) {
        expect(str_contains($css, '.pdp.gthumbs{margin-block-start:calc(10px-var(--pl-thumb-over,0)*40px)')
            || str_contains($css, '.pdp .gthumbs{margin-block-start:calc(10px - var(--pl-thumb-over,0) * 40px)'))
            ->toBeTrue("the phone strip is not placed by the overlap switch in the {$where}");
    }

    expect(ProductLayout::defaults()['thumb_over'])->toBe('0');

    app(ProductLayout::class)->save(['thumb_over' => '1']);
    expect(app(ProductLayout::class)->storefrontCss())->toContain('--pl-thumb-over:1');

    // Anything but one of its own two options is the shipped Off.
    app(ProductLayout::class)->save(['thumb_over' => '2']);
    expect(app(ProductLayout::class)->all()['thumb_over'])->toBe('0');
    expect(app(ProductLayout::class)->storefrontCss())->toBe('');
});

it('removes the space above the photo on a phone and keeps the laptop\'s', function () {
    /*
     * MEASURED: 22px between the bottom of the search bar (127px) and the top
     * of the photograph (149px) at 390; 0 after, the photo at 127px. 1280 is
     * 22 before and after -- he asked about the phone.
     *
     * MUTATION NOTE, RUN: change `var(--pl-gal-top-m,0px)` to 22px and
     * ProductPageLayoutTest's fallback case is red as well as this one.
     */
    foreach (pvSheets() as $where => $css) {
        expect(str_contains($css, '.pdp{row-gap:0;padding-block-start:var(--pl-gal-top-m,0px)}'))
            ->toBeTrue("the phone's space above the photo is not the control's in the {$where}");
        expect(str_contains($css, '.pdp{padding-block-start:var(--pl-gal-top-d,22px);--pdp-rule-pad:var(--pl-rule-pad-d,20px)}'))
            ->toBeTrue("the laptop's space above the photo is not the control's in the {$where}");
    }

    expect(ProductLayout::defaults()['gal_top_m'])->toBe(0);
    expect(ProductLayout::defaults()['gal_top_d'])->toBe(22);
});

/* ═════════════════ 4. the gaps, per device, and the migration ═══════════════ */

it('gives every gap down the buy column a phone and a laptop control', function () {
    $tab = ProductLayout::TABS['sp_buy'][2];

    foreach ([['buybox_gap', 'buybox_gap_d'], ['head_gap', 'head_gap_d'], ['rate_gap', 'rate_gap_d'],
        ['desc_gap_m', 'desc_gap_d'], ['more_gap_m', 'more_gap_d'], ['opt_gap_m', 'opt_gap_d'],
        ['rule_pad', 'rule_pad_d']] as [$phone, $laptop]) {
        expect($tab)->toContain($phone, $laptop);
        expect(ProductLayout::SCHEMA[$phone][1])->toContain('phone');
        expect(ProductLayout::SCHEMA[$laptop][1])->toContain('laptop');
    }

    expect(ProductLayout::TABS['ty_buy'][2])->toContain('brand_s');
});

it('carries a value he had already saved into the halves it was split into', function () {
    /*
     * A shared control became two. Without this, the first save of ANY other
     * control would emit the new laptop key at its default and quietly undo a
     * gap he had moved. And "Photo → brand · phone" now includes the grid's
     * 26px, so a saved 10 must become 36 to draw the same.
     *
     * MUTATION NOTE, RUN: empty the migration's COPIES and the head_gap_d
     * expectation is red; drop the `+ 26` and the buybox one is.
     */
    $settings = app(SettingsService::class);
    $settings->set('pdplay_head_gap', 4);
    $settings->set('pdplay_rule_gap', 12);
    $settings->set('pdplay_buybox_gap', 10);
    $settings->set('pdplay_opt_gap_d', 30); // already split by hand: left alone

    $migration = require database_path('migrations/2027_07_12_000000_clear_caches_product_top_controls.php');
    ob_start();
    $migration->up();
    ob_end_clean();

    $settings->flush();
    $all = app(ProductLayout::class)->all();

    expect($all['head_gap_d'])->toBe(4);
    expect($all['desc_gap_m'])->toBe(12);
    expect($all['desc_gap_d'])->toBe(12);
    expect($all['opt_gap_m'])->toBe(12);
    expect($all['opt_gap_d'])->toBe(30);
    expect($all['buybox_gap'])->toBe(36);
    expect($all['rate_gap_d'])->toBe(10);
    expect(DB::table('settings')->where('key', 'pdplay_rate_gap_d')->exists())->toBeFalse();
});
