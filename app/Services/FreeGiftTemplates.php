<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Admin\FreeGiftsController;
use App\Models\Product;
use App\Support\StoreTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Free gifts -> Ranges & gifts -> Templates (Lane GT, the owner 8 Nov 2026):
 * "save as template the whole gifts ranges in one single template, and from
 * the list i should be able to select and live instantly".
 *
 * A template is the WHOLE set of ranges and their gifts, as plain values:
 * every column of free_gift_ranges and free_gift_range_items except ids and
 * timestamps, gifts by product (and variation) id. Making one live replaces
 * every live range in ONE transaction -- all or nothing -- after copying the
 * ranges it replaces into an automatic "Before ..." template, so one click
 * always goes back. The last AUTO_KEEP of those are kept.
 *
 * RANGE IDS (what was checked before choosing to replace rather than edit):
 *   - gift offers (gift_offer_ranges / _items / _claims / _stats) are their
 *     OWN tables; no column there points at free_gift_ranges.
 *   - an order's gift lines keep `gift_range_id` and `gift_range_label` as a
 *     snapshot with no foreign key, and nothing reads the id back; screens
 *     and emails read the label. A placed order is not touched.
 *   - a cart holds no gift and no range: the box is worked out from the
 *     compiled ranges on every draw, and checkout does the same when the
 *     order is placed. carts.ct_gifts holds click COUNTS; the trail's "r12"
 *     click is turned into a label from today's ranges at display time.
 * So the live rows are deleted and the template's inserted with NEW ids
 * (auto-increment, never reused): an old "r12" then reads as a range that
 * no longer exists rather than borrowing the new range's label, and every
 * id an order kept stays the id it was. Nothing here is read by the shop;
 * FreeGifts::compile() runs after the commit, as every range save does.
 */
class FreeGiftTemplates
{
    public const NAME_MAX = 60;

    /** Automatic "Before ..." copies kept; older ones are deleted. */
    public const AUTO_KEEP = 5;

    private const RANGE_COLS = ['min_fils', 'max_fils', 'label_en', 'label_ar', 'sort', 'enabled', 'starts_at', 'ends_at', 'need_mask', 'need_mini', 'need_full'];

    private const DATE = '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/';

