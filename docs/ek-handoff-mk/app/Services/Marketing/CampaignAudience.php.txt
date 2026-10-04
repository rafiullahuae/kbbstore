<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\Order;
use App\Services\Payments\PaymentRefunder;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who a campaign goes to — Growth & Marketing → Email Marketing → Customer
 * groups (m3). Lane EK.
 *
 * TWO LISTS, NEVER MIXED (the owner, row 54): CUSTOMERS — people who ordered or
 * have an account (`customers`) — and SUBSCRIBERS — newsletter sign-ups
 * (`subscribers`). A group is one list plus rules; a campaign goes to one group.
 *
 * RULES ARE DATA FROM A CLOSED VOCABULARY. A rule is [field, op, value] and
 * clean() keeps only a field of FIELDS, an operator that field lists, and a
 * value of the shape that operator takes (a number, a month, a year, two dates,
 * an emirate key, a brand id). Nothing the admin sends is ever put into SQL:
 * every query below is written here, with bound values.
 *
 * WHO CAN BE EMAILED. Matching a group is not consent. A row counts as
 * "can be emailed" only if its address is well formed, it is not on the
 * marketing opt-out list, and —
 *   subscribers: the newsletter's double opt-in is complete
 *     (NewsletterList::marketable(): status subscribed AND confirmed_at set);
 *   customers: the customer said yes to marketing
 *     (customers.marketing_consent_at) OR the same address is a confirmed
 *     newsletter subscriber.
 * The shop had no marketing consent for customers before this package, so on
 * the day it ships "can be emailed" among customers is exactly the confirmed
 * subscribers who have also bought. That is reported, not papered over.
 *
 * MONEY AND ORDERS are what Store → Customers shows: Order::REAL_STATUSES, net
 * of refunds — "Spend counts paid orders only, after refunds" (the mock). The
 * emirate comes from the order's delivery address, matched by name or by
 * WooCommerce's two-letter code.
 *
 * Built for the size of this shop (thousands of customers): the list and its
 * flags are read in ONE query, each rule is one query returning ids (or a PHP
 * pass over figures already read), and sets are intersected (Match ALL) or
 * joined (Match ANY) in PHP. Never one query per customer.
 */
final class CampaignAudience
{
    public const AUDIENCES = ['customers' => 'Customers', 'subscribers' => 'Subscribers'];

    /** field => [label, list, [op => value shape]] */
    public const FIELDS = [
        'brand' => ['Brands bought', 'customers', ['mostly' => 'brand', 'ever' => 'brand']],
        'emirate' => ['Emirate', 'customers', ['any_of' => 'emirates', 'none_of' => 'emirates']],
        'order_date' => ['Order date', 'customers', ['in_month' => 'month', 'in_year' => 'year', 'between' => 'range']],
        'spent' => ['Total spent (AED)', 'customers', ['at_least' => 'money', 'at_most' => 'money']],
        'orders' => ['Number of orders', 'customers', ['at_least' => 'count', 'at_most' => 'count', 'exactly' => 'count']],
        'never_ordered' => ['Never ordered', 'customers', ['is' => 'none']],
        'subscribed' => ['Newsletter subscribed', 'customers', ['is' => 'none', 'is_not' => 'none']],
        'signed_up' => ['Signed up', 'subscribers', ['in_month' => 'month', 'in_year' => 'year', 'between' => 'range']],
        'is_customer' => ['Has an account or an order', 'subscribers', ['is' => 'none', 'is_not' => 'none']],
    ];

    public const OPS = [
        'mostly' => 'mostly', 'ever' => 'ever bought', 'any_of' => 'is any of', 'none_of' => 'is none of',
        'in_month' => 'in month', 'in_year' => 'in year', 'between' => 'between',
        'at_least' => 'at least', 'at_most' => 'at most', 'exactly' => 'exactly', 'is' => 'is', 'is_not' => 'is not',
    ];

