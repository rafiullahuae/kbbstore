<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where an order went, as one stored word: `orders.ship_emirate` (Lane EM).
 *
 * The owner, on Store → Orders: "i need here the Emirates / City column, so i
 * can see the orders are coming more from which city etc, and i can adjust my
 * campaigns." Then "The City = Emirate, so count the emirate data", with his
 * own short codes: "DXB, AUH, FUJ, ALAIN, UMQ, SHJ etc". AL AIN IS ITS OWN
 * BUCKET: a city in the Abu Dhabi emirate, counted apart from it because that
 * is how he plans campaigns.
 *
 * ── THE COLUMN ──────────────────────────────────────────────────────────────
 *
 *   'Dubai' … 'Fujairah', 'Al Ain'   the place, canonical English (NAMES)
 *   ''                               an address no UAE emirate can be read
 *                                    from: abroad, blank or unreadable
 *   NULL                             not read yet
 *
 * Counting and filtering are then one GROUP BY / WHERE on an indexed column,
 * whatever the number of orders -- never a JSON function per row in SQL.
 *
 * ── HOW IT IS FILLED, WITHOUT TOUCHING HOW ORDERS ARE WRITTEN ──────────────
 *
 * The checkout, the gateways and the order-creation path are locked (CLAUDE.md,
 * "Payments are frozen"), so nothing here hooks them. The migration fills every
 * row that exists; fillMissing() reads the newest NULL rows whenever the admin
 * Orders list or the owner app's orders are asked for, and the scheduler runs
 * it every minute (kbb:order-emirates). A new order therefore carries its
 * emirate within a minute, or the moment anybody looks. Editing an address on
 * the order screen writes the new answer straight away (AdminOrderController::
 * updateAddress).
 *
 * ── THE RULE (read()) ───────────────────────────────────────────────────────
 *
 *   1. The country is the shipping address's, else the billing one's. Not the
 *      UAE (and not blank) -> ''.
 *   2. Shipping first, then billing; each a UAE (or country-less) address:
 *      the emirate is AddressRegions::guess('AE', state, city) -- the checkout's
 *      own reading, so "dubai", "DXB", "AE-DU" and "دبي" are all Dubai, and a
 *      WooCommerce row with "Al Barsha" as the state and "Dubai" as the city
 *      is Dubai too.
 *   3. AL AIN: when that reading is Abu Dhabi or nothing, and the city, state
 *      or either address line names Al Ain ("Al Ain", "Al-Ain", "alain",
 *      "العين"), the answer is 'Al Ain'. Only then: "Al Ain Road" in a Dubai
 *      address stays Dubai. AddressRegions keeps mapping Al Ain to Abu Dhabi for
 *      the checkout -- that list is the shopper's, and this rule is not.
 *
 * ── A MIGRATION MUST NOT CALL THIS CLASS ────────────────────────────────────
 *
 * The updater runs migrations in a request where opcache can still serve the
 * old classes, and this one does not exist there (2.60.512 rolled back on
 * exactly that). The backfill migration carries its own literal copy of read();
 * OrderEmirateTest pins that the two agree and that no migration names this
 * class.
 */
final class OrderEmirate
{
    /** Canonical name => the owner's short code, in his order of importance. */
    public const CODES = [
        'Dubai' => 'DXB',
        'Abu Dhabi' => 'AUH',
        'Al Ain' => 'ALAIN',
        'Sharjah' => 'SHJ',
        'Ajman' => 'AJM',
        'Umm Al Quwain' => 'UMQ',
        'Ras Al Khaimah' => 'RAK',
        'Fujairah' => 'FUJ',
    ];

    /** The filter value for '' (abroad or unreadable). */
    public const OTHER = 'other';

    /** NULL rows read per request; the scheduler reads more. */
    public const REQUEST_LIMIT = 500;

    /** Rows per statement when the scheduler fills. */
    private const CHUNK = 500;

    /** Al Ain, as a word, in Latin or Arabic letters. */
    private const AL_AIN = '/(?<![\p{L}\p{N}])al[\s\-_]*ain(?![\p{L}\p{N}])|العين/iu';

    /** Set once a statement proves the column is not there yet (mid-update). */
    private static bool $missing = false;

    /** False while the new code runs against the old schema. */
    public static function ready(): bool
    {
        return ! self::$missing;
    }

