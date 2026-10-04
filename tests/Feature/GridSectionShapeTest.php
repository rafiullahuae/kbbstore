<?php

declare(strict_types=1);

use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;

/**
 * RULE 4 AND RULE 5, ON THE MARKUP THIS SECTION EMITS. (Lane GS)
 *
 * "No JavaScript that measures layout — this project sizes with `calc()` for a
 * reason, and two tests forbid the element-measuring APIs by name." A carousel
 * is the most tempting place in this codebase to break that rule, because a
 * previous/next arrow has to know how far to scroll and every way of knowing
 * that is a measurement.
 *
 * This section has NO arrows and NO script at all. The row is `scroll-snap`
 * over one `calc()` and its affordance is the peek of the next card, which is
 * the affordance `.kbb-home .rail` has used on this same page since it was
 * written. The forbidden list below is InstagramSectionShapeTest's and
 * CardsBannerSectionShapeTest's, unchanged, because the rule is the same rule
 * and a shorter list here would be a quieter promise.
 *
 * ── AND THE STYLESHEET IS A CONSTANT ────────────────────────────────────────
 *
 * Rule 5: "Anything printed unescaped is a constant, never a setting."
 * `GridSections::css()` is emitted with `{!! !!}` and therefore must not carry
 * one byte that came from a setting. The per-instance NUMBERS ride in each
 * section's own `style` attribute instead, through `{{ }}`, as integers clamped
 * twice — which is checked here against a heading built out of the characters
 * that break out of a style element.
 */
function gssPartial(): string
{
    return (string) file_get_contents(resource_path('views/partials/home/grid-section.blade.php'));
}

/**
 * The partial's source with its Blade comments removed.
 *
 * This file's own prose names `offsetWidth` and `getBoundingClientRect` — it
 * has to, they are what it forbids — and so does the partial's header, for the
 * same reason. A raw `str_contains` over the source therefore reports the
 * EXPLANATION of the rule as a breach of it, which is the fault
 * CardsBannerSectionShapeTest documents. Blade comments are stripped first, so
 * what is scanned is code.
 */
function gssCode(): string
{
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', gssPartial());
}

function gssRender(array $extra = []): string
{
    GridSection::query()->delete();
    GridSections::flush();

    Product::firstOrCreate(['slug' => 'gss-p1'], [
        'name' => 'GSS One', 'status' => 'publish', 'is_visible' => true,
        'price' => 5000, 'stock_status' => 'instock', 'type' => 'simple',
        'rating' => 0.0, 'review_count' => 0, 'total_sales' => 800000,
    ]);

    GridSection::create(array_merge([
        'name' => 'GSS', 'slug' => 'gss-'.uniqid(), 'status' => 'publish', 'position' => 1,
        'show_heading' => true, 'heading' => 'GSS heading', 'subheading' => '',
        'source' => 'bestsellers', 'include_children' => false,
        'count' => 4, 'mobile_count' => 4,
        'desktop_layout' => 'carousel', 'desktop_cols' => 4,
        'mobile_layout' => 'carousel', 'mobile_cols' => 2,
        'skin' => '', 'card_label' => '', 'show_rank' => false,
        'show_view_all' => false, 'view_all_label' => '', 'view_all_url' => '',
    ], $extra));

    GridSections::flush();

    return test()->get('/')->assertOk()->getContent();
}

/* ══════════════════════ rule 4: nothing measures anything ═════════════════ */

it('carries no script and reaches for no element-measuring API', function () {
    /*
     * MUTATION NOTE. Add `el.offsetWidth` — or any <script> block at all — to
     * resources/views/partials/home/grid-section.blade.php and this goes red on
     * the named API and on the script check. Run, red, put back.
     */
    $code = gssCode();

    expect($code)->not->toContain('<script');

    foreach ([
        'getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth',
        'clientHeight', 'clientWidth', 'scrollY', 'getComputedStyle',
        'ResizeObserver', 'requestAnimationFrame', 'addEventListener',
    ] as $api) {
        expect($code)->not->toContain($api);
    }

    // And nothing reaches the rendered section either — an @include of a script
    // partial would pass the source check above and fail this one.
    $html = gssRender();
    $section = substr($html, (int) strpos($html, 'class="sec kbb-gsec'));
    $section = substr($section, 0, (int) strpos($section, '</section>'));

    expect($section)->not->toContain('<script')
        ->and($section)->not->toContain('onclick');
});

