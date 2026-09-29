<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\SettingsService;
use App\Services\StockClaim;
use App\Services\StockSetRule;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\SetStockAdminRoutes;

/**
 * Does selling a Set take one of each member off the shelf? (Lane SP)
 *
 * The question has been put to the owner twice without an answer and there is a
 * real argument either way — App\Services\StockSetRule carries both. So the
 * rule is BUILT, TESTED, AND SHIPPED SWITCHED TO WHAT THE SHOP DOES TODAY, and
 * the first case in this file is the one that says so.
 */
beforeEach(function () {
    SetStockAdminRoutes::wire($this->app);
    SettingsService::forgetMemo();
});

function stkAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Stock '.$role,
        'email' => 'stk-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function stkProduct(string $name, int $stock): Product
{
    return Product::create([
        'slug' => 'stk-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9000,
        'stock_status' => 'instock',
        'manage_stock' => true,
        'stock' => $stock,
    ]);
}

/** @param list<array{0: Product, 1: int}> $members */
function stkSet(array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'stk-set-'.Str::random(8),
        'name' => 'Glow Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 19900,
        'stock_status' => 'instock',
        'manage_stock' => false,
    ], $overrides));

    $position = 0;

    foreach ($members as [$product, $quantity]) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $product->id,
            'quantity' => $quantity,
            'position' => $position++,
        ]);
    }

    return $set->fresh();
}

function stkClaim(Product $set, int $quantity = 1): void
{
    DB::transaction(function () use ($set, $quantity) {
        app(StockClaim::class)->claim([[
            'product_id' => $set->id,
            'variant_id' => null,
            'quantity' => $quantity,
            'label' => $set->name,
        ]]);
    });
}

/* ════════════════════════════════════════════════ the default, and it MOVED ═══ */

it('ships taking members off the shelf, which the owner asked for by name', function () {
    /*
     * ▲ THIS PIN WAS THE OPPOSITE UNTIL 29 SEPTEMBER 2026, AND THE OLD TEXT IS
     *   KEPT HERE BECAUSE IT WAS RIGHT WHEN IT WAS WRITTEN.
     *
     * It read: "ships switched off, so applying the package moves not one
     * unit", and cited CLAUDE.md rule 1 — any NEW setting ships at the value
     * the page already has. That was correct: the lane that built this rule had
     * no instruction to change what the shop counted, so it shipped the switch
     * off and said in its report that the choice was the owner's.
     *
     * He made it, in these words: *"Yes if the product sold inside set or
     * individual, the stock should be minus in any case."* That is rule 1's
     * stated exception — a default the owner asked for in as many words — and
     * it is called out in the commit rather than buried, which is the other
     * half of what rule 1 requires.
     *
     * So this case now pins the opposite fact, and the one it used to pin lives
     * on two cases below, where an operator who chooses the old rule keeps it.
     *
     * MUTATION NOTE. Change StockSetRule::mode()'s fall-through back to
     * MODE_SET and this is red: the toner keeps all six after a box containing
     * it is sold, and the shop sells a second box it cannot pack. RUN.
     */
    $toner = stkProduct('Toner', 6);
    $set = stkSet([[$toner, 1]]);

    expect(app(StockSetRule::class)->mode())->toBe(StockSetRule::MODE_MEMBERS)
        ->and(app(StockSetRule::class)->decrementsMembers())->toBeTrue();

    stkClaim($set);

    expect((int) $toner->fresh()->stock)->toBe(
        5,
        "A member's shelf must come down when the box it is in is sold."
    );
});

it('costs no query at all while it is switched off', function () {
    /*
     * OFF IS NO LONGER THE DEFAULT, so this case sets it. What it measures is
     * unchanged and still worth measuring: with the rule off, expand() must not
     * put a statement inside the transaction that places every order in this
     * shop.
     */
    app(\App\Services\SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_SET);

    /*
     * The other half of "moves nothing": the expansion must not put a statement
     * inside the transaction that places every order in this shop. expand()
     * returns the caller's own array before it asks the database anything.
     *
     * MUTATION NOTE. Move the `decrementsMembers()` test in
     * StockSetRule::expand() to AFTER setIdsAmong() and this is red by one
     * query on every order, set or no set. RUN.
     */
    $toner = stkProduct('Toner', 6);
    $set = stkSet([[$toner, 1]]);

    $lines = [['product_id' => $set->id, 'variant_id' => null, 'quantity' => 1, 'label' => 'Glow Set']];

    /*
     * Warm the settings map first. Reading a setting costs one statement the
     * FIRST time in a process and nothing afterwards, and by the time a
     * checkout reaches StockClaim this application has read that table many
     * times over — so counting it here would be measuring the boot rather than
     * this method. What is being measured is what expand() adds ON TOP of it,
     * which must be nothing.
     */
    app(StockSetRule::class)->mode();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $out = app(StockSetRule::class)->expand($lines);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($out)->toBe($lines)
        ->and($queries)->toBe(0);
});