    /** key => [label, the spellings an order's delivery address may carry] */
    public const EMIRATES = [
        'dubai' => ['Dubai', ['Dubai', 'DUBAI', 'dubai', 'DU', 'DXB', 'AE-DU']],
        'abu_dhabi' => ['Abu Dhabi', ['Abu Dhabi', 'ABU DHABI', 'abu dhabi', 'Abudhabi', 'AZ', 'AUH', 'AE-AZ']],
        'sharjah' => ['Sharjah', ['Sharjah', 'SHARJAH', 'sharjah', 'SH', 'AE-SH']],
        'ajman' => ['Ajman', ['Ajman', 'AJMAN', 'ajman', 'AJ', 'AE-AJ']],
        'rak' => ['Ras Al Khaimah', ['Ras Al Khaimah', 'Ras al Khaimah', 'RAS AL KHAIMAH', 'ras al khaimah', 'RK', 'AE-RK']],
        'fujairah' => ['Fujairah', ['Fujairah', 'FUJAIRAH', 'fujairah', 'FU', 'AE-FU']],
        'uaq' => ['Umm Al Quwain', ['Umm Al Quwain', 'Umm al Quwain', 'UMM AL QUWAIN', 'umm al quwain', 'UQ', 'AE-UQ']],
        'outside' => ['Outside UAE', []],
    ];

    public const MAX_RULES = 12;

    /** @var array<string, mixed> per-evaluation memo (rows, figures, brand shares) */
    private array $memo = [];

    /**
     * Rules from the outside world, made safe. Anything not in the vocabulary
     * is dropped (and named in $dropped for the screen to say so).
     *
     * @return list<array{field:string,op:string,value:mixed}>
     */
    public static function clean(string $audience, array $rules, ?array &$dropped = null): array
    {
        $dropped = [];
        $out = [];

        foreach (array_slice(array_values($rules), 0, self::MAX_RULES) as $i => $rule) {
            $field = is_array($rule) ? (string) ($rule['field'] ?? '') : '';
            $op = is_array($rule) ? (string) ($rule['op'] ?? '') : '';
            $def = self::FIELDS[$field] ?? null;

            if ($def === null || $def[1] !== $audience || ! isset($def[2][$op])) {
                $dropped[] = $i;

                continue;
            }

            $value = self::value($def[2][$op], $rule['value'] ?? null);

            if ($value === false) {
                $dropped[] = $i;

                continue;
            }

            $out[] = ['field' => $field, 'op' => $op, 'value' => $value];
        }

        return $out;
    }

    /**
     * How many match, and how many of those can be emailed.
     *
     * @return array{matched:int, emailable:int, unsubscribed:int, no_consent:int}
     */
    public function count(string $audience, array $rules, string $match = 'all'): array
    {
        $rows = $this->matching($audience, $rules, $match);
        $out = ['matched' => count($rows), 'emailable' => 0, 'unsubscribed' => 0, 'no_consent' => 0];

        foreach ($rows as $row) {
            if ($row['optout']) {
                $out['unsubscribed']++;
            } elseif (! $row['consent']) {
                $out['no_consent']++;
            } elseif ($row['valid']) {
                $out['emailable']++;
            }
        }

        return $out;
    }

    /**
     * Everyone who will receive it: matching, consenting, not opted out, a
     * well-formed address — each address once.
     *
     * @return list<array{email:string, first_name:string, customer_id:?int, subscriber_id:?int}>
     */
    public function recipients(string $audience, array $rules, string $match = 'all'): array
    {
        $out = [];
        $seen = [];

        foreach ($this->matching($audience, $rules, $match) as $row) {
            if ($row['optout'] || ! $row['consent'] || ! $row['valid'] || isset($seen[$row['email']])) {
                continue;
            }

            $seen[$row['email']] = true;
            $out[] = [
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'customer_id' => $audience === 'customers' ? $row['id'] : null,
                'subscriber_id' => $audience === 'subscribers' ? $row['id'] : null,
            ];
        }

        return $out;
    }

    /** The whole list's figures, for "Can be emailed … of N customers + M subscribers". */
    public function totals(): array
    {
        $c = $this->count('customers', []);
        $s = $this->count('subscribers', []);

        return [
            'customers' => $c['matched'], 'customers_emailable' => $c['emailable'],
            'subscribers' => $s['matched'], 'subscribers_emailable' => $s['emailable'],
        ];
    }

    /**
     * The brand this group spends most on, for a product grid filled with
     * "This group's top brand (auto)". [brand id, name] or null.
     *
     * @return array{0:int,1:string}|null
     */
    public function topBrand(string $audience, array $rules, string $match = 'all'): ?array
    {
        if ($audience !== 'customers') {
            return null;
        }

        $ids = array_flip(array_map(static fn (array $r) => $r['id'], $this->matching($audience, $rules, $match)));
        $totals = [];

        foreach ($this->brandShares() as $customer => $brands) {
            if (! isset($ids[$customer])) {
                continue;
            }

            foreach ($brands as $brand => $spend) {
                $totals[$brand] = ($totals[$brand] ?? 0) + $spend;
            }
        }

        if ($totals === []) {
            return null;
        }

        arsort($totals);
        $name = (string) array_key_first($totals);
        $brand = DB::table('brands')->whereRaw('LOWER(name) = ?', [$name])->first(['id', 'name']);

        return $brand === null ? null : [(int) $brand->id, (string) $brand->name];
    }

