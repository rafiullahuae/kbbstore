<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Support\SetContents;
use App\Support\SetPricing;
use Illuminate\Support\Str;

/**
 * A Set is edited on the PRODUCT editor, because a set is a product. (Lane SP)
 *
 * The owner, after building his first set on the standalone screen:
 *
 *   "can you please merge the Set functionality into the product itself? i want
 *    if i add product, on that page it will have optin to switch to Set product
 *    type, and all options will be shown for set, with remaing same sections
 *    like seo etc. ... so in this case i will have most of things ready made,
 *    like sorting etc on the same page instead of making such all options
 *    separately for set product type."
 *
 * The DATA MODEL did not change at all, and that is the point: a set was always
 * a `products` row with `type = 'set'` plus the `product_set_items` pivot, which
 * is the shape Lane SET argued for precisely so a set would carry slug, status,
 * category, description, images, SEO, tags and position as an ordinary product.
 * What moved is the admin, and only the admin.
 *
 * The PRICING RULE itself lives in App\Support\SetPricing and is tested by
 * SetPricingTest, including the half that matters most — that a derived price
 * cannot move under a shopper. This file is the editor.
 */
function speAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Editor owner',
        'email' => 'spe-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function speProduct(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'spe-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ], $overrides));
}

/** The minimum a create needs, so each case adds only what it is about. */
function spePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Glow Starter Set',
        'slug' => 'glow-starter-set-'.Str::lower(Str::random(6)),
        'status' => 'draft',
        'price_aed' => '129',
    ], $overrides);
}

/**
 * The editor's SOURCE, with every comment stripped first.
 *
 * ▲ THE STRIP IS THE WHOLE POINT. This screen explains what it does in prose,
 *   and a scan of the raw text would match the EXPLANATION and pass a screen
 *   that does not have the thing in it.
 */
function speEditorCode(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/**
 * One function's body out of a screen's source, by brace matching.
 *
 * Scanning a whole file for a listener counts every feature on it; scanning one
 * function counts the feature being asserted. (Lane SP)
 */
function speFunctionBody(string $code, string $signature): string
{
    $at = strpos($code, $signature);

    if ($at === false) {
        return '';
    }

    $open = strpos($code, '{', $at);
    $depth = 0;

    for ($i = $open; $i < strlen($code); $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($code, $open, $i - $open + 1);
            }
        }
    }

    return '';
}