/* ════════════════════════════════════════════════ and it works when it is on ═══ */

it('takes one of each member off its own shelf when the rule is on', function () {
    /*
     * Two toners in the box and two sets sold is four toners off the shelf:
     * both factors multiply, and both are integers.
     *
     * MUTATION NOTE. Delete the `app(StockSetRule::class)->expand($lines)` call
     * from StockClaim::claim() and this is red — the switch is on the screen,
     * the setting is stored, and nothing happens. RUN.
     */
    app(SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_MEMBERS);
    SettingsService::forgetMemo();

    $toner = stkProduct('Toner', 10);
    $serum = stkProduct('Serum', 10);
    $set = stkSet([[$toner, 2], [$serum, 1]]);

    stkClaim($set, 2);

    expect((int) $toner->fresh()->stock)->toBe(6, '2 in the box × 2 sets sold = 4 off the shelf.')
        ->and((int) $serum->fresh()->stock)->toBe(8);
});

it('takes a shared shelf down once for the total, not twice', function () {
    /*
     * ▲ THE REASON expand() RUNS BEFORE StockClaim::perShelf() AND NOT INSIDE
     *   THE LOOP AFTER IT.
     *
     * A basket holding the Glow Set AND the toner that is inside it must come
     * off the toner's shelf ONCE, for the total of three. perShelf() is what
     * sums two lines that share a shelf, so the expansion has to be upstream of
     * it — expanded afterwards, the same jar is taken twice and a sale the shop
     * could have filled is refused.
     *
     * MUTATION NOTE. Move the expand() call inside the foreach in
     * StockClaim::claim(), so each already-summed line is expanded, and this is
     * red: the toner goes down by 2 and then by 1 as two separate claims, which
     * happens to reach the same number here — so ALSO set the toner's stock to
     * 2 and watch it throw StockUnavailable for an order the shop could fill.
     * RUN (both).
     */
    app(SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_MEMBERS);
    SettingsService::forgetMemo();

    $toner = stkProduct('Toner', 3);
    $set = stkSet([[$toner, 2]]);

    DB::transaction(function () use ($set, $toner) {
        app(StockClaim::class)->claim([
            ['product_id' => $set->id, 'variant_id' => null, 'quantity' => 1, 'label' => 'Glow Set'],
            ['product_id' => $toner->id, 'variant_id' => null, 'quantity' => 1, 'label' => 'Toner'],
        ]);
    });

    expect((int) $toner->fresh()->stock)->toBe(0)
        ->and((string) $toner->fresh()->stock_status)->toBe('outofstock');
});

it('refuses the sale, naming the member AND the set it is inside', function () {
    /*
     * The name on the basket row is the SET's, so a refusal that named only the
     * member would send the shopper looking for a line that is not there.
     *
     * MUTATION NOTE. Change StockSetRule::memberLabel() to return the member's
     * name alone and the second half is red. RUN.
     */
    app(SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_MEMBERS);
    SettingsService::forgetMemo();

    $toner = stkProduct('Heartleaf Toner', 1);
    $set = stkSet([[$toner, 3]], ['name' => 'Glow Starter Set']);

    $message = null;

    try {
        stkClaim($set);
    } catch (\App\Services\StockUnavailable $e) {
        $message = $e->getMessage();
    }

    expect($message)->not->toBeNull('A box the shop cannot fill must refuse the sale.');
    expect(str_contains((string) $message, 'Heartleaf Toner'))->toBeTrue(
        'The refusal must name the product that is short.'
    );
    expect(str_contains((string) $message, 'Glow Starter Set'))->toBeTrue(
        'And the set it is inside, because that is the line on the shopper\'s screen.'
    );

    expect((int) $toner->fresh()->stock)->toBe(1, 'A refused claim writes nothing.');
});

