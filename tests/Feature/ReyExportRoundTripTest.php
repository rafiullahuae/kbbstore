<?php

declare(strict_types=1);

/*
 * =============================================================================
 * THE EXPORTER'S OWN REY OUTPUT, IMPORTED AND DRAWN  (Lane PJ-B, on PJ-A's file)
 * =============================================================================
 *
 * tests/Fixtures/kbb-export-rey is what plugin 1.10.0 (Lane PJ-A) writes for a
 * shop whose descriptions embed Rey Global Sections -- not a shape this lane
 * imagined. It carries the hard cases on purpose:
 *
 *   18159  heading + inner section of image-boxes and an image/heading/text
 *          column, and an Elementor SHORTCODE WIDGET naming 18160
 *   18160  a container with an icon list, and a text widget naming 18161 and a
 *          section that does not exist (88888)
 *   18161  an elementor_library template naming 18162 (deeper than the
 *          exporter goes, so not in the file), [elementor-template id="555"]
 *          (not in the file) and 18159 -- A CYCLE
 *
 *   product 4021  short description: `[rey_global_section class="mt-0" id='18159']`
 *   product 4022  description: `… [rey_global_section title="…" id=18159] [… id="99999"]`
 *   product 4023  short description: `Write [[rey_global_section id="18162"]] to embed…`
 *                 -- WordPress's ESCAPE, which prints the shortcode as text
 *
 * The whole export goes through the real ImportRunner the way the runbook runs
 * it, and the product pages are fetched.
 */

use App\Models\Block;
use App\Models\Product;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportReport;
use App\Services\Import\ImportRunner;

function pjbReyDir(): string
{
    return base_path('tests/Fixtures/kbb-export-rey');
}

function pjbReyImport(): ImportReport
{
    $manifest = json_decode((string) file_get_contents(pjbReyDir() . '/manifest.json'), true);

    return (new ImportRunner)->run(new ImportOptions(
        directory: pjbReyDir(),
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
        runKey: 'pjb-rey-' . bin2hex(random_bytes(4)),
    ));
}

/** The desktop Description panel's body, as served. */
function pjbReyPanel(string $html): string
{
    preg_match('#<div class="dcontent clamp">(.*?)</div>\s*<button class="readmore"#s', $html, $m);

    return $m[1] ?? '';
}

it('imports every section the exporter wrote, with nothing refused', function () {
    $report = pjbReyImport()->for('content-blocks');

    $blocks = Block::query()->whereNotNull('wc_id')->orderBy('wc_id')->get()->keyBy('wc_id');

    expect($report->rejectedCount())->toBe(0)
        ->and($report->created)->toBe(3)
        ->and($blocks->keys()->all())->toBe([18159, 18160, 18161])
        ->and($blocks[18159]->name)->toBe('Gentle Yet Effective Ingredients')
        ->and($blocks[18159]->source)->toBe('rey_global_section')
        // post_modified_gmt is UTC and is stored as it came.
        ->and($blocks[18159]->source_modified_at?->format('Y-m-d H:i:s'))->toBe('2024-02-03 09:14:15')
        ->and($blocks[18161]->name)->toBe('Routine Tip')
        ->and($blocks->every(fn (Block $b) => $b->status === 'published'))->toBeTrue();

    // Every widget in the real file is one the converter draws: no fallback.
    expect($report->adjustments())->toBe([])
        // The shortcode widget is kept as a shortcode, for the storefront.
        ->and((string) $blocks[18159]->content)->toContain('<p>[rey_global_section id="18160"]</p>')
        // The image column, the icon list.
        ->and((string) $blocks[18159]->content)->toContain('class="kbb-eblock__pic" src="https://kbeautybliss.com/wp-content/uploads/2023/06/bha.png"')
        ->and((string) $blocks[18160]->content)->toContain('<ul class="kbb-eblock__list"><li>Massage onto damp skin</li>');
});

it('draws the nested sections on the product page, stops the cycle, and prints nothing for a missing one', function () {
    /*
     * ON THE SHOP before this lane: `[rey_global_section title="Ingredients"
     * id=18159] [rey_global_section id="99999"]` as letters under "A toner.".
     *
     * 18159 -> 18160 -> 18161 -> 18159 is cut here by the DEPTH cap as well
     * as by the cycle guard, so removing the cycle guard alone leaves this
     * green (run, and it did) -- GlobalSectionRenderTest's two-section cycle
     * is the test that guard answers to.
     *
     * MUTATION, RUN: render() returning $content without expanding it -- red,
     * `[rey_global_section id="18160"]` printed as text inside the block.
     */
    pjbReyImport();

    $toner = Product::query()->where('wc_id', 4022)->sole();
    $html = $this->get('/product/' . $toner->slug . '/')->assertOk()->getContent();
    $panel = pjbReyPanel($html);

    expect($panel)->toStartWith('A toner. <div class="kbb-eblock">')
        ->and(substr_count($panel, 'Gentle Yet Effective Ingredients'))->toBe(1)
        ->and($panel)->toContain('How To Use')
        ->and($panel)->toContain('Routine tip: patch test first.')
        ->and($panel)->not->toContain('rey_global_section')
        ->and($panel)->not->toContain('elementor-template')
        ->and($html)->not->toContain('rey_global_section');
});

it('draws a section named in a short description, whatever its other attributes', function () {
    pjbReyImport();

    $serum = Product::query()->where('wc_id', 4021)->sole();
    $html = $this->get('/product/' . $serum->slug . '/')->assertOk()->getContent();

    expect($html)->toMatch('#<div class="[^"]*bb-desc">Hanbang ginseng, in a bottle\. <div class="kbb-eblock">#')
        ->and($html)->not->toContain('rey_global_section');
});

it('prints WordPress\'s escaped [[shortcode]] as the shortcode, once, as text', function () {
    /*
     * `[[rey_global_section id="18162"]]` is how a WordPress author writes the
     * shortcode in a sentence without running it, and WordPress prints
     * `[rey_global_section id="18162"]`. Read as a shortcode, the sentence lost
     * its subject and kept two stray brackets: "Write [] to embed a section."
     *
     * MUTATION, RUN: the (?<!\[) / (?!\]) guards removed from fragment() --
     * red, "Write [] to embed".
     */
    pjbReyImport();

    $cleanser = Product::query()->where('wc_id', 4023)->sole();
    $html = $this->get('/product/' . $cleanser->slug . '/')->assertOk()->getContent();

    expect($html)->toContain('Write [rey_global_section id="18162"] to embed a section.')
        ->and($html)->not->toContain('[[rey_global_section')
        ->and($html)->not->toContain('Write [] to embed');
});
