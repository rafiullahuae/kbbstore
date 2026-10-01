<?php

declare(strict_types=1);

/*
 * =============================================================================
 * TURNING AN IMPORTED PRODUCT INTO A SET, AND KEEPING IT ONE   (Lane PI-A, item 10)
 * =============================================================================
 *
 * The owner: "on the product edit page [I need] to convert/switch any product
 * to the set. as we have alot of sets which we used just as product ... please
 * make this super reliable function on the product edit page. and must be
 * working properly along with all options there which we have on set edit
 * page."
 *
 * Driven in Chromium first (tools/pia-convert-shots.cjs): Catalog -> Product
 * editor already offered the switch -- the Product type select -- and a
 * converted product saved as a working set. What the drive and this file found
 * wrong:
 *
 *   1. A RE-IMPORT UNDID IT. ProductImporter wrote `type` from the export on
 *      every run, so the products re-export the owner must make for the tabs
 *      (exporter 1.9.0) would have turned every converted set back into a
 *      simple product. Now a local set is never downgraded, and a rule-priced
 *      set's price is not overwritten.
 *   2. A PRODUCT ALREADY INSIDE A SET COULD BE CONVERTED, which makes a set
 *      inside a set and silently re-prices the OTHER set (its "bought
 *      separately" total loses the member). Refused now.
 *   3. A PRODUCT WITH OPTIONS COULD BE CONVERTED, leaving a set priced from an
 *      empty column with its sizes no longer offered. Refused now.
 *   4. The switch was silent. The screen now says, on a saved product, what
 *      converting keeps and what it changes (stock), and asks once.
 *
 * Everything else a set does is not re-implemented for converted ones: a
 * converted set IS the row a native set is -- type='set' plus
 * product_set_items -- and the tests below prove it behaves identically.
 */

use App\Models\AdminUser;
use App\Models\Cart;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Models\ProductTab;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\StockSetRule;
use App\Support\SetContents;
use App\Support\SetPricing;
use Illuminate\Support\Str;

function cvtAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Converter', 'email' => 'cvt-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

function cvtSimple(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'cvt-'.Str::slug($name).'-'.Str::lower(Str::random(5)),
        'name' => $name, 'type' => 'simple', 'status' => 'publish', 'is_visible' => true,
        'price' => $fils, 'stock_status' => 'instock', 'image' => '/img/'.Str::slug($name).'.jpg',
    ], $overrides));
}

/** The imported product the owner sold as a set before this shop had sets. */
function cvtImported(): Product
{
    $p = cvtSimple('Medicube - PDRN Glow Booster Set (Pink Edition)', 95000, [
        'wc_id' => 98765, 'sku' => 'MED-PDRN-SET-PINK', 'sale_price' => 85000,
        'sale_starts_at' => now()->subDay()->startOfMinute(), 'sale_ends_at' => now()->addMonth()->startOfMinute(),
        'manage_stock' => true, 'stock' => 7,
        'images' => ['/img/pink-open.jpg', '/img/pink-device.jpg'],
        'short_description' => 'The booster, the cream and the serum.',
        'description' => '<p>What is in the set.</p>',
        'seo' => ['title' => 'Pink set | K-Beauty Bliss', 'desc' => 'The pink PDRN set.'],
        'total_sales' => 420,
    ]);

    (new ProductTab)->forceFill(['product_id' => $p->id, 'import_key' => 'wc:1', 'title' => 'Major Ingredients',
        'body' => '<p>PDRN.</p>', 'position' => 50, 'is_enabled' => true])->save();

    Review::create(['product_id' => $p->id, 'author_name' => 'Layla', 'author_email' => 'l@example.test',
        'rating' => 5, 'content' => 'Brilliant.', 'status' => 'approved']);

    return $p->fresh();
}

/** The editor's own projection, sent back with the conversion in it -- what pressing Save sends. */
function cvtConvert(Product $p, array $members, array $extra = []): \Illuminate\Testing\TestResponse
{
    $body = test()->getJson('/admin-api/product-editor-load/'.$p->id)->assertOk()->json('product');
    unset($body['translations']);

    return test()->postJson('/admin-api/product-editor-save/'.$p->id, array_merge($body, [
        'type' => 'set',
        'set_members' => $members,
        'price_mode' => 'fixed',
    ], $extra));
}

