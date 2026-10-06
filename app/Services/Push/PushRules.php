<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\SettingsService;
use App\Support\StoreTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Growth & Marketing → Push Notifications → Settings and Automations (Lane PN):
 * every rule every sender obeys, in ONE setting (`push_rules`, not autoloaded:
 * no storefront page reads it).
 *
 * The owner, 5 October: "i want to minimal the notifications. orders updates
 * will be auto ... also all controls to controls all the notifications etc."
 *
 * DEFAULTS ARE THE OWNER'S, NOT A LANE'S (CLAUDE.md rule 1, the 30 September
 * reversal). He picked all four automations, so all four ship ON; "minimal" is
 * the frequency cap (1 a day, 3 a week) and the quiet hours (22:00–09:00 shop
 * time), which order updates are exempt from because a shopper is owed news
 * of their own order. The two numbers the brief left open — the basket delay
 * (3 hours, inside the 15-minute-to-60-day range the email reminder accepts)
 * and the price-drop threshold (10 %) — are said in the commit.
 *
 * Read straight from the settings table rather than through Setting::map(),
 * whose process-level memo would hide a change from a long-lived worker
 * (CLAUDE.md landmine); memoised per instance only.
 */
final class PushRules
{
    public const SETTING = 'push_rules';

    /**
     * The order statuses that send the shopper an email, in the same closed
     * list (App\Mail\OrderStatusChanged::WORDING). PushRulesTest holds the two
     * equal, so a status the emails learn is a status the pushes learn.
     */
    public const ORDER_STATUSES = ['shipped', 'cancelled', 'processing', 'onhold', 'completed', 'refunded', 'failed'];

    /** What the screen calls each status (the shop's own words for them). */
    public const STATUS_LABELS = [
        'processing' => 'Processing (confirmed)', 'onhold' => 'On hold', 'shipped' => 'Shipped',
        'completed' => 'Delivered (completed)', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded', 'failed' => 'Payment failed',
    ];

    /** The automations that are marketing: capped and held by quiet hours. Order updates are not. */
    public const MARKETING_KINDS = ['campaign', 'stock', 'cart', 'price'];

    /** The abandoned-basket delay's range, in hours: the email reminder's own (CartRecovery). */
    public const CART_MIN_HOURS = 0.25;

    public const CART_MAX_HOURS = 1440;

    public const DEFAULTS = [
        'cap_day' => 1,
        'cap_week' => 3,
        'quiet_on' => true,
        'quiet_from' => '22:00',
        'quiet_to' => '09:00',
        'geo_ip' => true,
        'order_on' => true,
        'order_statuses' => self::ORDER_STATUSES,
        'stock_on' => true,
        'cart_on' => true,
        'cart_hours' => 3,
        'price_on' => true,
        'price_pct' => 10,
    ];

    private ?array $memo = null;

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> every rule, cleaned, defaults filled */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $raw = null;
        try {
            $raw = DB::table('settings')->where('key', self::SETTING)->value('value');
        } catch (\Throwable) {
        }
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        return $this->memo = self::clean(is_array($raw) ? $raw : []);
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    /**
     * Merge a change into the stored rules, clamped. Unknown keys are dropped.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function save(array $changes): array
    {
        $next = self::clean(array_replace($this->all(), array_intersect_key($changes, self::DEFAULTS)));
        $this->settings->set(self::SETTING, $next, false);
        $this->memo = null;

        return $this->memo = $next;
    }

    /**
     * Every value in its own shape and range: a number is clamped, a time is
     * HH:MM, a status list keeps only statuses of ORDER_STATUSES.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public static function clean(array $in): array
    {
        $out = self::DEFAULTS;
        $int = static fn ($v, int $lo, int $hi, int $d): int => is_numeric($v) ? max($lo, min($hi, (int) $v)) : $d;
        $time = static fn ($v, string $d): string => is_string($v) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $v) === 1 ? $v : $d;
        $bool = static fn ($v, bool $d): bool => is_bool($v) ? $v : (in_array($v, [0, 1, '0', '1'], true) ? (bool) $v : $d);

        $out['cap_day'] = $int($in['cap_day'] ?? null, 0, 10, self::DEFAULTS['cap_day']);
        $out['cap_week'] = $int($in['cap_week'] ?? null, 0, 30, self::DEFAULTS['cap_week']);
        if ($out['cap_day'] > 0 && $out['cap_week'] > 0 && $out['cap_week'] < $out['cap_day']) {
            $out['cap_week'] = $out['cap_day'];
        }
        $out['quiet_on'] = $bool($in['quiet_on'] ?? null, true);
        $out['quiet_from'] = $time($in['quiet_from'] ?? null, self::DEFAULTS['quiet_from']);
        $out['quiet_to'] = $time($in['quiet_to'] ?? null, self::DEFAULTS['quiet_to']);
        $out['geo_ip'] = $bool($in['geo_ip'] ?? null, true);
        foreach (['order_on', 'stock_on', 'cart_on', 'price_on'] as $k) {
            $out[$k] = $bool($in[$k] ?? null, true);
        }
        $statuses = $in['order_statuses'] ?? self::ORDER_STATUSES;
        $out['order_statuses'] = is_array($statuses)
            ? array_values(array_intersect(self::ORDER_STATUSES, array_filter($statuses, 'is_string')))
            : self::ORDER_STATUSES;
        $hours = $in['cart_hours'] ?? null;
        $out['cart_hours'] = is_numeric($hours) ? max(self::CART_MIN_HOURS, min(self::CART_MAX_HOURS, round((float) $hours, 2))) : self::DEFAULTS['cart_hours'];
        $out['price_pct'] = $int($in['price_pct'] ?? null, 1, 90, self::DEFAULTS['price_pct']);

        return $out;
    }

    /* ------------------------------------------------------- quiet hours */

