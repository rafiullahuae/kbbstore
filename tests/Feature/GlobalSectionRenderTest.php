<?php

declare(strict_types=1);

/*
 * =============================================================================
 * `[rey_global_section id="18159"]` ON THE PRODUCT PAGE  (Lane PJ-B)
 * =============================================================================
 *
 * ON THE SHOP: /product/anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml/
 * opened its Description tab with the shortcode printed as letters. On the old
 * site that spot drew "Gentle Yet Effective Ingredients" and three ingredients
 * in a row. "He has a lot of products with these blocks."
 *
 * App\Support\GlobalSections resolves the shortcode against `blocks.wc_id`
 * when the page is drawn -- every spelling the old editor could save, nothing
 * at all for a missing or draft block, after wpautop() so the block is never
 * cut apart, in one query for the whole page.
 */

use App\Models\Block;
use App\Models\Product;
use App\Support\GlobalSections;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const PJB_BLOCK = '<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Gentle Yet Effective Ingredients</h3>'
    . '<div class="kbb-eblock__row kbb-eblock__row--3">'
    . '<div class="kbb-eblock__col"><div class="kbb-eblock__item"><img class="kbb-eblock__img" src="/wp-content/uploads/q-300x300.jpg" alt="QUERCETINOL" width="300" height="300" loading="lazy"><div class="kbb-eblock__body"><h4 class="kbb-eblock__title">QUERCETINOL</h4><div class="kbb-eblock__text">Calms redness.</div></div></div></div>'
    . '<div class="kbb-eblock__col"><div class="kbb-eblock__item"><img class="kbb-eblock__img" src="/wp-content/uploads/a-300x300.jpg" alt="ANTI-SEBUM P" width="300" height="300" loading="lazy"><div class="kbb-eblock__body"><h4 class="kbb-eblock__title">ANTI-SEBUM P</h4><div class="kbb-eblock__text">Keeps pores clear.</div></div></div></div>'
    . '<div class="kbb-eblock__col"><div class="kbb-eblock__item"><img class="kbb-eblock__img" src="/wp-content/uploads/b-300x300.jpg" alt="0.5% BHA" width="300" height="300" loading="lazy"><div class="kbb-eblock__body"><h4 class="kbb-eblock__title">0.5% BHA</h4><div class="kbb-eblock__text">Lifts blackheads.</div></div></div></div>'
    . '</div></div>';

/** The shape WooCommerce stored: the shortcode on its own line, then bare-newline copy. */
const PJB_DESCRIPTION = "[rey_global_section id=\"18159\"]\r\nAnua's deep-cleansing foam lifts oil and sebum.\r\nGentle enough for every day.\r\n\r\nRinse well.";

function pjbBlock(array $overrides = []): Block
{
    return Block::query()->forceCreate(array_merge([
        'slug' => 'gentle-' . Str::lower(Str::random(6)),
        'name' => 'Gentle Yet Effective Ingredients',
        'content' => PJB_BLOCK,
        'status' => 'published',
        'wc_id' => 18159,
        'source' => 'rey_global_section',
    ], $overrides));
}

function pjbProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'name' => 'Anua Heartleaf Quercetinol Pore Deep Cleansing Foam 150ml',
        'slug' => 'pjb-anua-' . Str::lower(Str::random(6)),
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 8000,
        'stock_status' => 'instock',
        'description' => PJB_DESCRIPTION,
    ], $overrides));
}

/** The desktop Description panel's body, as served. */
function pjbPanel(string $html): string
{
    preg_match('#<div class="dcontent clamp">(.*?)</div>\s*<button class="readmore"#s', $html, $m);

    return $m[1] ?? '';
}