/** Every column the owner said must survive, as the row holds it. */
function cvtSnapshot(Product $p): array
{
    $p = $p->fresh();

    return [
        'wc_id' => $p->wc_id, 'slug' => $p->slug, 'name' => $p->name, 'sku' => $p->sku,
        'price' => $p->price, 'sale_price' => $p->sale_price,
        'sale_starts_at' => (string) $p->sale_starts_at, 'sale_ends_at' => (string) $p->sale_ends_at,
        'manage_stock' => (bool) $p->manage_stock, 'stock' => $p->stock, 'stock_status' => $p->stock_status,
        'image' => $p->image, 'images' => $p->images, 'short_description' => $p->short_description,
        'description' => $p->description, 'seo_title' => $p->seo['title'] ?? null, 'seo_desc' => $p->seo['desc'] ?? null,
        'status' => $p->status, 'is_visible' => (bool) $p->is_visible, 'total_sales' => (int) $p->total_sales,
        'brand_id' => $p->brand_id, 'category_id' => $p->category_id,
        'tabs' => ProductTab::query()->where('product_id', $p->id)->orderBy('id')->get(['title', 'body', 'import_key'])->toArray(),
        'reviews' => Review::query()->where('product_id', $p->id)->count(),
    ];
}

beforeEach(function () {
    SetPricing::forget();
    $this->actingAs(cvtAdmin(), 'admin');
});

it('converts an imported simple product into a set through the editor, losing nothing', function () {
    $booster = cvtSimple('AGE-R Booster Pro', 75000);
    $cream = cvtSimple('Capsule Cream', 15000);
    $serum = cvtSimple('Peptide Serum', 9900);
    $p = cvtImported();
    $before = cvtSnapshot($p);

    cvtConvert($p, [
        ['product_id' => $booster->id, 'quantity' => 1],
        ['product_id' => $cream->id, 'quantity' => 1],
        ['product_id' => $serum->id, 'quantity' => 2],
    ])->assertOk();

    $after = $p->fresh();

    expect($after->isSet())->toBeTrue()
        ->and(cvtSnapshot($p))->toBe($before)
        ->and(ProductSetItem::where('set_product_id', $p->id)->orderBy('position')->pluck('quantity', 'member_product_id')->all())
            ->toBe([$booster->id => 1, $cream->id => 1, $serum->id => 2])
        // The typed price stays the set's price, anchored to today's box.
        ->and((int) $after->effectivePrice())->toBe(85000)
        ->and((int) $after->set_price_basis)->toBe(75000 + 15000 + 2 * 9900);
});