    /* ------------------------------------------------------------ evaluation */

    /**
     * Everyone the rules match, emailable or not, with the flags that say why
     * not (consent, optout, valid). "See the 86" lists these.
     *
     * @return list<array{id:int,email:string,first_name:string,consent:bool,optout:bool,valid:bool}>
     */
    public function matching(string $audience, array $rules, string $match): array
    {
        $audience = isset(self::AUDIENCES[$audience]) ? $audience : 'customers';
        $rules = self::clean($audience, $rules);
        $rows = $this->rows($audience);

        if ($rules === []) {
            return array_values($rows);
        }

        $sets = array_map(fn (array $rule) => $this->ids($audience, $rule, $rows), $rules);
        $keep = array_shift($sets);

        foreach ($sets as $set) {
            $keep = $match === 'any' ? $keep + $set : array_intersect_key($keep, $set);
        }

        return array_values(array_intersect_key($rows, $keep));
    }

    /** @return array<int, array> id => row, the whole list with its flags, in one query */
    private function rows(string $audience): array
    {
        if (isset($this->memo['rows'][$audience])) {
            return $this->memo['rows'][$audience];
        }

        $out = [];
        $optouts = Schema::hasTable('marketing_optouts');

        if ($audience === 'subscribers') {
            $q = DB::table('subscribers as s')
                ->select('s.id', 's.email', 's.status', 's.confirmed_at', 's.created_at');

            if ($optouts) {
                $q->leftJoin('marketing_optouts as mo', 'mo.email', '=', DB::raw('LOWER(s.email)'))->addSelect(DB::raw('mo.id as optout_id'));
            }

            foreach ($q->orderBy('s.id')->get() as $r) {
                $email = mb_strtolower(trim((string) $r->email));
                $out[(int) $r->id] = [
                    'id' => (int) $r->id, 'email' => $email, 'first_name' => '',
                    'consent' => (string) $r->status === 'subscribed' && $r->confirmed_at !== null,
                    'optout' => $optouts && $r->optout_id !== null,
                    'valid' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
                    'at' => (string) $r->created_at,
                ];
            }

            return $this->memo['rows'][$audience] = $out;
        }

        $confirmed = DB::table('subscribers')->where('status', 'subscribed')->whereNotNull('confirmed_at')->select('email');
        $q = DB::table('customers as c')
            ->whereNull('c.deleted_at')
            ->leftJoinSub($confirmed, 'ns', static fn ($j) => $j->on('ns.email', '=', DB::raw('LOWER(c.email)')))
            ->select('c.id', 'c.email', 'c.first_name', 'c.name', DB::raw('ns.email as subscribed_email'));

        $consent = Schema::hasColumn('customers', 'marketing_consent_at');

        if ($consent) {
            $q->addSelect('c.marketing_consent_at');
        }

        if ($optouts) {
            $q->leftJoin('marketing_optouts as mo', 'mo.email', '=', DB::raw('LOWER(c.email)'))->addSelect(DB::raw('mo.id as optout_id'));
        }

        foreach ($q->orderBy('c.id')->get() as $r) {
            $email = mb_strtolower(trim((string) $r->email));
            $first = trim((string) ($r->first_name ?? ''));

            if ($first === '' && trim((string) ($r->name ?? '')) !== '') {
                $first = (string) strtok(trim((string) $r->name), ' ');
            }

            $out[(int) $r->id] = [
                'id' => (int) $r->id, 'email' => $email, 'first_name' => mb_substr($first, 0, 60),
                'subscribed' => $r->subscribed_email !== null,
                'consent' => ($consent && $r->marketing_consent_at !== null) || $r->subscribed_email !== null,
                'optout' => $optouts && $r->optout_id !== null,
                'valid' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
            ];
        }

        return $this->memo['rows'][$audience] = $out;
    }

