<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Order;
use App\Support\StoreTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which visit an order came from.                                    (Lane AN)
 *
 * WHERE THE TOUCHES LIVE, AND WHY THE SESSION. The beacon runs in the web
 * group, so it already has the shopper's Laravel session -- the same one the
 * checkout reads a moment later. On the FIRST page of every browsing session
 * the beacon writes two small arrays into it: the first touch and the last
 * non-direct touch. At order placement CheckoutController reads them back:
 * no lookup in an_hits, no dependence on the daily visitor salt (which would
 * lose a shopper who came from Instagram on Monday and paid on Wednesday).
 *
 * The session alone forgets after SESSION_LIFETIME of idleness, so the
 * browser keeps the two touches too -- in localStorage (`kbb_at`), never a
 * cookie, and only the descriptors (referrer domain, utm fields, click-id
 * TYPE, landing path, day number): nothing that identifies anybody. They
 * ride on the first beacon of each new session and are re-validated and
 * re-classified here, so a hand-edited value can only ever produce one of
 * Channels::MAP's keys and capped strings.
 *
 * LAST TOUCH IGNORES DIRECT (GA's default): a direct visit never replaces a
 * known source; a later Instagram ad does.
 */
final class Attribution
{
    public const KEY = 'kbb_an_at';

    /**
     * Write the session's touches for the entry page just recorded.
     *
     * @param  array<string, mixed>  $row  the an_hits row Tracker wrote
     */
    public static function fromBeacon(Request $request, array $row): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $today = self::dayNumber();
        $now = self::touch($row['ch'], $row['src'], $row['med'], $row['cmp'], self::clickOf($request->input('ck')), $row['path'], $today);
        $first = self::decode($request->input('af'), $today) ?? $now;
        $last = self::decode($request->input('al'), $today);

        if ($now['ch'] !== 'direct') {
            $last = $now;
        }

        $request->session()->put(self::KEY, ['f' => $first, 'l' => $last]);
    }

    /**
     * Stamp an order placed in this request. One UPDATE through the query
     * builder (no model events: Order::updated has listeners that mail).
     * Never throws; an order with no touches stays NULL, which reads Unknown.
     */
    public static function stamp(Order $order, Request $request): void
    {
        try {
            $cols = self::columns($request->hasSession() ? $request->session()->get(self::KEY) : null);

            if ($cols !== null) {
                DB::table('orders')->where('id', $order->id)->update($cols);
            }
        } catch (\Throwable) {
            // Attribution is never worth a failed checkout.
        }
    }

    /**
     * @return array{src_channel: string, src_campaign: ?string, src_attr: string}|null
     */
    public static function columns(mixed $at): ?array
    {
        if (! is_array($at) || ! is_array($at['f'] ?? null)) {
            return null;
        }

        $today = self::dayNumber();
        $first = self::decode($at['f'], $today);
        $last = is_array($at['l'] ?? null) ? self::decode($at['l'], $today) : null;

        if ($first === null) {
            return null;
        }

        $win = $last ?? $first;

        return [
            'src_channel' => $win['ch'],
            'src_campaign' => $win['c'] !== '' ? $win['c'] : null,
            'src_attr' => json_encode([
                'first' => $first,
                'last' => $last,
                'days' => max(0, $today - $first['d']),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * A touch: channel, source, medium, campaign, click TYPE, landing path, day.
     *
     * @return array{ch: string, s: string, m: string, c: string, k: string, p: string, d: int}
     */
    public static function touch(string $ch, string $s, string $m, string $c, string $k, string $p, int $d): array
    {
        return ['ch' => Channels::valid($ch) ? $ch : 'direct', 's' => $s, 'm' => $m, 'c' => $c, 'k' => $k, 'p' => $p, 'd' => $d];
    }

    /**
     * A stored or browser-sent touch, re-validated and re-classified. The
     * browser's form is JSON {r: referrer domain, s, m, c, k, p, d}; the
     * session's form already carries `ch`. Anything else is null.
     *
     * @return array{ch: string, s: string, m: string, c: string, k: string, p: string, d: int}|null
     */
    public static function decode(mixed $raw, int $today): ?array
    {
        if (is_string($raw)) {
            if (strlen($raw) > 1200) {
                return null;
            }
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return null;
        }

        $s = Tracker::utm($raw['s'] ?? '', 60);
        $m = Tracker::utm($raw['m'] ?? '', 40);
        $c = Tracker::utm($raw['c'] ?? '', 100);
        $k = self::clickOf($raw['k'] ?? '');
        $p = Tracker::path(is_string($raw['p'] ?? null) ? $raw['p'] : '/') ?? '/';
        $d = is_numeric($raw['d'] ?? null) ? (int) $raw['d'] : $today;
        $d = ($d > $today || $d < $today - 3650) ? $today : $d;

        if (isset($raw['ch'])) {
            $ch = is_string($raw['ch']) && Channels::valid($raw['ch']) ? $raw['ch'] : 'direct';
        } else {
            $host = is_string($raw['r'] ?? null) && preg_match('/^[a-z0-9.-]{1,100}$/', $raw['r']) ? $raw['r'] : '';
            $ch = Channels::classify($host, $s, $m, $k);
        }

        return self::touch($ch, $s, $m, $c, $k, $p, $d);
    }

    public static function clickOf(mixed $k): string
    {
        return is_string($k) && isset(Channels::CLICKS[$k]) ? $k : '';
    }

    /** Days since 1970 on the shop's calendar -- what the browser sends as `d`. */
    public static function dayNumber(): int
    {
        return intdiv(StoreTime::now()->setTime(0, 0)->getTimestamp() + StoreTime::now()->getOffset(), 86400);
    }

    /** The chip the Orders list and the owner app print: "Instagram Ads · eid_sale". */
    public static function chip(?string $channel, ?string $campaign): string
    {
        $label = Channels::label($channel);

        return $campaign !== null && $campaign !== '' ? $label.' · '.$campaign : $label;
    }

    /**
     * The order screen's Source panel, from the stored columns. Allowlisted:
     * only these keys leave the server.
     *
     * @return array<string, mixed>
     */
    public static function panel(?string $channel, ?string $campaign, ?string $attr): array
    {
        $j = is_string($attr) ? json_decode($attr, true) : null;
        $fmt = static function ($t): ?array {
            if (! is_array($t)) {
                return null;
            }

            return [
                'channel' => Channels::label($t['ch'] ?? null),
                'source' => (string) ($t['s'] ?? ''),
                'medium' => (string) ($t['m'] ?? ''),
                'campaign' => (string) ($t['c'] ?? ''),
                'click' => ['g' => 'gclid', 'f' => 'fbclid', 't' => 'ttclid', 'm' => 'msclkid'][$t['k'] ?? ''] ?? '',
                'landing' => (string) ($t['p'] ?? ''),
            ];
        };

        return [
            'known' => $channel !== null,
            'chip' => self::chip($channel, $campaign),
            'channel' => $channel,
            'first' => $fmt($j['first'] ?? null),
            'last' => $fmt($j['last'] ?? null),
            'days' => is_array($j) && isset($j['days']) ? (int) $j['days'] : null,
        ];
    }
}
