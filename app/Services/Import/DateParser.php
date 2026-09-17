<?php

declare(strict_types=1);

namespace App\Services\Import;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * WooCommerce date text in, UTC instant out, or a rejection.
 *
 * WHY THIS IS NOT Carbon::parse(). Two reasons, and the second is the one that
 * costs money.
 *
 * 1. Carbon::parse() is lenient to the point of being dangerous on import data.
 *    It reads "0000-00-00 00:00:00" — which is what a MySQL 5.x WooCommerce
 *    writes for "never" — as a real date, and it reads a bare number as a time.
 *    An importer that accepts anything produces rows that look well-formed and
 *    are wrong. Only the shapes listed in FORMATS are accepted here; anything
 *    else is a rejection with the value quoted, so the owner can see what their
 *    export actually contains.
 *
 * 2. A date with no offset is NOT UTC. WooCommerce writes `post_date` in the
 *    site's WordPress timezone and `post_date_gmt` in UTC, and the common CSV
 *    exporters emit the local one. This schema stores UTC. Reading a local
 *    timestamp as UTC shifts every order in the store by the offset — four
 *    hours for Asia/Dubai — which silently moves orders placed after 20:00
 *    onto the next day and makes every daily revenue figure disagree with
 *    WooCommerce's own reports by a sliver that nobody can account for.
 *
 *    So the source timezone is a required argument, not a default. A caller
 *    that does not know it has to go and find out.
 *
 * WHY orders.created_at MATTERS MORE THAN IT LOOKS. Store -> Customers derives
 * "last order" as MAX(CASE WHEN status IN (...) THEN created_at END) — it is
 * created_at, not paid_at. An importer that lets Eloquent stamp created_at with
 * now() does not merely lose the order date: it makes every customer in the
 * store look like they bought something today, reversing the meaning of every
 * recency segment and sort on that screen at once, and doing it silently
 * because the data looks perfectly well-formed.
 */
final class DateParser
{
    /**
     * Accepted shapes, most specific first.
     *
     * The `!` prefix zeroes every field the format does not set, so 'Y-m-d'
     * yields midnight rather than the current time of day — without it, a
     * date-only column would import as "2019-03-04 at whatever o'clock the
     * import happened to run", and a re-run would produce a different value
     * for the same source row, which would make the import non-idempotent in a
     * way that is very hard to see.
     *
     * @var list<string>
     */
    private const FORMATS = [
        '!Y-m-d\TH:i:sP',
        '!Y-m-d\TH:i:sO',
        '!Y-m-d\TH:i:s\Z',
        '!Y-m-d\TH:i:s',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
        '!Y-m-d',
        '!Y/m/d H:i:s',
        '!Y/m/d',
    ];

    /**
     * Values a WooCommerce export uses to mean "no date", which must become
     * NULL and not 1 January 1970 — the store has already shipped a "last
     * active: 1 Jan 1970" bug once, on every customer who had never ordered.
     *
     * @var list<string>
     */
    private const EMPTY_VALUES = [
        '',
        '0',
        '0000-00-00',
        '0000-00-00 00:00:00',
        '1970-01-01 00:00:00',
        'null',
        'NULL',
    ];

    /**
     * @param  string  $field  the column name, for the rejection message
     * @param  string  $sourceTimezone  the WordPress site timezone the export's
     *                                  naked timestamps are written in
     *
     * @throws RowRejected
     */
    public static function utc(mixed $raw, string $field, string $sourceTimezone): ?CarbonImmutable
    {
        if ($raw === null) {
            return null;
        }

        $value = trim((string) $raw);

        if (in_array($value, self::EMPTY_VALUES, true)) {
            return null;
        }

        // A UNIX timestamp is unambiguous and offset-free, so it is accepted
        // as-is rather than run through the timezone conversion below.
        if (preg_match('/^\d{9,11}$/', $value) === 1) {
            return self::plausible(CarbonImmutable::createFromTimestampUTC((int) $value), $field, $value);
        }

        $carriesOffset = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
        $zone = $carriesOffset ? new DateTimeZone('UTC') : self::zone($sourceTimezone, $field);

        foreach (self::FORMATS as $format) {
            /*
             * \DateTimeImmutable and not CarbonImmutable, deliberately. Carbon
             * THROWS on a string it cannot read, which would turn every
             * candidate format after the first into an exception to catch, and
             * — worse — it is lenient where this needs to be strict.
             *
             * PHP's own createFromFormat returns a value for a string it only
             * PARTLY understands: "2019-03-04 garbage" parses under '!Y-m-d'
             * with the remainder reported as a warning rather than an error,
             * and "2019-13-45" rolls over into 2020. Reading getLastErrors()
             * and rejecting anything with a warning OR an error is the whole
             * difference between "this is a date" and "this begins with
             * something date-shaped".
             */
            $parsed = \DateTimeImmutable::createFromFormat($format, $value, $zone);

            if ($parsed === false) {
                continue;
            }

            $check = \DateTimeImmutable::getLastErrors();

            if (is_array($check) && (($check['warning_count'] ?? 0) > 0 || ($check['error_count'] ?? 0) > 0)) {
                continue;
            }

            return self::plausible(
                CarbonImmutable::instance($parsed)->setTimezone('UTC'),
                $field,
                $value,
            );
        }

        throw RowRejected::because(
            $field.": '".$raw."' is not a date this importer recognises "
            .'(expected Y-m-d, Y-m-d H:i:s, or an ISO 8601 timestamp)'
        );
    }