it('sizes the carousel from one calc over the column count and the peek', function () {
    /*
     * THE PEEK IS ARITHMETIC, NOT A MEASUREMENT. `--gs-d` whole cards, one gap
     * fewer than that, and `--gs-peek` of the next card showing — identical in
     * shape to the track arithmetic kbb.css already divides every product grid
     * by, and to the rule CardsBannerSectionShapeTest pins for the banner row.
     *
     * MUTATION: replace the `flex:0 0 calc(…)` on `.gs-car-d>*` with a fixed
     * `flex:0 0 280px` and this goes red — the column count stops deciding the
     * card width and "4 across" means nothing.
     */
    $css = GridSections::css();

    expect($css)->toContain('scroll-snap-type:x mandatory')
        ->toContain('scroll-snap-align:start')
        ->toContain('flex:0 0 calc(')
        ->toContain('var(--gs-d,4)')
        ->toContain('var(--gs-m,2)')
        ->toContain('var(--gs-peek,.28)')
        // Reduced motion stops the only motion this section has.
        ->toContain('@media (prefers-reduced-motion:reduce)');
});

it('writes no [dir] selector and only logical inline properties', function () {
    /*
     * ARABIC. A flex row inside `dir="rtl"` lays out and SCROLLS the other way
     * because that is what the inline axis IS — so there is nothing
     * direction-specific to keep in step, which is the only version of this
     * that cannot rot.
     *
     * MUTATION: change `padding-inline` to `padding-left`/`padding-right` in
     * GridSections::css() and the second expectation goes red. Run, red, put
     * back. (It also visibly breaks: the phone carousel's gutter lands on the
     * wrong side on /ar.)
     */
    $css = GridSections::css();

    expect($css)->not->toContain('[dir=')
        ->not->toContain('padding-left')
        ->not->toContain('padding-right')
        ->not->toContain('margin-left')
        ->not->toContain('margin-right')
        ->toContain('padding-inline')
        ->toContain('margin-inline')
        ->toContain('scroll-padding-inline-start');
});

/* ══════════════════ rule 5: the stylesheet is a constant ══════════════════ */

it('puts no byte of any setting inside the style element', function () {
    /*
     * `{!! !!}` does not escape. A heading interpolated into the stylesheet
     * would let an admin with the manage capability close the style element and
     * open a script one — and this shop's own console is the threat model for
     * that, not a shopper, which is exactly why the rule says CONSTANT rather
     * than "escaped".
     *
     * MUTATION: append `.$section->heading` inside GridSections::css() and this
     * goes red on the first expectation. Run, red, put back.
     */
    $html = gssRender([
        'heading' => '</style><script>alert(1)</script>',
        'subheading' => 'body{display:none}',
        'card_label' => '"><b>x',
        'view_all_label' => '</a><script>y</script>',
        'show_view_all' => true,
        'view_all_url' => '/shop/',
    ]);

    // The style element the loop pushed carries none of it.
    preg_match_all('#<style>(.*?)</style>#s', $html, $m);

    $styles = implode("\n", $m[1] ?? []);

    expect($styles)->not->toContain('alert(1)')
        ->not->toContain('body{display:none}')
        ->not->toContain('<b>x');

    // And every one of them is ESCAPED where it does print.
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<script>y</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('emits exactly two integers into the section’s style attribute and nothing else', function () {
    // ▲ Lane HC: a phone GRID. A phone carousel is sized by "Cards in view ·
    // phone" now (2.3 by default, the owner's), which HomepageHubTest pins.
    $html = gssRender(['desktop_cols' => 5, 'mobile_cols' => 3, 'mobile_layout' => 'grid']);

    preg_match('#<section class="sec kbb-gsec[^>]*>#', $html, $m);

    expect($m[0] ?? '')->not->toBe('');

    // The section wrapper's own style is the shop's existing padding rule; the
    // custom properties live on the grid.
    // ▲ Lane HC: `[^>]*` — a laptop carousel with arrows on (the default)
    // carries `data-ymal-track` after the style; still no value from a setting.
    preg_match('#<div class="[^"]*gs-grid[^"]*" data-skin="[^"]*" style="([^"]*)"[^>]*>#', $html, $g);

    expect($g[1] ?? '')->toBe('--gs-d:5;--gs-m:3');
});
