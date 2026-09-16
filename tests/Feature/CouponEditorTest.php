<?php

declare(strict_types=1);

/**
 * Store → Manage Coupons: the screen that can create, edit and delete a code.
 *
 * WHAT THESE ARE FOR. Every coupon on the shop arrived in the WooCommerce
 * import, because there was no editor at all — the console's only coupon screen
 * is a read-only usage report. An editor that writes the wrong integer into
 * `coupons.amount` is worse than no editor, because the column means two
 * different things depending on `type`: hundredths of a percent for a
 * percentage code, minor units (fils) for both fixed ones. Write 35 where 3500
 * belongs and a 35% sale becomes 0.35%; write 3500 where 35 belongs and AED 0.35
 * becomes AED 35.
 *
 * THE TEST THAT MATTERS MOST is the last section: a coupon created through this
 * endpoint, priced by the real App\Services\CouponService against a real cart.
 * Everything above it checks that the editor stores what it was told; that
 * section checks that what it stored means what the owner typed. An editor and
 * a pricing service that each work perfectly and disagree about the units is
 * exactly the bug this whole file exists to make impossible.
 *
 * Pest trap avoided throughout: toContain() takes its arguments as further
 * NEEDLES and toHaveKey() takes a second argument as an EXPECTED VALUE —
 * neither is a failure message. Where a message is wanted, the assertion is
 * str_contains(...) with toBeTrue('...').
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\CouponService;
use Illuminate\Support\Str;
use Tests\Support\CouponsAdminRoutes;

beforeEach(function () {
    CouponsAdminRoutes::wire(app());

    $this->admin = AdminUser::create([
        'name' => 'Owner',
        'email' => 'owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
});

/* ------------------------------------------------------------------ tools */

/** The payload the screen posts, with only the keys a test cares about set. */
function ceditorPayload(array $overrides = []): array
{
    return $overrides + [
        'code' => 'SUMMER20',
        'type' => 'percent',
        'amount' => '20',
        'description' => null,
        'starts_at' => null,
        'expires_at' => null,
        'minimum_amount' => null,
        'maximum_amount' => null,
        'exclude_sale_items' => false,
        'usage_limit' => null,
        'usage_limit_per_user' => null,
        'product_ids' => [],
        'excluded_product_ids' => [],
        'category_ids' => [],
        'excluded_category_ids' => [],
        'allowed_emails' => [],
    ];
}

function ceditorPost(array $overrides = [])
{
    return test()->actingAs(test()->admin, 'admin')
        ->postJson('/admin-api/coupons/manage', ceditorPayload($overrides));
}

function ceditorPut(int $id, array $overrides = [])
{
    return test()->actingAs(test()->admin, 'admin')
        ->putJson('/admin-api/coupons/manage/' . $id, ceditorPayload($overrides));
}

function ceditorGet(string $path)
{
    return test()->actingAs(test()->admin, 'admin')->getJson($path);
}

function ceditorDelete(int $id)
{
    return test()->actingAs(test()->admin, 'admin')
        ->deleteJson('/admin-api/coupons/manage/' . $id);
}

/**
 * A category with a slug nothing else can own.
 *
 * The migration set seeds a demo catalogue, so a fixture that reaches for an
 * obvious slug like `serums` collides with a row this file never created — and
 * the failure reads as a bug in the code under test rather than in the fixture.
 */
function ceditorCategory(string $name): Category
{
    return Category::create([
        'slug' => Str::slug($name) . '-' . Str::random(8),
        'name' => $name,
    ]);
}

function ceditorProduct(string $name, int $priceFils, ?int $saleFils = null): Product
{
    return Product::create([
        'slug' => Str::slug($name) . '-' . Str::random(8),
        'name' => $name,
        'status' => 'publish',
        'is_visible' => true,
        'price' => $priceFils,
        'sale_price' => $saleFils,
        'stock_status' => 'instock',
    ]);
}

/** A cart carrying one line per [product, quantity], at the product's price. */
function ceditorCart(Coupon $coupon, array $lines): Cart
{
    $cart = Cart::create([
        'token' => (string) Str::uuid(),
        'currency' => 'AED',
        'status' => 'active',
        'shipping_country' => 'AE',
        'coupon_id' => $coupon->id,
        'last_activity_at' => now(),
    ]);

    foreach ($lines as [$product, $quantity]) {
        $cart->items()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $product->effectivePrice(),
        ]);
    }

    return $cart->load('items.product');
}

