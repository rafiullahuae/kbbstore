<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\Marketing\Audience;
use App\Support\Locale;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who a push campaign goes to (Lane PN): phones, not people.
 *
 * The owner: "send to all or specific groups of customers etc, city, region
 * wise ... if customer is in dubai, and we want to target sharjah, so in this
 * case the dubai customers will be annoy."
 *
 * So the place is the PHONE's — site_app_push_subscriptions.region/city, from
 * its shopper's latest delivery address, the proxy's geo headers or the
 * offline IP table (Lane NT, and PushGeo here) — never where somebody once
 * shipped a gift. An emirate is matched through Marketing's own spelling list
 * (Audience::EMIRATES: "Dubai", "DXB", "AE-DU", "دبي" are one place), so this
 * screen and Marketing Emails cannot disagree about where Sharjah is.
 *
 * Purchase groups (brands bought, total spent, number of orders, last order)
 * are Marketing Emails' own rules, run by Audience over the same customer
 * aggregate, and joined to the phone by customer_id: shared, not copied.
 *
 * A spec is DATA from a closed vocabulary. clean() keeps only known keys and
 * known values; query() writes every WHERE here with bound values.
 *
 *   emirates   keys of Audience::EMIRATES, plus 'unknown'
 *   cities     up to 20 names, compared case-insensitively
 *   locales    the shop's languages (Locale::LOCALES)
 *   platforms  ios | android | desktop | other
 *   who        all | customers | guests
 *   rules      Marketing's customer rules (Audience::clean('customers', …))
 *   match      all | any (between those rules)
 */
final class PushAudience
{
    public const PLATFORMS = ['ios' => 'iPhone / iPad', 'android' => 'Android', 'desktop' => 'Computer', 'other' => 'Other'];

    public const WHO = ['all' => 'Everyone', 'customers' => 'Signed-in customers', 'guests' => 'Guests'];

    /** Marketing rules that make sense for a phone: what its shopper bought. */
    public const RULE_FIELDS = ['brand', 'spent', 'orders', 'last_order', 'first_order', 'never_ordered', 'category', 'product'];

    public const MAX_CITIES = 20;

    public function __construct(private Audience $audience) {}

