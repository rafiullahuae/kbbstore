<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Support\SetPricing;
use Illuminate\Support\Str;

/**
 * WHEN A HAND-TYPED SET PRICE IS RE-ANCHORED, AND WHEN IT IS LEFT ALONE.
 * (Lane SP2)
 *
 * SetFixedPriceFollowsMembersTest is the arithmetic. This is the editor, and it
 * is about the ONE decision that arithmetic cannot make for itself: when to
 * move the anchor the reduction is measured from.
 *
 * ── THE DEFECT THIS FILE EXISTS TO PREVENT ────────────────────────────────
 *
 * Re-anchoring is how an accumulated reduction is thrown away — the anchor
 * becomes today's total, the difference becomes zero, and the set jumps back to
 * the full typed price. The product editor posts EVERY FIELD IT HOLDS on every
 * save, so the naive signal ("the request carried set_members, so the box may
 * have changed") fires when the owner fixes a typo in the description. The
 * feature would then undo itself, silently, the next time anybody edited
 * anything about the set — and the only symptom would be a set that is
 * sometimes AED 180 and sometimes AED 165 with nothing on any screen saying
 * why.
 *
 * Where it sits: Catalog → Product editor → What is in the box.
 */
beforeEach(function () {
    SetPricing::forget();

    $this->actingAs(AdminUser::create([
        'name' => 'Anchor owner',
        'email' => 'sax-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
});

function saxProduct(string $name, int $fils): Product
{
    return Product::create([
        'slug' => 'sax-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ]);
}

/** A set built THROUGH the editor, which is the only writer that anchors. */
function saxSetThroughEditor(array $members, array $overrides = []): array
{
    $body = array_merge([
        'name' => 'Glow Starter Set',
        'slug' => 'sax-set-'.Str::lower(Str::random(8)),
        'status' => 'draft',
        'type' => 'set',
        'price_aed' => '180',
        'price_mode' => 'fixed',
        'set_members' => array_map(
            static fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1],
            $members
        ),
    ], $overrides);

    $id = test()->postJson('/admin-api/product-editor-create', $body)
        ->assertCreated()
        ->json('product.id');

    SetPricing::forget();

    return [Product::find($id), $id];
}

function saxBasis(int $id): ?int
{
    $stored = Product::find($id)->getAttributes()['set_price_basis'] ?? null;

    return $stored === null ? null : (int) $stored;
}

/* ═════════════════════════════════════════════════ taking the anchor ═══ */

it('anchors a hand-typed price to what the box was worth when it was typed', function () {
    /*
     * The operator builds a box worth AED 200 and types AED 180. The anchor is
     * 20000 fils — the figure he was looking at when he decided 180 was right —
     * and the reduction at that instant is zero, so nothing moves.
     *
     * MUTATION NOTE. Make anchorFixedPrice() return early unconditionally and
     * this is red: the basis is null and the set never follows anything. RUN.
     */
    [$set, $id] = saxSetThroughEditor([saxProduct('Toner', 12000), saxProduct('Serum', 8000)]);

    expect(saxBasis($id))->toBe(20000)
        ->and($set->effectivePrice())->toBe(18000)
        ->and(SetPricing::adjustment($set))->toBe(0);
});

it('leaves the anchor alone when the operator only edits the description', function () {
    /*
     * ▲ THE ONE THAT MATTERS MOST IN THIS FILE.
     *
     * The toner comes down AED 15, so the set is AED 165. The owner then opens
     * the set and fixes a typo in its description — a save carrying the same
     * price, the same sale price and the same box, because the screen posts
     * everything it holds.
     *
     * The anchor must not move. If it does, the AED 15 he never agreed to give
     * back is silently taken back, and the set is AED 180 again.
     *
     * MUTATION NOTE. Replace the membership comparison in applySet() with
     * `array_key_exists('set_members', $data)` — the obvious signal — and this
     * is red: the basis becomes 18500 and the price jumps back to 18000. RUN.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)]);

    $toner->update(['price' => 10500]);
    SetPricing::forget();

    expect(Product::find($id)->effectivePrice())->toBe(16500);

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'name' => 'Glow Starter Set',
        'type' => 'set',
        'price_aed' => '180',
        'price_mode' => 'fixed',
        'description' => '<p>Two steps, morning and night.</p>',
        'set_members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => Product::where('name', 'Serum')->latest('id')->first()->id, 'quantity' => 1],
        ],
    ])->assertOk();

    SetPricing::forget();

    expect(saxBasis($id))->toBe(20000, 'the anchor is where it was')
        ->and(Product::find($id)->effectivePrice())->toBe(
            16500,
            'Editing a description must not hand back a reduction the owner did not withdraw.'
        );
});

it('re-anchors when the operator types a new price', function () {
    /*
     * He was looking at today's total when he typed it, so today's total is
     * what it means. A price typed against a box worth 18500 is anchored to
     * 18500 and starts following from there.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)]);

    $toner->update(['price' => 10500]);
    SetPricing::forget();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'type' => 'set',
        'price_aed' => '170',
        'price_mode' => 'fixed',
        'set_members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => Product::where('name', 'Serum')->latest('id')->first()->id, 'quantity' => 1],
        ],
    ])->assertOk();

    SetPricing::forget();

    expect(saxBasis($id))->toBe(18500)
        ->and(Product::find($id)->effectivePrice())->toBe(17000, 'exactly what he typed, nothing off it');
});

it('re-anchors when a product is taken out of the box', function () {
    /*
     * CASES 4 AND 5 — A MEMBER REMOVED, AND ONE ADDED.
     *
     * Taking the serum out makes the box worth AED 120 instead of AED 200. That
     * is not a price reduction: the set contains less. Without the re-anchor
     * the difference of 8000 would come straight off the typed price, so a
     * two-product set stripped to one would drop by the whole value of the
     * product that was removed — and a box emptied one product at a time would
     * walk itself down to the floor.
     *
     * His typed price stands, the panel shows him the new total beside it, and
     * he decides.
     *
     * MUTATION NOTE. Take `$membersChanged` out of applySetPricing()'s trigger
     * list and this is red at 10000 — the set has marked itself down by the
     * price of a product it no longer contains. RUN.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)]);

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'type' => 'set',
        'price_aed' => '180',
        'price_mode' => 'fixed',
        'set_members' => [['product_id' => $toner->id, 'quantity' => 1]],
    ])->assertOk();

    SetPricing::forget();

    expect(saxBasis($id))->toBe(12000)
        ->and(ProductSetItem::where('set_product_id', $id)->count())->toBe(1)
        ->and(Product::find($id)->effectivePrice())->toBe(18000);
});

it('re-anchors when a product is added to the box', function () {
    /*
     * CASE 5, the other half of the one above. Adding the milky toner makes the
     * box worth AED 269 rather than AED 200. Without the re-anchor the box would
     * simply be worth more than its anchor for ever, the difference would be
     * permanently negative, and max(0, ...) would hold this set at the typed
     * price whatever its members did afterwards -- the feature switched off by
     * an edit that had nothing to do with pricing.
     *
     * Re-anchoring keeps the arithmetic honest from today: the typed price
     * stands, and the NEXT markdown on any member comes off it.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)]);

    $milky = saxProduct('Milky Toner', 6900);

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'type' => 'set',
        'price_aed' => '180',
        'price_mode' => 'fixed',
        'set_members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => Product::where('name', 'Serum')->latest('id')->first()->id, 'quantity' => 1],
            ['product_id' => $milky->id, 'quantity' => 1],
        ],
    ])->assertOk();

    SetPricing::forget();

    expect(saxBasis($id))->toBe(26900)
        ->and(Product::find($id)->effectivePrice())->toBe(18000);

    // And it follows from the NEW anchor, not the old one.
    $milky->update(['price' => 5900]);
    SetPricing::forget();

    expect(Product::find($id)->effectivePrice())->toBe(17000);
});

it('re-anchors on the button, without the operator retyping the same number', function () {
    /*
     * "Start again from today's total". The one trigger a human can reach
     * deliberately: an unchanged price is not a re-anchor, so without this
     * button an owner who WANTED his 180 measured from today's cheaper box
     * would have to type a different number and then type 180 again.
     *
     * It changes no price on the day it is pressed, which is what the note
     * under it says.
     *
     * MUTATION NOTE. Drop `reanchor` from the rules array and this is red —
     * $request->validate() strips the key, the trigger never fires and the
     * basis stays at 20000. RUN.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)]);

    $toner->update(['price' => 10500]);
    SetPricing::forget();

    $before = Product::find($id)->effectivePrice();

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'type' => 'set',
        'price_aed' => '180',
        'price_mode' => 'fixed',
        'reanchor' => true,
        'set_members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => Product::where('name', 'Serum')->latest('id')->first()->id, 'quantity' => 1],
        ],
    ])->assertOk();

    SetPricing::forget();

    expect($before)->toBe(16500)
        ->and(saxBasis($id))->toBe(18500, 'the starting point has moved to today')
        ->and(Product::find($id)->effectivePrice())->toBe(18000, 'and the typed price is whole again');
});

it('takes an anchor for the first time on a set that predates the feature', function () {
    /*
     * Every set already on the shop carries a NULL basis and does not move.
     * The first save from this screen takes its anchor — and because the anchor
     * is today's total, the reduction at that moment is zero and NO PRICE
     * MOVES. Applying the package changes nothing; saving a set opts it in.
     */
    $toner = saxProduct('Toner', 12000);

    $legacy = Product::create([
        'slug' => 'sax-legacy-'.Str::random(6),
        'name' => 'Legacy Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 18000,
        'stock_status' => 'instock',
    ]);

    ProductSetItem::create([
        'set_product_id' => $legacy->id,
        'member_product_id' => $toner->id,
        'quantity' => 1,
        'position' => 0,
    ]);

    expect(saxBasis($legacy->id))->toBeNull();

    test()->postJson('/admin-api/product-editor-save/'.$legacy->id, [
        'type' => 'set',
        'price_aed' => '180',
        'price_mode' => 'fixed',
        'set_members' => [['product_id' => $toner->id, 'quantity' => 1]],
    ])->assertOk();

    SetPricing::forget();

    expect(saxBasis($legacy->id))->toBe(12000)
        ->and(Product::find($legacy->id)->effectivePrice())->toBe(18000, 'the price did not move on the day it opted in');

    $toner->update(['price' => 10500]);
    SetPricing::forget();

    expect(Product::find($legacy->id)->effectivePrice())->toBe(16500, 'and it follows from then on');
});

/* ════════════════════════════════════ what the screen is told to show ═══ */

it('hands the screen the whole working-out, in the shop currency', function () {
    /*
     * A figure that moved on its own is only alarming when nothing explains it.
     * The endpoint publishes the anchor, today's total, the reduction and both
     * resulting prices, so the panel can print the arithmetic rather than
     * assert the answer.
     *
     * MUTATION NOTE. Delete `set_adjustment_aed` from payload() and this is
     * red; the panel would then have to compute the reduction itself, and a
     * screen that computes money the server also computes is two answers to
     * what a set costs. RUN.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)], ['sale_aed' => '160']);

    $toner->update(['price' => 10500]);
    SetPricing::forget();

    $p = test()->getJson('/admin-api/product-editor-load/'.$id)->assertOk()->json('product');

    expect($p['set_basis_aed'])->toBe('200.00')
        ->and($p['set_parts_total_aed'])->toBe('185.00')
        ->and($p['set_adjustment_aed'])->toBe('15.00')
        ->and($p['set_effective_aed'])->toBe('145.00', 'the sale price, less the reduction')
        ->and($p['set_sale_now_aed'])->toBe('145.00')
        ->and($p['set_members_missing'])->toBe(0);
});

it('tells the screen how many members have gone missing', function () {
    /*
     * The paused state, on the endpoint. A product deleted from the catalogue
     * stops the reduction dead (see SetFixedPriceFollowsMembersTest), and the
     * panel prints "N of the products in this box no longer exist" — because a
     * reduction that silently STOPPED happening is as confusing as one that
     * silently started.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)]);

    $toner->delete();
    SetPricing::forget();

    $p = test()->getJson('/admin-api/product-editor-load/'.$id)->assertOk()->json('product');

    expect($p['set_members_missing'])->toBe(1)
        ->and($p['set_adjustment_aed'])->toBe('0.00')
        ->and($p['set_effective_aed'])->toBe('180.00');
});

/* ═══════════════════════════════════════ and the two discount modes ═══ */

it('leaves the two discount modes deriving their price as they always did', function () {
    /*
     * The anchor is a `fixed`-mode instrument. A percentage off the total
     * already follows its members all the way down by construction, and
     * applying an adjustment on top of a derived price would take the same drop
     * TWICE.
     *
     * MUTATION NOTE. Delete the `mode($set) !== MODE_FIXED` guard from
     * SetPricing::adjustment() and this is red at 15000 rather than 16650 —
     * the 1500 comes off once in the derivation and once again in the
     * adjustment. RUN.
     */
    $toner = saxProduct('Toner', 12000);
    [, $id] = saxSetThroughEditor([$toner, saxProduct('Serum', 8000)]);

    expect(saxBasis($id))->toBe(20000);

    test()->postJson('/admin-api/product-editor-save/'.$id, [
        'type' => 'set',
        'price_mode' => 'discount_percent',
        'discount_percent' => '10',
        'set_members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => Product::where('name', 'Serum')->latest('id')->first()->id, 'quantity' => 1],
        ],
    ])->assertOk();

    $toner->update(['price' => 10500]);
    SetPricing::forget();

    // 18500 less 10%, rounded once: 16650.
    expect(Product::find($id)->effectivePrice())->toBe(16650)
        ->and(SetPricing::adjustment(Product::find($id)))->toBe(0);
});