/* =========================================================== 1. CREATING */

it('creates a coupon from the editor', function () {
    $response = ceditorPost(['code' => 'GLOW15', 'type' => 'percent', 'amount' => '15'])
        ->assertCreated();

    $coupon = Coupon::find($response->json('id'));

    expect($coupon)->not->toBeNull()
        ->and($coupon->code)->toBe('GLOW15')
        ->and($coupon->type)->toBe('percent')
        // 15% is 1500 hundredths of a percent. This is the whole ballgame.
        ->and($coupon->amount)->toBe(1500)
        ->and($coupon->usage_count)->toBe(0);
});

it('stores a fixed cart amount as minor units, not as a percentage', function () {
    $response = ceditorPost(['code' => 'TENOFF', 'type' => 'fixed_cart', 'amount' => '25.50'])
        ->assertCreated();

    // AED 25.50 is 2550 fils. The same "25.50" typed against a percentage code
    // would be 2550 hundredths of a percent, which is 25.5% — one string, two
    // meanings, decided by `type`.
    expect(Coupon::find($response->json('id'))->amount)->toBe(2550);
});

it('keeps two decimal places on a percentage exactly', function () {
    // 12.5% must be 1250, and 7.35% must be 735. Reached through a float —
    // (int) (7.35 * 100) — the second one is 734, because 7.35 is not
    // representable in binary. The conversion is integer arithmetic on the
    // digits for exactly this reason.
    $half = ceditorPost(['code' => 'HALF', 'type' => 'percent', 'amount' => '12.5'])->assertCreated();
    $odd = ceditorPost(['code' => 'ODD', 'type' => 'percent', 'amount' => '7.35'])->assertCreated();

    expect(Coupon::find($half->json('id'))->amount)->toBe(1250)
        ->and(Coupon::find($odd->json('id'))->amount)->toBe(735);
});

it('stores an empty restriction as null rather than an empty list', function () {
    $response = ceditorPost(['code' => 'PLAIN'])->assertCreated();

    $coupon = Coupon::find($response->json('id'));

    /*
     * CouponService reads these with a plain truthiness test, so [] and null
     * both mean "no restriction" — but null is what the import wrote and what
     * every existing row holds. Two spellings of the same fact in one column
     * is how a later `!== null` check ends up meaning the opposite of what it
     * reads.
     */
    expect($coupon->getAttributes()['product_ids'])->toBeNull()
        ->and($coupon->getAttributes()['category_ids'])->toBeNull()
        ->and($coupon->getAttributes()['allowed_emails'])->toBeNull();
});

it('records the restrictions the owner set', function () {
    $product = ceditorProduct('Snail Essence', 12000);
    $category = ceditorCategory('Serums');

    $response = ceditorPost([
        'code' => 'PICKY',
        'minimum_amount' => '100.00',
        'maximum_amount' => '500',
        'exclude_sale_items' => true,
        'product_ids' => [$product->id],
        'excluded_category_ids' => [$category->id],
        'allowed_emails' => ['Someone@Example.AE', 'other@example.ae'],
        'usage_limit' => 40,
        'usage_limit_per_user' => 2,
    ])->assertCreated();

    $coupon = Coupon::find($response->json('id'));

    expect($coupon->minimum_amount)->toBe(10000)
        ->and($coupon->maximum_amount)->toBe(50000)
        ->and($coupon->exclude_sale_items)->toBeTrue()
        ->and($coupon->product_ids)->toBe([$product->id])
        ->and($coupon->excluded_category_ids)->toBe([$category->id])
        // Lower-cased on the way in, because validate() lower-cases both sides
        // of the comparison — so the list the owner reads back is the list
        // that is actually being compared.
        ->and($coupon->allowed_emails)->toBe(['someone@example.ae', 'other@example.ae'])
        ->and($coupon->usage_limit)->toBe(40)
        ->and($coupon->usage_limit_per_user)->toBe(2);
});

/* ============================================================ 2. EDITING */

it('edits an existing coupon', function () {
    $id = ceditorPost(['code' => 'OLDCODE', 'type' => 'percent', 'amount' => '10'])
        ->assertCreated()->json('id');

    ceditorPut($id, ['code' => 'NEWCODE', 'type' => 'fixed_cart', 'amount' => '30'])
        ->assertOk();

    $coupon = Coupon::find($id);

    expect($coupon->code)->toBe('NEWCODE')
        ->and($coupon->type)->toBe('fixed_cart')
        ->and($coupon->amount)->toBe(3000);
});