    public function __construct(private FreeGifts $gifts) {}

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('free_gift_templates');
    }

    /**
     * The list: ONE query, whatever the count. Summaries come from the
     * payload already read, never from the products table.
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array
    {
        return DB::table('free_gift_templates')
            ->orderBy('auto')->orderByDesc('updated_at')->orderByDesc('id')
            ->get(['id', 'name', 'payload', 'auto', 'live_rev', 'updated_at'])
            ->map(function ($t) {
                $ranges = self::decode((string) $t->payload);

                return [
                    'id' => (int) $t->id,
                    'name' => (string) $t->name,
                    'auto' => (bool) $t->auto,
                    'ranges' => count($ranges),
                    'gifts' => array_sum(array_map(static fn ($r) => count($r['items'] ?? []), $ranges)),
                    'summary' => self::summary($ranges),
                    // The shop's clock, the same one the "Before ..." names use.
                    'saved_at' => StoreTime::display($t->updated_at ? (string) $t->updated_at : null)?->format('j M Y, H:i'),
                    'live_rev' => $t->live_rev === null ? null : (string) $t->live_rev,
                ];
            })->values()->all();
    }

    /** "AED 0–299 · 300–499 · 500+ · 14 gifts" */
    public static function summary(array $ranges): string
    {
        if ($ranges === []) {
            return 'No ranges';
        }

        usort($ranges, static fn ($a, $b) => ($a['min_fils'] <=> $b['min_fils']));
        $parts = [];
        foreach ($ranges as $r) {
            $min = number_format(intdiv((int) $r['min_fils'], 100));
            $parts[] = $r['max_fils'] === null ? $min . '+' : $min . '–' . number_format(intdiv((int) $r['max_fils'], 100));
        }
        $n = array_sum(array_map(static fn ($r) => count($r['items']), $ranges));

        return 'AED ' . implode(' · ', $parts) . ' · ' . $n . ' ' . ($n === 1 ? 'gift' : 'gifts');
    }

    /**
     * Every live range and its gifts as plain values, plus the revision those
     * rows give (FreeGifts::revisionOf), from two reads.
     *
     * @return array{0: list<array<string,mixed>>, 1: string}
     */
    public function snapshot(bool $lock = false): array
    {
        $rq = DB::table('free_gift_ranges')->orderBy('id');
        $iq = DB::table('free_gift_range_items')->orderBy('sort')->orderBy('id');
        $ranges = ($lock ? $rq->lockForUpdate() : $rq)->get();
        $items = ($lock ? $iq->lockForUpdate() : $iq)->get();
        $byRange = $items->groupBy('range_id');

        $out = [];
        foreach ($ranges as $r) {
            $row = [];
            foreach (self::RANGE_COLS as $c) {
                $row[$c] = $r->{$c} ?? null;
            }
            $row['items'] = ($byRange[$r->id] ?? collect())->map(static fn ($i) => [
                'product_id' => (int) $i->product_id,
                'variant_id' => (int) ($i->variant_id ?? 0) > 0 ? (int) $i->variant_id : null,
                'quantity' => (int) $i->quantity,
                'sort' => (int) $i->sort,
                'kind' => $i->kind ?? null,
                'role' => $i->role ?? 'primary',
            ])->values()->all();
            $out[] = $row;
        }

        return [self::normalise($out), FreeGifts::revisionOf($ranges, $items)];
    }

    /**
     * The one shape a payload may have, on save and on apply. Anything that
     * is not it throws \InvalidArgumentException: a damaged template is
     * refused with nothing written, never half applied.
     *
     * @return list<array<string,mixed>>
     */
    public static function normalise(mixed $ranges): array
    {
        if (! is_array($ranges) || ! array_is_list($ranges)) {
            throw new \InvalidArgumentException('The template is damaged.');
        }
        if (count($ranges) > FreeGiftsController::MAX_RANGES) {
            throw new \InvalidArgumentException('A template holds at most ' . FreeGiftsController::MAX_RANGES . ' ranges.');
        }

        $int = static function (mixed $v, int $lo, int $hi): int {
            if (! is_int($v) && ! (is_string($v) && preg_match('/^\d{1,10}$/', $v) === 1)) {
                throw new \InvalidArgumentException('The template is damaged.');
            }
            $v = (int) $v;
            if ($v < $lo || $v > $hi) {
                throw new \InvalidArgumentException('The template is damaged.');
            }

            return $v;
        };
        $str = static function (mixed $v): string {
            if (! is_string($v) || mb_strlen($v) > 160) {
                throw new \InvalidArgumentException('The template is damaged.');
            }

            return $v;
        };
        $date = static function (mixed $v): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if (! is_string($v) || preg_match(self::DATE, $v) !== 1) {
                throw new \InvalidArgumentException('The template is damaged.');
            }

            return $v;
        };

        $out = [];
        foreach ($ranges as $r) {
            if (! is_array($r) || ! is_array($r['items'] ?? null) || ! array_is_list($r['items'])) {
                throw new \InvalidArgumentException('The template is damaged.');
            }
            if (count($r['items']) > FreeGiftsController::MAX_GIFTS) {
                throw new \InvalidArgumentException('A range holds at most ' . FreeGiftsController::MAX_GIFTS . ' gifts.');
            }

            $min = $int($r['min_fils'] ?? null, 0, 100_000_000);
            $max = ($r['max_fils'] ?? null) === null ? null : $int($r['max_fils'], 0, 100_000_000);
            $needs = [];
            foreach (['need_mask', 'need_mini', 'need_full'] as $n) {
                $needs[$n] = ($r[$n] ?? null) === null ? null : $int($r[$n], 0, FreeGifts::MAX_NEED);
            }

            $items = [];
            $pairs = [];
            foreach ($r['items'] as $i) {
                if (! is_array($i)) {
                    throw new \InvalidArgumentException('The template is damaged.');
                }
                $pid = $int($i['product_id'] ?? null, 1, PHP_INT_MAX >> 1);
                $vid = ($i['variant_id'] ?? null) === null ? null : $int($i['variant_id'], 1, PHP_INT_MAX >> 1);
                $pair = $pid . ':' . (int) $vid;
                if (isset($pairs[$pair])) {
                    continue; // the table's own unique key: one row per product and variation
                }
                $pairs[$pair] = true;
                $kind = $i['kind'] ?? null;
                if ($kind !== null && ! in_array($kind, FreeGifts::KINDS, true)) {
                    throw new \InvalidArgumentException('The template is damaged.');
                }
                $role = $i['role'] ?? 'primary';
                if (! in_array($role, ['primary', 'backup'], true)) {
                    throw new \InvalidArgumentException('The template is damaged.');
                }
                $items[] = [
                    'product_id' => $pid,
                    'variant_id' => $vid,
                    'quantity' => $int($i['quantity'] ?? null, 1, FreeGifts::MAX_QTY),
                    'sort' => $int($i['sort'] ?? 0, 0, 65535),
                    'kind' => $kind,
                    'role' => $role,
                ];
            }

            $out[] = [
                'min_fils' => $min,
                'max_fils' => $max,
                'label_en' => $str($r['label_en'] ?? ''),
                'label_ar' => $str($r['label_ar'] ?? ''),
                'sort' => $int($r['sort'] ?? 0, 0, 65535),
                'enabled' => (bool) filter_var($r['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'starts_at' => $date($r['starts_at'] ?? null),
                'ends_at' => $date($r['ends_at'] ?? null),
            ] + $needs + ['items' => $items];
        }

        return $out;
    }

    /** Save the live ranges as a new template, or over template $id. */
    public function save(string $name, ?int $id = null): array
    {
        return DB::transaction(function () use ($name, $id) {
            [$ranges, $rev] = $this->snapshot();
            if ($ranges === []) {
                throw new \InvalidArgumentException('There are no ranges to save yet.');
            }
            $now = now();
            $row = ['payload' => json_encode(['v' => 1, 'ranges' => $ranges]), 'live_rev' => $rev, 'updated_at' => $now];

            // The live ranges ARE this template now: no other row says "Live".
            DB::table('free_gift_templates')->whereNotNull('live_rev')->update(['live_rev' => null]);

            if ($id === null) {
                $id = (int) DB::table('free_gift_templates')->insertGetId($row + ['name' => $name, 'auto' => false, 'created_at' => $now]);
            } else {
                DB::table('free_gift_templates')->where('id', $id)->update($row);
            }

            return ['id' => $id, 'ranges' => count($ranges)];
        });
    }

    /**
     * Make template $id the live ranges. One transaction: the "Before ..."
     * copy, the delete, every insert. Anything throwing rolls all of it back
     * and the shop keeps the ranges it had. compile() after the commit.
     *
     * @return array{skipped:int, before:?string, ranges:int}
     */
    public function apply(int $id): array
    {
        $result = DB::transaction(function () use ($id) {
            $tpl = DB::table('free_gift_templates')->where('id', $id)->lockForUpdate()->first(['id', 'name', 'payload']);
            if ($tpl === null) {
                throw new \OutOfBoundsException('not_found');
            }
            $ranges = self::normalise(self::decode((string) $tpl->payload, true));

            // 1. The ranges being replaced, kept as an automatic template.
            [$current] = $this->snapshot(true);
            $before = null;
            if ($current !== []) {
                $before = $this->autoName((string) $tpl->name);
                $now = now();
                DB::table('free_gift_templates')->insert([
                    'name' => $before, 'payload' => json_encode(['v' => 1, 'ranges' => $current]),
                    'auto' => true, 'live_rev' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $keep = DB::table('free_gift_templates')->where('auto', true)->orderByDesc('id')->limit(self::AUTO_KEEP)->pluck('id')->all();
                // The one being made live is never pruned under itself.
                DB::table('free_gift_templates')->where('auto', true)->whereNotIn('id', $keep)->where('id', '!=', $id)->delete();
            }

            // 2. Which gifts can still be given: two reads, whatever the size.
            $pids = array_values(array_unique(array_merge([], ...array_map(static fn ($r) => array_column($r['items'], 'product_id'), $ranges))));
            $vids = array_values(array_filter(array_unique(array_merge([], ...array_map(static fn ($r) => array_column($r['items'], 'variant_id'), $ranges)))));
            $types = $pids === [] ? [] : Product::query()->whereIn('id', $pids)->pluck('type', 'id')->all();
            $owners = ($vids === [] || ! FreeGifts::hasVariant()) ? [] : DB::table('product_variants')->whereIn('id', $vids)->pluck('product_id', 'id')->all();

            // 3. Replace. Items first, explicitly: no reliance on the cascade.
            DB::table('free_gift_range_items')->delete();
            DB::table('free_gift_ranges')->delete();

            $skipped = 0;
            $now = now();
            foreach ($ranges as $r) {
                $items = $r['items'];
                unset($r['items']);
                $rangeId = DB::table('free_gift_ranges')->insertGetId($r + ['created_at' => $now, 'updated_at' => $now]);

                foreach ($items as $i) {
                    $type = $types[$i['product_id']] ?? null;
                    $vid = $i['variant_id'];
                    $ok = $type !== null && ($vid === null
                        ? $type !== 'variable'
                        : $type === 'variable' && (int) ($owners[$vid] ?? 0) === $i['product_id']);
                    if (! $ok) {
                        $skipped++;

                        continue;
                    }
                    DB::table('free_gift_range_items')->insert([
                        'range_id' => $rangeId,
                        'product_id' => $i['product_id'],
                    ] + ($vid !== null ? ['variant_id' => $vid] : []) + [
                        'quantity' => $i['quantity'],
                        'sort' => $i['sort'],
                        'kind' => $i['kind'],
                        'role' => $i['role'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            // 4. This template is what is live now.
            [, $rev] = $this->snapshot();
            DB::table('free_gift_templates')->whereNotNull('live_rev')->update(['live_rev' => null]);
            DB::table('free_gift_templates')->where('id', $id)->update(['live_rev' => $rev]);

            return ['skipped' => $skipped, 'before' => $before, 'ranges' => count($ranges)];
        });

        $this->gifts->compile();

        return $result;
    }

    /** "Before Eid set · 2026-11-08 14:05:09", inside NAME_MAX and unique. */
    private function autoName(string $of): string
    {
        $stamp = ' · ' . StoreTime::now()->format('Y-m-d H:i:s');
        $room = self::NAME_MAX - mb_strlen('Before ' . $stamp);
        $base = 'Before ' . (mb_strlen($of) > $room ? rtrim(mb_substr($of, 0, $room - 1)) . '…' : $of) . $stamp;
        $taken = DB::table('free_gift_templates')->where('name', 'like', mb_substr($base, 0, 50) . '%')->pluck('name')->all();
        $name = $base;
        for ($n = 2; in_array($name, $taken, true); $n++) {
            $name = mb_substr($base, 0, self::NAME_MAX - 4) . ' (' . $n . ')';
        }

        return $name;
    }

    /**
     * The ranges inside a stored payload. A list that cannot be read is
     * empty for the list (it still shows, so it can be deleted) and an error
     * for an apply.
     */
    private static function decode(string $json, bool $strict = false): array
    {
        $data = json_decode($json, true);
        $ranges = is_array($data) ? ($data['ranges'] ?? null) : null;

        if (! is_array($ranges)) {
            if ($strict) {
                throw new \InvalidArgumentException('The template is damaged.');
            }

            return [];
        }

        if ($strict) {
            return $ranges;
        }

        try {
            return self::normalise($ranges);
        } catch (\InvalidArgumentException) {
            return [];
        }
    }
}