    /** @return array<int, true> the ids one rule keeps */
    private function ids(string $audience, array $rule, array $rows): array
    {
        $field = $rule['field'];
        $op = $rule['op'];
        $value = $rule['value'];

        if ($audience === 'subscribers') {
            if ($field === 'signed_up') {
                [$from, $to] = self::period($op, $value);

                return $this->filter($rows, static function (array $r) use ($from, $to): bool {
                    $at = $r['at'] !== '' ? CarbonImmutable::parse($r['at']) : null;

                    return $at !== null && $at->gte($from) && $at->lt($to);
                });
            }

            // is_customer
            $customers = array_flip(DB::table('customers')->whereNull('deleted_at')->pluck('email')->map(static fn ($e) => mb_strtolower(trim((string) $e)))->all());

            return $this->filter($rows, static fn (array $r) => isset($customers[$r['email']]) === ($op === 'is'));
        }

        return match ($field) {
            'spent' => $this->filter($rows, function (array $r) use ($op, $value): bool {
                $spend = $this->figures()[$r['id']]['s'] ?? 0;

                return $op === 'at_least' ? $spend >= $value : $spend <= $value;
            }),
            'orders' => $this->filter($rows, function (array $r) use ($op, $value): bool {
                $n = $this->figures()[$r['id']]['n'] ?? 0;

                return match ($op) {
                    'at_least' => $n >= $value,
                    'at_most' => $n <= $value,
                    default => $n === $value,
                };
            }),
            'never_ordered' => $this->filter($rows, fn (array $r) => ($this->figures()[$r['id']]['n'] ?? 0) === 0),
            'subscribed' => $this->filter($rows, static fn (array $r) => $r['subscribed'] === ($op === 'is')),
            'emirate' => $this->emirate($op, $value, $rows),
            'order_date' => $this->orderedIn(...self::period($op, $value)),
            'brand' => $this->brand($op, (int) $value),
            default => [],
        };
    }