it('lets a coupon keep its own code when edited', function () {
    $id = ceditorPost(['code' => 'KEEPME'])->assertCreated()->json('id');

    // The duplicate check must exclude the row being saved, or no coupon could
    // ever be edited without renaming it.
    ceditorPut($id, ['code' => 'KEEPME', 'amount' => '30'])->assertOk();

    expect(Coupon::find($id)->amount)->toBe(3000);
});

it('never lets the editor write the redemption counter', function () {
    $id = ceditorPost(['code' => 'COUNTED'])->assertCreated()->json('id');

    Coupon::whereKey($id)->update(['usage_count' => 7]);

    /*
     * usage_count must only ever move through CouponService::recordRedemption(),
     * under the lock that method takes. An editor that could set it would hand
     * back uses of a code that had already been spent — and the column is not
     * in the validated set, so a hand-rolled POST carrying it is ignored rather
     * than honoured.
     */
    test()->actingAs(test()->admin, 'admin')
        ->putJson('/admin-api/coupons/manage/' . $id, ceditorPayload(['code' => 'COUNTED']) + ['usage_count' => 0])
        ->assertOk();

    expect(Coupon::find($id)->usage_count)->toBe(7);
});

it('leaves free shipping and individual use exactly as the import left them', function () {
    $coupon = Coupon::create([
        'code' => 'IMPORTED',
        'type' => 'percent',
        'amount' => 1000,
        'free_shipping' => true,
        'individual_use' => true,
    ]);

    /*
     * Neither column is enforced anywhere in this application — free delivery
     * comes from a shipping method or the order-value threshold, and a basket
     * holds one coupon by construction (carts.coupon_id is a single FK). The
     * screen shows both as facts rather than controls, and the endpoint accepts
     * neither, so a value that arrived from WooCommerce survives an edit
     * untouched instead of being quietly cleared by a form that does not draw
     * an editable box for it.
     */
    ceditorPut($coupon->id, ['code' => 'IMPORTED', 'amount' => '10'])
        ->assertOk();

    $coupon->refresh();

    expect($coupon->free_shipping)->toBeTrue()
        ->and($coupon->individual_use)->toBeTrue();
});

/* ========================================================== 3. DUPLICATES */

it('refuses a duplicate coupon code', function () {
    ceditorPost(['code' => 'ONLYONE'])->assertCreated();

    $response = ceditorPost(['code' => 'ONLYONE'])->assertStatus(422);

    expect(str_contains(json_encode($response->json()), 'already a coupon'))
        ->toBeTrue('the duplicate was not refused with an explanation');

    expect(Coupon::where('code', 'ONLYONE')->count())->toBe(1);
});

it('refuses a duplicate that differs only in capitals', function () {
    ceditorPost(['code' => 'GLOW10'])->assertCreated();

    /*
     * THE CASE THE DATABASE WILL NOT SETTLE FOR US. Coupon::scopeCode() is
     * `LOWER(code) = ?`, so GLOW10 and glow10 are one code at the till.
     * Production MySQL is utf8mb4_unicode_ci and its unique index would refuse
     * the second row; the SQLite this suite also runs on is case-SENSITIVE and
     * would accept it, leaving two rows that scopeCode() picks between by
     * whichever the database hands back first. Checked in PHP so both engines
     * behave the same way.
     */
    ceditorPost(['code' => 'glow10'])->assertStatus(422);

    expect(Coupon::count())->toBe(1);
});

it('refuses a duplicate when renaming an existing coupon onto another', function () {
    ceditorPost(['code' => 'FIRST'])->assertCreated();
    $second = ceditorPost(['code' => 'SECOND'])->assertCreated()->json('id');

    ceditorPut($second, ['code' => 'first'])->assertStatus(422);

    expect(Coupon::find($second)->code)->toBe('SECOND');
});

/* ======================================================= 4. THE 100% CAP */

it('refuses a percentage above 100%', function () {
    $response = ceditorPost(['code' => 'TOOMUCH', 'type' => 'percent', 'amount' => '500'])
        ->assertStatus(422);

    expect(str_contains(json_encode($response->json()), 'more than 100%'))
        ->toBeTrue('a 500% coupon was not refused with an explanation');

    expect(Coupon::count())->toBe(0);
});