    /** Is it quiet hours now (or at $at), on the shop's clock? */
    public function quiet(?CarbonImmutable $at = null): bool
    {
        $r = $this->all();
        if (! $r['quiet_on'] || $r['quiet_from'] === $r['quiet_to']) {
            return false;
        }
        $local = ($at ?? CarbonImmutable::now('UTC'))->setTimezone(StoreTime::timezone());
        $m = (int) $local->format('G') * 60 + (int) $local->format('i');
        $from = self::minutes($r['quiet_from']);
        $to = self::minutes($r['quiet_to']);

        // 22:00–09:00 wraps midnight; 13:00–15:00 does not.
        return $from < $to ? ($m >= $from && $m < $to) : ($m >= $from || $m < $to);
    }

    /** When the current quiet hours end, in UTC; null when it is not quiet. */
    public function quietEnds(?CarbonImmutable $at = null): ?CarbonImmutable
    {
        if (! $this->quiet($at)) {
            return null;
        }
        $local = ($at ?? CarbonImmutable::now('UTC'))->setTimezone(StoreTime::timezone());
        [$h, $i] = array_map('intval', explode(':', (string) $this->all()['quiet_to']));
        $end = $local->setTime($h, $i);
        if ($end->lessThanOrEqualTo($local)) {
            $end = $end->addDay();
        }

        return $end->setTimezone('UTC');
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $i] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $i;
    }

    /* ------------------------------------------------------ frequency cap */

    /**
     * Of these subscriptions, the ones the cap still allows one more
     * marketing push to. ONE grouped query for the whole batch, never one per
     * phone. Counted: marketing messages delivered (or in flight) since
     * midnight shop time, and in the last seven days. Order updates and tests
     * never count.
     *
     * @param  list<int>  $ids
     * @return array<int, bool> id => may receive
     */
    public function room(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $r = $this->all();
        $out = array_fill_keys($ids, true);
        if ($r['cap_day'] === 0 && $r['cap_week'] === 0) {
            return $out;
        }

        $day = StoreTime::startOfDayUtc();
        $week = CarbonImmutable::now('UTC')->subDays(7);
        $rows = DB::table('push_sends')
            ->whereIn('subscription_id', $ids)
            ->whereIn('kind', self::MARKETING_KINDS)
            ->whereIn('status', ['delivered', 'sending'])
            ->where('sent_at', '>=', $week)
            ->groupBy('subscription_id')
            ->selectRaw('subscription_id, COUNT(*) as w, SUM(CASE WHEN sent_at >= ? THEN 1 ELSE 0 END) as d', [$day])
            ->get();

        foreach ($rows as $row) {
            if (($r['cap_day'] > 0 && (int) $row->d >= $r['cap_day']) || ($r['cap_week'] > 0 && (int) $row->w >= $r['cap_week'])) {
                $out[(int) $row->subscription_id] = false;
            }
        }

        return $out;
    }
}