function speSetsScreenCode(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/partials/sets-screen.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

beforeEach(function () {
    SetPricing::forget();
    $this->actingAs(speAdmin(), 'admin');
});

/* ════════════════════════════════ the type, and the guard around it ═══ */

it('creates a simple product when nothing says otherwise', function () {
    /*
     * ── NOTHING THAT ALREADY WORKS MAY CHANGE ──────────────────────────────
     *
     * Every client that predates this round posts no `type` at all, and must go
     * on getting what it always got: a simple product. The default lives in
     * `$product->type ??= 'simple'` INSIDE `if (! $product->exists)`.
     *
     * MUTATION NOTE. Replace the default with `$product->type = 'simple';` —
     * an assignment rather than `??=` — and the NEXT case is red: every save of
     * a set turns it back into a simple product.
     *
     * ▲ AND AN HONEST CORRECTION, because the obvious mutation does NOT go red
     *   and a note nobody ran is worse than none. MOVING the default out of
     *   `if (! $product->exists)` leaves both cases green, because `??=` only
     *   assigns to a null and a saved set's type is 'set'. The `exists` guard is
     *   belt-and-braces; what actually protects a saved set is the `??=` here
     *   and the array_key_exists + allowlist guard in apply(), and those are
     *   what the next case mutates. RUN (all three were run).
     */
    $id = $this->postJson('/admin-api/product-editor-create', spePayload())
        ->assertCreated()->json('product.id');

    expect(Product::find($id)->type)->toBe('simple');
});

it('cannot turn a saved set back into a simple product by omission', function () {
    /*
     * ▲ THE ONE THIS ENDPOINT WAS MOST LIKELY TO GET WRONG, and it was
     *   reachable two ways before this lane touched it:
     *
     *   BY OMISSION. `$product->type ??= 'simple'` would clobber a saved set if
     *   it ran outside `if (! $product->exists)`. Lane SET verified that guard;
     *   this lane is the one that made the controller write `type` on purpose,
     *   so it is re-verified here rather than trusted.
     *
     *   BY NULL. The rule was `['sometimes', 'nullable', 'string', 'max:40']`
     *   and the plain-field loop wrote whatever arrived — so `type: null` set
     *   the column to NULL, and Product::isSet() then answered false for a
     *   product whose members were still in the pivot and whose box had
     *   vanished from every surface that draws one. Silently.
     *
     * MUTATION NOTE. Put `'nullable'` back on the `type` rule and drop the
     * `in_array(..., self::EDITOR_TYPES, true)` guard in apply(), and the
     * second half is red. RUN.
     */
    $toner = speProduct('Toner', 9000);

    $id = $this->postJson('/admin-api/product-editor-create', spePayload([
        'type' => 'set',
        'set_members' => [['product_id' => $toner->id, 'quantity' => 1]],
    ]))->assertCreated()->json('product.id');

    expect(Product::find($id)->type)->toBe('set');

    // A save that says nothing about the type leaves it alone.
    $this->postJson('/admin-api/product-editor-save/'.$id, ['name' => 'Renamed'])->assertOk();
    expect(Product::find($id)->type)->toBe('set');

    // And a save that sends null is refused rather than obeyed.
    $this->postJson('/admin-api/product-editor-save/'.$id, ['type' => null])->assertStatus(422);
    expect(Product::find($id)->type)->toBe('set');

    // As is anything outside the three this editor offers.
    $this->postJson('/admin-api/product-editor-save/'.$id, ['type' => 'grouped'])->assertStatus(422);
    expect(Product::find($id)->type)->toBe('set');
});

it('keeps the box when a set is switched back to a simple product', function () {
    /*
     * ── A DECISION, MADE DELIBERATELY ──────────────────────────────────────
     *
     * Set → Simple does NOT delete the membership rows. It is a SELECT: an
     * operator who changes the type by accident, or to see what happens, must
     * be able to change it back and find the box as they left it. Emptying a
     * forty-product box on a dropdown change is destruction with no undo, from
     * a control that looks like every other control on the page.
     *
     * The rows are invisible while the type is not `set` — every reader goes
     * through Product::isSet() first — so a simple product carrying them
     * behaves in every way like a simple product, which is the second half of
     * the assertion below.
     *
     * MUTATION NOTE. Add a `ProductSetItem::where(...)->delete()` to the
     * not-a-set branch of applySet() and this is red. RUN.
     */
    $toner = speProduct('Toner', 9000);

    $id = $this->postJson('/admin-api/product-editor-create', spePayload([
        'type' => 'set',
        'set_members' => [['product_id' => $toner->id, 'quantity' => 2]],
    ]))->assertCreated()->json('product.id');

    $this->postJson('/admin-api/product-editor-save/'.$id, ['type' => 'simple'])->assertOk();

    $back = Product::find($id);

    expect($back->type)->toBe('simple')
        ->and($back->isSet())->toBeFalse()
        ->and(ProductSetItem::where('set_product_id', $id)->count())->toBe(1, 'The box is kept, not deleted.')
        ->and(SetContents::fromProduct($back))->toBe(SetContents::NONE, 'And it is invisible while it is not a set.');

    // Switch it back and the box is exactly where it was.
    $this->postJson('/admin-api/product-editor-save/'.$id, ['type' => 'set'])->assertOk();

    $again = Product::find($id);
    $again->load('setItems.member');

    expect(SetContents::fromProduct($again)['members'][0]['quantity'])->toBe(2);
});

it('leaves an order that was sold as a set printing what was in the box', function () {
    /*
     * CONFIRMED RATHER THAN ASSUMED, which is what the brief asked for. An
     * order's contents are `order_items.set_contents`, a JSON snapshot written
     * at checkout, and SetContents::fromOrderItem() reads it and never the
     * pivot. So history survives a type switch in either direction — the
     * invoice, the packing slip and the customer's order page all still print
     * the box.
     *
     * MUTATION NOTE. Change SetContents::fromOrderItem() to read
     * `fromProduct($item->product)` and this is red the moment the product
     * stops being a set. RUN.
     */
    $toner = speProduct('Toner', 9000);

    $id = $this->postJson('/admin-api/product-editor-create', spePayload([
        'type' => 'set',
        'set_members' => [['product_id' => $toner->id, 'quantity' => 1]],
    ]))->assertCreated()->json('product.id');

    $order = Order::create([
        'order_number' => 'SP-'.Str::upper(Str::random(6)),
        'status' => 'processing',
        'currency' => 'AED',
        'subtotal' => 12900, 'total' => 12900,
        'email' => 'buyer@example.com',
    ]);

    $line = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $id,
        'name' => 'Glow Starter Set',
        'quantity' => 1,
        'unit_price' => 12900,
        'subtotal' => 12900,
        'total' => 12900,
        'set_contents' => [[
            'name' => 'Toner', 'brand' => '', 'sku' => '', 'variant' => '',
            'quantity' => 1, 'unit' => 9000, 'image' => null,
        ]],
    ]);

    // The product stops being a set entirely.
    $this->postJson('/admin-api/product-editor-save/'.$id, ['type' => 'simple'])->assertOk();

    $contents = SetContents::fromOrderItem($line->fresh());

    expect($contents['members'][0]['name'])->toBe('Toner')
        ->and($contents['setPrice'])->toBe(12900);
});

