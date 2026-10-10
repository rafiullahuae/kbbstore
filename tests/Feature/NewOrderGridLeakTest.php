<?php

declare(strict_types=1);

/**
 * Store -> New Order, squeezed into two 156px strips. (Lane QK13)
 *
 * THE DEFECT, AS THE OWNER SAW IT at about 1530px: Customer and Items crammed
 * into a narrow left column with the text wrapping word by word ("Search by
 * r", "Add a new customer"), Payment & source and Order total in another narrow
 * column with the payment-method and order-status selects one letter wide
 * ("C", "p"), and the whole middle of the page empty. Measured in Chromium on
 * the preview: both grid children 156px wide at 390, 1280, 1530 and 1920.
 *
 * THE CAUSE was not New Order's own stylesheet. Appearance -> Mega Menu's
 * column board (menu-order.blade.php, Lane MO, 5 October) styled a bare
 * `.mo-col{flex:0 0 156px;width:156px;...}`. New Order names its two layout
 * columns `.mo-col` too, both partials are included in the one console
 * document, and the board's rule therefore sized New Order's columns. The fix
 * scopes the board's rule to `.mo-cols>.mo-col`, which is every board column
 * and nothing else.
 *
 * Pest has no layout engine, so what is pinned is the rule that produced the
 * pixels: no other admin stylesheet may style New Order's layout classes bare.
 * MUTATION NOTE: put `.mo-col{flex:0 0 156px;` back in menu-order.blade.php
 * (drop the `.mo-cols>`) and the first test is red.
 */
function qk13AdminStyles(): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/admin'))) as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }
        if ($file->getFilename() === 'manual-order-screen.blade.php') {
            continue;
        }

        $src = (string) file_get_contents($file->getPathname());

        if (preg_match_all('#<style[^>]*>(.*?)</style>#s', $src, $m)) {
            // Comments out, so a sentence naming a class is not mistaken for a rule.
            $out[$file->getFilename()] = preg_replace('#/\*.*?\*/#s', '', implode("\n", $m[1]));
        }
    }

    return $out;
}

it('lets no other admin stylesheet size New Order\'s grid, columns or cards', function () {
    // The classes that carry the screen's LAYOUT. (.mo-note and .mo-empty are
    // shared with the Mega Menu board too, but only as text styling that New
    // Order's own later rules override; they do not move a column.)
    $layout = ['mo-grid', 'mo-col', 'mo-rail', 'mo-card', 'mo-sum', 'mo-two'];

    $leaks = [];

    foreach (qk13AdminStyles() as $name => $css) {
        foreach ($layout as $class) {
            // The class at the START of a compound selector -- the start of the
            // block, or after a `}` or a `,` -- with no ancestor in front of it.
            // `.mo-cols>.mo-col` and `.mx-dragging .mo-col` are scoped and fine.
            if (preg_match('/(?:^|[{},])\s*\.'.preg_quote($class, '/').'(?![\w-])[^{}]*\{/m', $css, $hit)) {
                $leaks[] = $name.': '.trim($hit[0]);
            }
        }
    }

    expect($leaks)->toBe([]);
});

it('keeps the Mega Menu board column at its 156px size, scoped to the board', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/menu-order.blade.php'));

    // The board is unchanged: the same declaration, now reached only through
    // .mo-cols. And every place the board makes a column puts it in .mo-cols.
    expect(str_contains($src, '.mo-cols>.mo-col{flex:0 0 156px;width:156px;min-width:0;'))->toBeTrue('board column rule must stay, scoped')
        ->and(substr_count(preg_replace('#/\*.*?\*/#s', '', $src), '.mo-col{'))->toBe(1);

    $js = (string) file_get_contents(resource_path('js/kbb/admin/menu-order.js'));

    expect(str_contains($js, '<div class="mo-cols" data-mo-zone="root">'))->toBeTrue('the board wraps its columns in .mo-cols')
        ->and(str_contains($js, "cols.appendChild(frag(columnHtml("))->toBeTrue('a column added later is appended into .mo-cols');
});

it('still draws New Order as the two-column grid it was designed as', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/manual-order-screen.blade.php'));

    expect(str_contains($src, '.mo-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);'))->toBeTrue('the 1.5fr / 1fr grid')
        ->and(str_contains($src, '.mo-col{min-width:0}'))->toBeTrue('columns may shrink to their track, never a fixed width')
        ->and(str_contains($src, "'<div class=\"mo-grid\">'"))->toBeTrue('grid in the markup')
        ->and(str_contains($src, "'<div class=\"mo-col\">'"))->toBeTrue('left column in the markup')
        ->and(str_contains($src, "'<div class=\"mo-col mo-rail\">'"))->toBeTrue('right column in the markup')
        ->and(str_contains($src, '@media (max-width:1080px){'."\n".'  .mo-grid{grid-template-columns:minmax(0,1fr)}'))->toBeTrue('one column below 1081px');
});
