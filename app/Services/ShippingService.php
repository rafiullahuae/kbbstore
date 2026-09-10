<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShippingMethod;
use App\Models\ShippingZone;

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
    /**
     * Every country covered by a zone with at least one enabled method,
     * code => name. This is the checkout's default country list — the six
     * Gulf countries in production — before Extended adds anything to it.
     */
    public function coveredCountries(): array
    {
        return ShippingZone::whereHas('methods', fn ($q) => $q->where('enabled', true))
            ->with('locations')
            ->get()
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

        if ($state) {
            $zone = ShippingZone::whereHas('locations', fn ($q) => $q
                ->where('type', 'state')
                ->where('code', $country . ':' . $state))
                ->orderBy('position')
                ->first();

            if ($zone) {
                return $zone;
            }
        }

        return ShippingZone::whereHas('locations', fn ($q) => $q
            ->where('type', 'country')
            ->where('code', $country))
            ->orderBy('position')
            ->first();
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
