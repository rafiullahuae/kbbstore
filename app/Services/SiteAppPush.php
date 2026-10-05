<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\OwnerApp\VapidKeys;
use App\Services\OwnerApp\WebPush;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The shop app's notification question and its subscriptions (Lane NT).
 * App → Site App → "Ask shoppers for notifications when the app opens".
 *
 * The owner, 5 October: "the site app + owner app, apps should ask by default
 * about to allow notifications, after install when the open the app".
 *
 * GROUNDWORK ONLY. This stores who said yes, where the phone is (never by
 * asking), who is signed in on it, and which out-of-stock products it looked
 * at; nothing here sends. What a
 * shopper receives is the owner's decision, and a later lane reads
 * site_app_push_subscriptions and calls WebPush::send() with the same VAPID
 * pair the owner app already uses (VapidKeys: one identity for the shop, never
 * a second pair).
 *
 * ONE SETTING OF ITS OWN, `site_app_push` = {"ask": bool}, NOT AUTOLOADED. Not
 * a third key inside `site_app`: SiteApp::save() rewrites that value from
 * SiteApp::all(), which knows {on, name} only, so any other key there would be
 * dropped the next time the owner renamed the app. Not autoloaded because no
 * page reads it: only GET /api/site-app/push does, and only from an installed
 * app with the question still open.
 *
 * ON BY DEFAULT: he asked for it (CLAUDE.md rule 1, the 30 September reversal).
 */
final class SiteAppPush
{
    public const SETTING = 'site_app_push';

    public const TABLE = 'site_app_push_subscriptions';

    public const INTERESTS = 'site_app_push_interests';

    /** The phone's random subscriber handle: first-party, HttpOnly, no PII. */
    public const COOKIE = 'kbb_push';

    /** How long the cookie lives, in minutes (400 days, the browsers' own cap). */
    public const COOKIE_MINUTES = 576000;

    /** The largest request body the subscribe endpoint reads, in bytes. A real one is ~400. */
    public const MAX_BODY = 2048;

    /** The interface strings the sheet shows, InterfaceStrings 'store' group. */
    public const STRINGS = [
        'title' => 'store.site_app.push_title',
        'body' => 'store.site_app.push_body',
        'allow' => 'store.site_app.push_allow',
        'later' => 'store.site_app.push_later',
    ];

    public function __construct(private SettingsService $settings) {}