it('allows exactly 100%', function () {
    $id = ceditorPost(['code' => 'FREEBIE', 'type' => 'percent', 'amount' => '100'])
        ->assertCreated()->json('id');

    expect(Coupon::find($id)->amount)->toBe(10000);
});

it('does not cap a fixed amount at 100', function () {
    // 100 is a ceiling on a PERCENTAGE, not on money. AED 250 off a basket is
    // an ordinary coupon and a cap applied to the raw integer would refuse it.
    $id = ceditorPost(['code' => 'BIGONE', 'type' => 'fixed_cart', 'amount' => '250'])
        ->assertCreated()->json('id');

    expect(Coupon::find($id)->amount)->toBe(25000);
});

it('refuses an amount that is not a number', function () {
    ceditorPost(['code' => 'JUNK', 'amount' => 'ten percent'])->assertStatus(422);
    ceditorPost(['code' => 'NEG', 'amount' => '-5'])->assertStatus(422);
    // More than two decimals is refused rather than truncated: 7.125% saved as
    // 7.12% reads back looking like a typo the owner did not make.
    ceditorPost(['code' => 'TOOFINE', 'amount' => '7.125'])->assertStatus(422);

    expect(Coupon::count())->toBe(0);
});

/* ============================================================== 5. DATES */

it('accepts an expiry in the past and shows the code as expired', function () {
    $id = ceditorPost([
        'code' => 'LASTYEAR',
        'expires_at' => now()->subMonths(3)->toDateString(),
    ])->assertCreated()->json('id');

    // Saving history is allowed. The list is where it has to be obvious.
    $row = collect(ceditorGet('/admin-api/coupons/manage')->assertOk()->json('coupons'))
        ->firstWhere('id', $id);

    expect($row['status'])->toBe('expired')
        ->and($row['expired'])->toBeTrue();
});

it('makes an expiry date last all through that day', function () {
    $day = now()->addDays(2)->toDateString();

    $id = ceditorPost(['code' => 'ENDSFRIDAY', 'expires_at' => $day])
        ->assertCreated()->json('id');

    /*
     * CouponService refuses the code while `now > expires_at`. Stored at
     * midnight, a coupon "expiring on the 31st" would stop working as the 30th
     * ended — a day early, which is a day of a sale. 23:59:59 is what the
     * field's help text on the screen promises.
     */
    $coupon = Coupon::find($id);

    expect($coupon->expires_at->toDateString())->toBe($day)
        ->and($coupon->expires_at->format('H:i:s'))->toBe('23:59:59');
});

it('refuses a coupon that would expire before it started', function () {
    ceditorPost([
        'code' => 'BACKWARDS',
        'starts_at' => now()->addMonth()->toDateString(),
        'expires_at' => now()->toDateString(),
    ])->assertStatus(422);

    expect(Coupon::count())->toBe(0);
});

/* ============================================================ 6. DELETING */

it('deletes a coupon nobody has redeemed', function () {
    $id = ceditorPost(['code' => 'THROWAWAY'])->assertCreated()->json('id');

    ceditorDelete($id)->assertOk();

    expect(Coupon::find($id))->toBeNull();
});

it('refuses to delete a coupon that has been redeemed', function () {
    $id = ceditorPost(['code' => 'USEDCODE'])->assertCreated()->json('id');

    $customer = Customer::create(['name' => 'Shopper', 'email' => 'shopper@example.ae']);

    $order = Order::create([
        'order_number' => 'KBB-1', 'customer_id' => $customer->id, 'email' => $customer->email,
        'status' => 'processing', 'currency' => 'AED', 'subtotal' => 20000, 'discount_total' => 2000,
        'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0, 'total' => 18000,
        'payment_method' => 'cod', 'coupon_code' => 'USEDCODE',
    ]);

    CouponRedemption::create([
        'coupon_id' => $id, 'customer_id' => $customer->id, 'order_id' => $order->id,
        'email' => $customer->email, 'amount' => 2000,
    ]);

    /*
     * coupon_redemptions.coupon_id is `constrained()->cascadeOnDelete()`, so
     * deleting the coupon orphans nothing — the database takes every redemption
     * with it, silently. What is lost is the whole answer to "who used this
     * code and for how much", while orders.coupon_code keeps printing the code
     * on the order. That is not a confirmation dialog's worth of risk, so it is
     * refused and the owner is pointed at the expiry date instead.
     */
    $response = ceditorDelete($id)->assertStatus(409);

    expect(str_contains(json_encode($response->json()), 'Set its expiry date instead'))
        ->toBeTrue('the refusal did not say what to do instead');

    expect(Coupon::find($id))->not->toBeNull()
        ->and(CouponRedemption::where('coupon_id', $id)->count())->toBe(1);
});