it('draws the block where the shortcode stood, and the shortcode is gone from the page', function () {
    /*
     * The owner's report, end to end.
     *
     * MUTATION, RUN: ProductTabs::forProduct() without GlobalSections::expand()
     * -- red, the panel reads `<p>[rey_global_section id="18159"]</p>`.
     */
    pjbBlock();
    $p = pjbProduct();

    /*
     * The block's three pictures exist on disk. Since 2.60.364 a description
     * picture of ours whose file is missing is dropped at render time
     * (RichText::dropMissingPictures — a 404 held a blank box on the live
     * Shiseido Fino page), and this case is about the shortcode, not that.
     */
    $made = [];
    foreach (['q', 'a', 'b'] as $n) {
        $f = public_path('wp-content/uploads/' . $n . '-300x300.jpg');
        if (! is_file($f)) {
            @mkdir(\dirname($f), 0755, true);
            file_put_contents($f, 'x');
            $made[] = $f;
        }
    }

    try {
        $html = $this->get('/product/' . $p->slug . '/')->assertOk()->getContent();
    } finally {
        array_map('unlink', $made);
    }
    $panel = pjbPanel($html);

    expect($html)->not->toContain('rey_global_section')
        ->and($panel)->toStartWith(PJB_BLOCK)
        ->and($panel)->toContain('<p>Anua\'s deep-cleansing foam lifts oil and sebum.<br>')
        // Desktop panel and phone accordion each carry it once.
        ->and(substr_count($html, 'class="kbb-eblock"'))->toBe(2);
});

it('keeps the block out of wpautop(): no <p> around it, no <br> inside it', function () {
    /*
     * The order the task names. WooCommerce stores the shortcode on its own
     * line followed by bare-newline copy; through autop() as-is that is
     * `<p>[rey…]<br />Anua's…</p>`, and the block landed inside a <p> the
     * parser broke apart -- an empty paragraph above it, the copy's first line
     * orphaned below.
     *
     * MUTATION, RUN: GlobalSections::isolate() removed from forDisplay() --
     * red, the output starts `<p></p><div class="kbb-eblock">` / the first
     * line of copy loses its paragraph.
     */
    pjbBlock();

    $out = RichText::forStorefront(PJB_DESCRIPTION);

    expect($out)->toStartWith(PJB_BLOCK . "\n<p>Anua's deep-cleansing foam")
        ->and($out)->not->toContain('<p></p>')
        ->and($out)->not->toContain('<p><div');

    // The block's own bytes arrive untouched -- no <br> or <p> spliced in.
    expect(substr($out, 0, strlen(PJB_BLOCK)))->toBe(PJB_BLOCK);
});

it('resolves every spelling the old editor could have saved', function () {
    /*
     * MUTATION, RUN: idOf() with `"` as the only quote -- red on id='N', id=N
     * and the curly quotes WordPress's texturize writes.
     */
    pjbBlock();

    foreach ([
        '[rey_global_section id="18159"]',
        "[rey_global_section id='18159']",
        '[rey_global_section id=18159]',
        '[rey_global_section  id = "18159" ]',
        '[rey_global_section class="x" id="18159" lazy="1"]',
        '[REY_GLOBAL_SECTION id="18159"]',
        '[rey_global_section id=”18159″]',
        '[rey_global_section id=&quot;18159&quot;]',
    ] as $shortcode) {
        expect(GlobalSections::expand('<p>' . $shortcode . '</p>'))->toBe(PJB_BLOCK, $shortcode);
    }

    // Another attribute that merely ENDS in "id" is not this one.
    expect(GlobalSections::idOf('product_id="18159"'))->toBeNull()
        ->and(GlobalSections::idOf('data-id="18159"'))->toBeNull();
});

it('draws nothing -- never the letters -- for a missing block or a draft one', function () {
    /*
     * Draft is the off switch, as for [kbb_block]. A shortcode whose block is
     * not there must not be printed for a shopper to read.
     *
     * MUTATION, RUN: render() returning $shortcode for an unknown id -- red,
     * the shortcode text is back in the panel.
     */
    pjbBlock(['status' => 'draft']);
    $draft = pjbProduct();
    $missing = pjbProduct(['description' => "[rey_global_section id=\"99999\"]\r\nStill here.\r\nAnd here."]);

    foreach ([$draft, $missing] as $p) {
        $html = $this->get('/product/' . $p->slug . '/')->assertOk()->getContent();

        expect($html)->not->toContain('rey_global_section')
            ->and($html)->not->toContain('kbb-eblock')
            ->and(pjbPanel($html))->not->toContain('<p></p>');
    }

    expect(pjbPanel($this->get('/product/' . $missing->slug . '/')->getContent()))->toStartWith('<p>Still here.<br>');
});

