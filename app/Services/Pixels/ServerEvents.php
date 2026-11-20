<?php

declare(strict_types=1);

namespace App\Services\Pixels;

use App\Models\Order;
use App\Models\Refund;
use App\Services\Analytics;
use App\Support\InstantNav;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Server-side events: Meta Conversions API, GA4 Measurement Protocol and the
 * TikTok Events API. (Lane MP)
 *
 * ── WHAT GOES, AND WHEN ─────────────────────────────────────────────────────
 *
 *   AddToCart          Meta + TikTok, from a successful POST to the cart's
 *                      add endpoint.
 *   InitiateCheckout   Meta + TikTok, from a real (not prefetched) render of
 *                      the checkout page.
 *   Purchase           Meta (Purchase) + TikTok (CompletePayment) + GA4
 *                      (purchase), once per order, when the order first
 *                      reaches a PLACED status (processing, on hold, shipped,
 *                      completed) — the moment it is a sale, whether that is
 *                      the cash-on-delivery checkout itself or a card
 *                      payment's webhook minutes later.
 *
 * GA4 gets the purchase only, and only when the shopper's GA client id was
 * captured: GA4 merges a browser and a server purchase on transaction_id for
 * the SAME user only, so a server copy with no client id would be a second
 * sale in the reports. It has no event_id to deduplicate an add-to-cart with,
 * so it is not sent one.
 *
 * ── DEDUPLICATION ───────────────────────────────────────────────────────────
 *
 * Each server event carries the same id as its browser twin — Meta's
 * event_id ↔ fbq eventID, TikTok's event_id ↔ ttq event_id — and the platform
 * keeps one of the two (Meta: same event name and id within 48 hours).
 *   Purchase          "purchase-<order number>", known to both sides.
 *   InitiateCheckout  minted when the checkout page renders, printed into the
 *                     page's own fbq/ttq call, and sent with the same value.
 *   AddToCart         minted by the click listener, which drops it in the
 *                     short-lived kbb_eid cookie the add request carries.
 *
 * ── NEVER IN THE SHOPPER'S WAY ─────────────────────────────────────────────
 *
 * Nothing is sent while the page is being built. Each event is buffered and
 * sent from app()->terminating(), which runs after the response has gone to
 * the browser (fastcgi_finish_request under PHP-FPM) — the way OwnerAppEvents
 * already sends its pushes, because this host runs no queue worker. Every
 * call has a 2 s connect / 4 s total timeout, nothing here throws, and an
 * order's own transaction never waits on any of it (the purchase hook is
 * DB::afterCommit).
 *
 * A platform that refuses the token (HTTP 401/403, Meta error 190) trips a
 * ten-minute breaker for that platform, so a revoked token does not cost a
 * four-second call on every add-to-cart; the "Last events" panel says so.
 *
 * Every outcome is a row in marketing_server_events — platform, event, id,
 * status, HTTP code, the platform's message. No payload, no customer data.
 *
 * ── REFUNDS, CANCELLATIONS AND RETRIES (Lane AP-API) ────────────────────────
 *
 *   OrderRefunded    Meta only, a CUSTOM event, once per refund that moved
 *                    money ("refund-<refund id>"), value = the refund, positive.
 *   OrderCancelled   Meta only, custom, once per order that leaves a placed
 *                    status for cancelled/failed ("cancel-<order number>").
 * Both only for an order Meta can have counted (a browser purchase tag was
 * printed, or a server Purchase row exists), with the order's hashed
 * user_data and action_source "other". Neither corrects Ads Manager's
 * Purchase value -- Meta has no event that does -- they exist for audiences
 * and diagnostics; the Ads Autopilot reads the shop's own net revenue.
 *
 * A Meta Purchase / OrderRefunded / OrderCancelled that fails for a reason
 * worth retrying (no answer, 429, 5xx, a refused token, the breaker) is kept
 * ENCRYPTED in marketing_capi_retries and re-sent by `kbb:capi-retry` on the
 * scheduler cron (every minute; no queue worker on this host) at 5 min, 15
 * min, 1 h, 3 h, 8 h and 12 h after the first try, never once 47 hours have
 * passed: Meta merges a browser and a server copy only within 48 h, so a later
 * copy would be a second sale. A 400 (Meta refused the event itself) is not
 * retried; it would fail the same way every time.
 */
