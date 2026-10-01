<?php

declare(strict_types=1);

/*
 * =============================================================================
 * ELEMENTOR JSON -> THIS SHOP'S OWN HTML  (Lane PJ-B)
 * =============================================================================
 *
 * ON THE SHOP: the Description tab of the owner's Anua Heartleaf foam opened
 * with the letters `[rey_global_section id="18159"]`. On the old site that spot
 * was a Rey Global Section built in Elementor -- "Gentle Yet Effective
 * Ingredients", then QUERCETINOL / ANTI-SEBUM P / 0.5% BHA in a row, each a
 * square picture with a bold uppercase name and a sentence. The exporter ships
 * the section's raw Elementor tree; App\Services\Import\ElementorToHtml is what
 * turns it into the block. Every test here names the defect it would catch.
 */

use App\Services\Import\ElementorToHtml;
use Tests\Support\ContentBlocksFixture;

function pjbConvert(mixed $tree, ?string $plain = null): array
{
    return (new ElementorToHtml)->convert(is_string($tree) ? $tree : json_encode($tree), $plain);
}

function pjbWidget(string $type, array $settings = []): array
{
    return ['id' => 'x', 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => []];
}

function pjbSection(array ...$columns): array
{
    return ['id' => 's', 'elType' => 'section', 'settings' => [], 'elements' => array_map(
        static fn (array $children): array => ['id' => 'c', 'elType' => 'column', 'settings' => [], 'elements' => $children],
        $columns,
    )];
}

it('draws the owner\'s section as a heading and a row of three ingredients', function () {
    /*
     * The whole point. Before: the shortcode printed as letters, because
     * nothing could turn a Global Section into anything at all.
     *
     * MUTATION, RUN: element() returning implode('', $children) for a
     * `section` (no row) -- red, `kbb-eblock__row--3` absent; the three
     * ingredients stacked one under another at 1280.
     */
    $out = pjbConvert(ContentBlocksFixture::section18159(), ContentBlocksFixture::plain18159());

    expect($out['mode'])->toBe('elementor')
        ->and($out['unknown'])->toBe([])
        ->and($out['html'])->toStartWith("<div class=\"kbb-eblock\">\n<h3 class=\"kbb-eblock__heading\">Gentle Yet Effective Ingredients</h3>\n<div class=\"kbb-eblock__row kbb-eblock__row--3\">")
        ->and(substr_count($out['html'], 'class="kbb-eblock__row kbb-eblock__row--3"'))->toBe(1)
        ->and(substr_count($out['html'], 'class="kbb-eblock__col"'))->toBe(3)
        ->and(substr_count($out['html'], 'class="kbb-eblock__item"'))->toBe(3);

    foreach (ContentBlocksFixture::ingredients() as $item) {
        expect($out['html'])
            ->toContain('<h4 class="kbb-eblock__title">' . e($item['name']) . '</h4>')
            ->toContain('<div class="kbb-eblock__text">' . $item['text'] . '</div>')
            ->toContain('src="https://kbeautybliss.com/wp-content/uploads/' . $item['file'] . '"');
    }

    /*
     * ONE STRUCTURAL ELEMENT PER LINE. The owner edits this in a textarea on
     * Content -> HTML Blocks, and as clean() serialises it the whole section
     * was one 1.5 KB line. Heading, row, three columns, three items and the
     * closing tag: nine line breaks.
     *
     * MUTATION, RUN: lines() returning $html unchanged -- red, 0.
     */
    expect(substr_count($out['html'], "\n"))->toBe(9);
});

it('gives every picture a size and lazy loading, read off the WordPress file name', function () {
    /*
     * A picture with no width/height reserves no space, so the Description
     * tab jumps as each one arrives -- three times per product.
     *
     * MUTATION, RUN: dimensions() returning null always -- the image-box falls
     * back to 300x300, so the pinned 300x300 stays green; the standalone image
     * below loses its size and goes red.
     */
    $out = pjbConvert([pjbSection(
        [pjbWidget('image-box', ['image' => ['url' => 'https://old.test/wp-content/uploads/a-150x150.png'], 'title_text' => 'A'])],
        [pjbWidget('image', ['image' => ['url' => 'https://old.test/wp-content/uploads/b-640x427.jpg', 'alt' => 'Bottle']])],
    )]);

    expect($out['html'])
        ->toContain('<img class="kbb-eblock__img" src="https://old.test/wp-content/uploads/a-150x150.png" alt="A" width="150" height="150" loading="lazy">')
        ->toContain('<img class="kbb-eblock__pic" src="https://old.test/wp-content/uploads/b-640x427.jpg" alt="Bottle" width="640" height="427" loading="lazy">');
});