it('looks every section on the page up in ONE query, and copy with none costs none', function () {
    /*
     * StorefrontQueryBudgetTest's rule, measured here for the case it does not
     * seed: three different sections across the description and the short
     * description are one `blocks` query for the page, not three.
     *
     * MUTATION, RUN: load() querying per id (a loop of ->where('wc_id', $id))
     * -- red, 3 queries.
     */
    pjbBlock();
    pjbBlock(['wc_id' => 18160]);
    pjbBlock(['wc_id' => 18161]);

    $p = pjbProduct([
        'description' => "[rey_global_section id=18159]\n\nA\n\n[rey_global_section id=18160]\n\nB",
        'short_description' => '[rey_global_section id="18161"] Short.',
    ]);
    $plain = pjbProduct(['description' => "No sections.\nAt all."]);

    $count = function (string $slug): int {
        $n = 0;
        DB::listen(function ($q) use (&$n): void {
            if (str_contains($q->sql, '"blocks"') || str_contains($q->sql, '`blocks`')) {
                $n++;
            }
        });
        $this->get('/product/' . $slug . '/')->assertOk();

        return $n;
    };

    app()->forgetInstance('kbb.global_sections');
    $withSections = $count($p->slug);

    app()->forgetInstance('kbb.global_sections');
    $without = $count($plain->slug);

    /*
     * ONE, and it was two before GlobalSections::prime(): the tab is built in
     * the controller and the blurb in the view, each looking up its own.
     * MUTATION, RUN: the prime() call removed from ProductTabs::forProduct() --
     * red, 2.
     */
    expect($withSections)->toBe(1)
        ->and($without)->toBe(0);
});

it('draws a section named in the short description, in a <div> rather than inside the blurb\'s <p>', function () {
    pjbBlock();
    $p = pjbProduct(['short_description' => '[rey_global_section id="18159"]']);

    $html = $this->get('/product/' . $p->slug . '/')->assertOk()->getContent();

    expect($html)->toMatch('#<div class="[^"]*bb-desc"><div class="kbb-eblock">#')
        ->and($html)->not->toContain('rey_global_section');
});

it('keeps the shortcode\'s letters out of the quick view and the meta description', function () {
    /*
     * Both read product copy as TEXT. Google's snippet for the product would
     * have begun `[rey_global_section id="18159"] Anua's…`.
     *
     * MUTATION, RUN: GlobalSections::strip() removed from RichText::toText()
     * -- red on both.
     */
    pjbBlock();
    $p = pjbProduct(['short_description' => "[rey_global_section id=\"18159\"]\nAnua's foam, short."]);

    app(\App\Services\SettingsService::class)->setModule('quick_view', true); // off by default since 2.60.354; this case exercises it
    $html = $this->get('/product/' . $p->slug . '/')->getContent();
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $m);

    expect($m[1] ?? '')->toStartWith('Anua')
        ->and($this->getJson('/quick-view/' . $p->id)->assertOk()->json('html'))->not->toContain('rey_global_section');
});

it('changes nothing for copy that names no section', function () {
    /*
     * Rule 1. forDisplay() and toText() return exactly what they returned
     * before for every product without the shortcode -- which is the whole
     * shop StorefrontEnglishUnchangedTest pins.
     */
    $copy = "<strong>Benefits</strong>\r\nOne.\r\n\r\n<ul>\r\n<li>Two</li>\r\n</ul>";

    expect(RichText::forStorefront($copy))->toBe(RichText::forDisplay($copy))
        ->and(GlobalSections::expand('<p>[kbb_block slug="x"] [rey_other id=1]</p>'))->toBe('<p>[kbb_block slug="x"] [rey_other id=1]</p>')
        ->and(GlobalSections::isolate("a\nb"))->toBe("a\nb");
});