final class ServerEvents
{
    public const PLACED = ['processing', 'onhold', 'shipped', 'completed'];

    /** Leaving PLACED for one of these is a cancellation. */
    public const CANCELLED = ['cancelled', 'failed'];

    /** The Meta events worth a retry; a missed AddToCart is not worth the queue. */
    public const RETRIED = ['Purchase', 'OrderRefunded', 'OrderCancelled'];

    /** Minutes after the FIRST try at which attempt n+1 is made. */
    public const RETRY_AFTER = [5, 15, 60, 180, 480, 720];

    /** Meta keeps a browser/server pair as one event only within 48 h. */
    public const RETRY_HOURS = 47;

    private const ARMED = 'kbb.pixels.server.armed';

    private const BREAKER = 'kbb.pixels.breaker.';

    private const BREAKER_SECONDS = 600;

    private const KEEP_ROWS = 300;

    /** @var list<\Closure> */
    private static array $buffer = [];

    public function __construct(
        private Analytics $analytics,
        private PixelConfig $config,
        private PlatformClient $client,
    ) {}

    // ------------------------------------------------------------------ gates

    public function metaOn(): bool
    {
        return $this->analytics->active('meta') && $this->config->secret('meta_capi_token') !== null && $this->config->on('meta_capi');
    }

    public function tiktokOn(): bool
    {
        return $this->analytics->active('tiktok') && $this->config->secret('tiktok_token') !== null && $this->config->on('tiktok_eapi');
    }

    public function ga4On(): bool
    {
        $id = $this->analytics->validId('ga4');

        return $this->analytics->active('ga4') && $id !== null && str_starts_with($id, 'G-')
            && $this->config->secret('ga4_api_secret') !== null && $this->config->on('ga4_mp');
    }

    public function anyOn(): bool
    {
        return $this->metaOn() || $this->tiktokOn() || $this->ga4On();
    }

    /**
     * The statuses that make an order a sale for the server Purchase. On hold
     * is in it unless the owner switched it out (Ads Autopilot -> Measurement):
     * then an on-hold order sends its Purchase when it moves on to processing,
     * shipped or completed instead.
     *
     * @return list<string>
     */
    public function placedStatuses(): array
    {
        return $this->config->on('meta_purchase_onhold') ? self::PLACED : array_values(array_diff(self::PLACED, ['onhold']));
    }

    // ----------------------------------------------------------------- events

    /** A successful add to the cart. */
    public function addToCart(Request $request, int $productId, ?int $variantId, int $quantity, int $unitFils): void
    {
        if (InstantNav::isSpeculative($request) || ! ($this->metaOn() || $this->tiktokOn())) {
            return;
        }

        $ctx = BrowserContext::from($request, (string) $request->headers->get('referer', ''));
        $eventId = $ctx['eid'] ?? ('atc-' . bin2hex(random_bytes(8)));
        $cid = CatalogIds::line($productId, $variantId);
        $value = round($unitFils * $quantity / 100, 2);

        $custom = ['currency' => 'AED', 'value' => $value, 'content_type' => 'product', 'content_ids' => [$cid],
            'contents' => [['id' => $cid, 'quantity' => $quantity, 'item_price' => round($unitFils / 100, 2)]]];

        $this->dispatch('AddToCart', 'AddToCart', $eventId, $ctx, [], $custom, null);
    }