    /** For tests: forget a missing column seen earlier in the process. */
    public static function reset(): void
    {
        self::$missing = false;
    }

    /** The short code for a stored value, or null for '' / NULL / anything else. */
    public static function code(?string $emirate): ?string
    {
        return self::CODES[(string) $emirate] ?? null;
    }

    /**
     * A filter value from a request -- a name ('Dubai'), a code ('DXB', any
     * case) or 'other' -- as the stored value it selects, or null when it is
     * none of those (junk is ignored, the way the Source filter ignores it).
     */
    public static function filterValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (strtolower($value) === self::OTHER) {
            return '';
        }

        foreach (self::CODES as $name => $code) {
            if (strcasecmp($value, $name) === 0 || strcasecmp($value, $code) === 0) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The stored value for an order's two address snapshots (array, JSON
     * string or null each). Never null: '' when nothing UAE can be read.
     */
    public static function read(mixed $shipping, mixed $billing): string
    {
        $ship = self::decode($shipping);
        $bill = self::decode($billing);

        $country = self::country($ship);
        if ($country === '') {
            $country = self::country($bill);
        }

        if ($country !== '' && $country !== 'AE') {
            return '';
        }

        foreach ([$ship, $bill] as $a) {
            if ($a === [] || ! in_array(self::country($a), ['', 'AE'], true)) {
                continue;
            }

            $emirate = AddressRegions::guess('AE', self::text($a, 'state'), self::text($a, 'city'));

            if ($emirate === null || $emirate === 'Abu Dhabi') {
                foreach (['city', 'state', 'line2', 'line1'] as $field) {
                    if (preg_match(self::AL_AIN, self::text($a, $field)) === 1) {
                        return 'Al Ain';
                    }
                }
            }

            if ($emirate !== null) {
                return $emirate;
            }
        }

        return '';
    }

    /**
     * Read the newest rows whose emirate is still NULL and store it, one
     * UPDATE per distinct answer -- so a chunk costs one SELECT and at most
     * nine UPDATEs whatever its size. Returns how many rows were filled.
     *
     * Never throws: this runs in front of a list, and a list must not 500
     * because the column is not there yet (new code against the old schema
     * for the seconds an update takes) or a row is odd.
     */
    public static function fillMissing(int $limit = self::REQUEST_LIMIT): int
    {
        if (self::$missing) {
            return 0;
        }

        $done = 0;

        try {
            while ($done < $limit) {
                $rows = DB::table('orders')
                    ->whereNull('orders.ship_emirate')
                    ->orderByDesc('id')
                    ->limit(min(self::CHUNK, $limit - $done))
                    ->get(['id', 'shipping_address', 'billing_address']);

                if ($rows->isEmpty()) {
                    break;
                }

                $groups = [];
                foreach ($rows as $row) {
                    $groups[self::read($row->shipping_address, $row->billing_address)][] = (int) $row->id;
                }

                foreach ($groups as $value => $ids) {
                    DB::table('orders')->whereIn('id', $ids)->whereNull('orders.ship_emirate')
                        ->update(['ship_emirate' => (string) $value]);
                }

                $done += $rows->count();

                if ($rows->count() < self::CHUNK) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            if (self::isMissingColumn($e)) {
                self::$missing = true;
            } else {
                report($e);
            }
        }

        return $done;
    }

    /** Whether an exception is "there is no ship_emirate column". */
    public static function isMissingColumn(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return stripos($message, 'ship_emirate') !== false
            && (stripos($message, 'no such column') !== false || stripos($message, 'unknown column') !== false
                || stripos($message, 'does not exist') !== false || stripos($message, '42S22') !== false);
    }

    /** Mark the column missing (a list caught the error itself). */
    public static function markMissing(): void
    {
        self::$missing = true;
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $a */
    private static function country(array $a): string
    {
        $c = strtoupper(self::text($a, 'country'));

        return in_array($c, ['UAE', 'U.A.E', 'U.A.E.', 'UNITED ARAB EMIRATES'], true) ? 'AE' : $c;
    }

    /** @param array<string, mixed> $a */
    private static function text(array $a, string $key): string
    {
        $v = $a[$key] ?? '';

        return is_scalar($v) ? trim((string) $v) : '';
    }
}