    public function ask(): bool
    {
        $raw = $this->settings->get(self::SETTING);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) && array_key_exists('ask', $raw) ? (bool) $raw['ask'] : true;
    }

    public function setAsk(bool $on): void
    {
        $this->settings->set(self::SETTING, ['ask' => $on], false);
    }

    /** One of the shop's enabled languages; anything else is the default. */
    public static function locale(mixed $lang): string
    {
        return is_string($lang) && in_array($lang, Locale::enabledCodes(), true) ? $lang : Locale::DEFAULT;
    }

    /**
     * What the installed app is told when it opens: whether to ask, the public
     * VAPID key to subscribe with, and the sheet's four strings in its
     * language. Nothing else — this answers anybody.
     *
     * @return array{ask: bool, key: ?string, t: array<string,string>}
     */
    public function config(string $locale): array
    {
        $t = [];
        foreach (self::STRINGS as $k => $key) {
            $t[$k] = (string) __($key, [], $locale);
        }

        $key = VapidKeys::publicKey();

        return ['ask' => $this->ask() && $key !== null, 'key' => $key, 't' => $t];
    }

    /**
     * A subscription as a browser's PushSubscription.toJSON() gives it, or
     * null. The endpoint must be https on a known push service
     * (WebPush::allowedEndpoint: FCM, Mozilla, Apple, Windows), p256dh an
     * uncompressed P-256 point (65 bytes, base64url) that is really on the
     * curve, auth 16 bytes. Anything else — and any other type — is refused.
     *
     * @return array{endpoint:string, p256dh:string, auth:string}|null
     */
    public static function clean(mixed $endpoint, mixed $p256dh, mixed $auth): ?array
    {
        if (! is_string($endpoint) || ! is_string($p256dh) || ! is_string($auth)) {
            return null;
        }
        if (strlen($p256dh) > 120 || strlen($auth) > 40
            || ! preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/', $p256dh) || ! preg_match('/\A[A-Za-z0-9_-]+={0,2}\z/', $auth)) {
            return null;
        }

        $point = WebPush::b64uDecode($p256dh);
        if (! WebPush::allowedEndpoint($endpoint) || strlen($point) !== 65 || $point[0] !== "\x04"
            || strlen(WebPush::b64uDecode($auth)) !== 16 || WebPush::keyFromPoint($point) === null) {
            return null;
        }

        return ['endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth];
    }

    /** A fresh value for the `kbb_push` cookie: random, no PII, 32 hex. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** The cookie's value if it is one this shop could have set, else null. */
    public static function token(mixed $raw): ?string
    {
        return is_string($raw) && preg_match('/\A[0-9a-f]{32}\z/', $raw) === 1 ? $raw : null;
    }

    /** 'ios' | 'android' | 'desktop' | 'other' from a user agent. The string itself is never kept. */
    public static function platform(mixed $ua): string
    {
        $ua = is_string($ua) ? $ua : '';

        return match (true) {
            preg_match('/iPhone|iPad|iPod/', $ua) === 1 => 'ios',
            str_contains($ua, 'Android') => 'android',
            preg_match('/Windows|Macintosh|X11|CrOS|Linux/', $ua) === 1 => 'desktop',
            default => 'other',
        };
    }

    /**
     * Where the phone is, without asking the shopper anything (the owner:
     * "no disturbance to the customer about setting etc. everything should be
     * auto" -- so never navigator.geolocation). The signed-in shopper's latest
     * order's shipping address first; else the proxy's geo headers when the
     * host sends them (Cloudflare's CF-IPCountry / CF-Region / CF-IPCity: a
     * header read, so free when they are absent, as they may be in
     * production); else null.
     *
     * @return array{country:?string, region:?string, city:?string, location_source:string}|null
     */
    public static function locate(Request $request, ?int $customerId): ?array
    {
        if ($customerId !== null) {
            $found = self::fromOrderAddress(DB::table('orders')->where('customer_id', $customerId)->orderByDesc('id')->value('shipping_address'));
            if ($found !== null) {
                return $found;
            }
        }

        $country = strtoupper(trim((string) $request->header('CF-IPCountry', '')));
        $country = preg_match('/\A[A-Z]{2}\z/', $country) === 1 && ! in_array($country, ['XX', 'T1'], true) ? $country : null;
        $region = self::text($request->header('CF-Region'), 60);
        $city = self::text($request->header('CF-IPCity'), 80);
        if ($country === null && $region === null && $city === null) {
            return null;
        }

        return ['country' => $country, 'region' => $region, 'city' => $city, 'location_source' => 'ip-header'];
    }

    /**
     * An order's shipping address as location. The checkout stores the
     * emirate in `state` (and, at the owner's instruction, the city box holds
     * it too: CartAddressState::shape()), so region = state, falling back to city.
     *
     * @return array{country:?string, region:?string, city:?string, location_source:string}|null
     */
    public static function fromOrderAddress(mixed $address): ?array
    {
        if (is_string($address)) {
            $address = json_decode($address, true);
        }
        if (! is_array($address)) {
            return null;
        }
        $country = strtoupper(trim((string) ($address['country'] ?? '')));
        $country = preg_match('/\A[A-Z]{2}\z/', $country) === 1 ? $country : null;
        $city = self::text($address['city'] ?? null, 80);
        $region = self::text($address['state'] ?? null, 60) ?? ($city === null ? null : mb_substr($city, 0, 60));
        if ($country === null && $region === null && $city === null) {
            return null;
        }

        return ['country' => $country, 'region' => $region, 'city' => $city, 'location_source' => 'order'];
    }

    private static function text(mixed $v, int $max): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));

        return $v === '' || ! mb_check_encoding($v, 'UTF-8') ? null : mb_substr($v, 0, $max);
    }

    /**
     * Insert or refresh by endpoint: the same phone saying yes twice is one
     * row. A signed-in session sets customer_id; a guest sync keeps the one
     * already there. A location from an order is not replaced by a guess from
     * headers; no location at all keeps what the row had. One phone (cookie)
     * holds one row: a row it left behind under an old endpoint is removed.
     *
     * @param  array{endpoint:string, p256dh:string, auth:string}  $sub
     * @param  array{country:?string, region:?string, city:?string, location_source:string}|null  $where
     */
    public static function store(array $sub, string $token, ?int $customerId, string $locale, ?array $where, string $platform): void
    {
        $hash = hash('sha256', $sub['endpoint']);
        $cookie = hash('sha256', $token);
        $now = now();

        DB::transaction(function () use ($sub, $hash, $cookie, $customerId, $locale, $where, $platform, $now) {
            DB::table(self::TABLE)->where('cookie_hash', $cookie)->where('endpoint_hash', '!=', $hash)->delete();
            $old = DB::table(self::TABLE)->where('endpoint_hash', $hash)->first(['id', 'customer_id', 'location_source']);

            $row = ['endpoint' => $sub['endpoint'], 'p256dh' => $sub['p256dh'], 'auth' => $sub['auth'], 'cookie_hash' => $cookie,
                'locale' => $locale, 'platform' => $platform, 'status' => 'active', 'fail_count' => 0,
                'last_seen_at' => $now, 'updated_at' => $now];
            if ($customerId !== null || $old === null) {
                $row['customer_id'] = $customerId;
            }
            if ($where !== null && ($old === null || $where['location_source'] === 'order' || $old->location_source !== 'order')) {
                $row += $where + ['location_at' => $now];
            }

            if ($old === null) {
                DB::table(self::TABLE)->insert($row + ['endpoint_hash' => $hash, 'created_at' => $now]);
            } else {
                DB::table(self::TABLE)->where('id', $old->id)->update($row);
            }
        });
    }

    /**
     * An order placed from a phone holding a subscription (its `kbb_push`
     * cookie): link the shopper and take the order's shipping address as the
     * phone's location. Called from the Order::created hook the owner app
     * already has; costs nothing unless the cookie is there. Never for an
     * order an admin creates (a manual or sample order is not the phone's).
     */
    public static function orderPlaced(\App\Models\Order $order): void
    {
        $request = app()->bound('request') ? request() : null;
        $token = $request === null ? null : self::token($request->cookie(self::COOKIE));
        if ($token === null || (app()->runningInConsole() && ! app()->runningUnitTests())) {
            return;
        }
        try {
            if (auth('admin')->check()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        $row = ['last_seen_at' => now(), 'updated_at' => now()];
        if ($order->customer_id) {
            $row['customer_id'] = (int) $order->customer_id;
        }
        $where = self::fromOrderAddress($order->shipping_address);
        if ($where !== null) {
            $row += $where + ['location_at' => now()];
        }

        $hash = hash('sha256', $token);
        $write = static function () use ($hash, $row): void {
            try {
                DB::table(self::TABLE)->where('cookie_hash', $hash)->update($row);
            } catch (\Throwable) {
            }
        };
        try {
            DB::afterCommit($write);
        } catch (\Throwable) {
        }
    }

    /**
     * A subscribed phone looked at a product that is out of stock: remember
     * it once per product (a later visit refreshes viewed_at), so PN's "back
     * in stock" reaches only phones that visited it. Checked here, not taken
     * from the page: the product must exist and be out of stock now.
     */
    public static function viewed(string $token, int $productId): void
    {
        $sub = DB::table(self::TABLE)->where('cookie_hash', hash('sha256', $token))->where('status', 'active')->value('id');
        if ($sub === null) {
            return;
        }
        $product = \App\Models\Product::query()->find($productId);
        if ($product === null) {
            return;
        }
        $variants = $product->variants;
        $out = $product->stock_status !== 'instock' || ($variants->isNotEmpty() && $variants->first(fn ($v) => $v->inStock()) === null);
        if (! $out) {
            return;
        }

        DB::table(self::INTERESTS)->upsert(
            [['subscription_id' => (int) $sub, 'product_id' => $productId, 'viewed_at' => now()]],
            ['subscription_id', 'product_id'],
            ['viewed_at'],
        );
    }

    public static function forget(mixed $endpoint): void
    {
        if (is_string($endpoint) && $endpoint !== '' && strlen($endpoint) <= 1000) {
            DB::table(self::TABLE)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
        }
    }
}