/* ═══════════════════════════════════════════════ the box and the rule ═══ */

it('writes the box, in the order it was sent, and refuses a set inside a set', function () {
    /*
     * MUTATION NOTE. Delete the `$memberRow->isSet()` guard in
     * writeSetMembers() and the last expectation is red — a set inside itself
     * is an infinite box, and SetPricing::partsTotal() would then be asked to
     * price one. RUN.
     */
    $a = speProduct('Toner', 9000);
    $b = speProduct('Serum', 5000);

    $inner = $this->postJson('/admin-api/product-editor-create', spePayload([
        'type' => 'set',
        'set_members' => [['product_id' => $a->id, 'quantity' => 1]],
    ]))->assertCreated()->json('product.id');

    $id = $this->postJson('/admin-api/product-editor-create', spePayload([
        'type' => 'set',
        'set_members' => [
            ['product_id' => $b->id, 'quantity' => 3],
            ['product_id' => $a->id, 'quantity' => 1],
            // Sent twice: a member wanted twice is a quantity, not two rows.
            ['product_id' => $a->id, 'quantity' => 9],
            // And a set, which may not go in a box.
            ['product_id' => $inner, 'quantity' => 1],
        ],
    ]))->assertCreated()->json('product.id');

    $rows = ProductSetItem::where('set_product_id', $id)->orderBy('position')->get();

    expect($rows)->toHaveCount(2)
        ->and((int) $rows[0]->member_product_id)->toBe($b->id, 'The order sent is the order stored.')
        ->and((int) $rows[0]->quantity)->toBe(3)
        ->and((int) $rows[1]->member_product_id)->toBe($a->id)
        ->and($rows->pluck('member_product_id')->contains($inner))->toBeFalse();
});

