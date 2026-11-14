<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupSettings;
use Illuminate\Support\Str;

/** Fixtures shared by the Quiqup tests (static, so no global function names collide). */
final class QuiqupKit
{
    public const URL_SECRET = 'u_quiqup_test_url_secret_0123456789abcd';

    public const SIGNING = 'whsec_test_signing_secret';

    private static int $n = 0;

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/quiqup/' . $name));
    }

    /** @return array<string, mixed> */
    public static function json(string $name): array
    {
        return json_decode(self::fixture($name), true);
    }

    public static function configure(string $env = 'sandbox', string $key = 'qq_test_key_abcd1234', array $options = []): void
    {
        QuiqupConfig::query()->updateOrCreate(['id' => $env], [
            'active' => true,
            'secrets' => ['api_key' => $key, 'webhook_secret' => self::SIGNING, 'url_secret' => self::URL_SECRET],
        ]);
        QuiqupConfig::query()->where('id', '!=', $env)->update(['active' => false]);

        $opts = array_replace_recursive(QuiqupSettings::defaults(), [
            'pickup' => ['contact_name' => 'KBB Warehouse', 'contact_phone' => '+971501112222', 'address1' => 'Warehouse 4, Street 1', 'address2' => 'Al Quoz 3', 'town' => 'Dubai', 'emirate' => 'Dubai', 'notes' => ''],
            'services' => ['enabled' => ['partner_next_day', 'partner_same_day', 'partner_4hr'], 'default' => 'partner_next_day', 'by_method' => []],
            'mode' => $env === 'live' ? 'live' : 'testing',
        ], $options);

        QuiqupConfig::query()->updateOrCreate(['id' => QuiqupSettings::OPTIONS_ROW], ['options' => $opts]);
    }

    public static function admin(string $role = 'owner'): AdminUser
    {
        return AdminUser::create([
            'name' => 'QQ ' . ucfirst($role),
            'email' => 'qq-' . $role . '-' . uniqid() . '@example.test',
            'password' => 'secret-secret',
            'role' => $role,
        ]);
    }

    public static function order(array $attributes = []): Order
    {
        self::$n++;

        return Order::create(array_merge([
            'order_number' => 'QQ' . str_pad((string) (1000 + self::$n), 6, '0', STR_PAD_LEFT) . Str::random(3),
            'email' => 'qq-' . self::$n . '@example.test',
            'phone' => '0501234567',
            'status' => 'processing',
            'total' => 18900,
            'subtotal' => 18900,
            'payment_method' => 'cod',
            'payment_method_title' => 'Cash on delivery',
            'shipping_method' => 'Standard delivery',
            'shipping_address' => [
                'first_name' => 'Mariam', 'last_name' => 'Ali', 'line1' => 'Villa 12, Street 5',
                'line2' => 'Al Barsha 2', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '050 123 4567',
            ],
            'ship_emirate' => 'Dubai',
            'customer_note' => 'Call before arriving',
        ], $attributes));
    }

    public static function shipment(Order $order, array $attributes = []): Shipment
    {
        static $q = 20000001;

        return Shipment::create(array_merge([
            'order_id' => $order->id,
            'env' => 'sandbox',
            'generation' => 0,
            'request_uuid' => (string) Str::uuid(),
            'active_key' => $order->id,
            'qq_id' => $q++,
            'qq_uuid' => (string) Str::uuid(),
            'service_kind' => 'partner_next_day',
            'payment_mode' => 'paid_on_delivery',
            'cod_fils' => 18900,
            'parcels' => 1,
            'job' => 'created',
            'state' => 'ready_for_collection',
            'state_at' => now()->subHour(),
        ], $attributes));
    }

    /** The documented webhook body, pointed at one of our shipments. */
    public static function webhookBody(Shipment $s, string $state, string $at, array $extra = []): string
    {
        $body = self::json('webhook-ready-for-collection.json');
        $body['payload']['id'] = (int) $s->qq_id;
        $body['payload']['client_order_id'] = (int) $s->qq_id;
        $body['payload']['uuid'] = (string) $s->qq_uuid;
        $body['payload']['state'] = $state;
        $body['payload']['state_updated_at'] = $at;
        $body['payload'] = array_merge($body['payload'], $extra);

        return json_encode($body);
    }

    /** @return array<string, string> server vars for a signed delivery */
    public static function signed(string $raw, ?int $ts = null, string $secret = self::SIGNING): array
    {
        $ts ??= time();

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_QUIQUP_TIMESTAMP' => (string) $ts,
            'HTTP_X_QUIQUP_SIGNATURE' => hash_hmac('sha256', $ts . '.' . $raw, $secret),
        ];
    }
}
