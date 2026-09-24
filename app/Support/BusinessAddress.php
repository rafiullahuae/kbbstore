<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where this shop physically is, as the structured-data layer needs it.
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * `org_type` has offered `Store` and `LocalBusiness` since the SEO screen was
 * built, and choosing either bought NOTHING: the Organization node carried a
 * name, a url, a logo and sameAs, and not one property that makes a local
 * business local. A `LocalBusiness` with no address is a `LocalBusiness` Google
 * cannot place on a map, so the setting was a label on an empty box.
 *
 * ── THE GUARD, WHICH IS THE WHOLE POINT ─────────────────────────────────────
 *
 * A HALF-FILLED PostalAddress IS WORSE THAN NONE. A node that says
 * `{"addressCountry":"AE"}` and nothing else tells a search engine the shop
 * has published its address and that the address is "the UAE" -- which is a
 * worse answer than the silence it replaced, because silence leaves Google to
 * use the business listing it already has. So postal() answers null unless the
 * address is genuinely usable, and every caller treats null as "say nothing".
 *
 * Usable means street AND locality AND a country code this application
 * recognises. Those three are what a delivery driver needs and what Google
 * matches a Business Profile against.
 *
 * `postalCode` IS NOT REQUIRED, and that is not laziness. The UAE does not
 * operate a postal-code system for street addresses -- there are no ZIP codes
 * on Dubai shopfronts -- so requiring one would make a correct Dubai address
 * impossible to enter in a shop that trades from Dubai. The field exists for
 * the shops this software might serve elsewhere and is emitted when filled.
 *
 * `addressRegion` is optional for the same reason at one remove: the emirate
 * is genuinely useful ("Dubai", "Sharjah") but an address without it still
 * resolves, so it is emitted when present and never demanded.
 *
 * ── WHAT MAY GO ON WHICH @type ──────────────────────────────────────────────
 *
 * `address` and `telephone` are properties of Organization, so they are valid
 * whatever `org_type` says. `geo` and `openingHoursSpecification` are
 * properties of **Place**, and of the four types the setting offers only
 * `Store` and `LocalBusiness` are Places -- `Organization` and `OnlineStore`
 * are not. Emitting coordinates on an `OnlineStore` is invalid schema, so
 * isPlaceType() gates those two and only those two. An owner who fills in the
 * coordinates and leaves the type at `Organization` gets a valid document with
 * an address in it, rather than an invalid one with everything in it.
 */
final class BusinessAddress
{
    /** The `org_type` values that are schema.org Places and may carry geo/hours. */
    public const PLACE_TYPES = ['Store', 'LocalBusiness'];

    /**
     * The PostalAddress, or null when there is not enough to publish one.
     *
     * @param  array<string, mixed>  $s  the settings map, blanks already dropped
     * @return array<string, string>|null
     */
    public static function postal(array $s): ?array
    {
        $street = self::text($s, 'store_street');
        $locality = self::text($s, 'store_locality');
        $country = strtoupper(self::text($s, 'store_country'));

        /*
         * The country is checked for MEMBERSHIP, not merely for being two
         * letters. The settings screen stores it with the same `code` rule
         * `merchant_ship_country` uses, which accepts any two letters -- right
         * for that field, and not enough here, because this value is published
         * to Google as a fact about where the business is. A typo'd "AR" for
         * "AE" would be emitted as Argentina. An unrecognised code means no
         * address at all rather than a confident wrong one.
         */
        if ($street === '' || $locality === '' || ! array_key_exists($country, Countries::NAMES)) {
            return null;
        }

        $address = [
            '@type' => 'PostalAddress',
            'streetAddress' => $street,
            'addressLocality' => $locality,
        ];

        if (($region = self::text($s, 'store_region')) !== '') {
            $address['addressRegion'] = $region;
        }

        if (($postcode = self::text($s, 'store_postcode')) !== '') {
            $address['postalCode'] = $postcode;
        }

        $address['addressCountry'] = $country;

        return $address;
    }

    /**
     * GeoCoordinates, or null.
     *
     * BOTH HALVES OR NEITHER. A latitude on its own is not a location, and a
     * `geo` node carrying one is a node a consumer has to guess about.
     *
     * THE VALUES LEAVE HERE AS STRINGS, exactly as `CollectionSchema` emits a
     * price as a decimal string built from integer fils and never as the
     * column. schema.org lists Text among the expected types for latitude and
     * longitude, so this is not a compromise -- and it means the number Google
     * reads is character-for-character the number the owner typed, with no
     * float anywhere in the path to round 25.2048 into 25.204799999999999 on
     * some other host's `serialize_precision`. A coordinate is an identifier of
     * a point on the earth; it should survive the round trip exactly.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, string>|null
     */
    public static function geo(array $s): ?array
    {
        $lat = self::text($s, 'store_latitude');
        $lng = self::text($s, 'store_longitude');

        if (! self::isCoordinate($lat, 90) || ! self::isCoordinate($lng, 180)) {
            return null;
        }

        return [
            '@type' => 'GeoCoordinates',
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    /**
     * A coordinate in range, checked without constructing a float for the
     * shape test.
     *
     * The bound comparison does use one, which is safe: it asks whether a
     * value is inside a range, not what the value is, and a rounding error at
     * the sixteenth decimal cannot move a number across 90. The STORED string
     * is what gets emitted either way.
     */
    public static function isCoordinate(string $value, int $limit): bool
    {
        if (preg_match('/^-?\d{1,3}(\.\d{1,10})?$/', $value) !== 1) {
            return false;
        }

        return abs((float) $value) <= $limit;
    }

    /**
     * Everything the Organization node gains from the Business Details screen.
     *
     * Returned as one array to merge, rather than four accessors the caller
     * has to remember to call in the right order -- which is how the node ends
     * up with geo on an OnlineStore. The $orgType gate lives here, once,
     * beside the reason for it.
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    public static function organizationFragment(array $s, string $orgType): array
    {
        $out = [];

        if (($address = self::postal($s)) !== null) {
            $out['address'] = $address;
        }

        /*
         * The telephone is `support_phone` -- the number already on the
         * Business tab, already printed in the site header and footer. A second
         * "phone number for search engines" box would be a second place for one
         * fact, and the two would disagree within a month.
         */
        if (($phone = self::text($s, 'support_phone')) !== '') {
            $out['telephone'] = $phone;
        }

        if (! in_array($orgType, self::PLACE_TYPES, true)) {
            return $out;
        }

        if (($geo = self::geo($s)) !== null) {
            $out['geo'] = $geo;
        }

        if (($hours = OpeningHours::spec(self::text($s, 'store_hours'))) !== null) {
            $out['openingHoursSpecification'] = $hours;
        }

        return $out;
    }

    /** @param array<string, mixed> $s */
    private static function text(array $s, string $key): string
    {
        return isset($s[$key]) && is_scalar($s[$key]) ? trim((string) $s[$key]) : '';
    }
}