it('leaves a shortcode written inside an attribute alone, so the block cannot break the tag open', function () {
    /*
     * `<a title="[rey_global_section id=1]">` -- expanded there, the block's
     * own class="…" closed the title attribute and spilled its markup into the
     * <a> tag.
     *
     * MUTATION, RUN: the (?![^<>]*>) lookahead removed from pattern() -- red,
     * `title="<div class="kbb-eblock"` in the output.
     */
    pjbBlock();

    $out = GlobalSections::expand('<p><a href="/x" title="[rey_global_section id=&quot;18159&quot;]">Link</a></p>');

    expect($out)->toBe('<p><a href="/x" title="[rey_global_section id=&quot;18159&quot;]">Link</a></p>');
});

it('draws a section that names another, and stops a cycle the first time round', function () {
    /*
     * A section can name a section (Elementor's shortcode widget). Two that
     * name each other recursed until the depth cap cut them, so the first one
     * was drawn twice on the page -- and without the cap, until PHP ran out of
     * stack and the product page was a 500.
     *
     * MUTATION, RUN: render() without the `in_array($id, $stack)` guard --
     * red, "Section A" drawn twice.
     */
    pjbBlock(['wc_id' => 1, 'content' => '<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Section A</h3><p>[rey_global_section id="2"]</p></div>']);
    pjbBlock(['wc_id' => 2, 'content' => '<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Section B</h3><p>[rey_global_section id="1"]</p></div>']);

    $out = GlobalSections::expand('<p>[rey_global_section id="1"]</p>');

    expect(substr_count($out, 'Section A'))->toBe(1)
        ->and(substr_count($out, 'Section B'))->toBe(1)
        ->and($out)->not->toContain('rey_global_section');
});

it('draws sections three deep and no deeper, as the exporter carries them', function () {
    /*
     * Lane PJ-A's exporter stops three levels below a product ("Sections nested
     * more than 3 levels below a product are NOT in content_blocks.csv"), and so
     * does the storefront: a chain of distinct sections cannot make one product
     * page do unbounded work.
     *
     * MUTATION, RUN: the `count($stack) >= self::MAX_DEPTH` test removed from
     * render() -- red, "Level 4" drawn.
     */
    foreach ([1, 2, 3, 4, 5] as $n) {
        pjbBlock(['wc_id' => $n, 'content' => '<div class="kbb-eblock"><h3 class="kbb-eblock__heading">Level ' . $n . '</h3><p>[rey_global_section id="' . ($n + 1) . '"]</p></div>']);
    }

    $out = GlobalSections::expand('<p>[rey_global_section id="1"]</p>');

    expect($out)->toContain('Level 1')->toContain('Level 2')->toContain('Level 3')
        ->and($out)->not->toContain('Level 4')
        ->and($out)->not->toContain('rey_global_section');
});

it('cleans a block the owner wrote by hand before printing it into a description', function () {
    /*
     * Content -> HTML Blocks stores what is typed; a description is printed
     * with {!! !!}. An imported block was cleaned on the way in, an edited one
     * was not.
     *
     * MUTATION, RUN: load() storing $row->content without RichText::clean() --
     * red, the <script> reaches the page.
     */
    pjbBlock(['content' => '<p>Hi</p><script>alert(1)</script><img src=x onerror="alert(2)">']);

    $out = GlobalSections::expand('<p>[rey_global_section id="18159"]</p>');

    expect($out)->toContain('<p>Hi</p>')
        ->not->toContain('<script')
        ->not->toContain('onerror');
});

it('draws a section in an imported or authored tab too, not only the Description', function () {
    pjbBlock();
    $p = pjbProduct(['description' => 'Plain description.']);

    DB::table('product_tabs')->insert([
        'product_id' => $p->id, 'title' => 'Ingredients', 'body' => '<p>[rey_global_section id="18159"]</p>',
        'position' => 50, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    \App\Support\ProductTabs::flush();

    $html = $this->get('/product/' . $p->slug . '/')->assertOk()->getContent();

    expect($html)->toContain('kbb-eblock__heading')->not->toContain('rey_global_section');
});