    /** The checkout page, rendered for a real visit. The id is already in the page. */
    public function initiateCheckout(Request $request, string $eventId, int $totalFils): void
    {
        if (InstantNav::isSpeculative($request) || ! ($this->metaOn() || $this->tiktokOn())) {
            return;
        }

        $ctx = BrowserContext::from($request);
        $custom = ['currency' => 'AED', 'value' => round($totalFils / 100, 2)];

        $this->dispatch('InitiateCheckout', 'InitiateCheckout', $eventId, $ctx, [], $custom, null);
    }

    /**
     * The order became a sale. Sent once per order and platform: the unique
     * key on marketing_server_events is the claim, so a second status move,
     * a retried webhook or two workers at once cannot send it twice.
     */
    public function purchase(Order $order): void
    {
        if (! $this->anyOn()) {
            return;
        }

        $ctx = $this->takeContext((int) $order->id);

        if ($ctx === null) {
            // No browser context: this order did not come through the shop's
            // own checkout (an import, a manual order, a sample). Not a
            // conversion any ad can claim, so nothing is sent.
            return;
        }

        $eventId = self::purchaseId($order);
        $order->loadMissing('items');

        $contents = [];
        $ids = [];
        $items = [];
        $count = 0;

        foreach ($order->items as $item) {
            $cid = CatalogIds::line((int) $item->product_id, $item->product_variant_id ? (int) $item->product_variant_id : null);
            $qty = (int) $item->quantity;
            $price = round((int) $item->unit_price / 100, 2);
            $ids[] = $cid;
            $count += $qty;
            $contents[] = ['id' => $cid, 'quantity' => $qty, 'item_price' => $price, 'title' => mb_substr((string) $item->name, 0, 150)];
            $items[] = ['item_id' => (string) $item->product_id, 'item_name' => mb_substr((string) $item->name, 0, 100), 'price' => $price, 'quantity' => $qty];
        }

        $currency = (string) ($order->currency ?: 'AED');
        $value = round((int) $order->total / 100, 2);
        $custom = ['currency' => $currency, 'value' => $value, 'content_type' => 'product', 'content_ids' => $ids,
            'contents' => $contents, 'num_items' => $count, 'order_id' => (string) $order->order_number];

        $this->dispatch('Purchase', 'CompletePayment', $eventId, $ctx, self::customer($order), $custom, [
            'transaction_id' => (string) $order->order_number,
            'value' => $value,
            'currency' => $currency,
            'shipping' => round((int) $order->shipping_total / 100, 2),
            'tax' => round((int) $order->tax_total / 100, 2),
            'items' => $items,
        ]);

        try {
            DB::table('marketing_event_contexts')->where('order_id', (int) $order->id)->delete();
        } catch (\Throwable) {
        }
    }

    public static function purchaseId(Order $order): string
    {
        return 'purchase-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($order->order_number ?: $order->id));
    }

    /** A refund that moved money: Meta custom event OrderRefunded, once per refund. */
    public function refund(Refund $refund): void
    {
        if (! $this->refundEventsOn() || (int) $refund->amount <= 0) {
            return;
        }

        $order = $refund->order()->first();

        if (! $order instanceof Order || ! $this->metaCounted($order)) {
            return;
        }

        $this->dispatchMetaOnly('OrderRefunded', 'refund-' . (int) $refund->id, $order, [
            'currency' => (string) ($order->currency ?: 'AED'),
            'value' => round((int) $refund->amount / 100, 2),
            'order_id' => (string) $order->order_number,
            'refund_of' => self::purchaseId($order),
        ]);
    }

