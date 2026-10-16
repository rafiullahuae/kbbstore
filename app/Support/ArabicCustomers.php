<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * "Arabic customers" -- one definition, for Store -> Customers' filter and its
 * export, and for Marketing Emails' ready group (Lane AE).
 *
 * The owner: "i will want a separate list of customers who did from arabic
 * version, or include anything in arabic like their name or address etc. so i
 * can ... send them arabic content". A customer is on the list for any of four
 * reasons, each of which the list names on the row:
 *
 *   chose     customers.locale = 'ar' -- signed in, registered or ordered on
 *             /ar most recently, or the owner set Arabic by hand;
 *   ordered   any order of theirs placed in Arabic (orders.locale = 'ar');
 *   name      Arabic script in their name, first name or last name;
 *   address   Arabic script in a saved address, or in an order's delivery or
 *             billing address.
 *
 * ── ARABIC SCRIPT, PORTABLY ────────────────────────────────────────────────
 *
 * The Arabic block is U+0600-U+06FF. In UTF-8 every one of those characters
 * starts with a byte D8, D9, DA or DB, and no other character can put one of
 * those bytes at the start of a character (continuation bytes are 80-BF). So
 * "some character is Arabic" is exactly "the HEX of the text has D8-DB at an
 * even offset":
 *
 *     HEX(col) REGEXP '^(..)*D[89AB]'
 *
 * That is ASCII on both sides, so it means the same thing on MySQL 5.7 (whose
 * REGEXP is byte-wise and would mangle a literal Arabic range), MySQL 8 (ICU),
 * MariaDB (PCRE) and SQLite -- where REGEXP is a function the database does
 * not ship and registerSqlite() supplies, in PHP, on the connection. The even
 * offset is what keeps Korean out: 강 is EA B0 95, and an unanchored search
 * for "D8" would find one in plenty of Hangul and CJK strings.
 *
 * A JSON column written by PHP holds "ا", not the character -- json_encode
 * escapes by default -- so an order address is also matched on the literal
 * text `\u06`, which only the Arabic block produces. MySQL 8's native JSON
 * gives the characters back, which the HEX test catches.
 *
 * ── COST ───────────────────────────────────────────────────────────────────
 *
 * All of it is SQL inside the statement that is already running: the filter is
 * a WHERE, the reasons are four more columns, the chip count is one more SUM in
 * the chip-count statement. No query per row and no query per page -- the
 * customers list stays at the count it had, at 3 rows or 40.
 */
final class ArabicCustomers
{
    /** The hex test. A constant, never a setting, and never a binding. */
    public const HEX_PATTERN = '^(..)*D[89AB]';

    /** An escaped Arabic character in json_encode output. Bound, never inlined. */
    public const JSON_NEEDLE = '\\u06';

    public const REASONS = ['chose', 'ordered', 'name', 'address'];

    /** Make REGEXP exist on SQLite (tests, local); a no-op on MySQL/MariaDB. */
    public static function registerSqlite(): void
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $pdo = $connection->getPdo();

            if (method_exists($pdo, 'sqliteCreateFunction')) {
                $pdo->sqliteCreateFunction('regexp', static function ($pattern, $value): int {
                    return $value !== null && preg_match('/' . str_replace('/', '\\/', (string) $pattern) . '/u', (string) $value) === 1 ? 1 : 0;
                }, 2);
            }
        } catch (\Throwable) {
            // No REGEXP means the name/address halves cannot match; the rest still works.
        }
    }

    /** SQL: "this column holds an Arabic character". $column is a constant column name. */
    public static function script(string $column): string
    {
        return "(HEX(COALESCE({$column}, '')) REGEXP '" . self::HEX_PATTERN . "')";
    }

    /**
     * The four reasons as SQL, for the customer row whose id and columns are
     * reachable as `{$t}.id`, `{$t}.locale`, `{$t}.name` …, each with its
     * bindings. The locale reason only once its column exists.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function reasons(string $t = 'customers'): array
    {
        $ar = "'ar'";

        $orderAddress = '(INSTR(COALESCE(ox.shipping_address, \'\'), ?) > 0 OR INSTR(COALESCE(ox.billing_address, \'\'), ?) > 0 OR '
            . self::script('ox.shipping_address') . ' OR ' . self::script('ox.billing_address') . ')';

        $reasons = [
            'chose' => CustomerLocale::ready() ? ["({$t}.locale = {$ar})", []] : ['(1 = 0)', []],
            'ordered' => ["EXISTS (SELECT 1 FROM orders oe WHERE oe.customer_id = {$t}.id AND oe.locale = {$ar} AND oe.deleted_at IS NULL)", []],
            'name' => ['(' . self::script("{$t}.name") . ' OR ' . self::script("{$t}.first_name") . ' OR ' . self::script("{$t}.last_name") . ')', []],
            'address' => [
                '(EXISTS (SELECT 1 FROM addresses ax WHERE ax.customer_id = ' . $t . '.id AND ('
                    . implode(' OR ', array_map(static fn (string $c): string => self::script('ax.' . $c), ['first_name', 'last_name', 'line1', 'line2', 'city', 'state']))
                    . ')) OR EXISTS (SELECT 1 FROM orders ox WHERE ox.customer_id = ' . $t . '.id AND ox.deleted_at IS NULL AND ' . $orderAddress . '))',
                [self::JSON_NEEDLE, self::JSON_NEEDLE],
            ],
        ];

        return $reasons;
    }

    /**
     * The whole test, OR of the four, with its bindings in order.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function predicate(string $t = 'customers'): array
    {
        $sql = [];
        $bindings = [];

        foreach (self::reasons($t) as [$part, $b]) {
            $sql[] = $part;
            array_push($bindings, ...$b);
        }

        return ['(' . implode(' OR ', $sql) . ')', $bindings];
    }

    /**
     * The reason columns a selected row carries back, decoded to the list the
     * screen shows. Only the reasons that are true, in a fixed order.
     *
     * @return list<string>
     */
    public static function reasonsOf(object $row): array
    {
        $out = [];

        foreach (self::REASONS as $reason) {
            if ((int) ($row->{'ar_' . $reason} ?? 0) === 1) {
                $out[] = $reason;
            }
        }

        return $out;
    }
}