it('stores a pricing rule and prices the set from the members, not from the box', function () {
    /*
     * The owner's "how much discount on total, and it will auto set the price",
     * now on the product editor. `products.price` comes back as the DERIVED
     * figure, which is the cache SQL sorts and bands on.
     *
     * MUTATION NOTE. Delete the `$product->price = SetPricing::derived(...)`
     * line from applySetPricing() and the last expectation is red: the shop
     * charges the right price (it is derived on read) while Catalog → Products
     * sorts the set as though it cost whatever was typed. RUN.
     */
    $toner = speProduct('Toner', 9000);
    $serum = speProduct('Serum', 5000);

    $body = $this->postJson('/admin-api/product-editor-create', spePayload([
        'type' => 'set',
        'price_mode' => SetPricing::MODE_PERCENT,
        'discount_percent' => '10',
        'set_members' => [
            ['product_id' => $toner->id, 'quantity' => 1],
            ['product_id' => $serum->id, 'quantity' => 1],
        ],
    ]))->assertCreated()->json('product');

    $set = Product::find($body['id']);
    SetPricing::forget();

    expect($body['price_mode'])->toBe(SetPricing::MODE_PERCENT)
        ->and($body['discount_percent'])->toBe('10')
        ->and($set->getAttributes()['set_discount'])->toBe(1000)
        // 14000 less 10% = 12600, in integer fils.
        ->and($set->effectivePrice())->toBe(12600)
        ->and((int) $set->price)->toBe(12600, 'The cached column is refreshed from the rule at save time.');
});

it('refuses a discount rule on an empty box instead of publishing a free set', function () {
    /*
     * ▲ A DISCOUNT OFF NOTHING IS A FREE SET. A box with no products has a
     *   parts total of zero, so "10% off the total" prices it at AED 0.00 —
     *   published, buyable, and free.
     *
     * ▲ AND THE REFUSAL HAS TO ROLL BACK. This is the one refusal in apply()
     *   that happens AFTER $product->save(), because the parts total is a fact
     *   about rows written a statement ago — and `return` does not roll back a
     *   Laravel transaction. It throws for that reason, which is what the
     *   second expectation checks: no product was left behind.
     *
     * MUTATION NOTE. Change the throw to `return response()->json(..., 422)`
     * and the second expectation is red — the 422 is still returned and a
     * half-made set is committed underneath it. RUN.
     */
    $before = Product::count();

    $this->postJson('/admin-api/product-editor-create', spePayload([
        'name' => 'Empty Box Set',
        'type' => 'set',
        'price_mode' => SetPricing::MODE_AMOUNT,
        'discount_amount' => '10',
        'set_members' => [],
    ]))->assertStatus(422);

    expect(Product::count())->toBe($before, 'A refused create must leave nothing behind.');
});

it('ignores the box and the rule entirely for a product that is not a set', function () {
    /*
     * A simple product may post `set_members` — the screen does not, but a
     * script might — and nothing must happen. applySet() returns before it
     * reads a single one of those keys.
     *
     * MUTATION NOTE. Replace applySet()'s own `if (! $product->isSet())` with
     * `if (false)` and this is red: an ordinary product acquires a box, and then
     * a discount rule against a parts total of zero refuses the whole create.
     * RUN.
     *
     * ▲ MUTATE THE ONE IN applySet(), not the first `! $product->isSet()` in
     *   the file — setMembersPayload() has the same line and mutating THAT is
     *   green, which is a mutation that proves nothing. Caught by running it.
     */
    $toner = speProduct('Toner', 9000);

    $id = $this->postJson('/admin-api/product-editor-create', spePayload([
        'type' => 'simple',
        'set_members' => [['product_id' => $toner->id, 'quantity' => 1]],
        'price_mode' => SetPricing::MODE_PERCENT,
        'discount_percent' => '50',
    ]))->assertCreated()->json('product.id');

    $plain = Product::find($id);

    expect(ProductSetItem::where('set_product_id', $id)->count())->toBe(0)
        ->and($plain->getAttributes()['set_price_mode'])->toBeNull()
        ->and($plain->effectivePrice())->toBe(12900, 'It costs what was typed, as it always did.');
});

it('returns the box and the rule on every product, empty on the ones that are not sets', function () {
    /*
     * The screen decides what to draw from `type`, so a payload whose SHAPE
     * changed with the type would be a screen that has to guard every read.
     *
     * MUTATION NOTE. Make setMembersPayload() return null rather than [] for a
     * product that is not a set and this is red. RUN.
     */
    $plain = speProduct('Ordinary Toner', 8900);

    $body = $this->getJson('/admin-api/product-editor-load/'.$plain->id)->assertOk()->json('product');

    expect($body['set_members'])->toBe([])
        ->and($body['price_mode'])->toBe('fixed')
        ->and($body['discount_percent'])->toBe('')
        ->and($body['set_effective_aed'])->toBe('');
});