it('maps each heading size into h2 to h4, and anything else to h3', function () {
    /*
     * The product page owns the single h1, and the tab's own outline sits
     * under it. An Elementor h1 or a "div" heading must not become either.
     *
     * MUTATION, RUN: `$tag = $size ?: 'h3'` (no range) -- red on h1 and div.
     */
    $cases = ['h1' => 'h3', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h4', 'h5' => 'h3', 'div' => 'h3', '' => 'h3'];

    foreach ($cases as $given => $expected) {
        $html = pjbConvert([pjbSection([pjbWidget('heading', ['title' => 'T', 'header_size' => $given])])])['html'];

        expect($html)->toContain('<' . $expected . ' class="kbb-eblock__heading">T</' . $expected . '>');
    }
});

it('draws text-editor copy laid out, and an icon list as a list', function () {
    $out = pjbConvert([pjbSection([
        pjbWidget('text-editor', ['editor' => "First line\nsecond line\n\nNew paragraph"]),
        pjbWidget('icon-list', ['icon_list' => [['text' => 'Low pH'], ['text' => 'Fragrance free', 'link' => ['url' => '/shop/']]]]),
        pjbWidget('divider'),
    ])]);

    expect($out['mode'])->toBe('elementor')
        ->and($out['html'])->toContain('<div class="kbb-eblock__copy"><p>First line<br>')
        ->and($out['html'])->toContain('<p>New paragraph</p>')
        ->and($out['html'])->toContain('<ul class="kbb-eblock__list"><li>Low pH</li><li><a href="/shop/">Fragrance free</a></li></ul>')
        ->and($out['html'])->toContain('<hr class="kbb-eblock__rule">');
});

it('falls back to the section\'s plain HTML for a widget it cannot draw, and names it with a count', function () {
    /*
     * Half a block -- the heading drawn, the carousel under it silently gone --
     * looks finished and is wrong. The whole block uses plain_html instead,
     * and the widget types are returned for the import report.
     *
     * MUTATION, RUN: convert() ignoring $this->unknown -- red, mode
     * "elementor" with only the heading in it.
     */
    $out = pjbConvert([pjbSection(
        [pjbWidget('heading', ['title' => 'Heading'])],
        [pjbWidget('rey-carousel'), pjbWidget('rey-carousel'), pjbWidget('reycore-acf')],
    )], '<h3>Plain heading</h3><p>Plain words</p>');

    expect($out['mode'])->toBe('plain')
        ->and($out['unknown'])->toBe(['rey-carousel' => 2, 'reycore-acf' => 1])
        ->and($out['html'])->toBe('<div class="kbb-eblock kbb-eblock--plain"><h3>Plain heading</h3><p>Plain words</p></div>');
});

it('falls back for unreadable or empty Elementor data, and is empty when there is nothing at all', function () {
    expect(pjbConvert('{not json', '<p>Words</p>')['mode'])->toBe('plain')
        ->and(pjbConvert('', '<p>Words</p>')['html'])->toBe('<div class="kbb-eblock kbb-eblock--plain"><p>Words</p></div>')
        ->and(pjbConvert('[]', '')['mode'])->toBe('empty')
        ->and(pjbConvert('[]', '')['html'])->toBe('')
        // A tree of nothing but spacers draws nothing; the plain HTML is used.
        ->and(pjbConvert([pjbSection([pjbWidget('spacer')])], '<p>Kept</p>')['mode'])->toBe('plain');
});

it('draws a phone-only duplicate once, not twice', function () {
    /*
     * Elementor pages carry a widget twice -- one copy hidden on desktop, one
     * on phones. This shop is responsive itself, so drawing both printed the
     * heading twice on every product.
     *
     * MUTATION, RUN: hiddenOnDesktop() returning false -- red, two headings.
     */
    $html = pjbConvert(ContentBlocksFixture::section18159())['html'];

    expect(substr_count($html, 'Gentle Yet Effective Ingredients'))->toBe(1);
});

it('lays a row container out as a row, and a column container as a stack', function () {
    $item = fn (string $t) => pjbWidget('image-box', ['title_text' => $t, 'description_text' => 'x']);
    $container = fn (array $settings) => ['elType' => 'container', 'settings' => $settings, 'elements' => [$item('A'), $item('B')]];

    expect(pjbConvert([$container(['flex_direction' => 'row'])])['html'])->toContain('kbb-eblock__row--2')
        ->and(pjbConvert([$container(['container_type' => 'grid'])])['html'])->toContain('kbb-eblock__row--2')
        ->and(pjbConvert([$container([])])['html'])->not->toContain('kbb-eblock__row');
});

it('never stores what the allowlist would not print: scripts, handlers, javascript: links, inline style', function () {
    /*
     * `_elementor_data` is post meta any plugin could have written, and the
     * block is printed raw inside every product description that names it.
     *
     * MUTATION, RUN: convert() returning the assembled HTML without the final
     * RichText::clean() -- red on every needle below.
     */
    $out = pjbConvert([pjbSection([
        pjbWidget('heading', ['title' => 'Hi<script>alert(1)</script>', 'link' => ['url' => 'javascript:alert(2)']]),
        pjbWidget('image-box', [
            'image' => ['url' => 'https://old.test/x.jpg" onerror="alert(3)'],
            'title_text' => '<b style="position:fixed">T</b>',
            'description_text' => '<img src=x onerror=alert(4)>',
        ]),
        pjbWidget('text-editor', ['editor' => '<p onclick="alert(5)">Copy</p><iframe src="https://evil.test"></iframe>']),
    ])]);

    expect($out['html'])
        ->not->toContain('<script')
        ->not->toContain('alert(1)')
        ->not->toContain('javascript:')
        ->not->toContain('onclick')
        ->not->toContain('style=')
        ->not->toContain('<iframe');

    /*
     * The quote smuggled into the image URL stays INSIDE the src value, escaped
     * -- the letters "onerror" survive as part of a broken address and are not
     * an attribute. So that is asked of the parsed tree, not of the string.
     */
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $out['html']);
    $handlers = [];

    foreach ($dom->getElementsByTagName('*') as $el) {
        foreach ($el->attributes as $attr) {
            if (str_starts_with(strtolower($attr->name), 'on')) {
                $handlers[] = $el->tagName . '[' . $attr->name . ']';
            }
        }
    }

    expect($handlers)->toBe([]);
});

