<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Order;
use App\Services\NewsletterList;
use App\Support\CustomerAggregates;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who a campaign goes to — Marketing Emails → Customer groups (Lane MK,
 * docs/EMAILS-PLAN.md §3 and the owner's D3/D4).
 *
 * ── TWO LISTS, NEVER MIXED ─────────────────────────────────────────────────
 *
 *   customers    people who ordered or have an account (`customers`, not
 *                deleted). The figures are CustomerAggregates — the same
 *                derived aggregate Store → Customers shows, so "spent AED
 *                500+" is the same people on both screens.
 *   subscribers  newsletter sign-ups (`subscribers`).
 *
 * ── RULES ARE DATA FROM A CLOSED VOCABULARY ────────────────────────────────
 *
 * A rule is {field, op, value}. clean() keeps a field of FIELDS for that
 * list, an operator that field lists, and a value of the shape that operator
 * takes; anything else is refused with a sentence. compile() turns each into
 * a WHERE written HERE with bound values — nothing the admin sends is ever
 * part of a statement's text.
 *
 * ── MATCHING IS NOT THE SAME AS "CAN BE EMAILED" ───────────────────────────
 *
 * Everyone matched is classified, in SQL, into exactly one of:
 *
 *   ok            can be emailed
 *   invalid       the address is not one mail can be sent to
 *   bounced       email_suppressions says bounce or complaint
 *   unsubscribed  email_suppressions (unsubscribe/manual), outbound_optouts,
 *                 or a newsletter row marked unsubscribed
 *   pending       a newsletter sign-up never confirmed (double opt-in)
 *
 * "N match · M can be emailed" is the first number and the `ok` count, and
 * the difference is printed with its reasons. Suppressed, opted-out and
 * pending addresses are NEVER in an audience — recipients() reads only `ok`.
 *
 * Built for the size of this shop: count() is one statement, page() is one,
 * a list-building slice is one. No query per person.
 */
final class Audience
{
    public const AUDIENCES = ['customers' => 'Customers', 'subscribers' => 'Subscribers'];

    public const PAGE_SIZE = 50;

    public const MAX_RULES = 12;

    /** Reasons, in the order they are decided and printed. */
    public const REASONS = [
        'invalid' => 'address cannot receive mail',
        'bounced' => 'bounced or complained before',
        'unsubscribed' => 'unsubscribed',
        'pending' => 'newsletter sign-up not confirmed',
    ];

    /**
     * field => [label, list, [op => value kind], help].
     *
     * Value kinds: num (a whole number, or [from, to] for between), money
     * (AED), days (a whole number of days), date (YYYY-MM-DD), month
     * (YYYY-MM), year (YYYY), range ([YYYY-MM-DD, YYYY-MM-DD]), none,
     * emirates (keys of EMIRATES), name (a short text), id (a positive id),
     * country (two letters).
     */
    public const FIELDS = [
        'spent' => ['Total spent (AED)', 'customers', ['gte' => 'money', 'lte' => 'money', 'gt' => 'money', 'lt' => 'money', 'eq' => 'money', 'between' => 'money_range']],
        'orders' => ['Number of orders', 'customers', ['gte' => 'num', 'lte' => 'num', 'gt' => 'num', 'lt' => 'num', 'eq' => 'num', 'between' => 'num_range']],
        'avg_order' => ['Average order (AED)', 'customers', ['gte' => 'money', 'lte' => 'money', 'gt' => 'money', 'lt' => 'money', 'between' => 'money_range']],
        'emirate' => ['Emirate', 'customers', ['any_of' => 'emirates', 'none_of' => 'emirates']],
        'order_date' => ['Order date', 'customers', ['in_month' => 'month', 'in_year' => 'year', 'between' => 'range']],
        'brand' => ['Brands bought', 'customers', ['mostly' => 'name', 'ever' => 'name']],
        'last_order' => ['Last order', 'customers', ['within_days' => 'days', 'more_than_days' => 'days', 'before' => 'date', 'after' => 'date']],
        'first_order' => ['First order', 'customers', ['within_days' => 'days', 'more_than_days' => 'days', 'before' => 'date', 'after' => 'date']],
        'never_ordered' => ['Never ordered', 'customers', ['yes' => 'none', 'no' => 'none']],
        'product' => ['Bought a product', 'customers', ['is' => 'id']],
        'category' => ['Bought from a category', 'customers', ['is' => 'id']],
        'coupon' => ['Used a coupon', 'customers', ['is' => 'name', 'any' => 'none']],
        'country' => ['Country', 'customers', ['is' => 'country', 'is_not' => 'country']],
        'city' => ['City', 'customers', ['is' => 'name']],
        'has_account' => ['Has an account', 'customers', ['yes' => 'none', 'no' => 'none']],
        'newsletter' => ['Newsletter', 'customers', ['yes' => 'none', 'no' => 'none']],
        'customer_since' => ['Customer since', 'customers', ['within_days' => 'days', 'before' => 'date', 'after' => 'date']],

        'signed_up' => ['Signed up', 'subscribers', ['in_month' => 'month', 'in_year' => 'year', 'between' => 'range', 'within_days' => 'days']],
        'is_customer' => ['Also a customer', 'subscribers', ['yes' => 'none', 'no' => 'none']],
        'has_ordered' => ['Has ordered', 'subscribers', ['yes' => 'none', 'no' => 'none']],
        'source' => ['Signed up from', 'subscribers', ['is' => 'name']],
    ];

    public const OPS = [
        'gte' => 'at least', 'lte' => 'at most', 'gt' => 'more than', 'lt' => 'less than', 'eq' => 'exactly',
        'between' => 'between', 'any_of' => 'is any of', 'none_of' => 'is none of',
        'in_month' => 'in month', 'in_year' => 'in year', 'mostly' => 'mostly', 'ever' => 'ever bought',
        'within_days' => 'within the last … days', 'more_than_days' => 'more than … days ago',
        'before' => 'before', 'after' => 'after', 'yes' => 'yes', 'no' => 'no',
        'is' => 'is', 'is_not' => 'is not', 'any' => 'any coupon',
    ];

    /**
     * The seven emirates and outside the UAE (the owner's D3/D4: "region
     * (Dubai, Abu Dhabi, Sharjah…)"). Each lists the spellings a delivery
     * address may carry — typed at checkout (OrderAddress::EMIRATES),
     * imported from WooCommerce as its two-letter state code, or in Arabic —
     * all lower-case, because the comparison is LOWER(TRIM(…)).
     */
    public const EMIRATES = [
        'dubai' => ['Dubai', ['dubai', 'du', 'dxb', 'ae-du', 'دبي']],
        'abu_dhabi' => ['Abu Dhabi', ['abu dhabi', 'abudhabi', 'abu-dhabi', 'az', 'auh', 'ae-az', 'al ain', 'alain', 'أبو ظبي', 'ابوظبي', 'العين']],
        'sharjah' => ['Sharjah', ['sharjah', 'sh', 'shj', 'ae-sh', 'الشارقة']],
        'ajman' => ['Ajman', ['ajman', 'aj', 'ae-aj', 'عجمان']],
        'umm_al_quwain' => ['Umm Al Quwain', ['umm al quwain', 'umm al-quwain', 'ummalquwain', 'uaq', 'uq', 'ae-uq', 'أم القيوين']],
        'ras_al_khaimah' => ['Ras Al Khaimah', ['ras al khaimah', 'ras al-khaimah', 'rasalkhaimah', 'rak', 'rk', 'ae-rk', 'رأس الخيمة']],
        'fujairah' => ['Fujairah', ['fujairah', 'fu', 'fuj', 'ae-fu', 'الفجيرة']],
        'outside' => ['Outside UAE', []],
    ];

    /* ------------------------------------------------------------ cleaning */

    /**
     * Rules from the outside world, made safe, or refused with sentences.
     *
     * @param  list<string>  $errors
     * @return list<array{field:string, op:string, value:mixed}>
     */
    public static function clean(string $audience, mixed $rules, ?array &$errors = null): array
    {
        $errors = [];

        if (! isset(self::AUDIENCES[$audience])) {
            $errors[] = 'Choose Customers or Subscribers.';

            return [];
        }

        if (! is_array($rules)) {
            return [];
        }

        $rules = array_values($rules);

        if (count($rules) > self::MAX_RULES) {
            $errors[] = 'A group can have at most ' . self::MAX_RULES . ' rules.';
            $rules = array_slice($rules, 0, self::MAX_RULES);
        }

        $out = [];

        foreach ($rules as $i => $rule) {
            $field = is_array($rule) ? (string) ($rule['field'] ?? '') : '';
            $op = is_array($rule) ? (string) ($rule['op'] ?? '') : '';
            $def = self::FIELDS[$field] ?? null;

            if ($def === null || $def[1] !== $audience) {
                $errors[] = 'Rule ' . ($i + 1) . ' is not a rule ' . strtolower(self::AUDIENCES[$audience]) . ' can be grouped by.';

                continue;
            }

            if (! isset($def[2][$op])) {
                $errors[] = $def[0] . ': that comparison is not one this rule has.';

                continue;
            }

            $value = self::value($def[2][$op], $rule['value'] ?? null);

            if ($value === false) {
                $errors[] = $def[0] . ' ' . self::OPS[$op] . ': ' . self::valueHelp($def[2][$op]);

                continue;
            }

            $out[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        return $out;
    }

    public static function cleanMatch(mixed $match): string
    {
        return $match === 'any' ? 'any' : 'all';
    }

    /** The value in the shape its kind takes, or false. */
    private static function value(string $kind, mixed $v): mixed
    {
        $int = static function (mixed $x, int $max): int|false {
            $n = filter_var(is_string($x) ? str_replace(',', '', trim($x)) : $x, FILTER_VALIDATE_INT);

            return $n === false || $n < 0 || $n > $max ? false : (int) $n;
        };
        $date = static function (mixed $x): string|false {
            return is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) === 1 && checkdate((int) substr($x, 5, 2), (int) substr($x, 8, 2), (int) substr($x, 0, 4)) ? $x : false;
        };

        switch ($kind) {
            case 'num':
                return $int($v, 100000);
            case 'money':
                return $int($v, 10000000);
            case 'days':
                $n = $int($v, 3650);

                return $n === false || $n < 1 ? false : $n;
            case 'num_range':
            case 'money_range':
                if (! is_array($v) || count($v) !== 2) {
                    return false;
                }
                $a = $int(array_values($v)[0], 10000000);
                $b = $int(array_values($v)[1], 10000000);

                return $a === false || $b === false || $a > $b ? false : [$a, $b];
            case 'date':
                return $date($v);
            case 'month':
                return is_string($v) && preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $v) === 1 ? $v : false;
            case 'year':
                $n = $int($v, 2100);

                return $n === false || $n < 2000 ? false : $n;
            case 'range':
                if (! is_array($v) || count($v) !== 2) {
                    return false;
                }
                [$a, $b] = array_values($v);
                $a = $date($a);
                $b = $date($b);

                return $a === false || $b === false || $a > $b ? false : [$a, $b];
            case 'none':
                return null;
            case 'emirates':
                $keys = array_values(array_unique(array_filter((array) $v, fn ($k) => is_string($k) && isset(self::EMIRATES[$k]))));

                return $keys === [] ? false : $keys;
            case 'name':
                $s = is_scalar($v) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v)) : '';

                return $s === '' || mb_strlen($s) > 120 ? false : $s;
            case 'id':
                $n = $int($v, PHP_INT_MAX);

                return $n === false || $n < 1 ? false : $n;
            case 'country':
                return is_string($v) && preg_match('/^[A-Za-z]{2}$/', trim($v)) === 1 ? strtoupper(trim($v)) : false;
        }

        return false;
    }

    private static function valueHelp(string $kind): string
    {
        return match ($kind) {
            'num', 'money' => 'type a whole number.',
            'num_range', 'money_range' => 'type two whole numbers, the smaller first.',
            'days' => 'type a number of days (1 or more).',
            'date' => 'pick a date.',
            'month' => 'pick a month.',
            'year' => 'pick a year.',
            'range' => 'pick a From and a To date, From first.',
            'emirates' => 'pick at least one emirate.',
            'name' => 'type the name.',
            'id' => 'pick one.',
            'country' => 'use the two-letter country code, e.g. AE.',
            default => 'that value is not valid.',
        };
    }

    /* -------------------------------------------------------------- queries */

    /**
     * Everyone the rules match, one row each, with the reason they can or
     * cannot be emailed: columns id, email, first_name, name, reason, plus the
     * figures the "See the N" table shows.
     */
    public function annotated(string $audience, array $rules, string $match): Builder
    {
        $matched = $audience === 'subscribers' ? $this->subscribers($rules, $match) : $this->customers($rules, $match);

        $isEmail = "(LOWER(TRIM(m.email)) LIKE '%_@_%._%' AND LOWER(TRIM(m.email)) NOT LIKE '% %')";

        $pending = $audience === 'subscribers'
            ? "(m.sub_status <> 'subscribed' OR m.sub_confirmed IS NULL)"
            : "(ns.status = 'pending' OR (ns.status = 'subscribed' AND ns.confirmed_at IS NULL))";

        $reason = "CASE WHEN NOT $isEmail THEN 'invalid'"
            . " WHEN es.reason IN ('bounce', 'complaint') THEN 'bounced'"
            . " WHEN es.id IS NOT NULL OR oo.id IS NOT NULL OR ns.status = 'unsubscribed' THEN 'unsubscribed'"
            . " WHEN $pending THEN 'pending'"
            . " ELSE 'ok' END";

        return DB::query()
            ->fromSub($matched, 'm')
            ->leftJoin('email_suppressions as es', 'es.email', '=', DB::raw('LOWER(TRIM(m.email))'))
            ->leftJoin('outbound_optouts as oo', 'oo.email', '=', DB::raw('LOWER(TRIM(m.email))'))
            ->leftJoin('subscribers as ns', 'ns.email', '=', DB::raw('LOWER(TRIM(m.email))'))
            ->select('m.*')
            ->selectRaw($reason . ' as reason');
    }

    /**
     * "N match · M can be emailed", and why the rest cannot. One statement.
     *
     * @return array{matched:int, emailable:int, reasons:array<string,int>}
     */
    public function count(string $audience, array $rules, string $match = 'all'): array
    {
        $rows = DB::query()
            ->fromSub($this->annotated($audience, $rules, $match), 'a')
            ->groupBy('a.reason')
            ->selectRaw('a.reason as reason, COUNT(*) as n')
            ->get();

        $reasons = array_fill_keys(array_keys(self::REASONS), 0);
        $ok = 0;
        $total = 0;

        foreach ($rows as $r) {
            $n = (int) $r->n;
            $total += $n;

            if ($r->reason === 'ok') {
                $ok += $n;
            } elseif (isset($reasons[$r->reason])) {
                $reasons[$r->reason] += $n;
            }
        }

        return ['matched' => $total, 'emailable' => $ok, 'reasons' => $reasons];
    }

    /**
     * One page of "See the N", 50 at a time, in one statement.
     *
     * @return list<array<string, mixed>>
     */
    public function page(string $audience, array $rules, string $match, int $page): array
    {
        $page = max(1, min(10000, $page));

        return DB::query()
            ->fromSub($this->annotated($audience, $rules, $match), 'a')
            ->orderBy('a.id')
            ->offset(($page - 1) * self::PAGE_SIZE)
            ->limit(self::PAGE_SIZE)
            ->get()
            ->map(fn ($r) => $audience === 'subscribers' ? [
                'id' => (int) $r->id,
                'email' => (string) $r->email,
                'name' => '',
                'detail' => 'Signed up ' . (StoreTime::formatDate($r->created_at, 'j M Y') ?: '—') . ($r->source ? ' · ' . $r->source : ''),
                'reason' => (string) $r->reason,
            ] : [
                'id' => (int) $r->id,
                'email' => (string) $r->email,
                'name' => trim((string) ($r->name ?: trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')))),
                'detail' => (int) $r->paid_orders . ' order' . ((int) $r->paid_orders === 1 ? '' : 's')
                    . ' · ' . \App\Support\Money::plain((int) $r->spend_fils)
                    . ($r->paid_last_at ? ' · last ' . StoreTime::formatDate($r->paid_last_at, 'j M Y') : ''),
                'reason' => (string) $r->reason,
            ])
            ->all();
    }

    /**
     * The next slice of people who CAN be emailed, after $cursor (an id of
     * this list), for writing a campaign's send rows. One statement.
     *
     * @return list<array{id:int, email:string, first_name:string}>
     */
    public function recipientsAfter(string $audience, array $rules, string $match, int $cursor, int $limit = 1000): array
    {
        return DB::query()
            ->fromSub($this->annotated($audience, $rules, $match), 'a')
            ->where('a.reason', 'ok')
            ->where('a.id', '>', $cursor)
            ->orderBy('a.id')
            ->limit(max(1, min(1000, $limit)))
            ->get(['a.id', 'a.email', 'a.first_name'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'email' => mb_strtolower(trim((string) $r->email)),
                'first_name' => trim((string) ($r->first_name ?? '')),
            ])
            ->all();
    }

    /**
     * The brand this group spends most on — for a product block filled with
     * "This group's top brand (auto)". From order_items.brand, the snapshot
     * taken at purchase, over paid orders; mapped to a brand of the catalogue
     * by name so the block can fill from it.
     *
     * @return array{name:string, id:?int, slug:?string, spend_fils:int}|null
     */
    public function topBrand(string $audience, array $rules, string $match = 'all'): ?array
    {
        $ids = DB::query()->fromSub($audience === 'subscribers' ? $this->subscribers($rules, $match) : $this->customers($rules, $match), 'm');

        $q = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.status', Order::REAL_STATUSES)
            ->whereNull('o.deleted_at')
            ->whereNotNull('oi.brand')
            ->where('oi.brand', '<>', '');

        if ($audience === 'subscribers') {
            $q->whereIn(DB::raw('LOWER(TRIM(o.email))'), $ids->select(DB::raw('LOWER(TRIM(m.email))')));
        } else {
            $q->whereIn('o.customer_id', $ids->select('m.id'));
        }

        $row = $q->groupBy(DB::raw('LOWER(TRIM(oi.brand))'))
            ->selectRaw('MIN(oi.brand) as brand, SUM(oi.total) as spend')
            ->orderByDesc('spend')
            ->orderBy('brand')
            ->first();

        if ($row === null) {
            return null;
        }

        $name = trim((string) $row->brand);
        $brand = DB::table('brands')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->first(['id', 'name', 'slug']);

        return [
            'name' => $brand !== null ? (string) $brand->name : $name,
            'id' => $brand !== null ? (int) $brand->id : null,
            'slug' => $brand !== null ? (string) $brand->slug : null,
            'spend_fils' => (int) $row->spend,
        ];
    }

    /**
     * The whole of each list, for the Campaigns tiles: "1,184 can be emailed
     * of 3,712 customers + 412 subscribers".
     *
     * @return array{customers:int, customers_ok:int, subscribers:int, subscribers_ok:int}
     */
    public function totals(): array
    {
        $c = $this->count('customers', [], 'all');
        $s = $this->count('subscribers', [], 'all');

        return [
            'customers' => $c['matched'], 'customers_ok' => $c['emailable'],
            'subscribers' => $s['matched'], 'subscribers_ok' => $s['emailable'],
        ];
    }

    /* ----------------------------------------------------------- the lists */

    /** Customers matching the rules, with CustomerAggregates' figures. */
    private function customers(array $rules, string $match): Builder
    {
        $agg = CustomerAggregates::query()->addSelect(DB::raw('oa.paid_first_at as paid_first_at'));

        $q = DB::query()->fromSub($agg, 'c')->select([
            'c.id', 'c.email', 'c.first_name', 'c.last_name', 'c.name', 'c.paid_orders', 'c.spend_fils',
            'c.paid_last_at', 'c.paid_first_at', 'c.created_at',
        ]);

        return $this->applyRules($q, $rules, $match, 'customers');
    }

    /** Subscribers matching the rules. */
    private function subscribers(array $rules, string $match): Builder
    {
        $q = DB::table('subscribers as s')->select([
            's.id', 's.email', DB::raw('NULL as first_name'), DB::raw('NULL as last_name'), DB::raw('NULL as name'),
            's.source', 's.created_at', 's.status as sub_status', 's.confirmed_at as sub_confirmed',
        ]);

        return $this->applyRules($q, $rules, $match, 'subscribers');
    }

    private function applyRules(Builder $q, array $rules, string $match, string $audience): Builder
    {
        $rules = self::clean($audience, $rules);

        if ($rules === []) {
            return $q;
        }

        return $q->where(function (Builder $w) use ($rules, $match) {
            foreach ($rules as $rule) {
                $match === 'any'
                    ? $w->orWhere(fn (Builder $one) => $this->compile($one, $rule))
                    : $w->where(fn (Builder $one) => $this->compile($one, $rule));
            }
        });
    }

    /** One rule as a WHERE on the list's derived row — bound values only. */
    private function compile(Builder $w, array $rule): void
    {
        ['field' => $field, 'op' => $op, 'value' => $v] = $rule;

        switch ($field) {
            case 'spent':
                $this->compare($w, 'c.spend_fils', $op, $v, 100);
                break;

            case 'orders':
                $this->compare($w, 'c.paid_orders', $op, $v, 1);
                break;

            case 'avg_order':
                $this->compare($w, DB::raw('(CASE WHEN c.paid_orders > 0 THEN c.spend_fils / c.paid_orders ELSE 0 END)'), $op, $v, 100);
                break;

            case 'never_ordered':
                $w->where('c.paid_orders', $op === 'yes' ? '=' : '>', 0);
                break;

            case 'last_order':
            case 'first_order':
                $col = $field === 'last_order' ? 'c.paid_last_at' : 'c.paid_first_at';
                $w->whereNotNull($col);
                $this->dateRule($w, $col, $op, $v);
                break;

            case 'customer_since':
                $this->dateRule($w, 'c.created_at', $op, $v);
                break;

            case 'order_date':
                [$from, $to] = $this->period($op, $v);
                $w->whereExists(fn ($e) => $this->paidOrders($e)
                    ->where('o.created_at', '>=', $from)->where('o.created_at', '<', $to));
                break;

            case 'emirate':
                $exists = function ($e) use ($v) {
                    $this->paidOrders($e)->where(function ($in) use ($v) {
                        foreach ($v as $key) {
                            if ($key === 'outside') {
                                $in->orWhereRaw($this->countryExpr() . " NOT IN ('AE', '')");
                            } else {
                                $in->orWhere(fn ($one) => $one->whereRaw($this->countryExpr() . " IN ('AE', '')")
                                    ->whereIn(DB::raw($this->placeExpr()), self::EMIRATES[$key][1]));
                            }
                        }
                    });
                };
                $op === 'none_of' ? $w->whereNotExists($exists) : $w->whereExists($exists);
                break;

            case 'brand':
                $name = mb_strtolower(trim((string) $v));

                if ($op === 'ever') {
                    $w->whereExists(fn ($e) => $this->paidOrders($e)
                        ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
                        ->whereRaw('LOWER(TRIM(oi.brand)) = ?', [$name]));
                } else {
                    /*
                     * "Mostly bought X" (the owner, D3/D4): X is the brand with
                     * the biggest share of what this customer spent — line
                     * totals of paid orders. A tie counts for both brands.
                     */
                    $w->whereIn('c.id', function ($s) use ($name) {
                        $s->from($this->brandSpend(), 'b1')
                            ->select('b1.customer_id')
                            ->where('b1.brand_key', $name)
                            ->where('b1.spend', '>', 0)
                            ->whereNotExists(fn ($n) => $n->from($this->brandSpend(), 'b2')
                                ->whereColumn('b2.customer_id', 'b1.customer_id')
                                ->whereColumn('b2.spend', '>', 'b1.spend')
                                ->selectRaw('1'));
                    });
                }
                break;

            case 'product':
                $w->whereExists(fn ($e) => $this->paidOrders($e)
                    ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
                    ->where('oi.product_id', (int) $v));
                break;

            case 'category':
                $w->whereExists(fn ($e) => $this->paidOrders($e)
                    ->join('order_items as oi', 'oi.order_id', '=', 'o.id')
                    ->join('products as p', 'p.id', '=', 'oi.product_id')
                    ->where(fn ($c) => $c->where('p.category_id', (int) $v)
                        ->orWhereIn('p.id', fn ($s) => $s->select('product_id')->from('category_product')->where('category_id', (int) $v))));
                break;

            case 'coupon':
                $w->whereExists(fn ($e) => $op === 'any'
                    ? $this->paidOrders($e)->whereNotNull('o.coupon_code')->where('o.coupon_code', '<>', '')
                    : $this->paidOrders($e)->whereRaw('LOWER(TRIM(o.coupon_code)) = ?', [mb_strtolower((string) $v)]));
                break;

            case 'country':
                $has = fn ($e) => $this->paidOrders($e)->whereRaw($this->countryExpr() . ' = ?', [$v]);
                $op === 'is_not' ? $w->whereNotExists($has) : $w->whereExists($has);
                break;

            case 'city':
                $city = mb_strtolower((string) $v);
                $w->whereExists(fn ($e) => $this->paidOrders($e)->whereRaw('LOWER(TRIM(COALESCE(' . $this->json('o.shipping_address', 'city') . ', ' . $this->json('o.billing_address', 'city') . ", ''))) = ?", [$city]));
                break;

            case 'has_account':
                $login = DB::table('customers as ca')->whereColumn('ca.id', 'c.id')
                    ->where(fn ($p) => $p->whereNotNull('ca.password')->orWhereNotNull('ca.legacy_password'))->selectRaw('1');
                $op === 'yes' ? $w->whereExists($login) : $w->whereNotExists($login);
                break;

            case 'newsletter':
                $sub = NewsletterList::marketable()->whereRaw('subscribers.email = LOWER(TRIM(c.email))')->selectRaw('1');
                $op === 'yes' ? $w->whereExists($sub) : $w->whereNotExists($sub);
                break;

            /* ----------------------------------------------- subscribers */

            case 'signed_up':
                if ($op === 'within_days') {
                    $w->where('s.created_at', '>=', StoreTime::windowStartUtc((int) $v)->format('Y-m-d H:i:s'));
                } else {
                    [$from, $to] = $this->period($op, $v);
                    $w->where('s.created_at', '>=', $from)->where('s.created_at', '<', $to);
                }
                break;

            case 'is_customer':
                $c = DB::table('customers as cu')->whereNull('cu.deleted_at')->whereRaw('LOWER(TRIM(cu.email)) = s.email')->selectRaw('1');
                $op === 'yes' ? $w->whereExists($c) : $w->whereNotExists($c);
                break;

            case 'has_ordered':
                $o = DB::table('orders as o')->whereIn('o.status', Order::REAL_STATUSES)->whereNull('o.deleted_at')
                    ->whereRaw('LOWER(TRIM(o.email)) = s.email')->selectRaw('1');
                $op === 'yes' ? $w->whereExists($o) : $w->whereNotExists($o);
                break;

            case 'source':
                $w->whereRaw('LOWER(s.source) = ?', [mb_strtolower((string) $v)]);
                break;
        }
    }

    /** Paid (REAL_STATUSES) orders of the customer on the current row. */
    private function paidOrders($e)
    {
        return $e->from('orders as o')
            ->whereColumn('o.customer_id', 'c.id')
            ->whereIn('o.status', Order::REAL_STATUSES)
            ->whereNull('o.deleted_at')
            ->selectRaw('1');
    }

    /** Per (customer, brand): line totals of paid orders. */
    private function brandSpend(): Builder
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereIn('o.status', Order::REAL_STATUSES)
            ->whereNull('o.deleted_at')
            ->whereNotNull('o.customer_id')
            ->whereNotNull('oi.brand')
            ->groupBy('o.customer_id', DB::raw('LOWER(TRIM(oi.brand))'))
            ->selectRaw('o.customer_id as customer_id, LOWER(TRIM(oi.brand)) as brand_key, SUM(oi.total) as spend');
    }

    private function compare(Builder $w, mixed $col, string $op, mixed $v, int $scale): void
    {
        if ($op === 'between') {
            $w->whereBetween($col, [(int) $v[0] * $scale, (int) $v[1] * $scale]);

            return;
        }

        $sym = ['gte' => '>=', 'lte' => '<=', 'gt' => '>', 'lt' => '<', 'eq' => '='][$op] ?? '=';
        $w->where($col, $sym, (int) $v * $scale);
    }

    private function dateRule(Builder $w, string $col, string $op, mixed $v): void
    {
        match ($op) {
            'within_days' => $w->where($col, '>=', StoreTime::windowStartUtc((int) $v)->format('Y-m-d H:i:s')),
            'more_than_days' => $w->where($col, '<', StoreTime::windowStartUtc((int) $v)->format('Y-m-d H:i:s')),
            'before' => $w->where($col, '<', StoreTime::startOfDayUtc((string) $v)->format('Y-m-d H:i:s')),
            'after' => $w->where($col, '>=', StoreTime::startOfDayUtc(CarbonImmutable::parse((string) $v)->addDay()->format('Y-m-d'))->format('Y-m-d H:i:s')),
            default => null,
        };
    }

    /**
     * A month, a year or a custom range as [from, to) in UTC, on the shop's
     * own clock (Dubai midnight, not UTC midnight).
     *
     * @return array{0:string, 1:string}
     */
    private function period(string $op, mixed $v): array
    {
        [$a, $b] = match ($op) {
            'in_month' => [$v . '-01', CarbonImmutable::parse($v . '-01')->addMonth()->format('Y-m-d')],
            'in_year' => [$v . '-01-01', ($v + 1) . '-01-01'],
            default => [(string) $v[0], CarbonImmutable::parse((string) $v[1])->addDay()->format('Y-m-d')],
        };

        return [StoreTime::startOfDayUtc($a)->format('Y-m-d H:i:s'), StoreTime::startOfDayUtc($b)->format('Y-m-d H:i:s')];
    }

    /** The JSON text at $key of $column, on whichever engine this is. */
    private function json(string $column, string $key): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column . '->' . $key);
    }

    /**
     * Where the order went, as the emirate test compares it: the delivery
     * state, else the delivery city, else the billing state, else the billing
     * city — lower-cased and trimmed.
     */
    private function placeExpr(): string
    {
        $parts = array_map(fn ($p) => "NULLIF(TRIM({$p}), '')", [
            $this->json('o.shipping_address', 'state'),
            $this->json('o.shipping_address', 'city'),
            $this->json('o.billing_address', 'state'),
            $this->json('o.billing_address', 'city'),
        ]);

        return 'LOWER(COALESCE(' . implode(', ', $parts) . ", ''))";
    }

    /** The delivery country, upper-cased; '' when the order carries none. */
    private function countryExpr(): string
    {
        return 'UPPER(TRIM(COALESCE(' . "NULLIF(TRIM({$this->json('o.shipping_address', 'country')}), ''), "
            . "NULLIF(TRIM({$this->json('o.billing_address', 'country')}), ''), '')))";
    }

    /** The emirate key an address's state/city reads as, for display. */
    public static function emirateOf(?string $place, ?string $country = 'AE'): ?string
    {
        $country = strtoupper(trim((string) $country));

        if ($country !== '' && $country !== 'AE') {
            return 'outside';
        }

        $place = mb_strtolower(trim((string) $place));

        foreach (self::EMIRATES as $key => [, $spellings]) {
            if (in_array($place, $spellings, true)) {
                return $key;
            }
        }

        return null;
    }
}