it('deletes a coupon whose counter came over from WooCommerce with no rows behind it', function () {
    /*
     * usage_count was migrated from WooCommerce verbatim, so a coupon can read
     * "used 40 times" with no redemption rows in this application at all.
     * Refusing those would mean most of the imported codes could never be
     * removed, so they delete — and the response hands back the figure the
     * counter was carrying, so the number is not merely gone.
     */
    $coupon = Coupon::create(['code' => 'WOOLEGACY', 'type' => 'percent', 'amount' => 1000, 'wc_id' => 99]);
    Coupon::whereKey($coupon->id)->update(['usage_count' => 40]);

    $response = ceditorDelete($coupon->id)->assertOk();

    expect($response->json('imported_usage_count'))->toBe(40)
        ->and(Coupon::find($coupon->id))->toBeNull();
});

it('marks a redeemed coupon as not deletable in the list', function () {
    $id = ceditorPost(['code' => 'REDEEMED'])->assertCreated()->json('id');

    CouponRedemption::create(['coupon_id' => $id, 'email' => 'a@example.ae', 'amount' => 500]);

    $row = collect(ceditorGet('/admin-api/coupons/manage')->assertOk()->json('coupons'))
        ->firstWhere('id', $id);

    // A Delete button that is only refused when pressed is worse than one that
    // is not offered.
    expect($row['deletable'])->toBeFalse()
        ->and($row['redemptions'])->toBe(1);
});

/* ============================================================== 7. THE LIST */

it('reads a coupon back into the editor in the units the owner typed', function () {
    $product = ceditorProduct('Rice Toner', 8900);

    $id = ceditorPost([
        'code' => 'ROUNDTRIP', 'type' => 'percent', 'amount' => '12.5',
        'minimum_amount' => '75.00',
        'product_ids' => [$product->id],
        'allowed_emails' => ['vip@example.ae'],
    ])->assertCreated()->json('id');

    $coupon = ceditorGet('/admin-api/coupons/manage/' . $id)->assertOk()->json('coupon');

    // Stored as 1250, shown as "12.5" — what was typed, not what was stored.
    expect($coupon['amount'])->toBe(1250)
        ->and($coupon['amount_input'])->toBe('12.5')
        ->and($coupon['amount_display'])->toBe('12.5%')
        ->and($coupon['minimum_amount'])->toBe('75.00')
        ->and($coupon['products'][0]['label'])->toBe('Rice Toner')
        ->and($coupon['allowed_emails'])->toBe(['vip@example.ae']);
});

it('keeps a selection whose product has since been deleted, and says so', function () {
    $product = ceditorProduct('Discontinued Cream', 5000);

    $id = ceditorPost(['code' => 'STALE', 'product_ids' => [$product->id]])
        ->assertCreated()->json('id');

    $productId = $product->id;
    $product->delete();

    /*
     * Nothing cleans these JSON columns when a product goes, so the stale id
     * sits in the rule for ever and eligibleItems() keeps comparing live
     * product ids against it. Dropping it silently from the editor would make
     * the screen disagree with the pricing; shown as deleted, the owner can see
     * it and take it out.
     */
    $coupon = ceditorGet('/admin-api/coupons/manage/' . $id)->assertOk()->json('coupon');

    expect($coupon['products'][0]['id'])->toBe($productId)
        ->and($coupon['products'][0]['missing'])->toBeTrue();
});

it('filters the list by status', function () {
    ceditorPost(['code' => 'LIVEONE'])->assertCreated();
    ceditorPost(['code' => 'DEADONE', 'expires_at' => now()->subYear()->toDateString()])->assertCreated();

    $expired = ceditorGet('/admin-api/coupons/manage?status=expired')->assertOk()->json('coupons');
    $active = ceditorGet('/admin-api/coupons/manage?status=active')->assertOk()->json('coupons');

    expect(collect($expired)->pluck('code')->all())->toBe(['DEADONE'])
        ->and(collect($active)->pluck('code')->all())->toBe(['LIVEONE']);
});

