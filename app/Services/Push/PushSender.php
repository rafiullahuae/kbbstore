<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\OwnerApp\WebPush;
use App\Support\Locale;
use App\Support\Url;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every shop-app push goes out through here (Lane PN): campaigns, order
 * updates, back in stock, the basket reminder and price drops, and the
 * owner's test.
 *
 * ── QUEUE-FREE, AS EVERYTHING ON THIS HOST ─────────────────────────────────
 *
 * A message is a push_sends ROW before it is a request. The scheduler's one
 * cron line runs `php artisan kbb:push-step` every minute (routes/console.php,
 * the same line Marketing Emails' kbb:campaigns-step needs); a campaign's
 * "Send now" also steps it inside the owner's request for up to STEP_SECONDS,
 * and an order update is delivered right after the response that caused it
 * (terminating callback), so neither waits for cron.
 *
 * ── ONCE, BY CONSTRUCTION ──────────────────────────────────────────────────
 *
 *   one row per (message, phone)   push_sends.dedupe is UNIQUE; a second
 *                                  writer's insertOrIgnore() adds nothing.
 *   one POST per row               claim(): a single UPDATE moves rows from
 *                                  queued to sending under a random token and
 *                                  only the rows carrying that token are sent.
 *   one stepper per campaign       a lease on push_campaigns (lock_until /
 *                                  lock_token), taken by a conditional UPDATE.
 *
 * So two ticks that overlap, or a tick and the owner's tab, send each phone
 * one message — PushCampaignsTest runs that race.
 *
 * ── MINIMAL ────────────────────────────────────────────────────────────────
 *
 * Every marketing message (campaign, back in stock, basket, price) passes the
 * frequency cap (PushRules::room, one grouped query per batch) and waits out
 * quiet hours. Order updates pass neither: a shopper is owed news of their own
 * order. A push service that says 404 or 410 has retired the endpoint: the
 * phone's row goes to `gone` at once and is never sent to again.
 *
 * ── WHAT A PAYLOAD CARRIES ─────────────────────────────────────────────────
 *
 * {t: title, b: body, u: a path on this shop, g: a tag, c: the click token}
 * — text the worker shows with showNotification(), never markup, and an
 * address the worker opens only if it is on this shop (sw.js rule 6). Nothing
 * personal beyond the shopper's own order number. Well under 4 KB: the title
 * is at most 80 characters and the body 200, and payload() refuses anything
 * over MAX_PAYLOAD bytes.
 */
final class PushSender
{
    /** Phones walked per campaign batch. */
    public const BATCH = 100;

    /** Seconds one step may spend sending. */
    public const STEP_SECONDS = 20;

    /** How long a campaign lease lasts if its holder dies. */
    public const LEASE_SECONDS = 120;

    /** An automation message not sent within this long (capped, quiet) is dropped, not sent late. */
    public const EXPIRE_HOURS = 72;

    public const MAX_PAYLOAD = 3000;

    /** kind => [TTL seconds, Urgency]. */
    public const DELIVERY = [
        'order' => [172800, 'high'],
        'test' => [600, 'high'],
        'campaign' => [43200, 'normal'],
        'stock' => [86400, 'normal'],
        'price' => [86400, 'normal'],
        'cart' => [21600, 'normal'],
    ];

    public function __construct(private PushRules $rules, private PushAudience $audience) {}

    /* ------------------------------------------------------------ payload */

    /** The click token for one send row: its id and an HMAC of it. */
    public static function clickToken(int $sendId): string
    {
        return $sendId.'.'.substr(hash_hmac('sha256', 'push-click|'.$sendId, self::key()), 0, 24);
    }

    /** The send id a click token stands for, or null if it was not signed here. */
    public static function clickId(mixed $token): ?int
    {
        if (! is_string($token) || preg_match('/\A([1-9]\d{0,17})\.([0-9a-f]{24})\z/', $token, $m) !== 1) {
            return null;
        }

        return hash_equals(self::clickToken((int) $m[1]), $token) ? (int) $m[1] : null;
    }

    private static function key(): string
    {
        return (string) config('app.key', '');
    }

    /** The address a payload carries for a stored path, in the phone's language. */
    public static function address(?string $path, string $locale): string
    {
        $path = PushLinks::path($path) ?? '/';
        if ($locale !== Locale::DEFAULT && Locale::localisable($path) && Locale::enabled($locale)) {
            $path = Locale::withSegment($path, $locale);
        }

        return Url::raw($path);
    }

    /** The JSON a phone receives for one send row, or null if it would be too big. */
    public static function payload(object $row): ?string
    {
        $tag = match ($row->kind) {
            'order' => 'o'.$row->ref,
            'campaign' => 'c'.$row->campaign_id,
            'stock' => 's'.$row->ref,
            'price' => 'p'.$row->ref,
            'cart' => 'cart',
            default => 'kbb',
        };
        $json = json_encode([
            't' => mb_substr((string) $row->title, 0, 80),
            'b' => mb_substr((string) $row->body, 0, 200),
            'u' => self::address($row->url, (string) ($row->locale ?? Locale::DEFAULT)),
            'g' => $tag,
            'c' => self::clickToken((int) $row->id),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false && strlen($json) <= self::MAX_PAYLOAD ? $json : null;
    }

    /* ------------------------------------------------------- the network */

    /**
     * Take these queued rows (CAS) and send them. Rows somebody else has
     * claimed are skipped. Returns how many the push services accepted.
     *
     * @param  list<int>  $ids  push_sends ids
     */
    public function deliver(array $ids): int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }
        $token = bin2hex(random_bytes(16));
        $now = now();
        $claimed = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $claimed += DB::table('push_sends')->whereIn('id', $chunk)->where('status', 'queued')
                ->update(['status' => 'sending', 'claim' => $token, 'sent_at' => $now, 'updated_at' => $now]);
        }
        if ($claimed === 0) {
            return 0;
        }

        $rows = DB::table('push_sends as p')
            ->join('site_app_push_subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->where('p.claim', $token)
            ->get(['p.id', 'p.kind', 'p.ref', 'p.campaign_id', 'p.title', 'p.body', 'p.url', 'p.subscription_id',
                's.endpoint', 's.p256dh', 's.auth', 's.locale', 's.status as sub_status']);

        $accepted = 0;
        $results = [];
        foreach ($rows->groupBy('kind') as $kind => $group) {
            [$ttl, $urgency] = self::DELIVERY[$kind] ?? [86400, 'normal'];
            $targets = [];
            $payloads = [];
            foreach ($group as $r) {
                $payload = $r->sub_status === 'active' ? self::payload($r) : null;
                if ($payload === null) {
                    $results[(int) $r->id] = -1;
                    continue;
                }
                $targets[] = (object) ['id' => (int) $r->id, 'endpoint' => $r->endpoint, 'p256dh' => $r->p256dh, 'auth' => $r->auth];
                $payloads[(int) $r->id] = $payload;
            }
            $statuses = $targets === [] ? [] : WebPush::deliver($targets, $payloads, $ttl, $urgency);
            foreach ($targets as $t) {
                $results[$t->id] = $statuses[$t->id] ?? 0;
            }
        }

        $bySub = [];
        foreach ($rows as $r) {
            $bySub[(int) $r->id] = (int) $r->subscription_id;
        }
        // Rows claimed but whose phone row has vanished (deleted meanwhile): failed.
        $orphans = DB::table('push_sends')->where('claim', $token)->whereNotIn('id', array_keys($bySub) ?: [0])->pluck('id')->all();
        foreach ($orphans as $id) {
            $results[(int) $id] = -1;
        }

        $delivered = $gone = $failed = [];
        foreach ($results as $id => $status) {
            if ($status >= 200 && $status < 300) {
                $delivered[] = $id;
            } elseif ($status === 404 || $status === 410) {
                $gone[] = $id;
            } else {
                $failed[] = $id;
            }
        }
        $accepted = count($delivered);

        try {
            $now = now();
            foreach (['delivered' => $delivered, 'gone' => $gone, 'failed' => $failed] as $status => $list) {
                foreach (array_chunk($list, 500) as $chunk) {
                    DB::table('push_sends')->whereIn('id', $chunk)->where('claim', $token)->update(['status' => $status, 'updated_at' => $now]);
                }
            }
            $goneSubs = array_values(array_unique(array_map(fn ($id) => $bySub[$id] ?? 0, $gone)));
            $failedSubs = array_values(array_unique(array_filter(array_map(fn ($id) => $results[$id] >= 0 ? ($bySub[$id] ?? 0) : 0, $failed))));
            $okSubs = array_values(array_unique(array_map(fn ($id) => $bySub[$id] ?? 0, $delivered)));
            if ($goneSubs !== []) {
                $n = DB::table('site_app_push_subscriptions')->whereIn('id', $goneSubs)->where('status', 'active')
                    ->update(['status' => 'gone', 'updated_at' => $now]);
                PushStats::bump('gone', $n);
            }
            if ($failedSubs !== []) {
                DB::table('site_app_push_subscriptions')->whereIn('id', $failedSubs)->increment('fail_count');
                $n = DB::table('site_app_push_subscriptions')->whereIn('id', $failedSubs)->where('fail_count', '>', 20)
                    ->where('status', 'active')->update(['status' => 'gone', 'updated_at' => $now]);
                PushStats::bump('gone', $n);
            }
            if ($okSubs !== []) {
                DB::table('site_app_push_subscriptions')->whereIn('id', $okSubs)->where('fail_count', '>', 0)->update(['fail_count' => 0]);
            }
        } catch (\Throwable $e) {
            Log::warning('shop app push bookkeeping failed', ['exception' => class_basename($e)]);
        }

        return $accepted;
    }

    /* --------------------------------------------------------- automations */

    /**
     * Queue automation messages (rows already rendered in each phone's
     * language). insertOrIgnore on the unique dedupe: a message that exists
     * is not queued twice. Returns the ids of the rows THIS call created.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    public function queue(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $now = now();
        $keys = [];
        $insert = [];
        foreach ($rows as $r) {
            $keys[] = $r['dedupe'];
            $insert[] = $r + ['campaign_id' => null, 'status' => 'queued', 'due_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }
        $before = DB::table('push_sends')->whereIn('dedupe', $keys)->pluck('id')->all();
        foreach (array_chunk($insert, 200) as $chunk) {
            DB::table('push_sends')->insertOrIgnore($chunk);
        }

        return array_values(array_diff(
            array_map('intval', DB::table('push_sends')->whereIn('dedupe', $keys)->pluck('id')->all()),
            array_map('intval', $before),
        ));
    }

    /**
     * Send what the automations have queued and is due: order updates at
     * once, marketing ones only outside quiet hours and within the cap. A
     * marketing message still capped after EXPIRE_HOURS is dropped
     * ('expired'), never sent days late.
     */
    public function runQueue(int $limit = 300): int
    {
        $now = CarbonImmutable::now('UTC');
        DB::table('push_sends')->whereNull('campaign_id')->where('status', 'queued')
            ->where('created_at', '<', $now->subHours(self::EXPIRE_HOURS))->update(['status' => 'expired', 'updated_at' => $now]);
        // A row claimed by a process that died mid-send.
        DB::table('push_sends')->where('status', 'sending')->where('sent_at', '<', $now->subMinutes(10))
            ->update(['status' => 'failed', 'updated_at' => $now]);

        $quiet = $this->rules->quiet($now);
        $q = DB::table('push_sends')->whereNull('campaign_id')->where('status', 'queued')->where('due_at', '<=', $now);
        if ($quiet) {
            $q->where('kind', 'order');
        }
        $due = $q->orderBy('id')->limit($limit)->get(['id', 'kind', 'subscription_id']);
        if ($due->isEmpty()) {
            return 0;
        }

        $marketing = $due->filter(fn ($r) => $r->kind !== 'order');
        $room = $this->rules->room($marketing->pluck('subscription_id')->all());
        $send = [];
        $took = [];
        foreach ($due as $r) {
            if ($r->kind === 'order') {
                $send[] = (int) $r->id;
                continue;
            }
            $sid = (int) $r->subscription_id;
            if (($room[$sid] ?? false) && ! isset($took[$sid])) {
                $send[] = (int) $r->id;
                $took[$sid] = true;    // at most one marketing message per phone per batch
            }
        }

        return $this->deliver($send);
    }

    /* ----------------------------------------------------------- campaigns */

    /** draft|scheduled → sending, once (a conditional UPDATE). */
    public function start(int $id): bool
    {
        return DB::table('push_campaigns')->where('id', $id)->whereIn('status', ['draft', 'scheduled'])
            ->update(['status' => 'sending', 'started_at' => now(), 'scheduled_at' => null, 'updated_at' => now()]) === 1;
    }

    /** Start every scheduled campaign whose time has come. */
    public function startDue(): int
    {
        $n = 0;
        foreach (DB::table('push_campaigns')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->pluck('id') as $id) {
            $n += DB::table('push_campaigns')->where('id', $id)->where('status', 'scheduled')
                ->update(['status' => 'sending', 'started_at' => now(), 'updated_at' => now()]);
        }

        return $n;
    }

    /**
     * Send the next batches of one campaign, for up to $seconds. Holds the
     * campaign's lease throughout; a campaign somebody else is stepping is
     * left alone. Waits out quiet hours.
     *
     * @return array<string, mixed> the campaign's progress
     */
    public function step(int $id, int $seconds = self::STEP_SECONDS): array
    {
        $lease = bin2hex(random_bytes(16));
        $now = now();
        $got = DB::table('push_campaigns')->where('id', $id)->where('status', 'sending')
            ->where(fn ($q) => $q->whereNull('lock_until')->orWhere('lock_until', '<', $now))
            ->update(['lock_until' => $now->copy()->addSeconds(self::LEASE_SECONDS), 'lock_token' => $lease]);
        if ($got !== 1) {
            return $this->progress($id);
        }

        try {
            $began = hrtime(true);
            while ((hrtime(true) - $began) / 1e9 < $seconds) {
                if ($this->rules->quiet()) {
                    break;
                }
                $c = DB::table('push_campaigns')->where('id', $id)->where('lock_token', $lease)->first();
                if ($c === null || $c->status !== 'sending') {
                    break;
                }

                // Anything a dead stepper queued and never sent goes first.
                $left = DB::table('push_sends')->where('campaign_id', $id)->where('status', 'queued')->limit(self::BATCH)->pluck('id')->all();
                if ($left !== []) {
                    $this->deliver($left);
                    continue;
                }

                $spec = json_decode((string) $c->audience, true) ?: [];
                $batch = $this->audience->query($spec)->where('s.id', '>', (int) $c->cursor)->orderBy('s.id')->limit(self::BATCH)
                    ->get(['s.id', 's.region', 's.city', 's.country']);
                if ($batch->isEmpty()) {
                    DB::table('push_campaigns')->where('id', $id)->where('lock_token', $lease)->where('status', 'sending')
                        ->update(['status' => 'sent', 'finished_at' => now(), 'updated_at' => now()]);
                    break;
                }

                $room = $this->rules->room($batch->pluck('id')->all());
                $at = now();
                $rows = [];
                foreach ($batch as $s) {
                    $rows[] = [
                        'campaign_id' => $id, 'kind' => 'campaign', 'ref' => $id, 'subscription_id' => (int) $s->id,
                        'emirate' => PushAudience::emirateOf($s->region, $s->city, $s->country),
                        'title' => (string) $c->title, 'body' => (string) $c->body, 'url' => $c->url,
                        'status' => ($room[(int) $s->id] ?? false) ? 'queued' : 'held',
                        'dedupe' => 'c:'.$id.':'.$s->id, 'due_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                    ];
                }
                DB::table('push_sends')->insertOrIgnore($rows);
                DB::table('push_campaigns')->where('id', $id)->where('lock_token', $lease)
                    ->update(['cursor' => (int) $batch->last()->id, 'lock_until' => now()->addSeconds(self::LEASE_SECONDS)]);

                $queued = DB::table('push_sends')->where('campaign_id', $id)->where('status', 'queued')
                    ->whereIn('subscription_id', $batch->pluck('id')->all())->pluck('id')->all();
                $this->deliver($queued);
            }
        } finally {
            DB::table('push_campaigns')->where('id', $id)->where('lock_token', $lease)->update(['lock_until' => null, 'lock_token' => null]);
            $this->recount($id);
        }

        return $this->progress($id);
    }

    /** Recount a campaign's totals from its rows: one grouped query. */
    public function recount(int $id): void
    {
        $by = DB::table('push_sends')->where('campaign_id', $id)->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->all();
        $clicks = (int) DB::table('push_sends')->where('campaign_id', $id)->whereNotNull('clicked_at')->count();
        DB::table('push_campaigns')->where('id', $id)->update([
            'targeted' => array_sum(array_map('intval', $by)),
            'delivered' => (int) ($by['delivered'] ?? 0),
            'failed' => (int) ($by['failed'] ?? 0),
            'gone' => (int) ($by['gone'] ?? 0),
            'held' => (int) ($by['held'] ?? 0),
            'clicks' => $clicks,
        ]);
    }

    /** @return array<string, mixed> */
    public function progress(int $id): array
    {
        $c = DB::table('push_campaigns')->where('id', $id)->first();
        if ($c === null) {
            return ['status' => 'missing'];
        }

        return [
            'status' => $c->status, 'targeted' => (int) $c->targeted, 'delivered' => (int) $c->delivered,
            'failed' => (int) $c->failed, 'gone' => (int) $c->gone, 'held' => (int) $c->held, 'clicks' => (int) $c->clicks,
            'waiting_quiet' => $c->status === 'sending' && $this->rules->quiet(),
        ];
    }

    /** Stop a scheduled or sending campaign. Rows not yet sent are dropped. */
    public function cancel(int $id): bool
    {
        $stopped = DB::table('push_campaigns')->where('id', $id)->whereIn('status', ['scheduled', 'sending'])
            ->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]) === 1;
        if ($stopped) {
            DB::table('push_sends')->where('campaign_id', $id)->where('status', 'queued')->update(['status' => 'expired', 'updated_at' => now()]);
            $this->recount($id);
        }

        return $stopped;
    }

    /**
     * A test to the owner's own phones: no cap, no quiet hours, not counted
     * anywhere. Returns how many phones accepted it.
     *
     * @param  list<int>  $subscriptionIds
     */
    public function test(array $subscriptionIds, string $title, string $body, ?string $url): int
    {
        $messages = [];
        foreach (array_slice(array_values(array_unique(array_map('intval', $subscriptionIds))), 0, 5) as $sid) {
            $messages[$sid] = [$title, $body, $url];
        }

        return count(array_filter($this->testEach($messages), static fn ($r) => $r === 'delivered'));
    }

    /**
     * A test to chosen phones, each with its own text (Push Notifications →
     * Devices, Lane PD). kind 'test' and no campaign: never in a campaign's
     * totals, never in the frequency cap (PushRules::MARKETING_KINDS), and not
     * held by quiet hours, because deliver() is called directly. A phone whose
     * push service answers 404/410 goes to `gone` in deliver(), as for any send.
     *
     * @param  array<int, array{0:string, 1:string, 2:?string}>  $messages  subscription id => [title, body, url]
     * @return array<int, string> subscription id => delivered | gone | failed
     */
    public function testEach(array $messages): array
    {
        $now = now();
        $rows = [];
        foreach ($messages as $sid => [$title, $body, $url]) {
            $rows[] = ['campaign_id' => null, 'kind' => 'test', 'ref' => null, 'subscription_id' => (int) $sid, 'emirate' => null,
                'title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 200), 'url' => $url, 'status' => 'queued',
                'dedupe' => 't:'.(int) $sid.':'.bin2hex(random_bytes(8)), 'due_at' => $now, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows === []) {
            return [];
        }
        DB::table('push_sends')->insert($rows);
        $ids = DB::table('push_sends')->whereIn('dedupe', array_column($rows, 'dedupe'))->pluck('id')->all();
        $this->deliver(array_map('intval', $ids));

        $out = [];
        foreach (DB::table('push_sends')->whereIn('id', $ids)->get(['subscription_id', 'status']) as $r) {
            $out[(int) $r->subscription_id] = in_array($r->status, ['delivered', 'gone'], true) ? (string) $r->status : 'failed';
        }

        return $out;
    }

    /** A click from the worker's beacon: counted once per message. */
    public static function click(int $sendId): bool
    {
        $ok = DB::table('push_sends')->where('id', $sendId)->whereNull('clicked_at')
            ->whereIn('status', ['delivered', 'failed'])->update(['clicked_at' => now()]) === 1;
        if ($ok) {
            $cid = DB::table('push_sends')->where('id', $sendId)->value('campaign_id');
            if ($cid !== null) {
                DB::table('push_campaigns')->where('id', $cid)->increment('clicks');
            }
        }

        return $ok;
    }
}
