<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Services\ExtendedDelivery;
use App\Services\SettingsService;
use App\Services\ShippingService;
use App\Support\Countries;

/**
 * Which countries each payment method is offered in.          (Lane PCY)
 *
 * The owner, 12 October: "Cash on delivery we offer only in UAE. please on the
 * payment method COD, we should have option to choose the country we offer
 * for. The same we need for all other payment gateways. so we will have full
 * control, on which country to offer which payment gateway."
 *
 * ── ONE RULE, ASKED EVERYWHERE A METHOD IS OFFERED OR ACCEPTED ─────────────
 *
 * allows() is called from GatewayRegistry::availableFor() — which builds the
 * checkout's list, the list re-drawn when the shopper changes country, the list
 * Place order re-checks the posted method against (Apple Pay and Google Pay
 * included, they place through it), and the "Send order link" page's list —
 * and from the public Api\CheckoutController, which asks a gateway directly.
 * A country rule shown on the page and not enforced on submit would not be a
 * rule, so the submit paths ask the same question with the same country they
 * write onto the order.
 *
 * It is a gate IN FRONT of each gateway's own availableFor(), never instead of
 * it: Tabby still answers for its own country list and Tamara for its own
 * limits. Those classes are frozen (CLAUDE.md, 10 October) and this file does
 * not touch them.
 *
 * ── STORED AS ONE SETTING, READ FROM THE MAP THE PAGE ALREADY HOLDS ────────
 *
 * `payment_countries` = {"cod":["AE"],"tamara":["AE","SA"],...}. An absent id
 * or an empty list means EVERY country — exactly how every method behaved
 * before this existed. Read through SettingsService::get(), which answers
 * from the one settings snapshot per request (SettingsRequestMemo), so the
 * checkout asks no new query. Memoised per instance as well, because the list
 * is asked once per gateway per render.
 *
 * ── THE ONE DEFAULT THAT MOVES ─────────────────────────────────────────────
 *
 * Cash on delivery ships as UAE ONLY, because he asked for it in those words
 * (CLAUDE.md, 30 September: what he asked for ships on). Every other method
 * ships with no list = every country, unchanged. Once the owner saves the
 * screen, the stored map is the whole answer and this default is not read.
 *
 * ── A SHOPPER WITH NO COUNTRY ──────────────────────────────────────────────
 *
 * Asked with no country (null or blank), the answer is the shop's own country
 * (`store_country`) -- the same assumption the checkout makes before an
 * address exists and the one the public endpoint records on such an order. So
 * "COD in the UAE only" still offers COD to a UAE shop's basket with no address
 * yet, and a damaged or empty setting never reads as every country.
 */
class GatewayCountries
{
    public const SETTING = 'payment_countries';

    /** Applied while nothing is saved: the owner's "COD only in UAE". */
    public const DEFAULTS = ['cod' => ['AE']];

    /** Not a country: what a damaged list reads back as, so it matches nobody. */
    public const NONE = '--';

    /** A list longer than every country there is cannot be honest input. */
    public const MAX = 250;

    /** @var array<string, list<string>>|null */
    private ?array $map = null;

    public function __construct(private SettingsService $settings) {}

    /**
     * The countries this method is offered in. Empty = every country.
     *
     * @return list<string>
     */
    public function for(string $gatewayId): array
    {
        return $this->map()[$gatewayId] ?? [];
    }

    /** May this method be offered to a shopper in $country? */
    public function allows(string $gatewayId, ?string $country): bool
    {
        $list = $this->for($gatewayId);

        if ($list === []) {
            return true;
        }

        $country = strtoupper(trim((string) $country));

        // No country named yet is the shop's own country, which is what the
        // checkout itself assumes before an address exists (its country list
        // defaults to store_country, and the public endpoint records 'AE').
        if ($country === '') {
            $country = strtoupper(trim((string) $this->settings->get('store_country', 'AE')));
        }

        return $country !== '' && in_array($country, $list, true);
    }

    /**
     * Store one method's list. Every code must be a real ISO-3166 alpha-2 code
     * this shop knows (Countries::NAMES); anything else is refused, not
     * repaired, so a typo can never quietly become "every country".
     *
     * @param  list<mixed>  $codes
     * @return string|null  the refusal, or null when saved
     */
    public function save(string $gatewayId, array $codes): ?string
    {
        $refused = $this->check($codes);

        if ($refused !== null) {
            return $refused;
        }

        $clean = [];

        foreach ($codes as $code) {
            $clean[strtoupper(trim((string) $code))] = true;
        }

        $map = $this->map();
        $map[$gatewayId] = array_keys($clean);
        ksort($map);

        $this->settings->set(self::SETTING, json_encode($map, JSON_UNESCAPED_SLASHES));
        $this->map = null;

        return null;
    }

    /**
     * Why these codes cannot be saved, or null when they can.
     *
     * @param  list<mixed>  $codes
     */
    public function check(array $codes): ?string
    {
        if (count($codes) > self::MAX) {
            return 'Too many countries.';
        }

        foreach ($codes as $code) {
            $code = is_string($code) ? strtoupper(trim($code)) : '';

            if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || ! isset(Countries::NAMES[$code])) {
                return 'Unknown country code.';
            }
        }

        return null;
    }

    /**
     * The countries the screen offers to tick: the ones this shop delivers to
     * (the same zone countries, then Extended Delivery's, that the checkout's
     * country list is built from), plus any code already saved, so a saved
     * choice is always visible and removable.
     *
     * @return array<string, string>  code => name
     */
    public function options(): array
    {
        $out = app(ShippingService::class)->coveredCountries();

        if ($out === []) {
            $out = ['AE' => Countries::NAMES['AE'] ?? 'United Arab Emirates'];
        }

        $extended = app(ExtendedDelivery::class);

        if ($extended->enabled()) {
            foreach (array_keys($extended->served()) as $code) {
                $out[$code] ??= Countries::NAMES[$code] ?? $code;
            }
        }

        foreach ($this->map() as $codes) {
            foreach ($codes as $code) {
                if (isset(Countries::NAMES[$code])) {
                    $out[$code] ??= Countries::NAMES[$code];
                }
            }
        }

        // Countries only. A zone location can be a region ("AE:DU"), which is
        // not something a payment method is offered in and save() would refuse.
        return array_filter(
            $out,
            static fn ($name, $code) => is_string($code) && isset(Countries::NAMES[$code]),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** @return array<string, list<string>> */
    private function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $raw = $this->settings->get(self::SETTING, null);

        if ($raw === null || $raw === '') {
            return $this->map = self::DEFAULTS;
        }

        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $map = [];

        // Read back as strictly as it was written: a hand-edited or damaged
        // row cannot widen a method to countries nobody chose.
        foreach (is_array($decoded) ? $decoded : [] as $id => $codes) {
            if (! is_string($id) || ! is_array($codes)) {
                continue;
            }

            $valid = array_values(array_filter(
                array_map(static fn ($c) => is_string($c) ? strtoupper($c) : '', $codes),
                static fn (string $c) => isset(Countries::NAMES[$c])
            ));

            // A list that named countries, none of them real, fails CLOSED:
            // an empty list would mean "every country", the widest answer.
            $map[$id] = ($valid === [] && $codes !== []) ? [self::NONE] : $valid;
        }

        return $this->map = $map;
    }
}
