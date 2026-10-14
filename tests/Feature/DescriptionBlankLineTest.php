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

it('sizes the blank line in the product stylesheet without margins of its own', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect(substr_count($css, '.details .dcontent p.kbb-bl{margin-block-end:0;height:.75em}'))->toBe(1);
});
