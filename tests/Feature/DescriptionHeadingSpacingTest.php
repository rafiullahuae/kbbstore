<?php

declare(strict_types=1);

/**
 * The same space under every heading of an English description.   (Lane LN15)
 *
 * THE DEFECT, ON THE LIVE SHOP (15 November 2026). The owner: "in long
 * desecription in english. there'es some spacing issues under headings. plz
 * note, must be equal spacing under each heading." Measured in Chromium on all
 * 875 English product pages (tools/ln15-gaps.cjs, docs/plans/new-products-15-nov):
 * the gap between a heading line and the first line under it was 0px, 6px,
 * 11px or 18px by how the copy happened to be typed --
 *   `<p><b>How to use:</b></p>`                         11px (paragraph margin)
 *   `<div class="productDescription"><b>Benefits:</b></div><div><ul>`  6px (list margin)
 *   `<p><span><b>Recommended for:</b></span></p><p class="kbb-bl">`   18px (VT PDRN
 *     Reedle Shot hair serum, the newest product: an empty line under that one heading only)
 *   `<p><strong>About brand: …</strong></p><div>`         0px
 * and 223 products had two different gaps on one page. After: 2,609 of 2,615
 * headings 0-1px at 1280 (2,593 of 2,599 at 390), 2 products uneven (copy that
 * glues a heading to the line before it; a heading inside a <span>).
 *
 * The rule: a heading sits directly on its text; the space between sections
 * stays above the heading. English pages only -- /ar is drawn exactly as before.
 *
 * MUTATIONS, RUN:
 *   - drop the markHeadings() call in ProductController::tabs() -- the page case red;
 *   - drop its Locale::isDefault() guard -- the Arabic case red;
 *   - delete the second (bare bold) pass in markHeadings() -- the "old shop's bare
 *     <strong>" case red;
 *   - delete `.details .dcontent .kbb-hd + .kbb-bl` -- the CSS case red
 *     (and VT's 18px is back).
 */

use App\Models\Product;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ArabicShop;

/** The body with this lane's class taken back out: what went in, byte for byte. */
function ln15Unmark(string $html): string
{
    return str_replace([' class="kbb-hd-br"', ' class="kbb-hd"', 'class="kbb-hd ', '"kbb-hd"'], ['', '', 'class="', '""'], $html);
}