it('counts the summary over the whole filtered set, not just the page', function () {
    // AggregatesQueries strips the row columns, the ORDER BY and the page
    // window from a clone before adding the aggregate. Done by hand this is
    // MySQL error 1140 under ONLY_FULL_GROUP_BY, and a surviving OFFSET makes
    // every total read zero from page two on. Both shipped once.
    ceditorPost(['code' => 'ONE'])->assertCreated();
    ceditorPost(['code' => 'TWO', 'expires_at' => now()->subYear()->toDateString()])->assertCreated();
    ceditorPost(['code' => 'THREE', 'expires_at' => now()->subYear()->toDateString()])->assertCreated();

    $summary = ceditorGet('/admin-api/coupons/manage')->assertOk()->json('summary');

    expect($summary['coupons'])->toBe(3)
        ->and($summary['expired'])->toBe(2);
});

it('searches by code and by description', function () {
    ceditorPost(['code' => 'ALPHA', 'description' => 'Ramadan mailer'])->assertCreated();
    ceditorPost(['code' => 'BETA', 'description' => 'Instagram story'])->assertCreated();

    $byCode = ceditorGet('/admin-api/coupons/manage?q=alph')->assertOk()->json('coupons');
    $byNote = ceditorGet('/admin-api/coupons/manage?q=ramadan')->assertOk()->json('coupons');

    expect(collect($byCode)->pluck('code')->all())->toBe(['ALPHA'])
        ->and(collect($byNote)->pluck('code')->all())->toBe(['ALPHA']);
});

it('finds products and categories for the pickers', function () {
    // Deliberately unlike anything in the seeded demo catalogue: a search term
    // the fixtures share with seed data proves nothing about the search.
    ceditorProduct('Zzyzx Marshmallow Ampoule', 9900);
    ceditorCategory('Zzyzx Ampoules');

    $products = ceditorGet('/admin-api/coupons/manage/lookup?kind=product&q=zzyzx')->assertOk()->json('items');
    $categories = ceditorGet('/admin-api/coupons/manage/lookup?kind=category&q=zzyzx')->assertOk()->json('items');

    expect(collect($products)->pluck('label')->all())->toBe(['Zzyzx Marshmallow Ampoule'])
        ->and(collect($categories)->pluck('label')->all())->toBe(['Zzyzx Ampoules']);
});

/* =====================================================================
 | 8. THE ONE THAT MATTERS: the editor and the pricing must agree.
 |
 | Everything above proves the editor stores what it was told. These prove
 | that what it stored MEANS what the owner typed — by handing the row it
 | wrote to the real CouponService, against a real cart, and checking the
 | exact number of fils.
 ==================================================================== */

it('prices a percentage coupon created through the editor exactly as the shop does', function () {
    // 35% is the awkward one on purpose: 0.35 is not representable in binary,
    // so it is the value that catches a float anywhere in either half.
    $id = ceditorPost(['code' => 'THIRTYFIVE', 'type' => 'percent', 'amount' => '35'])
        ->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $product = ceditorProduct('Sheet Mask Box', 20490);
    $cart = ceditorCart($coupon, [[$product, 1]]);

    $service = app(CouponService::class);

    // 35% of 20,490 fils is exactly 7,171.5, which rounds half-up to 7,172.
    // If the editor had stored 35 instead of 3500 this would be 72 — a hundred
    // times too small, and the shop would quietly hand out 0.35% off.
    expect($service->discountFor($coupon, $cart))->toBe(7172);

    $check = $service->validate('THIRTYFIVE', $cart);
    expect($check['ok'])->toBeTrue();
});

it('prices a fixed cart coupon created through the editor exactly as the shop does', function () {
    $id = ceditorPost(['code' => 'THIRTYOFF', 'type' => 'fixed_cart', 'amount' => '30.00'])
        ->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $product = ceditorProduct('Cleansing Oil', 12000);
    $cart = ceditorCart($coupon, [[$product, 1]]);

    // AED 30.00 off, which is 3000 fils. Stored as a percentage this would be
    // 3000 hundredths of a percent — 30% of 120.00, i.e. AED 36 — and the two
    // are close enough that a casual look would not catch it.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(3000);
});