    /**
     * @param  list<string>  $errors
     * @return array{emirates:list<string>, cities:list<string>, locales:list<string>, platforms:list<string>, who:string, rules:list<array>, match:string}
     */
    public static function clean(mixed $in, ?array &$errors = null): array
    {
        $errors = [];
        $in = is_array($in) ? $in : [];
        $list = static fn ($v): array => is_array($v) ? array_values(array_filter($v, 'is_string')) : [];

        $emirates = array_values(array_unique(array_filter($list($in['emirates'] ?? []),
            static fn (string $k) => $k === 'unknown' || isset(Audience::EMIRATES[$k]))));
        $cities = [];
        foreach ($list($in['cities'] ?? []) as $c) {
            $c = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $c));
            if ($c !== '' && mb_check_encoding($c, 'UTF-8') && mb_strlen($c) <= 80) {
                $cities[mb_strtolower($c)] = $c;
            }
        }
        if (count($cities) > self::MAX_CITIES) {
            $errors[] = 'Pick at most '.self::MAX_CITIES.' cities.';
        }
        $locales = array_values(array_unique(array_intersect($list($in['locales'] ?? []), array_keys(Locale::LOCALES))));
        $platforms = array_values(array_unique(array_intersect($list($in['platforms'] ?? []), array_keys(self::PLATFORMS))));
        $who = isset(self::WHO[$in['who'] ?? '']) ? (string) $in['who'] : 'all';

        $rules = [];
        $raw = is_array($in['rules'] ?? null) ? array_values($in['rules']) : [];
        $raw = array_values(array_filter($raw, static fn ($r) => is_array($r) && in_array($r['field'] ?? '', self::RULE_FIELDS, true)));
        if ($raw !== []) {
            $rules = Audience::clean('customers', $raw, $ruleErrors);
            array_push($errors, ...$ruleErrors);
        }

        return [
            'emirates' => $emirates,
            'cities' => array_slice(array_values($cities), 0, self::MAX_CITIES),
            'locales' => $locales,
            'platforms' => $platforms,
            'who' => $rules !== [] && $who === 'all' ? 'customers' : $who,
            'rules' => $rules,
            'match' => Audience::cleanMatch($in['match'] ?? 'all'),
        ];
    }

    /** Every active subscription the spec matches: a builder over `s`. */
    public function query(array $spec): Builder
    {
        $spec = self::clean($spec);
        $q = DB::table('site_app_push_subscriptions as s')->where('s.status', 'active');

        if ($spec['emirates'] !== []) {
            $place = self::placeSql();
            $q->where(function (Builder $w) use ($spec, $place) {
                foreach ($spec['emirates'] as $key) {
                    if ($key === 'unknown') {
                        $w->orWhere(fn (Builder $u) => $u->whereRaw("$place = ''")->where(fn (Builder $c) => $c->whereNull('s.country')->orWhere('s.country', 'AE')));
                    } elseif ($key === 'outside') {
                        $w->orWhere(fn (Builder $o) => $o->whereNotNull('s.country')->where('s.country', '<>', 'AE'));
                    } else {
                        $spellings = Audience::EMIRATES[$key][1];
                        $w->orWhere(fn (Builder $e) => $e->whereIn(DB::raw($place), $spellings)
                            ->where(fn (Builder $c) => $c->whereNull('s.country')->orWhere('s.country', 'AE')));
                    }
                }
            });
        }

        if ($spec['cities'] !== []) {
            $q->whereIn(DB::raw("LOWER(TRIM(COALESCE(s.city, '')))"), array_map('mb_strtolower', $spec['cities']));
        }
        if ($spec['locales'] !== []) {
            $q->whereIn('s.locale', $spec['locales']);
        }
        if ($spec['platforms'] !== []) {
            $q->whereIn('s.platform', $spec['platforms']);
        }
        if ($spec['who'] === 'customers') {
            $q->whereNotNull('s.customer_id');
        } elseif ($spec['who'] === 'guests') {
            $q->whereNull('s.customer_id');
        }

        if ($spec['rules'] !== []) {
            $ids = $this->audience->customerIds($spec['rules'], $spec['match']);
            $q->whereIn('s.customer_id', $ids);
        }

        return $q;
    }

    /** How many phones match. One statement. */
    public function count(array $spec): int
    {
        return (int) $this->query($spec)->count();
    }

    /**
     * The place a phone's emirate is read from, as Audience compares it:
     * region (the delivery state), else city, lower-cased and trimmed.
     */
    public static function placeSql(string $alias = 's'): string
    {
        return "LOWER(TRIM(COALESCE(NULLIF(TRIM({$alias}.region), ''), NULLIF(TRIM({$alias}.city), ''), '')))";
    }

    /** The emirate key of one phone, for a send row and the report. */
    public static function emirateOf(?string $region, ?string $city, ?string $country): ?string
    {
        $place = trim((string) ($region ?? '')) !== '' ? (string) $region : (string) ($city ?? '');
        if (trim($place) === '' && ($country === null || $country === '' || $country === 'AE')) {
            return null;
        }

        return Audience::emirateOf($place, $country ?: 'AE');
    }

    /** A short sentence for the campaign list: "Sharjah · Arabic · Android". */
    public static function describe(array $spec): string
    {
        $spec = self::clean($spec);
        $parts = [];
        if ($spec['emirates'] !== []) {
            $parts[] = implode(', ', array_map(static fn ($k) => $k === 'unknown' ? 'Place unknown' : Audience::EMIRATES[$k][0], $spec['emirates']));
        }
        if ($spec['cities'] !== []) {
            $parts[] = implode(', ', $spec['cities']);
        }
        if ($spec['locales'] !== []) {
            $parts[] = implode(', ', array_map(static fn ($l) => Locale::LOCALES[$l]['name'] ?? $l, $spec['locales']));
        }
        if ($spec['platforms'] !== []) {
            $parts[] = implode(', ', array_map(static fn ($p) => self::PLATFORMS[$p], $spec['platforms']));
        }
        if ($spec['who'] !== 'all') {
            $parts[] = self::WHO[$spec['who']];
        }
        if ($spec['rules'] !== []) {
            $parts[] = count($spec['rules']).' purchase rule'.(count($spec['rules']) === 1 ? '' : 's');
        }

        return $parts === [] ? 'All subscribers' : implode(' · ', $parts);
    }
}
