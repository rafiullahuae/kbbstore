<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Which orders have this product in them?" (Lane OQ) -- ONE rule, asked by
 * Store → Orders in the admin and by the owner app's Orders search.
 *
 * The owner: "if we search any product on the orders page, the list of orders
 * should come up which ever has that product in order. so we can be sure what
 * customers order that specific product, and easily track ourselves."
 *
 * ── WHAT MATCHES ───────────────────────────────────────────────────────────
 *
 * An order line matches when EVERY word typed appears in its product name, its
 * SKU or its option snapshot (variant_attributes: "Shade: #13 Bright Beige").
 * Word by word, not the whole phrase, so "medicube jelly" finds "Medicube PDRN
 * Jelly Cream" -- the way he remembers a product, not the way it is spelled on
 * the box. Words are capped (MAX_WORDS) and so is the term (MAX_TERM): the
 * statement's size is bounded whatever is pasted into the box.
 *
 * An imported WooCommerce line stores its name HTML-encoded ("SKIN&amp;LAB");
 * a word with "&" in it is also tried in that form, so "skin&lab" finds both.
 *
 * ── HOW IT COSTS NOTHING PER ROW ───────────────────────────────────────────
 *
 * `orders.id IN (SELECT order_id FROM order_items WHERE …)`, NOT a correlated
 * EXISTS. Under the OR that joins it to the email / phone / name columns MySQL
 * cannot turn an EXISTS into a semi-join, so it would re-run the subquery for
 * every order on the table; an uncorrelated IN is materialised once (one scan
 * of order_items) and probed by hash. A leading-wildcard LIKE cannot use a
 * b-tree index whatever is indexed, so no index is added for it: order_id is
 * already indexed (2027_10_24_100000) and that is the one the lookups below use.
 *
 * ── SAFETY ─────────────────────────────────────────────────────────────────
 *
 * Every pattern is bound, % and _ are escaped with an explicit ESCAPE '!' (the
 * same constant both order screens already use, identical on MySQL and
 * SQLite), and column names are literals here, never request input.
 */
final class OrderProductSearch
{
    /** The longest term looked at; the rest of a paste is dropped. */
    public const MAX_TERM = 100;

    /** Words beyond this are ignored, so the statement stays a fixed size. */
    public const MAX_WORDS = 6;

    /** One letter matches half the catalogue; a line match needs two. */
    public const MIN_TERM = 2;

    /** Chips shown under one order's row; the rest are counted. */
    public const PER_ORDER = 2;

    /** Products named in the summary line above the list. */
    public const SUMMARY_TOP = 3;

    public const LIKE_ESCAPE = '!';

    public static function term(mixed $raw): string
    {
        return trim(mb_substr(trim(is_string($raw) ? $raw : ''), 0, self::MAX_TERM));
    }

    public static function applies(string $term): bool
    {
        return mb_strlen($term) >= self::MIN_TERM && self::words($term) !== [];
    }

    /** @return list<string> */
    public static function words(string $term): array
    {
        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::MAX_WORDS);
    }

    public static function escape(string $s): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $s
        );
    }

    /**
     * Constrain a query over order_items (aliased $a) to the lines the term
     * matches. Every word must appear in the name, the SKU or the options.
     */
    public static function constrainLines(Builder $q, string $a, string $term): Builder
    {
        foreach (self::words($term) as $word) {
            $like = '%'.self::escape($word).'%';
            $esc = " like ? escape '".self::LIKE_ESCAPE."'";

            $q->where(function (Builder $w) use ($a, $like, $word, $esc) {
                $w->whereRaw($a.'.name'.$esc, [$like])
                    ->orWhereRaw($a.'.sku'.$esc, [$like])
                    ->orWhereRaw($a.'.variant_attributes'.$esc, [$like]);

                if (str_contains($word, '&')) {
                    $w->orWhereRaw($a.'.name'.$esc, ['%'.self::escape(str_replace('&', '&amp;', $word)).'%']);
                }
            });
        }

        return $q;
    }

    /** SELECT order_id FROM order_items WHERE <the line matches>, for an IN. */
    public static function orderIdsQuery(string $term): Builder
    {
        return self::constrainLines(DB::table('order_items as oqs_i')->select('oqs_i.order_id'), 'oqs_i', $term);
    }

    /**
     * The matching lines of the orders on one page, for the "why it matched"
     * chips: ONE statement for the whole page, whatever its size.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{lines: list<array<string, mixed>>, more: int}>
     */
    public static function matchedLines(array $orderIds, string $term): array
    {
        if ($orderIds === [] || ! self::applies($term)) {
            return [];
        }

        $rows = self::constrainLines(
            DB::table('order_items as oqs_m')
                ->whereIn('oqs_m.order_id', $orderIds)
                ->select(['oqs_m.order_id', 'oqs_m.name', 'oqs_m.sku', 'oqs_m.variant_attributes', 'oqs_m.quantity', 'oqs_m.is_gift'])
                ->orderBy('oqs_m.order_id')->orderBy('oqs_m.id'),
            'oqs_m',
            $term
        )->get();

        $out = [];

        foreach ($rows as $r) {
            $id = (int) $r->order_id;
            $out[$id] ??= ['lines' => [], 'more' => 0];

            if (count($out[$id]['lines']) >= self::PER_ORDER) {
                $out[$id]['more']++;

                continue;
            }

            $out[$id]['lines'][] = [
                'name' => (string) PlainText::decode((string) $r->name),
                'variant' => VariantText::line($r->variant_attributes, ' · '),
                'sku' => (string) ($r->sku ?? ''),
                'qty' => (int) $r->quantity,
                'gift' => (bool) $r->is_gift,
            ];
        }

        return $out;
    }

    /**
     * "Medicube PDRN Jelly Cream — in 37 orders · 52 pieces", for the orders
     * in the view: ONE grouped statement. $orderIds selects the view's ids.
     *
     * @return array{products: list<array<string, mixed>>, more: bool}|null
     */
    public static function summary(Builder $orderIds, string $term): ?array
    {
        if (! self::applies($term)) {
            return null;
        }

        $rows = self::constrainLines(
            DB::table('order_items as oqs_s')
                ->whereIn('oqs_s.order_id', $orderIds)
                ->groupBy('oqs_s.name')
                ->selectRaw('oqs_s.name as name, COUNT(DISTINCT oqs_s.order_id) as orders,'
                    .' COALESCE(SUM(CASE WHEN oqs_s.is_gift = 1 THEN 0 ELSE oqs_s.quantity END), 0) as pieces,'
                    .' COALESCE(SUM(CASE WHEN oqs_s.is_gift = 1 THEN oqs_s.quantity ELSE 0 END), 0) as gift_pieces')
                ->orderByDesc('orders')->orderByDesc('pieces')->orderBy('oqs_s.name')
                ->limit(self::SUMMARY_TOP + 1),
            'oqs_s',
            $term
        )->get();

        if ($rows->isEmpty()) {
            return null;
        }

        return [
            'products' => $rows->take(self::SUMMARY_TOP)->map(fn ($r) => [
                'name' => (string) PlainText::decode((string) $r->name),
                'orders' => (int) $r->orders,
                'pieces' => (int) $r->pieces,
                'gift_pieces' => (int) $r->gift_pieces,
            ])->values()->all(),
            'more' => $rows->count() > self::SUMMARY_TOP,
        ];
    }
}