    private function filter(array $rows, callable $keep): array
    {
        $out = [];

        foreach ($rows as $id => $row) {
            if ($keep($row)) {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /** customer id => [n => real orders, s => spend in fils net of refunds], one grouped query */
    private function figures(): array
    {
        if (isset($this->memo['figures'])) {
            return $this->memo['figures'];
        }

        $refunded = DB::table('refunds')
            ->whereIn('status', PaymentRefunder::COUNTED)
            ->groupBy('order_id')
            ->selectRaw('order_id, COALESCE(SUM(amount), 0) as refunded_fils');

        $out = [];

        foreach (DB::table('orders')
            ->leftJoinSub($refunded, 'rf', 'rf.order_id', '=', 'orders.id')
            ->whereNotNull('orders.customer_id')
            ->whereIn('orders.status', Order::REAL_STATUSES)
            ->groupBy('orders.customer_id')
            ->selectRaw('orders.customer_id as customer_id, COUNT(*) as n,'
                . ' COALESCE(SUM(CASE WHEN orders.total - COALESCE(rf.refunded_fils, 0) > 0'
                . ' THEN orders.total - COALESCE(rf.refunded_fils, 0) ELSE 0 END), 0) as s')
            ->get() as $r) {
            $out[(int) $r->customer_id] = ['n' => (int) $r->n, 's' => (int) $r->s];
        }

        return $this->memo['figures'] = $out;
    }

    private function realOrders(): \Illuminate\Database\Query\Builder
    {
        return DB::table('orders')->whereNotNull('customer_id')->whereIn('status', Order::REAL_STATUSES);
    }

    private function emirate(string $op, array $keys, array $rows): array
    {
        $variants = [];
        $outside = in_array('outside', $keys, true);

        foreach ($keys as $key) {
            array_push($variants, ...self::EMIRATES[$key][1]);
        }

        $q = $this->realOrders()->where(static function ($w) use ($variants, $outside): void {
            if ($variants !== []) {
                $w->orWhereIn('shipping_address->state', $variants);
            }

            if ($outside) {
                $w->orWhere(static function ($o): void {
                    $o->whereNotNull('shipping_address->country')
                        ->whereNotIn('shipping_address->country', ['AE', 'ae', 'UAE', 'United Arab Emirates']);
                });
            }
        });

        $ids = array_flip(array_map('intval', $q->distinct()->pluck('customer_id')->all()));

        return $op === 'any_of' ? $ids : array_diff_key(array_fill_keys(array_keys($rows), true), $ids);
    }

    private function orderedIn(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_fill_keys(array_map('intval', $this->realOrders()
            ->where('created_at', '>=', $from->utc()->toDateTimeString())
            ->where('created_at', '<', $to->utc()->toDateTimeString())
            ->distinct()->pluck('customer_id')->all()), true);
    }

    private function brand(string $op, int $brandId): array
    {
        $name = mb_strtolower(trim((string) DB::table('brands')->where('id', $brandId)->value('name')));

        if ($name === '') {
            return [];
        }

        $out = [];

        foreach ($this->brandShares() as $customer => $brands) {
            if (! isset($brands[$name])) {
                continue;
            }

            // "mostly" = the brand with the biggest share of what they spent.
            if ($op === 'ever' || $brands[$name] >= max($brands)) {
                $out[$customer] = true;
            }
        }

        return $out;
    }

    /** customer id => [lower-cased brand name => fils spent on it], one grouped query */
    private function brandShares(): array
    {
        if (isset($this->memo['brands'])) {
            return $this->memo['brands'];
        }

        $out = [];

        foreach (DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNotNull('o.customer_id')
            ->whereIn('o.status', Order::REAL_STATUSES)
            ->whereNotNull('oi.brand')
            ->groupBy('o.customer_id', 'oi.brand')
            ->selectRaw('o.customer_id as customer_id, oi.brand as brand, SUM(oi.total) as spent')
            ->get() as $r) {
            $brand = mb_strtolower(trim((string) $r->brand));

            if ($brand !== '') {
                $out[(int) $r->customer_id][$brand] = ($out[(int) $r->customer_id][$brand] ?? 0) + (int) $r->spent;
            }
        }

        return $this->memo['brands'] = $out;
    }

    /* ---------------------------------------------------------------- values */

    /** A rule's value in the one shape its operator takes, or false. */
    private static function value(string $shape, mixed $value): mixed
    {
        switch ($shape) {
            case 'none':
                return null;

            case 'money':
                // AED typed, fils stored.
                return is_numeric($value) && (float) $value >= 0 && (float) $value <= 10_000_000 ? (int) round((float) $value * 100) : false;

            case 'count':
                return is_numeric($value) && (int) $value >= 0 && (int) $value <= 100_000 ? (int) $value : false;

            case 'brand':
                return is_numeric($value) && (int) $value > 0 && DB::table('brands')->where('id', (int) $value)->exists() ? (int) $value : false;

            case 'emirates':
                $keys = array_values(array_unique(array_filter((array) $value, static fn ($k) => is_string($k) && isset(self::EMIRATES[$k]))));

                return $keys === [] ? false : $keys;

            case 'month':
                return is_string($value) && preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $value) === 1 ? $value : false;

            case 'year':
                return is_numeric($value) && (int) $value >= 2000 && (int) $value <= 2100 ? (int) $value : false;

            case 'range':
                $from = is_array($value) ? (string) ($value[0] ?? '') : '';
                $to = is_array($value) ? (string) ($value[1] ?? '') : '';
                $ok = static fn (string $d) => preg_match('/^20\d\d-\d\d-\d\d$/', $d) === 1 && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4));

                return $ok($from) && $ok($to) && $from <= $to ? [$from, $to] : false;
        }

        return false;
    }

    /** [from, to) in the shop's own time zone. */
    private static function period(string $op, mixed $value): array
    {
        $tz = StoreTime::timezone();

        return match ($op) {
            'in_month' => [CarbonImmutable::parse($value . '-01 00:00:00', $tz), CarbonImmutable::parse($value . '-01 00:00:00', $tz)->addMonth()],
            'in_year' => [CarbonImmutable::create((int) $value, 1, 1, 0, 0, 0, $tz), CarbonImmutable::create((int) $value + 1, 1, 1, 0, 0, 0, $tz)],
            default => [CarbonImmutable::parse($value[0] . ' 00:00:00', $tz), CarbonImmutable::parse($value[1] . ' 00:00:00', $tz)->addDay()],
        };
    }

    /** A rule as a sentence, for the saved-groups list and Review & send. */
    public static function describe(array $rule): string
    {
        $def = self::FIELDS[$rule['field']] ?? null;

        if ($def === null) {
            return '';
        }

        $value = $rule['value'];
        $text = match ($def[2][$rule['op']] ?? '') {
            'money' => 'AED ' . number_format(((int) $value) / 100),
            'brand' => (string) DB::table('brands')->where('id', (int) $value)->value('name'),
            'emirates' => implode(', ', array_map(static fn ($k) => self::EMIRATES[$k][0], (array) $value)),
            'range' => $value[0] . ' – ' . $value[1],
            'none' => '',
            default => (string) $value,
        };

        return trim($def[0] . ' ' . (self::OPS[$rule['op']] ?? $rule['op']) . ' ' . $text);
    }
}
