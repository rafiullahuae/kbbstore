<?php

declare(strict_types=1);

namespace Tests;

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Models\User;
use App\Services\SettingsService;

/**
 * Shared setup for the manual-order tests.
 *
 * The routes are registered here the way routes/manual-orders-admin.php's own
 * header tells the integrator to register them, and no other way: inside
 * `auth:admin`, inside the /admin-api prefix, behind NoStoreAdminApi. Anything
 * these tests prove about the guard is therefore a property of that wiring, so
 * a future change that moves the require somewhere laxer is caught by
 * ManualOrderRouteWiringTest rather than by nothing.
 */
final class ManualOrders
{
    /**
     * The route file is registered by Tests\CreatesApplication, during
     * application creation, using the exact nesting its own header documents.
     * This is kept as an explicit no-op so a test reads as though it asked for
     * them, and so there is one place to change if that ever moves.
     */
    public static function registerRoutes(): void
    {
        // Intentionally empty — see Tests\CreatesApplication::createApplication().
    }

    /** Every path this lane registers, as method => uri pairs. */
    public static function paths(): array
    {
        return [
            ['GET', '/admin-api/manual-orders/bootstrap'],
            ['GET', '/admin-api/manual-orders/customers'],
            ['GET', '/admin-api/manual-orders/products'],
            ['POST', '/admin-api/manual-orders/quote'],
            ['POST', '/admin-api/manual-orders'],
            ['GET', '/admin-api/manual-orders/1/packing-list.csv'],
        ];
    }

    public static function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Owner',
            'email' => 'owner@kbeautybliss.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ]);
    }

    public static function webUser(): User
    {
        return User::create([
            'name' => 'Plain web user',
            'email' => 'web@example.test',
            'password' => 'secret-secret',
        ]);
    }

    public static function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + [
            'name' => 'Layla Al Mansoori',
            'first_name' => 'Layla',
            'last_name' => 'Al Mansoori',
            'email' => 'layla@example.ae',
            'phone' => '+971500000001',
        ]);
    }

    /**
     * The shop, configured the way production is: a UAE zone at AED 20 flat
     * with free delivery over AED 199, and cash on delivery switched on.
     */
    public static function shop(int $codFeeFils = 0): void
    {
        // 2026_08_27_100000_seed_demo_catalogue puts 24 placeholder products in
        // every fresh database, including a real "Heartleaf 77% Soothing Toner".
        // A catalogue-search assertion would then be measuring the seeder, so
        // the tests start from an empty shelf and stock it themselves.
        Product::query()->forceDelete();

        $zone = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);

        ShippingZoneLocation::create([
            'shipping_zone_id' => $zone->id,
            'type' => 'country',
            'code' => 'AE',
        ]);

        ShippingMethod::create([
            'shipping_zone_id' => $zone->id,
            'type' => 'flat_rate',
            'title' => 'Flat rate',
            'enabled' => true,
            'cost' => 2000,              // AED 20
            'position' => 0,
        ]);

        ShippingMethod::create([
            'shipping_zone_id' => $zone->id,
            'type' => 'free_shipping',
            'title' => 'Free delivery',
            'enabled' => true,
            'cost' => 0,
            'min_amount' => 19900,       // AED 199
            'position' => 1,
        ]);

        PaymentProvider::create([
            'id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'position' => 0,
        ]);
        PaymentProvider::create([
            'id' => 'stripe', 'title' => 'Card', 'enabled' => true, 'position' => 1,
        ]);

        self::setting('cod_fee', $codFeeFils);
        self::setting('hide_paid_when_free', true);
        self::setting('store_country', 'AE');

        // Quantity bundles default to ON with a 5% tier at two units and 10%
        // at three (BundleService::DEFAULT_TIERS), which is real behaviour a
        // manual order inherits along with everything else. Switched off for
        // the baseline so each test's arithmetic is the one thing it is about;
        // the test that cares turns it back on and asserts the tier applies.
        self::setting('bundles_enabled', false);
    }

    public static function setting(string $key, mixed $value): void
    {
        // Through SettingsService rather than straight at the table: it decides
        // how a value is encoded (scalars raw, anything else JSON) and it is
        // the only thing that knows to clear both its own cached payload and
        // Setting::map()'s. Writing the row by hand leaves readers on the old
        // value for five minutes, which in a test reads as the setting simply
        // not working.
        app(SettingsService::class)->set($key, $value);
    }

    public static function product(string $name, int $priceFils, array $attributes = []): Product
    {
        static $n = 0;
        $n++;

        $brand = Brand::firstOrCreate(['slug' => 'kbb'], ['name' => 'K-Beauty Bliss']);

        return Product::create($attributes + [
            'slug' => 'p-' . $n . '-' . substr(md5($name), 0, 6),
            'name' => $name,
            'sku' => 'KBB-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'brand_id' => $brand->id,
            'type' => 'simple',
            // publish | draft | private — the real vocabulary of this column.
            'status' => 'publish',
            'is_visible' => true,
            'price' => $priceFils,
            'stock' => 50,
            'stock_status' => 'instock',
        ]);
    }

    public static function coupon(string $code, string $type, int $amount, array $attributes = []): Coupon
    {
        return Coupon::create($attributes + [
            'code' => $code,
            'type' => $type,
            'amount' => $amount,
        ]);
    }

    /** A complete, valid create payload, for tests to vary one thing at a time. */
    public static function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'items' => [],
            'address' => [
                'line1' => 'Villa 12, Al Wasl Road',
                'city' => 'Dubai',
                'state' => 'Dubai',
                'country' => 'AE',
                'phone' => '+971500000001',
            ],
            'payment_method' => 'cod',
            'status' => 'processing',
            'channel' => 'whatsapp',
        ], $overrides);
    }
}
