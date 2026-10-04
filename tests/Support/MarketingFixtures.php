<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Rows for Lane MK's Marketing Emails tests: a catalogue, customers with paid
 * orders delivered to an emirate, subscribers in each state, admins by role.
 * Every name is unique per call so files can share one database.
 */
final class MarketingFixtures
{
    private static int $n = 0;

    public static function admin(string $role = 'owner'): AdminUser
    {
        self::$n++;

        return AdminUser::create([
            'name' => 'MK ' . $role, 'email' => 'mk-' . $role . '-' . self::$n . '-' . uniqid() . '@example.test',
            'password' => 'secret-secret', 'role' => $role,
        ]);
    }

    public static function brand(string $name): Brand
    {
        self::$n++;

        return Brand::firstOrCreate(['name' => $name], ['slug' => 'mk-' . strtolower(preg_replace('/\W+/', '-', $name)) . '-' . self::$n]);
    }

    /** @param array<string, mixed> $attrs */
    public static function product(string $name, int $priceAed, array $attrs = []): Product
    {
        self::$n++;

        return Product::create(array_merge([
            'name' => $name,
            'slug' => 'mk-p-' . self::$n . '-' . uniqid(),
            'status' => 'publish',
            'is_visible' => true,
            'price' => $priceAed * 100,
            'stock_status' => 'instock',
            'image' => '/wp-content/uploads/mk-' . self::$n . '.jpg',
        ], $attrs));
    }

    /** @param array<string, mixed> $attrs */
    public static function customer(string $email, array $attrs = []): Customer
    {
        return Customer::create(array_merge(['name' => 'Customer ' . $email, 'first_name' => ucfirst(strtok($email, '@')), 'email' => $email], $attrs));
    }

    /**
     * A paid order with one line per [brand, fils] pair, delivered to $state.
     *
     * @param  list<array{0:string, 1:int}>  $lines
     */
    public static function order(Customer $c, array $lines, string $status = 'processing', string $state = 'Dubai', ?string $at = null, string $country = 'AE'): Order
    {
        self::$n++;
        $total = array_sum(array_column($lines, 1));

        $order = Order::create([
            'customer_id' => $c->id,
            'order_number' => 'MK-' . self::$n . '-' . uniqid(),
            'email' => $c->email,
            'status' => $status,
            'subtotal' => $total,
            'total' => $total,
            'paid_at' => in_array($status, Order::REAL_STATUSES, true) ? now() : null,
            'shipping_address' => ['first_name' => 'A', 'city' => 'X', 'state' => $state, 'country' => $country],
        ]);

        if ($at !== null) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);
        }

        foreach ($lines as [$brand, $fils]) {
            DB::table('order_items')->insert([
                'order_id' => $order->id, 'name' => $brand . ' thing', 'brand' => $brand,
                'quantity' => 1, 'unit_price' => $fils, 'subtotal' => $fils, 'total' => $fils,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $order;
    }

    public static function subscriber(string $email, string $status = 'subscribed', bool $confirmed = true): int
    {
        return (int) DB::table('subscribers')->insertGetId([
            'email' => strtolower($email), 'source' => 'homepage', 'status' => $status,
            'confirmed_at' => $confirmed ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<array{field:string, op:string, value:mixed}> $rules */
    public static function group(string $name, array $rules, string $audience = 'customers', string $match = 'all'): int
    {
        return (int) DB::table('mkt_segments')->insertGetId([
            'name' => $name, 'audience' => $audience, 'match' => $match, 'rules' => json_encode($rules),
            'preset' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A draft campaign from a ready template, aimed at a group. */
    public static function campaign(string $templateKey, ?int $segmentId, array $attrs = []): int
    {
        $t = DB::table('mkt_templates')->where('key', $templateKey)->first();

        return (int) DB::table('mkt_campaigns')->insertGetId(array_merge([
            'name' => 'MK ' . $templateKey,
            'template_id' => $t->id,
            'blocks' => $t->blocks,
            'subject' => $t->subject,
            'preheader' => $t->preheader,
            'segment_id' => $segmentId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }
}