it('prices a fixed product coupon per item and per quantity', function () {
    $id = ceditorPost(['code' => 'FIVEEACH', 'type' => 'fixed_product', 'amount' => '5.00'])
        ->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $cheap = ceditorProduct('Lip Balm', 300);      // AED 3.00 — cheaper than the discount
    $normal = ceditorProduct('Toner', 6000);

    $cart = ceditorCart($coupon, [[$cheap, 2], [$normal, 3]]);

    // 500 fils off each item, times the quantity, and never more than the item
    // itself costs: min(500, 300) * 2 + min(500, 6000) * 3 = 600 + 1500.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(2100);
});

it('honours a minimum spend set in the editor', function () {
    $id = ceditorPost(['code' => 'SPENDMORE', 'amount' => '10', 'minimum_amount' => '150.00'])
        ->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $product = ceditorProduct('Small Thing', 10000);       // AED 100 — under the bar
    $cart = ceditorCart($coupon, [[$product, 1]]);

    $service = app(CouponService::class);
    $refused = $service->validate('SPENDMORE', $cart);

    expect($refused['ok'])->toBeFalse()
        ->and(str_contains((string) $refused['error'], 'minimum'))
        ->toBeTrue('the refusal did not mention the minimum');

    // And the same coupon passes once the basket clears AED 150.
    $bigger = ceditorCart($coupon, [[$product, 2]]);
    expect($service->validate('SPENDMORE', $bigger)['ok'])->toBeTrue();
});