it('behaves exactly like a set built as a set: price, contents, snapshot and stock', function () {
    $booster = cvtSimple('AGE-R Booster Pro', 75000, ['manage_stock' => true, 'stock' => 10]);
    $serum = cvtSimple('Peptide Serum', 9900, ['manage_stock' => true, 'stock' => 10]);
    $members = [['product_id' => $booster->id, 'quantity' => 1], ['product_id' => $serum->id, 'quantity' => 2]];

    $converted = cvtImported();
    cvtConvert($converted, $members)->assertOk();

    $nativeId = $this->postJson('/admin-api/product-editor-create', [
        'name' => 'Native set', 'slug' => 'cvt-native-'.Str::lower(Str::random(5)), 'status' => 'publish',
        'type' => 'set', 'set_members' => $members, 'price_mode' => 'fixed',
        'price_aed' => '950', 'sale_aed' => '850',
        'sale_starts_at' => now()->subDay()->format('Y-m-d\TH:i'), 'sale_ends_at' => now()->addMonth()->format('Y-m-d\TH:i'),
    ])->assertCreated()->json('product.id');

    $c = $converted->fresh();
    $n = Product::findOrFail($nativeId);
    SetPricing::forget();

    // Pricing: the same typed figures, the same anchor, the same answer -- and
    // after a member is marked down, the same reduction on both.
    expect((int) $c->effectivePrice())->toBe((int) $n->effectivePrice())
        ->and((int) $c->set_price_basis)->toBe((int) $n->set_price_basis);

    $booster->update(['price' => 70000]);
    SetPricing::forget();
    expect((int) $c->fresh()->effectivePrice())->toBe(80000)
        ->and((int) $n->fresh()->effectivePrice())->toBe(80000);

    // The box the page draws, and the snapshot an order keeps.
    $strip = fn (?array $s) => array_map(fn ($m) => [$m['name'], $m['quantity'], $m['unit']], $s ?? []);
    expect($strip(SetContents::snapshot($c->fresh())))->toBe($strip(SetContents::snapshot($n->fresh())))
        ->and(SetContents::fromProduct($c->fresh())['members'])->toHaveCount(2);

    // Stock: the members come off their shelves for either.
    $rule = app(StockSetRule::class);
    $line = fn (Product $p) => [['product_id' => $p->id, 'variant_id' => null, 'quantity' => 1, 'label' => $p->name]];
    $members = fn (array $lines) => array_map(fn ($l) => [$l['product_id'], $l['quantity']], array_slice($lines, 1));
    expect($members($rule->expand($line($c))))->toBe($members($rule->expand($line($n))))
        ->and($members($rule->expand($line($c))))->toBe([[$booster->id, 1], [$serum->id, 2]]);

    // A percentage rule works on a converted set exactly as on a native one.
    $body = ['price_mode' => 'discount_percent', 'discount_percent' => '10'];
    $this->postJson('/admin-api/product-editor-save/'.$c->id, $body)->assertOk();
    $this->postJson('/admin-api/product-editor-save/'.$n->id, $body)->assertOk();
    SetPricing::forget();
    expect((int) $c->fresh()->effectivePrice())->toBe((int) $n->fresh()->effectivePrice())
        ->and((int) $c->fresh()->effectivePrice())->toBe((int) round((70000 + 2 * 9900) * 0.9));
});