it('survives a hostile tree: wrong types everywhere, and nesting deep enough to recurse forever', function () {
    $deep = pjbWidget('heading', ['title' => 'Bottom']);

    for ($i = 0; $i < 200; $i++) {
        $deep = ['elType' => 'section', 'elements' => [$deep]];
    }

    expect(pjbConvert([$deep], '<p>Fallback</p>')['mode'])->toBeIn(['elementor', 'plain'])
        ->and(pjbConvert([['elType' => 'widget', 'widgetType' => ['x'], 'settings' => 'nope'], 7, null], '<p>F</p>')['unknown'])
        ->toBe(['(none)' => 1]);
});

it('keeps the sections an Elementor shortcode widget names, and falls back for any other shortcode', function () {
    /*
     * Lane PJ-A's real export: section 18159 names 18160 through Elementor's
     * shortcode widget. Dropped, the "How To Use" section under the
     * ingredients vanished; printed raw, the shopper read the shortcode.
     *
     * MUTATION, RUN: 'shortcode' removed from widget()'s match -- red, the
     * block falls back with `shortcode` named as unknown.
     */
    $kept = pjbConvert([pjbSection([
        pjbWidget('heading', ['title' => 'Top']),
        pjbWidget('shortcode', ['shortcode' => "[rey_global_section id=\"18160\"]\n[elementor-template id='7']"]),
    ])]);

    expect($kept['mode'])->toBe('elementor')
        ->and($kept['html'])->toContain('<p>[rey_global_section id="18160"]</p><p>[elementor-template id="7"]</p>');

    $other = pjbConvert([pjbSection([pjbWidget('shortcode', ['shortcode' => '[contact-form-7 id="12"]'])])], '<p>Form</p>');

    expect($other['mode'])->toBe('plain')
        ->and($other['unknown'])->toBe(['shortcode [contact-form-7]' => 1]);
});

it('reads Elementor data that still carries the slashes WordPress stores it with', function () {
    /*
     * PJ-A exports `_elementor_data` exactly as stored. Read through
     * get_post_meta() it is plain JSON, but a copy taken from the table by
     * other means carries wp_slash()'s backslashes, and then nothing decoded
     * and every such section fell back to its plain text.
     *
     * MUTATION, RUN: the stripslashes() retry removed from decode() -- red,
     * mode "plain".
     */
    $slashed = addslashes((string) json_encode([pjbSection([pjbWidget('heading', ['title' => 'Slashed "quotes"'])])]));

    $out = pjbConvert($slashed, '<p>Plain</p>');

    expect($out['mode'])->toBe('elementor')
        ->and($out['html'])->toContain('Slashed "quotes"');
});