/* ══════════════════════════════════════════════════════ the two screens ═══ */

it('puts tags on the product editor, de-duplicated on the slug', function () {
    /*
     * ── A FINDING, AND THEN A FIX (Lane SP) ────────────────────────────────
     *
     * The merge was briefed as "everything you were about to build separately,
     * you now get for nothing — the SEO block, the tags, the gallery, the
     * category picker". Three of those four were true. TAGS WERE NOT: `tags`
     * and `product_tag` have existed since the original schema and NOTHING in
     * this application had ever written to them — not the importer, not the
     * product editor, not the retired Sets editor. The owner had asked for
     * them by name.
     *
     * So they are on the PRODUCT editor rather than on a set's own panel,
     * because a set is a product and so is everything else on that screen.
     *
     * MUTATION NOTE. Key the firstOrCreate() on ['name' => $name] instead of
     * the slug and the last expectation is red with a duplicate-key error from
     * `tags.slug`. RUN.
     */
    $id = $this->postJson('/admin-api/product-editor-create', spePayload([
        'tags' => ['Gift Set', 'gift set', '  Korean skincare  ', ''],
    ]))->assertCreated()->json('product.id');

    $product = Product::find($id);

    expect($product->tags()->count())->toBe(2);
    expect(\App\Models\Tag::where('slug', 'gift-set')->count())->toBe(1);
    expect($product->tags()->pluck('slug')->sort()->values()->all())
        ->toBe(['gift-set', 'korean-skincare']);

    // And omission leaves them alone, so Catalog → Products' inline price cell
    // cannot strip a product's tags by not knowing about them.
    $this->postJson('/admin-api/product-editor-save/'.$id, ['name' => 'Renamed'])->assertOk();

    expect(Product::find($id)->tags()->count())->toBe(2);
});

it('publishes a set\'s tags to Google as keywords, and an ordinary product\'s not at all', function () {
    /*
     * "everything for google adoptions etc."
     *
     * ▲ A SET'S PAGE ONLY, AND THAT IS A DELIBERATE LIMIT RATHER THAN AN
     *   OVERSIGHT. Loading tags for EVERY product page would be one more query
     *   on every product page in the shop — StorefrontQueryBudgetTest's
     *   `product` ceiling — for a feature that is brand new and, until today,
     *   had no way of being populated at all. A set already pays for its
     *   members' eager load, so the query is confined to the pages that have
     *   something to say. Widening it is one line and a budget decision, and it
     *   is in the report as such.
     *
     * MUTATION NOTE. Delete the `keywords` block from App\Support\Seo's product
     * node and this is red. RUN.
     */
    $toner = speProduct('Toner', 9000);

    $setId = $this->postJson('/admin-api/product-editor-create', spePayload([
        'name' => 'Keyword Set',
        'status' => 'publish',
        'type' => 'set',
        'tags' => ['gift set', 'korean skincare'],
        'set_members' => [['product_id' => $toner->id, 'quantity' => 1]],
    ]))->assertCreated()->json('product.id');

    $set = Product::find($setId);
    $set->is_visible = true;
    $set->save();

    $html = $this->get($set->url())->assertOk()->getContent();

    expect(str_contains($html, '"keywords":"gift set, korean skincare"'))->toBeTrue(
        "A set's page must publish its tags as schema.org keywords."
    );

    /*
     * AND THE SET IS DESCRIBED AS A COLLECTION WITHOUT CEASING TO BE A PRODUCT.
     * schema.org HAS a better-fitting type — ProductCollection — and Google's
     * product structured data supports Product and ProductGroup only, so
     * swapping the node's @type would trade a working merchant listing (price,
     * availability, review stars) for a vocabulary nothing reads. The node says
     * it is ALSO a collection and carries the box in the collection's own
     * includesObject shape.
     *
     * MUTATION NOTE. Delete the `additionalType` line from Seo and this is red.
     * RUN.
     */
    expect(str_contains($html, '"additionalType":"https:\/\/schema.org\/ProductCollection"'))->toBeTrue(
        'A set must say it is also a ProductCollection.'
    );
    expect(str_contains($html, '"@type":"TypeAndQuantityNode"'))->toBeTrue(
        "And carry what is in the box in the collection's own shape."
    );

    // An ordinary product's structured data is exactly what it always was.
    expect(str_contains($this->get($toner->url())->assertOk()->getContent(), 'keywords'))->toBeFalse();
});

