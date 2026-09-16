<?php

declare(strict_types=1);

use App\Support\ProductTitle;

/**
 * The product editor shows, as each description box's placeholder, the alt text
 * the storefront would use if that box were left empty.
 *
 * That default is not new — Product::altFor() has always fallen back to
 * ProductTitle::alt() — but the editor showed a generic "Describe this photo"
 * hint, so an empty box looked like a missing alt attribute rather than a
 * working default. The owner asked for the name to be filled in automatically;
 * this makes the automatic name visible, and still lets them overwrite it.
 *
 * The risk in duplicating the rule in JavaScript is that the two drift and the
 * editor promises wording the storefront does not use. These pin the PHP side's
 * behaviour and assert the screen carries the same three decisions.
 */
it('mirrors the storefront rule in the editor, decision for decision', function () {
    $blade = file_get_contents(
        base_path('resources/views/admin/partials/product-editor-screen.blade.php')
    );

    expect($blade)->toContain('function suggestedAlt(index, total)');

    // 1. The brand is not repeated when the name already leads with it.
    expect(ProductTitle::alt('Anua', 'Anua Heartleaf Toner', 0, 1))
        ->toBe('Anua Heartleaf Toner');
    expect(str_contains($blade, 'indexOf(brand.toLowerCase()) === 0'))->toBeTrue();

    // 2. Brand and name are joined when it does not.
    expect(ProductTitle::alt('COSRX', 'Snail Mucin Essence', 0, 1))
        ->toBe('COSRX Snail Mucin Essence');

    // 3. The view counter appears only when there is more than one shot.
    expect(ProductTitle::alt('COSRX', 'Snail Mucin Essence', 0, 4))
        ->toBe('COSRX Snail Mucin Essence');
    expect(ProductTitle::alt('COSRX', 'Snail Mucin Essence', 2, 4))
        ->toBe('COSRX Snail Mucin Essence, view 3 of 4');
    expect(str_contains($blade, "', view ' + (index + 1) + ' of ' + total"))->toBeTrue();

    // 4. Either half missing leaves the other standing alone.
    expect(ProductTitle::alt('', 'Heartleaf Toner', 0, 1))->toBe('Heartleaf Toner');
    expect(ProductTitle::alt('Anua', '', 0, 1))->toBe('Anua');
});

it('uses the suggestion as the placeholder, never as the stored value', function () {
    $blade = file_get_contents(
        base_path('resources/views/admin/partials/product-editor-screen.blade.php')
    );

    /*
     * The distinction that matters. Writing the suggestion into `value` would
     * save it as though the operator had typed it, which freezes today's
     * product name into every photo's alt text — rename the product and the
     * descriptions silently keep the old name. As a placeholder the default
     * stays live, and altFor() recomputes it on every page render.
     */
    expect($blade)->toContain("'placeholder=\"' + esc(suggestedAlt(i + 1, model.images.length + 1)")
        ->and($blade)->toContain("'placeholder=\"' + esc(suggestedAlt(0, 1)");

    expect($blade)->toContain("value=\"' + esc(altOf(u)) + '\"")
        ->and($blade)->toContain("value=\"' + esc(altOf(model.image)) + '\"");
});