it('marks the heading in every shape the live copy writes one, and changes nothing else', function (string $in, array $headings) {
    $out = RichText::markHeadings($in);

    preg_match_all('#<(\w+)[^>]*\bkbb-hd(?![\w-])[^>]*>(.*?)</\1>#s', $out, $m);
    expect(array_map(fn ($h) => trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')), $m[2]))->toBe($headings)
        ->and(ln15Unmark($out))->toBe($in);
})->with([
    'the owner\'s own copy (Anua PDRN eye cream)' => [
        '<div><b>Key Benefits:</b></div><div><ul><li>Helps reduce puffiness.</li></ul></div><div>Made in Korea </div><div class="kbb-bl"></div><div><b>Hero Ingredients: </b></div><div><ul><li>PDRN.</li></ul><div class="kbb-bl"></div></div><div><b>How to Use: </b></div><div><ul><li>Apply.</li></ul></div><div><b>Size: </b><span>20 ml</span></div>',
        ['Key Benefits:', 'Hero Ingredients:', 'How to Use:'],
    ],
    'VT hair serum: an empty line under one heading' => [
        '<p><span><b>Benefits:</b></span></p><ul><li><span>Instant cool-down:</span><span> </span><span>Menthol.</span></li></ul><span><br></span><p><span><b>Recommended for:</b></span></p><p class="kbb-bl"></p><ul><li><span>Flat hair</span></li></ul><p class="kbb-bl"></p><p><span><b>How to Use:</b></span></p><ul><li><span>Brush.</span></li></ul>',
        ['Benefits:', 'Recommended for:', 'How to Use:'],
    ],
    'imported copy: a class already on the heading, a heading closing its wrapper' => [
        "<div class=\"productDescription\"><b>Benefits:</b></div>\n<div class=\"enrichedContent\">\n<ul>\n<li>Gentle.</li>\n</ul>\n<p>Made in Korea</p>\n<p><b>How to Use:</b></p>\n<ul>\n<li>Lather.</li>\n</ul>\n</div>\n<section><div><b>About brand:&nbsp;COSRX</b></div></section><div>COSRX makes…</div>",
        ['Benefits:', 'How to Use:', "About brand:\u{00A0}COSRX"],
    ],
    'the old shop\'s bare <strong> on its own line, and one closing a paragraph' => [
        "<div>\n\n<strong>Benefits:</strong>\n<ul>\n <li>SPF 50+</li>\n</ul>\nMade in Korea\n\n</div>\n<p>Made in Korea<br>\n<b>How to Use:</b></p>\n<ul>\n<li>Apply.</li>\n</ul>\n<section><b>Note:</b>\n<ul><li>Patch test.</li></ul></section>",
        ['Benefits:', 'How to Use:', 'Note:'],
    ],
    'a line break closing the heading (Dr.Reju-All, Anua Azelaic pad)' => [
        "<p>Made in Korea</p>\n<p><strong>How to Use:<br>\n</strong></p>\n<ul>\n<li>Apply.</li>\n</ul>\n<div><b>Key Benefits: </b><br><ul>\n<li>Calms.</li></ul></div>",
        ['How to Use:', 'Key Benefits:'],
    ],
    'an empty bold line opening the block under it (medicube Vanilla duo, 18px)' => [
        "<b>What this set includes: </b><div><b><br></b>\n<ul>\n <li class=\"p1\"><strong>Deodorant</strong> calms.</li>\n</ul>\n<strong><div class=\"kbb-bl\"></div>How to Use :&nbsp;</strong><div class=\"kbb-bl\"></div><div>Deodorant:</div></div>",
        ['What this set includes:', "How to Use :\u{00A0}"],
    ],
    'an h3 and a sub-heading under a heading' => [
        "<p><strong>Key ingredients</strong></p>\n<p><strong>Rice bran water 29%</strong></p>\n<p>Soothes.</p>\n<h3>About brand: Beauty of Joseon</h3>\n<p>Since 2010.</p>",
        ['Key ingredients', 'Rice bran water 29%', 'About brand: Beauty of Joseon'],
    ],
]);

it('leaves copy with no heading byte for byte: labels, list items, long bold lines, a last line, the designed blocks', function (string $in) {
    expect(RichText::markHeadings($in))->toBe($in);
})->with([
    'a bold label with its value on the line' => ['<p><b>Size:</b> 20 ml</p><p>More.</p>'],
    'a label inside a bullet' => ['<ul><li><b>Niacinamide:</b><br>Brightens.</li></ul><p>x</p>'],
    'bold in the middle of a line' => ['<p>Text <span>x</span><b>Mid:</b></p><ul><li>a</li></ul>'],
    'a bold label, a line break, then its text' => ['<p><b>How to use:</b><br>Apply it.</p><p>More.</p>'],
    'a bold sentence longer than a heading' => ['<p><b>'.str_repeat('Long bold promise ', 6).'</b></p><p>x</p>'],
    'a heading with nothing under it' => ["<p>Made in Korea</p>\n<p class=\"kbb-bl\"></p>\n<p><b>Hero Ingredients:</b></p>"],
    'an ingredients list' => ['<span>Water, Glycerin, Niacinamide(50,000ppm), Butylene Glycol</span>'],
    'the old shop\'s designed block (GlobalSections)' => ['<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Gentle Yet Effective</h3><div class="kbb-eblock__copy"><p><b>QUERCETINOL</b></p><p>Calms.</p></div></div><p>After.</p>'],
    'no HTML at all' => ['Plain words with no tags.'],
]);

function ln15Product(string $description): Product
{
    return Product::create([
        'slug' => 'ln15-'.Str::lower(Str::random(8)), 'name' => 'VT PDRN Reedle Shot Brush Hair Serum', 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 8900, 'stock_status' => 'instock',
        'short_description' => '<p>A scalp serum.</p>', 'description' => $description,
    ]);
}

it('marks the headings on the English product page, in both prints, and stores nothing', function () {
    $raw = "<p><span><b>Recommended for:</b></span></p><p><br></p><ul><li><span>Flat hair</span></li></ul><p><br></p><p><span><b>How to Use:</b></span></p><ul><li><span>Brush.</span></li></ul>";
    $p = ln15Product($raw);

    $html = (string) $this->get('/product/'.$p->slug.'/')->assertOk()->getContent();
    $drawn = '<p class="kbb-hd"><span><b>Recommended for:</b></span></p><p class="kbb-bl"></p><ul><li><span>Flat hair</span></li></ul><p class="kbb-bl"></p><p class="kbb-hd"><span><b>How to Use:</b></span></p>';

    expect(substr_count($html, $drawn))->toBe(2) // tab panel + phone accordion
        ->and(Product::find($p->id)->description)->toBe($raw);
});

it('draws the Arabic page exactly as before: no mark, the same bytes', function () {
    ArabicShop::on();
    $p = ln15Product('<p><b>Benefits:</b></p><ul><li>Soft.</li></ul>');
    $ar = '<p><b>الفوائد:</b></p><p><br></p><ul><li>ناعم.</li></ul>';
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $p->id, 'field' => 'description',
        'value' => $ar, 'status' => 'published', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    \App\Services\Translation\TranslationStore::flush();

    $html = (string) $this->get('/ar/product/'.$p->slug.'/')->assertOk()->getContent();

    // What the page drew before this lane: the stored Arabic through the same
    // render steps, lined up with its English (2.60.671) -- and no kbb-hd.
    $before = RichText::markBlankLines(RichText::trimBlankEdges(\App\Support\BlockStructure::forDisplay('<p><b>Benefits:</b></p><ul><li>Soft.</li></ul>', $ar)));
    expect(substr_count($html, '<div class="dcontent clamp">'.$before.'</div>'))->toBe(1)
        ->and($html)->not->toContain('kbb-hd');
});

it('costs no query: a page with headings asks the database exactly what one without asks', function () {
    $plain = ln15Product('<p>Soft.</p><ul><li>Light.</li></ul>');
    $headed = ln15Product('<p><b>Benefits:</b></p><ul><li>Light.</li></ul>');
    $this->get('/product/'.$plain->slug.'/')->assertOk(); // warm the settings / caches alike

    $count = function (Product $p): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/product/'.$p->slug.'/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    expect($count($headed))->toBe($count($plain));
});

it('draws one gap under every heading: none, the space between sections stays above', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));

    expect(substr_count($css, '.details .dcontent .kbb-hd{margin-block-end:0}'))->toBe(1)
        // the plain rule needs no :has(), so an older browser keeps it
        ->and(substr_count($css, ".details .dcontent .kbb-hd + :not(#kbb-hd),\n.details .dcontent .kbb-hd + br + :not(#kbb-hd),\n.details .dcontent .kbb-hd + * > :first-child,\n.details .dcontent .kbb-hd + * > :first-child > :first-child,\n.details .dcontent .kbb-hd + * > :first-child > :first-child > :first-child,\n.details .dcontent .kbb-hd + * > :first-child > :first-child > :first-child > :first-child{margin-block-start:0}"))->toBe(1)
        ->and(substr_count($css, ".details .dcontent .kbb-hd + .kbb-bl,\n.details .dcontent .kbb-hd + * > .kbb-bl:first-child{height:0}"))->toBe(1)
        ->and(substr_count($css, '.details .dcontent p:has(> .kbb-hd:last-child){margin-block-end:0}'))->toBe(1)
        ->and(substr_count($css, '.details .dcontent .kbb-hd-br{display:none}'))->toBe(1);

    // and the built stylesheet the shop links carries it (CLAUDE.md: rebuild after a CSS change)
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-product.css']['file']));
    expect($built)->toContain('.kbb-hd+.kbb-bl,');
});