it('puts the type control and the set panel on the product editor, once each', function () {
    /*
     * MUTATION NOTE. Delete the `setbox` entry from the PANELS registry and the
     * panel is red; delete `typeField()` from basicsView() and the control is.
     * RUN (both).
     */
    $code = speEditorCode();

    expect(substr_count($code, "data-bind=\"type\""))->toBe(1);
    expect(substr_count($code, "key: 'setbox'"))->toBe(1);
    expect(substr_count($code, 'function setboxView()'))->toBe(1);
    expect(substr_count($code, "data-bind=\"price_mode\""))->toBe(1);
    expect(substr_count($code, "key: 'tags'"))->toBe(1);
    expect(substr_count($code, 'id="peo-tagin"'))->toBe(1);
    expect(substr_count($code, 'id="peo-usetotal"'))->toBe(1);

    // The three money tiles the live preview writes into.
    foreach (['parts', 'price', 'saving'] as $tile) {
        expect(substr_count($code, 'data-peo-money="'.$tile.'"'))->toBe(1);
    }
});

it('previews the set price in integer fils and never as a float chain', function () {
    /*
     * ▲ THE SCREEN DOES NOT DECIDE WHAT A SET COSTS — the server does — but the
     *   preview must agree with it to the fil or nobody trusts it again. So the
     *   browser's arithmetic is the same three lines SetPricing runs.
     *
     * MUTATION NOTE. Rewrite setTotals()'s percent branch as
     * `Math.round(parts * (1 - pct / 100))` and this is red — and on the screen
     * a 10% discount on AED 200.01 previews a fil under what the server saves.
     * RUN.
     */
    $code = speEditorCode();

    expect(str_contains($code, 'Math.floor((parts * (10000 - bp) + 5000) / 10000)'))->toBeTrue(
        'The percent preview must be integer fils, rounded once, as SetPricing::derived() is.'
    );

    expect(substr_count($code, 'return isFinite(n) ? Math.round(n * 100) : 0;'))->toBe(
        1,
        'setFils() is the ONE place a price becomes a whole number on this screen.'
    );
});

