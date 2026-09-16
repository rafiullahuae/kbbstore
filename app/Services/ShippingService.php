<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

/**
 * Zone matching and rate selection.
 *
 * Production runs two live zones: "All UAE" (AED 20 flat, free over AED 199) and
 * "Gulf Countries" across five locations (AED 150 flat, free over AED 1,600).
 * Nothing here assumes the UAE — the kbb-theme country lock would have made every
 * Gulf order impossible, so multi-country is the default, not an option (D-63).
 */
class ShippingService
{
    public const ZONES_KEY = 'kbb.shipping.zones';

    /**
     * Every zone, with its locations and its offerable methods, read ONCE.
     *
     * WHAT THIS REPLACES. Each answer about a destination cost two queries —
     * `whereHas('locations')` for the zone, then its `methods` lazily — and
     * nothing remembered either one. A storefront page asks between one and
     * four times:
     *
     *   every page      the header's free-delivery bar (StoreComposer)
     *   /cart           + the cart totals, + the drawer's totals
     *   /checkout       + the delivery list, + the country selector's list
     *
     * so /checkout ran the pair four times over and /cart three, and EVERY
     * page on the site paid for it at least once. Measured by
     * StorefrontQueryBudgetTest on its own fixture: eight of the checkout's
     * twenty-five queries and six of the cart's fifteen were the same handful
     * of rows, fetched again and again.
     *
     * WHY A CACHE AND NOT A PER-REQUEST MEMO. A memo takes the repeats off
     * /cart and /checkout and does nothing at all for the homepage, which asks
     * exactly once and still paid two queries for the answer. This is
     * configuration, not data — production runs two zones across six locations
     * and four methods, a dozen small rows in total — and it changes only when
     * the owner edits Store → Shipping.
     *
     * NOTHING GOES STALE. The eviction is not a TTL: the three models write
     * through flushZones() on every save and delete (registered in
     * AppServiceProvider, alongside the catalogue hooks that evict the homepage
     * fragments for the same reason). An edit on the shipping screen is live on
     * the next request, exactly as it was before this cache existed.
     *
     * The matching below is the same matching that used to be done in SQL,
     * moved into PHP over a dozen rows already in memory.
     */
    private function zones(): Collection
    {
        $zones = Cache::rememberForever(
            self::ZONES_KEY,
            fn () => ShippingZone::with(['locations', 'methods'])->get()
        );

        // A cache entry written by an older build must never take the
        // storefront down -- the same guard SettingsService uses.
        if (! $zones instanceof Collection) {
            Cache::forget(self::ZONES_KEY);

            return ShippingZone::with(['locations', 'methods'])->get();
        }

        return $zones;
    }

    /** Called whenever a zone, a location or a method is written. */
    public static function flushZones(): void
    {
        Cache::forget(self::ZONES_KEY);
    }

    /**
     * Every country covered by a zone with at least one enabled method,
     * code => name. This is the checkout's default country list — the six
     * Gulf countries in production — before Extended adds anything to it.
     *
     * `methods` is already the enabled-only relation (see ShippingZone), which
     * is why the old query asked for `enabled = ?` twice. Zone order is the
     * unordered `get()` it always was — this list is the checkout's country
     * dropdown, and reordering it is a visible change nobody asked for.
     */
    public function coveredCountries(): array
    {
        return $this->zones()
            ->filter(fn (ShippingZone $z) => $z->methods->isNotEmpty())
            ->flatMap(fn (ShippingZone $z) => $z->locations)
            ->pluck('code')
            ->unique()
            ->map(fn ($c) => strtoupper($c))
            ->mapWithKeys(fn ($code) => [$code => \App\Support\Countries::NAMES[$code] ?? $code])
            ->all();
    }

    /** Most specific zone wins: state match beats country match. */
    public function zoneFor(?string $country, ?string $state = null): ?ShippingZone
    {
        if (! $country) {
            return null;
        }

        $country = strtoupper($country);

        // sortBy('position') where the query said orderBy('position'), and
        // first() where it said first().
        $byPosition = $this->zones()->sortBy('position');

        if ($state) {
            $zone = $byPosition->first(fn (ShippingZone $z) => $this->hasLocation($z, 'state', $country . ':' . $state));

            if ($zone) {
                return $zone;
            }
        }

        return $byPosition->first(fn (ShippingZone $z) => $this->hasLocation($z, 'country', $country));
    }

    private function hasLocation(ShippingZone $zone, string $type, string $code): bool
    {
        return $zone->locations->contains(
            fn (ShippingZoneLocation $l) => $l->type === $type && $l->code === $code
        );
    }