it('places an order for a converted set with its box on the order and its members off the shelf', function () {
    app(\App\Services\Payments\GatewayCredentials::class)->forget();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create(['shipping_zone_id' => $uae->id, 'type' => 'flat_rate', 'title' => 'Delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0]);

    $booster = cvtSimple('AGE-R Booster Pro', 75000, ['manage_stock' => true, 'stock' => 10]);
    $p = cvtImported();
    cvtConvert($p, [['product_id' => $booster->id, 'quantity' => 1]])->assertOk();

    $cart = Cart::create(['token' => Str::random(32), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 85000]);

    $this->withCredentials()
        ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->post('/checkout/place', [
            'billing_email' => 'buyer@example.com', 'billing_phone' => '+971500000000',
            'billing_first_name' => 'Aisha', 'billing_last_name' => 'Khan',
            'billing_address_1' => '12 Marina Walk', 'billing_city' => 'Dubai', 'billing_state' => 'Dubai',
            'billing_country' => 'AE', 'payment_method' => 'cod',
        ])->assertRedirect();

    $line = Order::latest('id')->first()->items()->first();

    expect($line->product_id)->toBe($p->id)
        ->and($line->set_contents[0]['name'])->toBe('AGE-R Booster Pro')
        ->and($booster->fresh()->stock)->toBe(9)
        // Its own imported count is still a limit on the set itself, as the editor says.
        ->and($p->fresh()->stock)->toBe(6);
});

it('refuses to convert a product that is already inside another set', function () {
    /*
     * ON THE SHOP, before the guard: converting the booster that sits inside
     * another set takes it out of that set's "Bought separately" total -- a set
     * inside a set is skipped -- so the OTHER set is re-priced by a change
     * nobody made to it. Measured with the guard removed: a "10% off the box"
     * set holding the booster and the serum fell from AED 764.10 to AED 89.10;
     * a hand-priced one kept AED 850 but its follow-the-members reduction was
     * paused (one member counted missing) for as long as the booster stayed a set.
     *
     * MUTATION, RUN: the conversionRefusal() call removed from apply() -- red,
     * the save answered 200 and the booster became a set.
     */
    $booster = cvtSimple('AGE-R Booster Pro', 75000);
    $serum = cvtSimple('Peptide Serum', 9900);

    $nativeId = $this->postJson('/admin-api/product-editor-create', [
        'name' => 'Native set', 'slug' => 'cvt-native-'.Str::lower(Str::random(5)), 'status' => 'publish',
        'type' => 'set', 'price_mode' => 'discount_percent', 'discount_percent' => '10',
        'set_members' => [['product_id' => $booster->id, 'quantity' => 1], ['product_id' => $serum->id, 'quantity' => 1]],
    ])->assertCreated()->json('product.id');

    $priceBefore = (int) Product::findOrFail($nativeId)->effectivePrice();

    $this->postJson('/admin-api/product-editor-save/'.$booster->id, ['type' => 'set'])
        ->assertStatus(422)
        ->assertJsonPath('errors.type.0', 'Inside another set.');

    SetPricing::forget();
    expect($booster->fresh()->type)->toBe('simple')
        ->and((int) Product::findOrFail($nativeId)->effectivePrice())->toBe($priceBefore);
});

it('refuses to convert a product with options', function () {
    /* MUTATION, RUN: the variants check removed -- red, a variable product became a set with price NULL. */
    $variable = cvtSimple('Capsule Cream', 0, ['type' => 'variable', 'price' => null]);
    ProductVariant::create(['product_id' => $variable->id, 'price' => 15000, 'stock_status' => 'instock']);

    $this->postJson('/admin-api/product-editor-save/'.$variable->id, ['type' => 'set'])
        ->assertStatus(422)
        ->assertJsonPath('errors.type.0', 'Has options.');

    expect($variable->fresh()->type)->toBe('variable');
});

it('keeps the box when a converted set is switched back, and is a set again with it', function () {
    $booster = cvtSimple('AGE-R Booster Pro', 75000);
    $p = cvtImported();
    cvtConvert($p, [['product_id' => $booster->id, 'quantity' => 1]])->assertOk();

    $this->postJson('/admin-api/product-editor-save/'.$p->id, ['type' => 'simple'])->assertOk();
    expect($p->fresh()->isSet())->toBeFalse()
        ->and(ProductSetItem::where('set_product_id', $p->id)->count())->toBe(1);

    $this->postJson('/admin-api/product-editor-save/'.$p->id, ['type' => 'set'])->assertOk();
    expect($p->fresh()->isSet())->toBeTrue()
        ->and(SetContents::fromProduct($p->fresh())['members'])->toHaveCount(1);
});

it('lets only a role that may manage the catalogue convert', function () {
    $p = cvtImported();
    $this->actingAs(cvtAdmin('support'), 'admin');

    $this->postJson('/admin-api/product-editor-save/'.$p->id, ['type' => 'set'])->assertForbidden();
    expect($p->fresh()->type)->toBe('simple');
});

it('shows the converted set under the Sets chip of Catalog -> Products', function () {
    $booster = cvtSimple('AGE-R Booster Pro', 75000);
    $p = cvtImported();
    cvtConvert($p, [['product_id' => $booster->id, 'quantity' => 1]])->assertOk();

    $ids = collect($this->getJson('/admin-api/catalog-products-list?filter=set&per_page=100')->assertOk()->json('products'))->pluck('id')->all();

    expect($ids)->toContain($p->id)->not->toContain($booster->id);
});

/* ═══════════════════════════════════════════ re-import must not undo it ═══ */

function cvtFixtureImport(?string $dir = null): \App\Services\Import\ImportReport
{
    $dir ??= base_path('tests/Fixtures/kbb-export');
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    return (new ImportRunner)->run(new ImportOptions(
        directory: $dir, sourceTimezone: $manifest['source']['timezone'], adoptBySlug: true,
    ));
}

/** A copy of the fixture export with one product's cells changed. */
function cvtExportWith(int $wcId, array $cells): string
{
    $dir = sys_get_temp_dir().'/kbb-cvt-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    foreach (glob(base_path('tests/Fixtures/kbb-export').'/*') ?: [] as $file) {
        copy($file, $dir.'/'.basename($file));
    }

    $in = fopen(base_path('tests/Fixtures/kbb-export/products.csv'), 'r');
    $out = fopen($dir.'/products.csv', 'w');
    $header = fgetcsv($in, null, ',', '"', '');
    fputcsv($out, $header, ',', '"', '');

    while (($row = fgetcsv($in, null, ',', '"', '')) !== false) {
        if ((int) $row[0] === $wcId) {
            foreach ($cells as $column => $value) {
                $row[array_search($column, $header, true)] = $value;
            }
        }

        fputcsv($out, $row, ',', '"', '');
    }

    fclose($in);
    fclose($out);

    return $dir;
}

it('keeps a converted set a set, with its box, when the products are imported again', function () {
    /*
     * ON THE SHOP it would have looked like this: the owner converts his gift
     * sets, re-exports Products for the tabs, imports -- and every converted
     * set is an ordinary product again, its box gone from every page.
     *
     * MUTATION, RUN: the keepLocalSet() call removed from import() -- red, the
     * serum came back `type = simple`.
     */
    cvtFixtureImport();
    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();
    $toner = Product::query()->where('wc_id', 4022)->firstOrFail();

    cvtConvert($serum, [['product_id' => $toner->id, 'quantity' => 2]])->assertOk();

    $report = cvtFixtureImport();

    $kept = collect($report->for('products')->adjustments())
        ->first(fn ($a, $kind) => str_starts_with((string) $kind, 'kept as a set'));

    expect($serum->fresh()->type)->toBe('set')
        ->and(ProductSetItem::where('set_product_id', $serum->id)->pluck('quantity', 'member_product_id')->all())
            ->toBe([$toner->id => 2])
        ->and($kept)->not->toBeNull()
        ->and($kept['samples'][0]['before'])->toBe('simple');
});

it('leaves a rule-priced set\'s price alone on re-import, and re-anchors a hand-priced one whose price moved', function () {
    /*
     * MUTATIONS, RUN:
     *   the unset() of the price columns in keepLocalSet() removed -- red, the
     *   export's sale price was written back onto a percentage-priced set;
     *   reanchorAfterImport() returning false -- red, the basis kept the old box.
     */
    cvtFixtureImport();
    $serum = Product::query()->where('wc_id', 4021)->firstOrFail();
    // A member this shop added itself, so the import has no price of its own to write over it.
    $toner = cvtSimple('Shop-only toner', 12000);

    cvtConvert($serum, [['product_id' => $toner->id, 'quantity' => 1]], [
        'price_mode' => 'discount_percent', 'discount_percent' => '10',
    ])->assertOk();

    $ruled = $serum->fresh();
    expect($ruled->sale_price)->toBeNull();

    cvtFixtureImport();
    SetPricing::forget();

    expect($serum->fresh()->sale_price)->toBeNull()
        ->and((int) $serum->fresh()->price)->toBe((int) $ruled->price)
        ->and((int) $serum->fresh()->effectivePrice())->toBe((int) round($toner->fresh()->effectivePrice() * 0.9));

    // Now hand-priced, with the toner since marked down -- and the old shop's price changed.
    $this->postJson('/admin-api/product-editor-save/'.$serum->id, ['price_mode' => 'fixed', 'price_aed' => '99'])->assertOk();
    $toner->update(['price' => (int) $toner->price - 1000]);
    SetPricing::forget();

    cvtFixtureImport(cvtExportWith(4021, ['regular_price' => '120.00', 'sale_price' => '']));
    SetPricing::forget();

    $after = $serum->fresh();
    expect((int) $after->price)->toBe(12000)
        ->and((int) $after->set_price_basis)->toBe(SetPricing::partsTotal($after))
        ->and((int) $after->effectivePrice())->toBe(12000);
});

it('says on the editor what converting a saved product keeps, and asks first', function () {
    /*
     * MUTATION, RUN: the confirm() branch removed from the type change handler
     * -- red on the convertMessage() call.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    expect($code)->toContain("savedType !== 'set'")
        ->and($code)->toContain('window.confirm(convertMessage())')
        ->and($code)->toContain('<b>Convert to a set:</b>')
        ->and($code)->toContain('You can switch it back to Simple later, and the box is kept.');

    $boot = $this->getJson('/admin-api/product-editor-bootstrap')->assertOk()->json();
    expect($boot['set_stock_mode'])->toBeIn(StockSetRule::MODES);
});
