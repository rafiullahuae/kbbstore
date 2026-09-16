<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DeliveryCountry;
use Illuminate\Http\Request;

/**
 * Extended delivery — per-country charges, and detecting which country a
 * shopper is in.
 *
 * Off by default. While it is off, every method here reports "not in use" and
 * the zone rules answer exactly as they did before; this class is not consulted
 * at all. That is the whole point of it being extended rather than a rewrite.
 *
 * When it is on it is **authoritative**: the country list and the charges come
 * from here and the zones are not consulted. One switch, one answer — a blend
 * of the two would mean two places could disagree about the delivery charge,
 * and this project has paid for that mistake more than once.
 */
class ExtendedDelivery
{
    public const SETTING_ON = 'extended_delivery';
    public const SETTING_DETECT = 'extended_delivery_detect';
    public const SETTING_SHOW_ALL = 'extended_delivery_show_all';

    /** Resolved once per request. */
    private ?array $rows = null;

    public function __construct(private SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_ON, false);
    }

    public function detectEnabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_DETECT, true);
    }

    public function showsUnserved(): bool
    {
        return (bool) $this->settings->get(self::SETTING_SHOW_ALL, false);
    }

    /**
     * The configured countries, keyed by code.
     *
     * @return array<string, array{charge:int, free_from:?int, eta:?string, enabled:bool}>
     */
    public function countries(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (! $this->enabled()) {
            return $this->rows = [];
        }

        return $this->rows = DeliveryCountry::query()
            ->orderBy('position')
            ->get()
            ->mapWithKeys(fn (DeliveryCountry $c) => [$c->code => [
                'charge' => (int) $c->charge,
                'free_from' => $c->free_from === null ? null : (int) $c->free_from,
                'eta' => $c->eta,
                'enabled' => (bool) $c->enabled,
            ]])
            ->all();
    }

    /** Only the ones actually being delivered to. */
    public function served(): array
    {
        return array_filter($this->countries(), static fn ($c) => $c['enabled']);
    }

    public function serves(?string $code): bool
    {
        return $code !== null && isset($this->served()[strtoupper($code)]);
    }

    /**
     * Rates for a country, in the shape ShippingService already returns, so the
     * checkout, the totals and the cart bar need no change to read them.
     *
     * @return array<int, array{id:string, title:string, cost:int, type:string}>
     */
    public function ratesFor(string $code, int $subtotalFils, bool $hidePaidWhenFree = true): array
    {
        $row = $this->served()[strtoupper($code)] ?? null;

        if ($row === null) {
            return [];
        }

        $rates = [];
        $free = $row['free_from'] !== null && $subtotalFils >= $row['free_from'];

        if ($free) {
            $rates[] = ['id' => 'xd_free', 'title' => 'Free delivery', 'cost' => 0, 'type' => 'free_shipping'];
        }

        if (! $free || ! $hidePaidWhenFree) {
            $rates[] = ['id' => 'xd_flat', 'title' => 'Delivery', 'cost' => $row['charge'], 'type' => 'flat_rate'];
        }

        return $rates;
    }

    /** The free-delivery threshold for a country, or null when it has none. */
    public function thresholdFor(string $code): ?int
    {
        return $this->served()[strtoupper($code)]['free_from'] ?? null;
    }

    public function etaFor(string $code): ?string
    {
        return $this->served()[strtoupper($code)]['eta'] ?? null;
    }

    /**
     * Where the shopper probably is — a raw guess, not filtered against any
     * particular country list.
     *
     * Detection is a general checkout feature, not something Extended switches
     * on: it works for the default Gulf countries just as much as for anything
     * Extended adds, so it is gated only on its own toggle, not on
     * enabled(). The caller (the checkout) decides whether the guess lands on
     * a country it can actually deliver to.
     *
     * Order matters and is fixed:
     *   1. a country header from the host's proxy — exact, free, no third party
     *   2. the browser's time zone, posted by the storefront on the first visit
     *   3. nothing — the caller falls back to the store's own country
     *
     * What the shopper chose and what is in their saved address both outrank
     * this; neither is decided here, because both belong to the caller that
     * knows about the request and the customer.
     */
    public function detect(Request $request): ?string
    {
        if (! $this->detectEnabled()) {
            return null;
        }

        foreach (['CF-IPCountry', 'X-Country-Code', 'X-Appengine-Country'] as $header) {
            $value = strtoupper(trim((string) $request->header($header, '')));

            // Cloudflare sends XX for anonymised or unknown addresses.
            if (strlen($value) === 2 && $value !== 'XX') {
                return $value;
            }
        }

        /*
         * THE RAW COOKIE, NOT THE DECRYPTED ONE, AND THIS IS NOT BELT-AND-BRACES.
         *
         * `kbb_tz` is written by resources/js/kbb/app.js in the BROWSER, so it
         * arrives without Laravel's encryption envelope. EncryptCookies drops
         * anything that does not decrypt and never says so, which is why this
         * tier read null on every request the site has ever served: a signal
         * that never arrives and a signal that says "I don't know" look
         * identical from here. bootstrap/app.php now exempts the cookie, and
         * that exemption is correct — but it CANNOT REACH THE LIVE SERVER.
         * UpdateGuard forbids `bootstrap/` in a package outright (a bad
         * bootstrap stops the application booting, which would leave the
         * updater unable to roll itself back), and the host has no shell. So on
         * production the exemption is not there and the decrypted bag is empty.
         *
         * $_COOKIE is the request as PHP received it. No middleware touches it,
         * so it carries the value whether or not the exemption is in place, and
         * this line is what makes the tier work on the server rather than only
         * in a checkout. The decrypted bag is still consulted first, so a value
         * Laravel did decrypt is preferred.
         *
         * Nothing is trusted on the strength of it. The value is mapped through
         * TIMEZONE_COUNTRY below — a fixed table — so anything not on that
         * table becomes null rather than a country, and the visitor could have
         * set their browser's time zone to whatever they liked in any case.
         * Length-capped before it is used as an array key, so a megabyte of
         * cookie is not carried around to be thrown away.
         */
        $zone = (string) $request->cookie('kbb_tz', '');

        if ($zone === '' && isset($_COOKIE['kbb_tz']) && is_string($_COOKIE['kbb_tz'])) {
            $zone = substr(urldecode($_COOKIE['kbb_tz']), 0, 64);
        }

        if ($zone !== '') {
            $code = self::TIMEZONE_COUNTRY[$zone] ?? null;

            if ($code !== null) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Time zone to country, for the zones a shop in this region actually sees.
     *
     * Deliberately not the full IANA list: a wrong guess costs a shopper one
     * click, and a table of six hundred rows to maintain costs more than that.
     * Anything not listed falls through to the store default.
     */
    public const TIMEZONE_COUNTRY = [
        'Asia/Dubai' => 'AE', 'Asia/Muscat' => 'OM', 'Asia/Riyadh' => 'SA',
        'Asia/Kuwait' => 'KW', 'Asia/Qatar' => 'QA', 'Asia/Bahrain' => 'BH',
        'Asia/Baghdad' => 'IQ', 'Asia/Amman' => 'JO', 'Asia/Beirut' => 'LB',
        'Asia/Karachi' => 'PK', 'Asia/Kolkata' => 'IN', 'Asia/Calcutta' => 'IN',
        'Asia/Colombo' => 'LK', 'Asia/Dhaka' => 'BD', 'Asia/Kathmandu' => 'NP',
        'Asia/Manila' => 'PH', 'Asia/Singapore' => 'SG', 'Asia/Kuala_Lumpur' => 'MY',
        'Asia/Jakarta' => 'ID', 'Asia/Bangkok' => 'TH', 'Asia/Seoul' => 'KR',
        'Asia/Tokyo' => 'JP', 'Asia/Shanghai' => 'CN', 'Asia/Hong_Kong' => 'HK',
        'Africa/Cairo' => 'EG', 'Africa/Casablanca' => 'MA', 'Africa/Lagos' => 'NG',
        'Africa/Nairobi' => 'KE', 'Africa/Johannesburg' => 'ZA',
        'Europe/London' => 'GB', 'Europe/Dublin' => 'IE', 'Europe/Paris' => 'FR',
        'Europe/Berlin' => 'DE', 'Europe/Madrid' => 'ES', 'Europe/Rome' => 'IT',
        'Europe/Amsterdam' => 'NL', 'Europe/Brussels' => 'BE', 'Europe/Zurich' => 'CH',
        'Europe/Vienna' => 'AT', 'Europe/Stockholm' => 'SE', 'Europe/Oslo' => 'NO',
        'Europe/Copenhagen' => 'DK', 'Europe/Helsinki' => 'FI', 'Europe/Lisbon' => 'PT',
        'Europe/Warsaw' => 'PL', 'Europe/Prague' => 'CZ', 'Europe/Athens' => 'GR',
        'Europe/Istanbul' => 'TR', 'Europe/Moscow' => 'RU', 'Europe/Kiev' => 'UA',
        'America/New_York' => 'US', 'America/Chicago' => 'US', 'America/Denver' => 'US',
        'America/Los_Angeles' => 'US', 'America/Toronto' => 'CA', 'America/Vancouver' => 'CA',
        'America/Mexico_City' => 'MX', 'America/Sao_Paulo' => 'BR',
        'Australia/Sydney' => 'AU', 'Australia/Melbourne' => 'AU', 'Australia/Perth' => 'AU',
        'Pacific/Auckland' => 'NZ',
    ];
}