    /**
     * Rates available for a cart subtotal, in fils.
     *
     * When free shipping qualifies, paid rates are dropped so the customer is not
     * offered the chance to pay for something they have already earned. This
     * mirrors the theme's hide_paid_when_free behaviour and is on by default.
     */
    public function ratesFor(?string $country, ?string $state, int $subtotalFils, bool $hidePaidWhenFree = true): array
    {
        // Extended delivery adds countries on top of the zones; it does not
        // replace them. A country the admin has switched on under Extended is
        // answered from there; every other country — including every zone
        // country, whether Extended is on or off — is answered from the zone
        // exactly as before. The admin screen's country picker excludes zone
        // countries for this reason: one country, one place that decides its
        // rate, never two.
        $extended = app(ExtendedDelivery::class);

        if ($country !== null && $extended->enabled() && $extended->serves($country)) {
            return $extended->ratesFor($country, $subtotalFils, $hidePaidWhenFree);
        }

        $zone = $this->zoneFor($country, $state);
        if (! $zone) {
            return [];
        }

        $rates = [];
        foreach ($zone->methods as $method) {
            if ($method->isFree()) {
                $threshold = (int) ($method->min_amount ?? 0);
                if ($threshold > 0 && $subtotalFils < $threshold) {
                    continue;   // not qualified yet
                }
                $rates[] = $this->rate($method, 0);

                continue;
            }

            $rates[] = $this->rate($method, (int) $method->cost);
        }

        if ($hidePaidWhenFree && collect($rates)->contains(fn ($r) => $r['cost'] === 0 && $r['type'] === 'free_shipping')) {
            $rates = array_values(array_filter($rates, fn ($r) => $r['type'] === 'free_shipping'));
        }

        return $rates;
    }

    /**
     * The free-shipping threshold for a destination, in fils, or null when the
     * zone has no free-shipping method. Read from the shipping method, never a
     * constant — production uses 199 for the UAE and 1,600 for the Gulf.
     */
    public function freeShippingThreshold(?string $country, ?string $state = null): ?int
    {
        $extended = app(ExtendedDelivery::class);

        if ($country !== null && $extended->enabled() && $extended->serves($country)) {
            return $extended->thresholdFor($country);
        }

        $zone = $this->zoneFor($country, $state);
        if (! $zone) {
            return null;
        }

        $free = $zone->methods->firstWhere('type', 'free_shipping');

        return $free && $free->min_amount ? (int) $free->min_amount : null;
    }

    /**
     * The same threshold, for WHEREVER THIS VISITOR IS STANDING.
     *
     * WHY THIS EXISTS. The storefront advertises a free-delivery figure in four
     * places — partials/announcement.blade.php, the home page's delivery band,
     * its ticker and its trust row — and every one of them used to name the
     * shop's own country or, worse, a number typed into the Blade.
     *
     * (The first of those four is not actually live: no layout includes
     * partials/announcement.blade.php. This comment said "the announcement bar
     * on every page" and Lane DM corrected it. The rule below is unchanged —
     * the other three callers are real, and the partial will need it if it is
     * ever wired back up.) The
     * figure is per-destination: production runs 199 for the UAE and 1,600 for
     * the Gulf, and a shop on Extended Delivery carries a `free_from` per
     * country. So a shopper in Riyadh was shown the Dubai number.
     *
     * One method rather than four call sites, for the same reason
     * App\Support\DeliveryLine exists: four readers of one rule is four places
     * for it to drift. This is the FIGURE; DeliveryLine is the SENTENCE.
     *
     * NULL IS A REAL ANSWER and means the shop records no free delivery where
     * this visitor is. Nothing is invented to fill it — every caller drops the
     * claim instead. The old announcement bar did the opposite: with no
     * free-shipping method configured at all it still printed a figure of its
     * own, so a shop that had never offered free delivery advertised it.
     *
     * COSTS NOTHING TO ASK TWICE. Memoised on the Request, keyed by the country
     * it answered for, so the composer and the home page share one answer and a
     * country changing mid-request (the checkout's selector, through
     * ShopperCountry::remember()) simply misses the memo and re-asks. On the
     * Request rather than in a class static, deliberately: a process-level
     * static is the trap CLAUDE.md records against Setting::map(), correct
     * under PHP-FPM and wrong in a queue worker or a test process.
     *
     * AND IT MUST NEVER BE CACHED IN A SHARED CACHE. See the note above the
     * header extras in App\View\Composers\StoreComposer: a per-visitor value
     * behind a global cache key means every shopper is shown whichever country
     * happened to warm it.
     */
    public function thresholdHere(?\Illuminate\Http\Request $request = null): ?int
    {
        $request ??= request();

        $country = \App\Support\ShopperCountry::for($request)->code;
        $key = 'kbb.free_ship_threshold.' . $country;

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $threshold = $this->freeShippingThreshold($country);

        $request->attributes->set($key, $threshold);

        return $threshold;
    }

    /** How much more is needed to unlock free delivery. Zero once unlocked. */
    public function amountToFreeShipping(?string $country, ?string $state, int $subtotalFils): ?int
    {
        $threshold = $this->freeShippingThreshold($country, $state);
        if ($threshold === null) {
            return null;
        }

        return max(0, $threshold - $subtotalFils);
    }

    private function rate(ShippingMethod $method, int $cost): array
    {
        return [
            'id' => $method->id,
            'type' => $method->type,
            'title' => $method->title,
            'cost' => $cost,
            'zone' => $method->shipping_zone_id,
        ];
    }
}