    /**
     * Like utc(), but a missing date is itself the rejection.
     *
     * For orders, where `created_at` is load-bearing: an order with no usable
     * date is better refused and reported than imported with a date the
     * importer invented.
     *
     * @throws RowRejected
     */
    public static function requiredUtc(mixed $raw, string $field, string $sourceTimezone): CarbonImmutable
    {
        $parsed = self::utc($raw, $field, $sourceTimezone);

        if ($parsed === null) {
            throw RowRejected::because(
                $field.' is empty, and it is the date the Customers screen reads as "last order" — '
                .'importing it as today would make this customer look like they bought something today'
            );
        }

        return $parsed;
    }

    /**
     * Check a declared source timezone against the export's own GMT column.
     *
     * THE ONE ASSUMPTION IN THIS IMPORTER THAT NOTHING COULD CHECK. `--timezone`
     * is a value the owner types, it defaults to Asia/Dubai, and getting it
     * wrong shifts every order in the store by four hours in a way that leaves
     * no trace: the rows are well-formed, the totals reconcile, and the only
     * symptom is that daily revenue disagrees with WooCommerce's own reports by
     * a sliver nobody can account for. The shop has already paid for this once
     * -- the plan's "The shop's own clock" is the same bug arriving from the
     * other direction, where every order placed between midnight and 4am was
     * filed under the previous day.
     *
     * WHAT MAKES IT CHECKABLE. WooCommerce stores both: `post_date` in the
     * site's WordPress timezone and `post_date_gmt` in UTC, and its exporters
     * emit both as `date_created` and `date_created_gmt`. When a row carries
     * both, the difference between them IS the site's offset at that instant,
     * measured rather than declared. If parsing the local column under the
     * declared zone does not land on the GMT column, the declared zone is
     * wrong, and it is wrong for every row in the file.
     *
     * DST IS WHY THIS COMPARES INSTANTS AND NOT A FIXED NUMBER OF HOURS.
     * Asia/Dubai has no daylight saving, but an export from a store that was
     * configured in Europe/London does, and an offset check hard-coded to one
     * number would pass in January and fail in July on the same correct zone.
     * Converting the local reading to UTC and comparing it with the GMT column
     * asks the question that is actually being asked.
     *
     * Returns null when the row does not carry both columns, which is most
     * exporters and is not an error -- it is simply a run this check cannot
     * make, and the report says so rather than implying a verification that
     * did not happen.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null  [what the declared
     *         zone gives, what the GMT column says] when they DISAGREE; null when
     *         they agree or the check cannot be made.
     *
     * @throws RowRejected
     */
    public static function disagreementWithGmt(
        mixed $localRaw,
        mixed $gmtRaw,
        string $field,
        string $sourceTimezone,
    ): ?array {
        if ($localRaw === null || $gmtRaw === null) {
            return null;
        }

        $local = self::utc($localRaw, $field, $sourceTimezone);
        // The GMT column is UTC by definition, whatever the site timezone is.
        $gmt = self::utc($gmtRaw, $field.'_gmt', 'UTC');

        if ($local === null || $gmt === null) {
            return null;
        }

        // A whole-second comparison: the two columns are written by the same
        // WordPress call and differ only by the offset, never by a fraction.
        return $local->equalTo($gmt) ? null : [$local, $gmt];
    }

    /**
     * A date far outside the life of an e-commerce store is much more likely to
     * be a mis-parse than a real value, and a mis-parse that lands in 1901 or
     * 3013 sorts to one end of every date-ordered screen where it is maximally
     * confusing.
     *
     * @throws RowRejected
     */
    private static function plausible(CarbonImmutable $date, string $field, string $raw): CarbonImmutable
    {
        $year = (int) $date->format('Y');

        if ($year < 1995 || $year > 2100) {
            throw RowRejected::because(
                $field.": '".$raw."' parses to ".$date->toDateString()
                .', which is outside the plausible life of this store — it is more likely a mis-read value than a real date'
            );
        }

        return $date;
    }

    /**
     * @throws RowRejected
     */
    private static function zone(string $timezone, string $field): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (\Throwable) {
            throw RowRejected::because(
                $field.": '".$timezone."' is not a timezone — pass the WordPress site timezone, e.g. Asia/Dubai"
            );
        }
    }
}
