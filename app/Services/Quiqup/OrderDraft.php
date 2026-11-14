<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

use App\Models\Order;
use App\Models\Refund;
use App\Models\Shipment;
use App\Services\Payments\PaymentRefunder;
use App\Support\UaePhone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * One order, prepared for Quiqup: the draft the sheet shows, the checks that
 * block or warn, and the exact create payload (QUIQUP-PLAN §1.3, §3.2).
 *
 * FLAT COST. drafts() reads a whole selection in four queries -- orders,
 * counted refunds, live shipments, order lines -- whatever its size, so 3
 * orders and 100 orders cost the same (QuiqupScheduleTest pins it).
 *
 * Field names are Quiqup's own, checked key by key against the OpenAPI
 * schema in QuiqupPayloadContractTest: Quiqup ignores an unknown field
 * silently, so a typo would otherwise "work".
 */
final class OrderDraft
{
    public const BOOKABLE = ['processing', 'onhold'];

    public const SHIPPED = ['shipped', 'completed'];

    public const GCC = ['SA', 'KW', 'BH', 'QA', 'OM'];

    public function __construct(private QuiqupSettings $settings) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $defaults  {service?, parcels?, allow_shipped?}
     * @param  array<int|string, array<string, mixed>>  $rows  per-order overrides
     * @return list<array<string, mixed>>
     */
    public function drafts(array $ids, array $defaults = [], array $rows = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));

        if ($ids === []) {
            return [];
        }

        $orders = Order::query()->whereIn('id', $ids)->get();
        $refunded = Refund::query()->whereIn('order_id', $ids)->whereIn('status', PaymentRefunder::COUNTED)
            ->groupBy('order_id')->select('order_id', DB::raw('SUM(amount) as fils'))->pluck('fils', 'order_id');
        $live = Shipment::query()->whereIn('order_id', $ids)->whereNotNull('active_key')->get()->keyBy('order_id');
        $lines = DB::table('order_items')->whereIn('order_id', $ids)->orderBy('id')
            ->get(['order_id', 'name', 'sku', 'quantity'])->groupBy('order_id');

        $opts = $this->settings->options();
        $mode = $this->settings->mode();
        $env = $this->settings->env();
        $configured = $this->settings->configured($env);
        // Internal testing with a real courier: the live prerequisites must
        // still hold (a replaced live key is unverified until tested again).
        $notReady = $mode === 'testing' && $env === 'live' ? $this->settings->liveMissing() : [];
        $configured = $configured && $notReady === [];

        $out = [];

        foreach ($ids as $id) {
            $order = $orders->firstWhere('id', $id);

            if ($order === null) {
                $out[] = ['id' => $id, 'number' => '', 'blocks' => [['field' => 'order', 'text' => 'No such order.']], 'warns' => [], 'booked' => null];

                continue;
            }

            $out[] = $this->one($order, (int) ($refunded[$id] ?? 0), $live->get($id), $lines->get($id) ?? collect(),
                $opts, $mode, $env, $configured, $defaults, is_array($rows[$id] ?? null) ? $rows[$id] : []);
        }

        return $out;
    }

    /**
     * @param  Collection<int, object>  $lines
     * @param  array<string, mixed>  $opts
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $o  this row's overrides
     * @return array<string, mixed>
     */
    private function one(Order $order, int $refundedFils, ?Shipment $live, Collection $lines, array $opts, string $mode, string $env,
        bool $configured, array $defaults, array $o): array
    {
        $blocks = [];
        $warns = [];
        $block = function (string $field, string $text) use (&$blocks) { $blocks[] = ['field' => $field, 'text' => $text]; };

        $addr = is_array($order->shipping_address) && $order->shipping_address !== [] ? $order->shipping_address
            : (is_array($order->billing_address) ? $order->billing_address : []);

        $name = trim(self::str($addr['first_name'] ?? '') . ' ' . self::str($addr['last_name'] ?? ''));
        $rawPhone = isset($o['phone']) && is_string($o['phone']) && trim($o['phone']) !== '' ? $o['phone'] : (self::str($addr['phone'] ?? '') ?: (string) $order->phone);
        $phone = UaePhone::e164($rawPhone);
        $address1 = isset($o['address1']) && is_string($o['address1']) && trim($o['address1']) !== '' ? self::clip($o['address1'], 300) : self::clip(self::str($addr['line1'] ?? ''), 300);
        $address2 = self::clip(trim(self::str($addr['line2'] ?? '') . ' ' . self::str($addr['company'] ?? '')), 300);
        $town = self::clip(self::str($addr['city'] ?? ''), 100);
        $emirate = QuiqupSettings::quiqupEmirate(isset($o['emirate']) && is_string($o['emirate']) && $o['emirate'] !== '' ? $o['emirate'] : ($order->ship_emirate ?: self::str($addr['state'] ?? '')));
        $country = strtoupper(self::str($addr['country'] ?? ''));

        if ($country === '' && $emirate !== null) {
            $country = 'AE';
        }

        $test = $this->settings->isTestOrder($order, $opts);

        // Mode first: in Off nothing books, in Internal testing only test orders.
        if ($mode === 'off') {
            $block('mode', 'Quiqup is Off. Choose Internal testing or Live in Store → Quiqup.');
        } elseif ($mode === 'testing' && ! $test) {
            $block('mode', 'Internal testing: only test orders can be booked. Flag this order as a test order, or add the customer under Store → Quiqup → Test customers.');
        }

        if (! $configured) {
            $block('mode', $mode === 'testing' && $env === 'live'
                ? 'Internal testing books with a real courier, which is not ready: verify the live key, save the live webhook secret and the pickup address — or switch back to the Quiqup test system in Store → Quiqup.'
                : ($env === 'live' ? 'No live API key is saved.' : 'Add your staging API key in Store → Quiqup → Connection.'));
        }

        if ($live !== null) {
            $blocks = [['field' => 'booked', 'text' => 'Already booked with Quiqup.']];
        }

        if ($name === '') {
            $block('name', 'No customer name on the shipping address.');
        }

        if ($phone === null || ! UaePhone::isMobile($phone)) {
            $block('phone', 'Not a UAE mobile number Quiqup accepts (+9715X XXX XXXX).');
        }

        if ($address1 === '') {
            $block('address1', 'The address has no street or building.');
        }

        if ($town === '') {
            $warns[] = 'No city on the address; the emirate is sent as the town.';
            $town = (string) $emirate;
        }

        $export = false;

        if ($country !== 'AE') {
            if (($opts['uae_only'] ?? true) || ! in_array($country, self::GCC, true)) {
                $block('country', $country === '' ? 'No country on the address.' : 'Outside the UAE (' . $country . '). Quiqup is set to UAE addresses only.');
            } else {
                $export = true;
                $warns[] = 'Export to ' . $country . ': Quiqup needs HS codes, weights and sizes at release.';
            }
        } elseif ($emirate === null) {
            $block('emirate', 'Choose the emirate.');
        }

        // Payment (§3.2): COD not yet paid -> collect; anything else must be paid.
        $cod = strtolower((string) $order->payment_method) === 'cod' && $order->paid_at === null;
        $codFils = $cod ? max(0, (int) $order->total - $refundedFils) : null;

        if ($cod && $export) {
            $block('payment', 'Cash on delivery is not available on export orders.');
        } elseif ($cod && $codFils <= 100) {
            $block('payment', 'Cash to collect must be above AED 1 (Quiqup\'s rule).');
        } elseif (! $cod && $order->paid_at === null) {
            $block('payment', 'Not paid yet. Only cash-on-delivery orders can be booked before payment.');
        }

        $status = (string) $order->status;

        if (in_array($status, self::SHIPPED, true)) {
            if (empty($defaults['allow_shipped'])) {
                $block('status', 'Already ' . $status . '. Tick "include shipped orders" to book it anyway.');
            } else {
                $warns[] = 'Already marked ' . $status . '.';
            }
        } elseif (! in_array($status, self::BOOKABLE, true)) {
            $block('status', 'The order is ' . $status . '.');
        }

        $enabled = $opts['services']['enabled'];
        $service = $o['service'] ?? $defaults['service'] ?? ($opts['services']['by_method'][(string) $order->shipping_method] ?? null) ?? $opts['services']['default'];

        if (! in_array($service, $enabled, true)) {
            $service = $opts['services']['default'];
        }

        $cut = (string) ($opts['cutoffs'][$service] ?? '');

        if ($cut !== '' && now('Asia/Dubai')->format('H:i') > $cut) {
            $warns[] = 'After today\'s ' . (QuiqupSettings::SERVICES[$service] ?? $service) . ' cut-off (' . $cut . '): collected next cycle.';
        }

        $parcels = max(1, min(10, (int) ($o['parcels'] ?? $defaults['parcels'] ?? $opts['parcels'])));
        $note = ($opts['note_policy'] ?? 'customer') === 'customer' ? self::clip(self::str($order->customer_note), 250) : '';

        if (isset($o['note']) && is_string($o['note'])) {
            $note = self::clip($o['note'], 250);
        }

        $pickup = $opts['pickup'];

        if ($pickup['contact_name'] === '' || $pickup['contact_phone'] === '' || $pickup['address1'] === '') {
            $block('pickup', 'Set the pickup address in Store → Quiqup → Pickup & defaults.');
        }

        return [
            'id' => (int) $order->id,
            'number' => (string) $order->order_number,
            'customer' => $name,
            'email' => $export ? (string) $order->email : null,
            'phone' => $phone ?? self::clip($rawPhone, 40),
            'address1' => $address1,
            'address2' => $address2,
            'town' => $town,
            'emirate' => $emirate,
            'country' => $country,
            'export' => $export,
            'payment_mode' => $cod ? 'paid_on_delivery' : 'pre_paid',
            'cod_fils' => $codFils,
            'cod_aed' => $codFils === null ? null : number_format($codFils / 100, 2, '.', ''),
            'status' => $status,
            'service' => $service,
            'parcels' => $parcels,
            'note' => $note,
            'test' => $test,
            'env' => $env,
            'products' => ($opts['send_products'] ?? true) ? $lines->map(fn ($l) => [
                'sku' => self::clip(self::str($l->sku), 64),
                'description' => self::clip(self::str($l->name), 18),
                'quantity' => max(1, (int) $l->quantity),
            ])->values()->all() : [],
            'release' => (bool) ($opts['release'] ?? true),
            'four_hour_time' => (string) ($opts['four_hour_time'] ?? '10:00'),
            'blocks' => $blocks,
            'warns' => $warns,
            'booked' => $live === null ? null : [
                'qq_id' => $live->qq_id, 'tracking_url' => $live->tracking_url, 'state' => $live->state,
                'state_label' => QuiqupStates::adminLabel($live->state), 'job' => $live->job, 'env' => $live->env,
            ],
        ];
    }

    /**
     * The create body, from a draft with no blocks (§1.3). Only documented
     * fields; prepaid sends no amount; the gift message is never sent.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public function payload(array $d, string $uuid): array
    {
        $p = $this->settings->options()['pickup'];

        $body = [
            'uuid' => $uuid,
            'kind' => $d['export'] ? 'partner_export' : $d['service'],
            'partner_order_id' => $d['number'],
            'payment_mode' => $d['payment_mode'],
        ];

        if ($d['payment_mode'] === 'paid_on_delivery') {
            $body['payment_amount'] = round($d['cod_fils'] / 100, 2);
        }

        $body['ready_for_collection'] = (bool) $d['release'];
        $body['origin'] = array_filter([
            'contact_name' => $p['contact_name'],
            'contact_phone' => $p['contact_phone'],
            'address' => array_filter(['address1' => $p['address1'], 'address2' => $p['address2'], 'town' => $p['town'], 'country' => 'AE'], fn ($v) => $v !== ''),
            'emirate' => QuiqupSettings::quiqupEmirate($p['emirate']),
            'notes' => $p['notes'],
        ], fn ($v) => $v !== '' && $v !== null);

        $dest = [
            'contact_name' => $d['customer'],
            'contact_phone' => $d['phone'],
            'address' => array_filter(['address1' => $d['address1'], 'address2' => $d['address2'], 'town' => $d['town'], 'country' => $d['country']], fn ($v) => $v !== '' && $v !== null),
            'emirate' => $d['export'] ? null : $d['emirate'],
            'notes' => $d['note'],
        ];

        if ($d['export']) {
            $dest['contact_email'] = $d['email'];
        }

        $body['destination'] = array_filter($dest, fn ($v) => $v !== '' && $v !== null);

        $n = (int) $d['parcels'];
        $body['items'] = [];

        for ($i = 1; $i <= $n; $i++) {
            $body['items'][] = ['name' => 'Parcel ' . $i . ' of ' . $n, 'quantity' => 1, 'parcel_barcode' => ''];
        }

        if ($d['products'] !== []) {
            $body['products'] = array_map(fn ($pr) => array_filter($pr, fn ($v) => $v !== ''), $d['products']);
        }

        if ($d['service'] === 'partner_4hr' && ! $d['export']) {
            $body['scheduled_for'] = self::fourHourSlot((string) $d['four_hour_time']);
        }

        return $body;
    }

    /**
     * The next collection time at HH:MM Dubai, in UTC with the Z suffix --
     * Quiqup checks the hour AS WRITTEN and wants 04:00-14:59 UTC (§1.3).
     */
    public static function fourHourSlot(string $hhmm, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now('Asia/Dubai');
        [$h, $m] = array_map('intval', explode(':', preg_match('/^\d{2}:\d{2}$/', $hhmm) ? $hhmm : '10:00'));
        $h = max(8, min(18, $h));
        $slot = $now->setTimezone('Asia/Dubai')->setTime($h, $m);

        if ($slot->lessThanOrEqualTo($now->addMinutes(15))) {
            $slot = $now->setTimezone('Asia/Dubai')->addMinutes(30)->second(0);

            if ((int) $slot->format('H') >= 19 || (int) $slot->format('H') < 8) {
                $slot = $now->setTimezone('Asia/Dubai')->addDay()->setTime($h, $m);
            }
        }

        return $slot->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** UUIDv5 in a namespace derived from APP_KEY: deterministic per env, order and generation (§4.3). */
    public static function requestUuid(string $env, int $orderId, int $generation): string
    {
        $ns = substr(hash_hmac('sha256', 'quiqup-ns', (string) config('app.key')), 0, 32);
        $ns = substr($ns, 0, 8) . '-' . substr($ns, 8, 4) . '-' . substr($ns, 12, 4) . '-' . substr($ns, 16, 4) . '-' . substr($ns, 20, 12);

        return Uuid::uuid5($ns, $env . ':' . $orderId . ':' . $generation)->toString();
    }

    private static function str(mixed $v): string
    {
        return is_scalar($v) ? trim((string) $v) : '';
    }

    private static function clip(string $v, int $max): string
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v) ?? '');

        return mb_substr($v, 0, $max);
    }
}