it('lets the box be dragged, still lets it be moved by keyboard, and measures nothing', function () {
    /*
     * "i need a nice drag n drop type functionality." HTML5 drag events, and
     * THE ARROWS STAY: drag is a mouse gesture, this console is used every day,
     * and a member list a keyboard cannot reorder is a member list half the
     * people who use it cannot reorder.
     *
     * ▲ AND NOTHING READS AN ELEMENT RECTANGLE. Which row the pointer is over
     *   is a question the BROWSER answers by firing the event on that row;
     *   asking it any other way would be the layout measurement CLAUDE.md
     *   rule 4 forbids.
     *
     * MUTATION NOTE. Delete `draggable="true"` and the first is red; delete the
     * two arrow buttons and the second is; add a getBoundingClientRect() to the
     * dragover handler and the third is. RUN (all three).
     */
    $code = speEditorCode();

    /* ▲ `data-peo-setdrag` AND NOT `data-peo-drag`. The panel ARRANGE bar on
       this same screen already owns `data-peo-drag`, and both drag handlers find
       their row with closest() — so sharing the name meant a member row's drop
       could resolve to a panel's. This count is what caught it. */
    expect(substr_count($code, 'data-peo-setdrag="'))->toBe(1);
    expect(substr_count($code, 'data-peo-drag="'))->toBe(1, 'Still exactly one, and it is the arrange bar\'s.');

    /*
     * Counted INSIDE bindSetBox() and not across the file: the panel-arrange bar
     * and the image tiles are dragged on this same screen and register their
     * own dragstart handlers, so a file-wide count would be a count of three
     * features and would go red when any of them changed. Scoping it is what
     * makes the number mean "the box's handlers", which is what is being
     * asserted.
     */
    $bind = speFunctionBody($code, 'function bindSetBox()');

    foreach (['dragstart', 'dragover', 'drop', 'dragend'] as $event) {
        expect(substr_count($bind, "addEventListener('".$event."'"))->toBe(
            1,
            'The box\'s '.$event.' handler must be registered exactly once.'
        );
    }

    expect(substr_count($code, 'data-peo-up="'))->toBe(1)
        ->and(substr_count($code, 'data-peo-down="'))->toBe(1);

    /*
     * ▲ SCOPED TO THE BOX'S OWN CODE, and honestly so. This editor DOES call
     *   getBoundingClientRect elsewhere -- the panel-arrange drag, which
     *   predates this lane and is not this lane's to rewrite -- so a file-wide
     *   sweep here would either be red on somebody else's code or would have to
     *   be weakened until it asserted nothing. What this lane is accountable
     *   for is that the BOX's drag reads no geometry, and that is what is
     *   counted. The file-wide rule is enforced where it belongs, by the two
     *   tests CLAUDE.md names, over the storefront.
     */
    $boxCode = speFunctionBody($code, 'function setboxView()').$bind;

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'getComputedStyle'] as $api) {
        expect(str_contains($boxCode, $api))->toBeFalse(
            'The box must not call '.$api.' — which row the pointer is over is the browser\'s answer.'
        );
    }
});

it('leaves exactly one screen in this console that can edit a set', function () {
    /*
     * ▲ THE POINT OF THE WHOLE REBUILD. Two screens that can both edit a set is
     *   the shape it exists to remove, and "we deleted the other one" is a
     *   claim worth checking rather than asserting.
     *
     * Catalog → Sets is now a LIST: it reads, it deletes, and both its buttons
     * hand over to the product editor's own entry points. It has no save path,
     * no member picker of its own and no pricing controls.
     *
     * MUTATION NOTE. Add a `api('/sets', payload, 'PUT')` back to that screen
     * and the first expectation is red. RUN.
     */
    $sets = speSetsScreenCode();

    foreach (["'PUT'", 'data-kst-save', 'data-kst-f="', 'data-kst-add=', 'data-kst-pick'] as $editorish) {
        expect(str_contains($sets, $editorish))->toBeFalse(
            'Catalog → Sets must not be able to edit a set — found '.$editorish
        );
    }

    // And it hands over to the one editor that can.
    expect(substr_count($sets, 'window.peoEdit('))->toBe(1)
        ->and(substr_count($sets, 'window.peoNew('))->toBe(1);

    // The Stock rule stays, because it is a shop-wide rule and not a product's.
    // Twice: the control that carries it, and the one listener that claims it.
    expect(substr_count($sets, 'data-kst-stock'))->toBe(2);

    // Still no file box anywhere: pictures are the product editor's.
    expect(str_contains($sets, 'type="file"'))->toBeFalse();
});

it('escapes every operator string both screens print', function () {
    /*
     * A product name, a brand, a category and a server error are all settings,
     * and both screens build their markup by string concatenation — so an
     * unescaped interpolation is stored XSS in the back office.
     *
     * MUTATION NOTE. Change `esc(m.name)` in setboxView() to `m.name` and this
     * is red. RUN.
     */
    $editor = speEditorCode();
    $sets = speSetsScreenCode();

    foreach (['esc(m.name)', 'esc(p.name)', 'esc(m.brand)'] as $needle) {
        expect(str_contains($editor, $needle))->toBeTrue(
            $needle.' must be escaped on the way into the product editor markup.'
        );
    }

    foreach (['esc(s.name)', 'esc(state.error)'] as $needle) {
        expect(str_contains($sets, $needle))->toBeTrue(
            $needle.' must be escaped on the way into the Sets list markup.'
        );
    }
});
