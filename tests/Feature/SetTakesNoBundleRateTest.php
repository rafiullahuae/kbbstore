<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\CartService;
use Illuminate\Support\Str;

/**
 * A set is charged its own price at every quantity, and the shop stops taking a
 * member off only the set's own shelf.
 *
 * Two owner decisions of 29 September 2026, in his words:
 *
 *   "no there's no bulk discount for sets products."
 *   "Yes if the product sold inside set or individual, the stock should be
 *    minus in any case."
 *
 * Both are CLAUDE.md rule 1 exceptions — defaults he asked for by name — and
 * both change what a live shop does, so each is pinned here rather than left to
 * the commit message.
 */
function setNoBundleProduct(string $name, int $fils, string $type = 'simple'): Product
{
    return Product::create([
        'slug' => 'nb-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => $type,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ]);
}

it('charges a set its own price however many are bought', function () {
    /*
     * The tier table is what an ordinary product gets, and the assertion is a
     * COMPARISON rather than a figure: whatever the shop's bundle policy is
     * today, an ordinary product must follow it and a set must not. A test
     * asserting "a set costs 12000 at qty 3" would also pass on a shop that had
     * quietly lost its bundles altogether.
     */
    $cart = app(CartService::class);

    $plain = setNoBundleProduct('Ordinary Toner', 12000);
    $set = setNoBundleProduct('Glow Set', 12000, 'set');

    $member = setNoBundleProduct('Heartleaf Toner', 9000);
    ProductSetItem::create([
        'set_product_id' => $set->id,
        'member_product_id' => $member->id,
        'quantity' => 1,
        'position' => 0,
    ]);

    $plainAtThree = $cart->unitPriceFor($plain, null, 3);
    $setAtThree = $cart->unitPriceFor($set, null, 3);

    expect($plainAtThree)->toBeLessThan(
        12000,
        'The shop offers no quantity discount at all, so this case cannot tell a set apart from an '
        .'ordinary product. Check BundleService::enabled() and the tier table before reading the '
        .'assertion below as a pass.'
    );

    /*
     * MUTATION NOTE. Remove the `$type === 'set'` guard from
     * CartService::unitPriceFor() and the set is charged the tier rate — the
     * same figure as the ordinary product on the line above. RUN.
     */
    expect($setAtThree)->toBe(
        12000,
        'A set was charged a quantity-bundle rate. The shop stopped ADVERTISING bundles on a set '
        .'when BundleService::forProduct() learnt to answer [] for one; this is the basket half, '
        .'and without it the page and the basket disagree.'
    );

    expect($cart->unitPriceFor($set, null, 1))->toBe(12000);
});

it('takes a set line off its members shelves by default', function () {
    /*
     * MUTATION NOTE. Put MODE_SET back as StockSetRule::mode()'s default AND
     * delete 2027_04_21_000000 and this is red: expand() hands back the caller's
     * own array and the member's shelf never moves. RUN.
     *
     * The migration is tested separately from the code default because they
     * answer different questions -- a shop installed after this package versus
     * one that already existed -- and only the pair keeps "nobody ever opened
     * that screen" apart from "the operator chose set-only".
     */
    expect(app(\App\Services\StockSetRule::class)->decrementsMembers())->toBeTrue(
        'Catalog -> Sets -> Stock must default to taking each member off its own shelf.'
    );

    expect(app(\App\Services\StockSetRule::class)->mode())->toBe(
        \App\Services\StockSetRule::MODE_MEMBERS
    );
});

it('still lets the owner choose the old rule, and remembers it', function () {
    /*
     * The other half, and the reason the migration writes a row rather than
     * relying on the code default alone: an operator who deliberately picks
     * "the set's own stock only" must keep it, and nothing may put the new
     * default back over the top of his choice.
     *
     * MUTATION NOTE. Make mode() ignore the stored value and always answer
     * MODE_MEMBERS and this is red. RUN.
     */
    app(\App\Services\SettingsService::class)->set(
        \App\Services\StockSetRule::KEY,
        \App\Services\StockSetRule::MODE_SET
    );

    expect(app(\App\Services\StockSetRule::class)->decrementsMembers())->toBeFalse(
        'An operator who chose the old rule had it taken away from him.'
    );
});

it('reads an unrecognised stored value as the SAFER rule', function () {
    /*
     * A row written by hand, by an import, or by a screen not yet written. The
     * old default fell back to MODE_SET, which is the PERMISSIVE answer: it
     * sells a box whose contents may not exist. Now that members are counted,
     * the fallback goes the other way -- a garbled row makes the shop refuse an
     * order it cannot pack rather than accept one it cannot.
     *
     * MUTATION NOTE. Change either MODE_MEMBERS in mode() back to MODE_SET and
     * this is red. RUN.
     */
    app(\App\Services\SettingsService::class)->set(\App\Services\StockSetRule::KEY, 'nonsense');

    expect(app(\App\Services\StockSetRule::class)->mode())->toBe(
        \App\Services\StockSetRule::MODE_MEMBERS,
        'An unrecognised stock rule must fall to the answer that refuses an unpackable order.'
    );
});