it('honours a product restriction set in the editor', function () {
    $eligible = ceditorProduct('Chosen Serum', 10000);
    $other = ceditorProduct('Other Serum', 10000);

    $id = ceditorPost([
        'code' => 'ONEPRODUCT', 'type' => 'percent', 'amount' => '50',
        'product_ids' => [$eligible->id],
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $cart = ceditorCart($coupon, [[$eligible, 1], [$other, 1]]);

    // 50% of the ONE eligible line (10,000 fils), not of the 20,000 basket.
    // This is the rule that failed open in production and gave the whole basket
    // away; the editor writing it has to line up with the service reading it.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(5000);
});

it('honours an exclude-sale-items rule set in the editor', function () {
    $full = ceditorProduct('Full Price Cream', 10000);
    $onSale = ceditorProduct('Reduced Cream', 10000, 6000);

    $id = ceditorPost([
        'code' => 'NOSALES', 'type' => 'percent', 'amount' => '20',
        'exclude_sale_items' => true,
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $cart = ceditorCart($coupon, [[$full, 1], [$onSale, 1]]);

    // 20% of the full-price line only: 2000 fils, not 20% of 16,000.
    expect(app(CouponService::class)->discountFor($coupon, $cart))->toBe(2000);
});

it('honours an allowed-emails list set in the editor', function () {
    $id = ceditorPost([
        'code' => 'VIPONLY', 'amount' => '10',
        'allowed_emails' => ['VIP@Example.AE'],
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $product = ceditorProduct('Anything', 10000);
    $cart = ceditorCart($coupon, [[$product, 1]]);

    $service = app(CouponService::class);

    // Capitals are irrelevant on both sides — the editor lower-cases on the way
    // in and validate() lower-cases on the way out.
    expect($service->validate('VIPONLY', $cart, 'vip@example.ae')['ok'])->toBeTrue()
        ->and($service->validate('VIPONLY', $cart, 'Vip@Example.ae')['ok'])->toBeTrue()
        ->and($service->validate('VIPONLY', $cart, 'someone@else.ae')['ok'])->toBeFalse();
});

it('honours a usage limit set in the editor', function () {
    $id = ceditorPost(['code' => 'ONCEONLY', 'amount' => '10', 'usage_limit' => 1])
        ->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $product = ceditorProduct('Anything At All', 10000);
    $cart = ceditorCart($coupon, [[$product, 1]]);

    $service = app(CouponService::class);
    expect($service->validate('ONCEONLY', $cart)['ok'])->toBeTrue();

    Coupon::whereKey($id)->update(['usage_count' => 1]);

    $spent = $service->validate('ONCEONLY', $cart->fresh('items.product'));
    expect($spent['ok'])->toBeFalse()
        ->and(str_contains((string) $spent['error'], 'fully redeemed'))
        ->toBeTrue('an exhausted code was not refused in the usual words');
});

it('honours an expiry set in the editor', function () {
    $id = ceditorPost([
        'code' => 'RANLASTWEEK', 'amount' => '10',
        'expires_at' => now()->subWeek()->toDateString(),
    ])->assertCreated()->json('id');

    $coupon = Coupon::find($id);
    $product = ceditorProduct('Still For Sale', 10000);
    $cart = ceditorCart($coupon, [[$product, 1]]);

    $check = app(CouponService::class)->validate('RANLASTWEEK', $cart);

    expect($check['ok'])->toBeFalse()
        ->and(str_contains((string) $check['error'], 'expired'))
        ->toBeTrue('an expired code was not refused as expired');
});

it('matches a code typed in any capitals, exactly as the basket does', function () {
    ceditorPost(['code' => 'MiXeDcAsE', 'amount' => '10'])->assertCreated();

    $coupon = Coupon::code('mixedcase')->first();
    $product = ceditorProduct('Whatever', 10000);
    $cart = ceditorCart($coupon, [[$product, 1]]);

    $service = app(CouponService::class);

    expect($service->validate('mixedcase', $cart)['ok'])->toBeTrue()
        ->and($service->validate('MIXEDCASE', $cart)['ok'])->toBeTrue();
});

/* =========================================================== 9. THE SCREEN */

it('gives the owner an Add Coupon button', function () {
    // The owner's actual complaint, as an assertion. str_contains + toBeTrue
    // rather than toContain(), whose second argument is another needle.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/coupon-editor-screen.blade.php'));

    expect(str_contains($screen, 'Add Coupon'))->toBeTrue('the screen has no Add Coupon button')
        ->and(str_contains($screen, 'Generate coupon code'))->toBeTrue('no generate-code button')
        ->and(str_contains($screen, 'Usage restriction'))->toBeTrue('no Usage restriction tab')
        ->and(str_contains($screen, 'Usage limits'))->toBeTrue('no Usage limits tab');
});

it('is pulled into the admin console', function () {
    // A screen in its own file that nothing includes is a screen that does not
    // exist. The console only renders partials app.blade.php names.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(str_contains($app, "@include('admin.partials.coupon-editor-screen')"))
        ->toBeTrue('app.blade.php does not include the coupon editor screen');
});

it('says plainly which WooCommerce fields this shop does not apply', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/coupon-editor-screen.blade.php'));

    /*
     * The three honest gaps. Each is named ON THE SCREEN rather than left out
     * silently, because the owner knows the WooCommerce editor and will look
     * for them: a field that is missing with no explanation reads as a bug, and
     * a field that is present but ignored is worse — a restriction they believe
     * is fenced and is not.
     */
    expect(str_contains($screen, 'not applied by this shop'))
        ->toBeTrue('free shipping is not marked as unapplied')
        ->and(str_contains($screen, '"Limit usage to X items" is not available'))
        ->toBeTrue('the missing item cap is not explained')
        ->and(str_contains($screen, 'already true of every code'))
        ->toBeTrue('individual use is not explained as unconditional');
});

it('lets the coupon editor shrink below its content on a phone', function () {
    $css = (string) file_get_contents(resource_path('views/admin/partials/coupon-editor-screen.blade.php'));

    /*
     * A grid or flex item's default min-width is `auto` — "at least as wide as
     * my content" — so a card holding a wide table refuses to shrink, its inner
     * overflow-x:auto never gets the chance to scroll, and the whole column is
     * stretched. That shipped on the Coupons usage screen and measured 677px at
     * a 390px viewport. Structural, because Pest has no layout engine; the
     * measurement lives in the commit.
     */
    expect($css)->toMatch('/\.ce-wrap\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ce-wrap\s*>\s*\*\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ce-scroll\{[^}]*overflow-x:auto/')
        ->and($css)->toMatch('/\.ce-scroll\{[^}]*min-width:0/')
        ->and($css)->toMatch('/\.ce-scroll\{[^}]*max-width:100%/')
        // A fixed minmax floor cannot go narrower than its track, so two form
        // fields plus the gap overflow a 390px phone instead of stacking.
        ->and($css)->toMatch('/\.ce-grid\{[^}]*minmax\(min\(240px,\s*100%\),\s*1fr\)/')
        ->and($css)->toMatch('/\.ce-stats\{[^}]*minmax\(min\(150px,\s*100%\),\s*1fr\)/');
});
