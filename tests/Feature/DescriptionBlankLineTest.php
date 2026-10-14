<?php

declare(strict_types=1);

/**
 * A blank line typed between paragraphs is one line of space.  (2.60.503)
 *
 * THE DEFECT, ON THE LIVE SHOP (14 October 2026). On Anua Azelaic 10 every
 * Enter on an empty line in the description editor -- saved as `<p><br></p>`,
 * `<p><b></b><br></p>` or `<p>&nbsp;</p>` -- drew a full line-box plus a
 * paragraph margin on each side, ~48px between "Made in Korea" and "Hero
 * Ingredients:" where a paragraph break is ~11px. The owner: "it should be
 * normal space like one line that's it."
 *
 * MUTATIONS, RUN:
 *   - drop the markBlankLines() call in ProductController::tabs() -- the page
 *     case red;
 *   - make markBlankLines() ignore VISIBLE_EMPTY -- the picture case red;
 *   - delete `.details .dcontent p.kbb-bl` from kbb-product.css -- the CSS case red.
 */

use App\Models\Product;
use App\Support\RichText;
use Illuminate\Support\Str;

it('marks every shape of empty paragraph the editors save, and nothing with content', function () {
    $html = '<p class="p1">Made in Korea</p>'."\n".'<p class="p2"><br></p>'."\n"
        .'<p class="p3"><b></b><br></p>'."\n".'<p>&nbsp;</p>'."\n".'<p><strong> </strong></p>'
        ."\n".'<p><b>Hero Ingredients:</b></p>';

    expect(RichText::markBlankLines($html))->toBe('<p class="p1">Made in Korea</p>'."\n"
        .'<p class="kbb-bl"></p>'."\n".'<p class="kbb-bl"></p>'."\n".'<p class="kbb-bl"></p>'
        ."\n".'<p class="kbb-bl"></p>'."\n".'<p><b>Hero Ingredients:</b></p>');
});

it('keeps a paragraph holding a picture or a rule, and copy with no blank line byte for byte', function () {
    $pic = '<p>One</p><p><img src="/x.jpg" alt="" width="10" height="10"></p><p><hr></p><p>Two</p>';
    $plain = "<p><strong>Benefits:</strong></p>\n<ul><li>Soft</li></ul>\n<p>Copy &amp; more.</p>";

    expect(RichText::markBlankLines($pic))->toBe($pic)
        ->and(RichText::markBlankLines($plain))->toBe($plain);
});

it('draws the blank line on the product page as one line, and stores nothing', function () {
    $raw = "<p>Made in Korea</p>\n<p class=\"p2\"><br></p>\n<p><b>Hero Ingredients:</b></p>";
    $p = Product::create([
        'slug' => 'dbl-'.Str::lower(Str::random(8)), 'name' => 'Anua Azelaic 10', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 6500, 'stock_status' => 'instock',
        'short_description' => '<p>Pads.</p>', 'description' => $raw,
    ]);

    $html = (string) $this->get('/product/'.$p->slug.'/')->assertOk()->getContent();

    expect(substr_count($html, "<p>Made in Korea</p>\n<p class=\"kbb-bl\"></p>\n<p><b>Hero Ingredients:</b></p>"))->toBe(2) // tab panel + phone accordion
        ->and(Product::find($p->id)->description)->toBe($raw);
});

it('closes up only a description written with empty lines, and draws each empty line 18px', function () {
    // 2.60.504, the owner: "single enter ... without any spacing ... enter two
    // times ... 18px space, rest 0 0" and "if the description is already
    // correct. don't mess up further." Mutation: drop `:has(.kbb-bl)` and
    // every description in the shop closes up -- the first expectation is red.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect(substr_count($css, '.details .dcontent:has(.kbb-bl) :is(p,ul,ol){margin-block-end:0}'))->toBe(1)
        ->and(substr_count($css, '.details .dcontent:has(.kbb-bl) :is(ul,ol){margin-block-start:0}'))->toBe(1)
        ->and(substr_count($css, '.details .dcontent:has(.kbb-bl) :is(ul,ol) > li:last-child{margin-bottom:0}'))->toBe(1)
        ->and(substr_count($css, '.details .dcontent .kbb-bl{display:block;margin-block:0;padding:0;height:var(--pl-body-blank,18px)}'))->toBe(1)
        // The plain paragraph rule every other description uses is where it was.
        ->and(substr_count($css, '.details .dcontent p{margin-block-end:.75em}'))->toBe(1);
});

it('takes the old site\'s section padding, repeated empty lines and jammed headings out of a description', function () {
    // 2.60.507: the spacing check of all 754 pages (docs/description-spacing).
    // 175 pages had 52-218px gaps from `section{padding:52px 0}` applied to the
    // <section> wrappers in their copy; empty lines in a row drew 18px each;
    // seven headings sat 0px under bare text. Mutation: delete the section
    // rule and the Anua Peach 77 page is back to a 103px top gap.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect(substr_count($css, '.details .dcontent section{padding:0;margin:0}'))->toBe(1)
        ->and(substr_count($css, '.details .dcontent .kbb-bl + .kbb-bl{height:0}'))->toBe(1)
        ->and(substr_count($css, '.details .dcontent:not(:has(.kbb-bl)) :is(p,div):not(:first-child):has(> :is(strong,b):only-child){margin-block-start:.75em}'))->toBe(1);
});

it('marks a <div><br></div> blank line, drops an empty list, and leaves a truly empty div alone', function () {
    expect(RichText::markBlankLines('<section><p>x</p><div><br></div><p>y</p><ul><li></li></ul></section>'))
        ->toBe('<section><p>x</p><div class="kbb-bl"></div><p>y</p></section>')
        // Innermost div only: the wrapper keeps its tags.
        ->and(RichText::markBlankLines('<div><div><br></div></div><p>b</p>'))->toBe('<div><div class="kbb-bl"></div></div><p>b</p>')
        // `<div></div>` draws nothing today and stays nothing.
        ->and(RichText::markBlankLines('<p>a</p><div></div><p>b</p>'))->toBe('<p>a</p><div></div><p>b</p>')
        // A list with words, or with a picture, is a list.
        ->and(RichText::markBlankLines('<ul><li>Soft</li></ul>'))->toBe('<ul><li>Soft</li></ul>');
});

it('does not let a blank line after the last word close up the whole description', function () {
    // The Anua Niacinamide TXA pad: its only blank line was the last thing in
    // the body, which switched on the close-up layout and jammed every section
    // (2.60.504's defect). Mutation: put trimLeadingBlank() back in
    // ProductController::tabs() -- red.
    $p = Product::create([
        'slug' => 'dbl-trail-'.Str::lower(Str::random(6)), 'name' => 'Anua Niacinamide 5 TXA Pad', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 6500, 'stock_status' => 'instock',
        'short_description' => '<p>Pads.</p>', 'description' => "<p><b>Benefits:</b></p>\n<ul><li>Brightens</li></ul>\n<p>Made in korea</p>\n<p><br></p>",
    ]);

    $html = (string) $this->get('/product/'.$p->slug.'/')->assertOk()->getContent();

    expect($html)->not->toContain('kbb-bl')
        ->and($html)->toContain('<p>Made in korea</p></div>');
});

it('carries the empty-line size from the admin to the page', function () {
    $defaults = collect(\App\Services\ProductLayout::SCHEMA)->map(fn ($d) => $d[2])->all();

    expect(\App\Services\ProductLayout::css($defaults))->toContain('--pl-body-blank:18px')
        ->and(\App\Services\ProductLayout::css(['body_blank' => 24] + $defaults))->toContain('--pl-body-blank:24px');
});