it('leaves an ordinary product alone whichever way the switch is set', function () {
    // The rule is about sets. Nothing else in the shop may notice it exists.
    app(SettingsService::class)->set(StockSetRule::KEY, StockSetRule::MODE_MEMBERS);
    SettingsService::forgetMemo();

    $toner = stkProduct('Toner', 5);

    DB::transaction(function () use ($toner) {
        app(StockClaim::class)->claim([[
            'product_id' => $toner->id, 'variant_id' => null, 'quantity' => 2, 'label' => 'Toner',
        ]]);
    });

    expect((int) $toner->fresh()->stock)->toBe(3);
});

/* ═════════════════════════════════════════════════════════ the endpoint ═══ */

it('stores one of its own two options or the default, never what arrived', function () {
    /*
     * CLAUDE.md rule 5. The validator refuses a third value with a 422 and
     * nothing is written; StockSetRule::mode() reads anything unrecognised back
     * as the default, so a row written another way still cannot surprise
     * anyone.
     *
     * MUTATION NOTE. Drop the `in:` rule from SetStockApiController::save() and
     * the 422 becomes a 200 storing 'everything'. RUN.
     */
    $this->actingAs(stkAdmin(), 'admin');

    $this->postJson('/admin-api/set-stock', ['mode' => 'everything'])->assertStatus(422);

    /*
     * The refused post wrote nothing, so this reads the DEFAULT — which is
     * MODE_MEMBERS since 29 September 2026. The value here is deliberately not
     * spelled out as a literal: what the case is about is that a third value
     * cannot be stored, not which of the two is currently the default.
     */
    expect(app(StockSetRule::class)->mode())->toBe(StockSetRule::MODE_MEMBERS);

    // The operator's own choice of the OTHER rule is stored and honoured.
    $this->postJson('/admin-api/set-stock', ['mode' => StockSetRule::MODE_SET])
        ->assertOk()
        ->assertJson(['saved' => true, 'mode' => StockSetRule::MODE_SET]);

    SettingsService::forgetMemo();

    expect(app(StockSetRule::class)->mode())->toBe(StockSetRule::MODE_SET);

    /*
     * And a row that got there another way reads back as the default — which is
     * now the SAFER of the two. It used to fall to MODE_SET, the permissive
     * answer, which would sell a box whose contents may not exist; a garbled
     * row now makes the shop refuse an order it cannot pack instead.
     */
    app(SettingsService::class)->set(StockSetRule::KEY, 'nonsense');
    SettingsService::forgetMemo();

    expect(app(StockSetRule::class)->mode())->toBe(StockSetRule::MODE_MEMBERS);
});

it('gives both endpoints their own capability and fails closed', function () {
    /*
     * A route App\Support\AdminCapabilities::RULES has never heard of resolves
     * to null and EnforceAdminCapability turns null into 403 for everyone but
     * the owner — the right default and a terrible thing to rely on, because
     * "owner-only because somebody decided so" and "owner-only because nobody
     * mapped it" are the same 403 and a very different piece of evidence.
     *
     * MUTATION NOTE. Delete the two 'admin-api/set-stock' lines from
     * AdminCapabilities::RULES and the first half is red. Add 'editor' to
     * `sets.stock` in CAPABILITIES and the second half is red. RUN (both).
     */
    foreach (SetStockAdminRoutes::registered() as $route) {
        $capability = AdminCapabilities::forPath(
            collect($route->methods())->first(fn ($m) => $m !== 'HEAD') ?? 'GET',
            $route->uri()
        );

        expect($capability)->toBe(
            'sets.stock',
            $route->uri().' must resolve to its own capability, not to the owner-only default.'
        );
    }

    // NARROWER THAN sets.manage on purpose: an editor may build sets and may
    // not decide that selling one empties three other shelves.
    expect(AdminCapabilities::CAPABILITIES['sets.stock'])->toBe(['owner', 'manager']);

    $this->actingAs(stkAdmin('editor'), 'admin');
    $this->getJson('/admin-api/set-stock')->assertStatus(403);
    $this->postJson('/admin-api/set-stock', ['mode' => StockSetRule::MODE_MEMBERS])->assertStatus(403);
});