    /** A placed order cancelled (a refused COD parcel, a voided BNPL): OrderCancelled, once. */
    public function cancellation(Order $order): void
    {
        if (! $this->refundEventsOn() || ! $this->metaCounted($order)) {
            return;
        }

        $this->dispatchMetaOnly('OrderCancelled', 'cancel-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($order->order_number ?: $order->id)), $order, [
            'currency' => (string) ($order->currency ?: 'AED'),
            'value' => round((int) $order->total / 100, 2),
            'order_id' => (string) $order->order_number,
            'refund_of' => self::purchaseId($order),
        ]);
    }

    public function refundEventsOn(): bool
    {
        return $this->metaOn() && $this->config->on('meta_refund_events');
    }

    /**
     * Could Meta have counted this order? The browser purchase tag was printed
     * (pixels_fired_at), or a server Purchase was claimed for it. An imported or
     * manual order Meta never saw gets no correction either.
     */
    private function metaCounted(Order $order): bool
    {
        if ($order->pixels_fired_at !== null) {
            return true;
        }

        try {
            return DB::table('marketing_server_events')->where(['platform' => 'meta', 'event' => 'Purchase', 'event_id' => self::purchaseId($order)])
                ->whereIn('status', ['queued', 'sent', 'failed', 'paused'])->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $custom */
    private function dispatchMetaOnly(string $name, string $eventId, Order $order, array $custom): void
    {
        $pixel = (string) $this->analytics->validId('meta');
        $token = (string) $this->config->secret('meta_capi_token');
        $test = $this->config->get('meta_test_code') ?: null;
        $event = self::metaEvent($name, $eventId, time(), [], self::customer($order), $custom);
        $event['action_source'] = 'other';

        if (! $this->claim('meta', $name, $eventId)) {
            return;
        }

        $retry = ['pixel' => $pixel, 'event' => $event, 'test' => $test];
        self::later(function () use ($name, $eventId, $pixel, $token, $event, $test, $retry): void {
            $this->send('meta', $name, $eventId, fn () => $this->client->metaEvents($pixel, $token, [$event], $test), $retry);
        });
    }

    // -------------------------------------------------------------- context

    /** Called when the checkout creates the order: keep the shopper's signals. */
    public function captureContext(Order $order, ?Request $request): void
    {
        if ($request === null || ! $this->anyOn()) {
            return;
        }

        try {
            DB::table('marketing_event_contexts')->insertOrIgnore([
                'order_id' => (int) $order->id,
                'payload' => Crypt::encryptString(json_encode(BrowserContext::from($request, (string) $request->headers->get('referer', '')) + ['at' => time()])),
                'created_at' => now(),
            ]);

            if (random_int(1, 50) === 1) {
                DB::table('marketing_event_contexts')->where('created_at', '<', now()->subDays(7))->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Marketing pixels: could not keep the order context: ' . $e->getMessage());
        }
    }

    private function takeContext(int $orderId): ?array
    {
        try {
            $row = DB::table('marketing_event_contexts')->where('order_id', $orderId)->first();

            if ($row === null) {
                return null;
            }

            $ctx = json_decode(Crypt::decryptString((string) $row->payload), true);

            return is_array($ctx) ? $ctx : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------- payloads

    /** @return array<string, list<string>> Meta user_data, hashed, from the order. */
    public static function customer(Order $order): array
    {
        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $country = UserData::country((string) ($billing['country'] ?? 'AE')) ?? 'ae';

        return array_filter([
            'em' => UserData::hashed(UserData::email((string) $order->email)),
            'ph' => UserData::hashed(UserData::phoneDigits((string) ($order->phone ?: ($billing['phone'] ?? '')))),
            'ph_e164' => UserData::hashed(UserData::phoneE164((string) ($order->phone ?: ($billing['phone'] ?? '')))),
            'fn' => UserData::hashed(UserData::name($billing['first_name'] ?? null)),
            'ln' => UserData::hashed(UserData::name($billing['last_name'] ?? null)),
            'ct' => UserData::hashed(UserData::city($billing['city'] ?? null)),
            'country' => UserData::hashed($country),
            'external_id' => $order->customer_id ? UserData::hash('kbb-customer-' . $order->customer_id) : null,
        ]);
    }

    /** One Meta Conversions API event. */
    public static function metaEvent(string $name, string $eventId, int $time, array $ctx, array $customer, array $custom): array
    {
        $user = array_filter([
            'em' => isset($customer['em']) ? [$customer['em']] : null,
            'ph' => isset($customer['ph']) ? [$customer['ph']] : null,
            'fn' => isset($customer['fn']) ? [$customer['fn']] : null,
            'ln' => isset($customer['ln']) ? [$customer['ln']] : null,
            'ct' => isset($customer['ct']) ? [$customer['ct']] : null,
            'country' => isset($customer['country']) ? [$customer['country']] : null,
            'external_id' => isset($customer['external_id']) ? [$customer['external_id']] : null,
            'client_ip_address' => $ctx['ip'] ?? null,
            'client_user_agent' => $ctx['ua'] ?? null,
            'fbp' => $ctx['fbp'] ?? null,
            'fbc' => $ctx['fbc'] ?? null,
        ]);

        if (isset($custom['contents'])) {
            $custom['contents'] = array_map(fn ($c) => ['id' => $c['id'], 'quantity' => $c['quantity'], 'item_price' => $c['item_price']], $custom['contents']);
        }

        return array_filter([
            'event_name' => $name,
            'event_time' => $time,
            'event_id' => $eventId,
            'action_source' => 'website',
            'event_source_url' => $ctx['url'] ?? null,
            'user_data' => $user,
            'custom_data' => $custom,
        ], fn ($v) => $v !== null && $v !== []);
    }

    /** One TikTok Events API body (a single event). */
    public static function tiktokBody(string $pixel, string $name, string $eventId, int $time, array $ctx, array $customer, array $custom, ?string $testCode): array
    {
        $contents = [];

        foreach ($custom['contents'] ?? [] as $c) {
            $contents[] = array_filter(['content_id' => $c['id'], 'content_type' => 'product', 'content_name' => $c['title'] ?? null,
                'price' => $c['item_price'], 'quantity' => $c['quantity']], fn ($v) => $v !== null);
        }

        $body = [
            'event_source' => 'web',
            'event_source_id' => $pixel,
            'data' => [array_filter([
                'event' => $name,
                'event_time' => $time,
                'event_id' => $eventId,
                'user' => array_filter([
                    'email' => $customer['em'] ?? null,
                    'phone' => $customer['ph_e164'] ?? null,
                    'external_id' => $customer['external_id'] ?? null,
                    'ip' => $ctx['ip'] ?? null,
                    'user_agent' => $ctx['ua'] ?? null,
                    'ttp' => $ctx['ttp'] ?? null,
                ]),
                'page' => array_filter(['url' => $ctx['url'] ?? null]),
                'properties' => array_filter([
                    'currency' => $custom['currency'] ?? 'AED',
                    'value' => $custom['value'] ?? null,
                    'content_type' => 'product',
                    'contents' => $contents ?: null,
                    'order_id' => $custom['order_id'] ?? null,
                ], fn ($v) => $v !== null),
            ], fn ($v) => $v !== null && $v !== [])],
        ];

        if ($testCode !== null && $testCode !== '') {
            $body['test_event_code'] = $testCode;
        }

        return $body;
    }

    /** One GA4 Measurement Protocol body. */
    public static function ga4Body(string $clientId, ?string $sessionId, string $name, array $params): array
    {
        if ($sessionId !== null) {
            $params['session_id'] = $sessionId;
        }

        $params['engagement_time_msec'] = 1;

        return ['client_id' => $clientId, 'events' => [['name' => $name, 'params' => $params]]];
    }

    // ------------------------------------------------------------- sending

    /**
     * Build what each switched-on platform needs NOW (settings and context are
     * read in the request), and send it after the response.
     */
    private function dispatch(string $metaName, string $tiktokName, string $eventId, array $ctx, array $customer, array $custom, ?array $ga4Params): void
    {
        $time = time();
        $jobs = [];

        if ($this->metaOn()) {
            $pixel = (string) $this->analytics->validId('meta');
            $token = (string) $this->config->secret('meta_capi_token');
            $test = $this->config->get('meta_test_code') ?: null;
            $event = self::metaEvent($metaName, $eventId, $time, $ctx, $customer, $custom);
            $retry = in_array($metaName, self::RETRIED, true) ? ['pixel' => $pixel, 'event' => $event, 'test' => $test] : null;
            $jobs[] = ['meta', $metaName, fn () => $this->client->metaEvents($pixel, $token, [$event], $test), $retry];
        }

        if ($this->tiktokOn()) {
            $pixel = (string) $this->analytics->validId('tiktok');
            $token = (string) $this->config->secret('tiktok_token');
            $body = self::tiktokBody($pixel, $tiktokName, $eventId, $time, $ctx, $customer, $custom, $this->config->get('tiktok_test_code') ?: null);
            $jobs[] = ['tiktok', $tiktokName, fn () => $this->client->tiktok($token, $body), null];
        }

        if ($ga4Params !== null && $this->ga4On() && ($ctx['ga_client'] ?? null) !== null) {
            $mid = (string) $this->analytics->validId('ga4');
            $secret = (string) $this->config->secret('ga4_api_secret');
            $body = self::ga4Body((string) $ctx['ga_client'], $ctx['ga_session'] ?? null, 'purchase', $ga4Params);
            $jobs[] = ['ga4', 'purchase', fn () => $this->client->ga4($mid, $secret, $body), null];
        } elseif ($ga4Params !== null && $this->ga4On()) {
            $this->log('ga4', 'purchase', $eventId, 'skipped', null, 'No Google Analytics client id on this order (cookie blocked or declined), so the browser purchase is the only one — sending a second from the server would double-count it.');
        }

        foreach ($jobs as [$platform, $name, $send, $retry]) {
            if (! $this->claim($platform, $name, $eventId)) {
                continue;
            }

            self::later(function () use ($platform, $name, $eventId, $send, $retry): void {
                $this->send($platform, $name, $eventId, $send, $retry);
            });
        }
    }

    /** @param array{pixel: string, event: array, test: ?string}|null $retry what to keep if this fails */
    private function send(string $platform, string $name, string $eventId, \Closure $send, ?array $retry = null): void
    {
        if (Cache::has(self::BREAKER . $platform)) {
            $kept = $retry !== null && $this->keepForRetry($name, $eventId, $retry, 'paused by the breaker');
            $this->finish($platform, $name, $eventId, 'paused', null, 'Paused for 10 minutes after the platform refused the access token. Check the token on the Connect tab.' . ($kept ? ' Will retry.' : ''));

            return;
        }

        $answer = ['ok' => false, 'status' => 0, 'message' => 'Not sent.', 'data' => []];

        try {
            $answer = $send();
        } catch (\Throwable $e) {
            $answer['message'] = mb_substr($e->getMessage(), 0, 200);
        }

        if (! $answer['ok'] && self::authFailure($answer)) {
            try {
                Cache::put(self::BREAKER . $platform, 1, self::BREAKER_SECONDS);
            } catch (\Throwable) {
            }
        }

        $message = $answer['ok'] ? self::successNote($platform, $answer['data']) : ($answer['message'] ?: 'Refused.');

        if (! $answer['ok'] && $retry !== null && self::retryable($answer) && $this->keepForRetry($name, $eventId, $retry, $message)) {
            $message = mb_substr($message, 0, 280) . ' Will retry.';
        }

        $this->finish($platform, $name, $eventId, $answer['ok'] ? 'sent' : 'failed', $answer['status'] ?: null, $message);

        if (! $answer['ok']) {
            Log::warning("Marketing pixels: {$platform} {$name} failed: {$message}");
        }
    }

    /** No answer, rate limited, a server fault or a refused token: worth another go. A 400 is not. */
    public static function retryable(array $answer): bool
    {
        $status = (int) $answer['status'];

        return $status === 0 || $status === 429 || $status >= 500 || self::authFailure($answer);
    }

    /** @param array{pixel: string, event: array, test: ?string} $retry */
    private function keepForRetry(string $name, string $eventId, array $retry, string $why): bool
    {
        if (! $this->config->on('meta_capi_retry') || ! in_array($name, self::RETRIED, true)) {
            return false;
        }

        try {
            return DB::table('marketing_capi_retries')->insertOrIgnore([
                'event' => $name,
                'event_id' => mb_substr($eventId, 0, 80),
                'payload' => Crypt::encryptString((string) json_encode($retry)),
                'attempts' => 0,
                'next_at' => now()->addMinutes(self::RETRY_AFTER[0]),
                'first_at' => now(),
                'last_error' => mb_substr($why, 0, 300),
            ]) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Re-send what is due. Run by `kbb:capi-retry` from the scheduler. One
     * indexed read when nothing is due. Uses the token as it is NOW, so a
     * token the owner has just fixed delivers what failed under the old one.
     *
     * @return array{due: int, sent: int, failed: int, dropped: int}
     */
    public function retryDue(int $limit = 20): array
    {
        $out = ['due' => 0, 'sent' => 0, 'failed' => 0, 'dropped' => 0];

        try {
            $rows = DB::table('marketing_capi_retries')->where('next_at', '<=', now())->orderBy('next_at')->limit($limit)->get();
        } catch (\Throwable) {
            return $out;
        }

        $out['due'] = $rows->count();

        foreach ($rows as $row) {
            $tooLate = strtotime((string) $row->first_at) < time() - self::RETRY_HOURS * 3600;
            $payload = null;

            try {
                $payload = json_decode(Crypt::decryptString((string) $row->payload), true);
            } catch (\Throwable) {
            }

            if ($tooLate || ! is_array($payload) || ! $this->metaOn() || ! $this->config->on('meta_capi_retry')) {
                DB::table('marketing_capi_retries')->where('id', $row->id)->delete();
                $why = $tooLate ? 'Gave up: ' . self::RETRY_HOURS . ' hours passed (Meta merges a browser and server copy only within 48 h).'
                    : 'Dropped: Meta server events or retries were switched off, or the kept event could not be read.';
                $this->finish('meta', (string) $row->event, (string) $row->event_id, 'failed', null, $why);
                $out['dropped']++;

                continue;
            }

            if (Cache::has(self::BREAKER . 'meta')) {
                DB::table('marketing_capi_retries')->where('id', $row->id)->update(['next_at' => now()->addMinutes(10)]);

                continue;
            }

            $attempt = (int) $row->attempts + 1;
            $answer = ['ok' => false, 'status' => 0, 'message' => 'Not sent.', 'data' => []];

            try {
                $answer = $this->client->metaEvents((string) $payload['pixel'], (string) $this->config->secret('meta_capi_token'), [$payload['event']], $payload['test'] ?? null);
            } catch (\Throwable $e) {
                $answer['message'] = mb_substr($e->getMessage(), 0, 200);
            }

            if ($answer['ok']) {
                DB::table('marketing_capi_retries')->where('id', $row->id)->delete();
                $this->finish('meta', (string) $row->event, (string) $row->event_id, 'sent', $answer['status'] ?: null, self::successNote('meta', $answer['data']) . " (retry {$attempt})");
                $out['sent']++;

                continue;
            }

            if (self::authFailure($answer)) {
                try {
                    Cache::put(self::BREAKER . 'meta', 1, self::BREAKER_SECONDS);
                } catch (\Throwable) {
                }
            }

            $message = ($answer['message'] ?: 'Refused.') . " (retry {$attempt})";
            $next = self::RETRY_AFTER[$attempt] ?? null;

            if (! self::retryable($answer) || $next === null) {
                DB::table('marketing_capi_retries')->where('id', $row->id)->delete();
                $this->finish('meta', (string) $row->event, (string) $row->event_id, 'failed', $answer['status'] ?: null, $message . ' Gave up.');
            } else {
                DB::table('marketing_capi_retries')->where('id', $row->id)->update([
                    'attempts' => $attempt,
                    'next_at' => \Illuminate\Support\Carbon::parse((string) $row->first_at)->addMinutes($next),
                    'last_error' => mb_substr($message, 0, 300),
                ]);
                $this->finish('meta', (string) $row->event, (string) $row->event_id, 'failed', $answer['status'] ?: null, $message . ' Will retry.');
            }

            Log::warning("Marketing pixels: meta {$row->event} retry {$attempt} failed: {$message}");
            $out['failed']++;
        }

        return $out;
    }

    /** @return array{waiting: int, oldest: string|null} for the admin screen */
    public static function retryQueue(): array
    {
        try {
            return ['waiting' => (int) DB::table('marketing_capi_retries')->count(), 'oldest' => DB::table('marketing_capi_retries')->min('first_at')];
        } catch (\Throwable) {
            return ['waiting' => 0, 'oldest' => null];
        }
    }

    private static function authFailure(array $answer): bool
    {
        $code = (int) ($answer['data']['error']['code'] ?? 0);

        return in_array((int) $answer['status'], [401, 403], true) || $code === 190;
    }

    private static function successNote(string $platform, array $data): string
    {
        return match ($platform) {
            'meta' => 'Received: ' . (int) ($data['events_received'] ?? 0),
            'tiktok' => 'OK',
            default => 'Accepted',
        };
    }

    /** Insert the claim row; false when this event already went (or is going). */
    private function claim(string $platform, string $name, string $eventId): bool
    {
        try {
            return DB::table('marketing_server_events')->insertOrIgnore([
                'platform' => $platform, 'event' => $name, 'event_id' => mb_substr($eventId, 0, 80),
                'status' => 'queued', 'created_at' => now(),
            ]) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    private function finish(string $platform, string $name, string $eventId, string $status, ?int $http, string $message): void
    {
        try {
            DB::table('marketing_server_events')
                ->where(['platform' => $platform, 'event' => $name, 'event_id' => mb_substr($eventId, 0, 80)])
                ->update(['status' => $status, 'http_status' => $http, 'message' => mb_substr($message, 0, 300)]);

            if (random_int(1, 25) === 1) {
                $floor = (int) DB::table('marketing_server_events')->max('id') - self::KEEP_ROWS;
                if ($floor > 0) {
                    DB::table('marketing_server_events')->where('id', '<', $floor)->delete();
                }
            }
        } catch (\Throwable) {
        }
    }

    private function log(string $platform, string $name, string $eventId, string $status, ?int $http, string $message): void
    {
        if ($this->claim($platform, $name, $eventId)) {
            $this->finish($platform, $name, $eventId, $status, $http, $message);
        }
    }

    /** Run after the response. Registered once per request. */
    private static function later(\Closure $fn): void
    {
        self::$buffer[] = $fn;
        $app = app();

        if ($app->bound(self::ARMED)) {
            return;
        }

        $app->instance(self::ARMED, true);
        $app->terminating(static function (): void {
            $jobs = self::$buffer;
            self::$buffer = [];

            foreach ($jobs as $job) {
                try {
                    $job();
                } catch (\Throwable) {
                }
            }
        });
    }

    /**
     * Drop what is waiting without sending it -- a test's reset, never the
     * shop's. Under PHP-FPM the buffer cannot outlive its request (statics
     * start empty in every request, and terminating() drains it), but in one
     * long test process a case that queues an event and never terminates
     * would hand its closure to the next case's first terminate().
     */
    public static function forget(): void
    {
        self::$buffer = [];
    }

    /** @return list<array<string, mixed>> the newest rows for the panel. */
    public static function recent(int $limit = 30): array
    {
        try {
            return DB::table('marketing_server_events')->orderByDesc('id')->limit($limit)
                ->get(['platform', 'event', 'event_id', 'status', 'http_status', 'message', 'created_at'])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
