<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Mail\CampaignMail;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailLog;
use App\Support\Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sending a campaign on a host with no queue worker (Lane MK,
 * docs/EMAILS-PLAN.md §4 — CustomerInviter's design, not a queue).
 *
 * ── START: FREEZE ──────────────────────────────────────────────────────────
 *
 * start() snapshots the draft's blocks (with every product block's ids
 * resolved and the latest posts filled in) and the group's rules, writes the
 * campaign's link list (mkt_links), and flips the status to `sending` in ONE
 * conditional UPDATE … WHERE status IN ('draft', 'scheduled'). Two ticks, two
 * tabs or a tick and a tab can all try; exactly one wins, which is what makes
 * a scheduled campaign fire once.
 *
 * ── STEP ───────────────────────────────────────────────────────────────────
 *
 * step() is shared by both drivers — the admin's open tab (Driver A, POST …
 * /campaigns/{id}/step) and the scheduler (Driver B, kbb:campaigns-step). One
 * step does ONE of:
 *
 *   build   write the next 1,000 people who can be emailed into mkt_sends
 *           (insert-or-ignore on unique(campaign_id, email), so repeating a
 *           slice is harmless), or
 *   send    claim up to min(rate left this minute, daily cap left, 25) rows,
 *           each by its own conditional UPDATE … WHERE status = 'pending' —
 *           a row two steps both want is sent by whichever UPDATE matched,
 *           and the other moves on — render, send, mark sent or failed, and
 *           stop after 15 seconds, handing unsent claims back.
 *
 * A claim older than 180 seconds belongs to a step that died mid-request and
 * goes back to `pending` (plan §4.2).
 *
 * ── NEVER ON A SHOPPER'S REQUEST ───────────────────────────────────────────
 *
 * Nothing here is reachable from a storefront page. Back-in-stock and basket
 * reminders ride OutboundTick on the tail of page views; marketing does not,
 * because hundreds of SMTP conversations must not run on the end of a
 * customer's page load. tests/Feature/MarketingEmailsSendingTest.php pins it.
 *
 * ── RECHECKED AT THE MOMENT OF SENDING ─────────────────────────────────────
 *
 * The list is written when the send starts; an address that unsubscribes,
 * bounces or opts out while a 4,000-person send is half done is checked
 * again per step (one query) and skipped.
 */
final class CampaignSender
{
    public const STALE_SECONDS = 180;

    public const SLICE = 1000;

    public const MAIL_KIND = 'campaign';

    public function __construct(
        private Audience $audience,
        private CampaignRenderer $renderer,
        private SendLimits $limits,
        private MailLog $log,
    ) {}

    /* --------------------------------------------------------------- start */

    /**
     * Freeze and start. Returns [ok, message].
     *
     * @return array{0:bool, 1:string}
     */
    public function start(int $id, ?int $by = null): array
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null || ! in_array($c->status, ['draft', 'scheduled'], true)) {
            return [false, 'This campaign has already been sent or started.'];
        }

        $errors = [];
        $blocks = Blocks::clean(json_decode((string) $c->blocks, true), $errors);

        if ($errors !== []) {
            return [false, $errors[0]];
        }

        if (trim((string) $c->subject) === '') {
            return [false, 'Write a subject line first.'];
        }

        $segment = $c->segment_id ? DB::table('mkt_segments')->where('id', $c->segment_id)->first() : null;

        if ($segment === null) {
            return [false, 'Choose who gets it: pick a customer group.'];
        }

        $rules = Audience::clean((string) $segment->audience, json_decode((string) $segment->rules, true) ?: []);
        $match = Audience::cleanMatch($segment->match);
        $top = $this->needsTopBrand($blocks) ? $this->audience->topBrand((string) $segment->audience, $rules, $match) : null;

        $frozen = $this->renderer->materialize($blocks, $top['id'] ?? null);
        $data = $this->renderer->data($frozen);
        $sample = $this->renderer->render($frozen, self::brandVars($top) + [
            'data' => $data, 'audience' => $segment->audience, 'subject' => $c->subject, 'preheader' => $c->preheader,
            'unsubscribe' => Url::external('/email/u/' . str_repeat('z', 13) . '-' . str_repeat('0', 32)),
            'href' => fn (string $url) => Url::external('/email/c/' . str_repeat('0', 40) . '/99'),
        ]);

        if ($sample['bytes'] > CampaignRenderer::MAX_BYTES) {
            return [false, 'This email is ' . round($sample['bytes'] / 1024) . ' KB. Gmail cuts emails over 102 KB, so the builder refuses above 95 KB — remove a block or two.'];
        }

        $won = DB::table('mkt_campaigns')->where('id', $id)->whereIn('status', ['draft', 'scheduled'])->update([
            'status' => 'sending',
            'started_at' => now(),
            'blocks_snapshot' => json_encode($frozen),
            'rules_snapshot' => json_encode([
                'audience' => $segment->audience, 'match' => $match, 'rules' => $rules,
                'segment' => (string) $segment->name, 'top_brand' => $top,
            ]),
            'audience' => $segment->audience,
            'audience_cursor' => 0,
            'audience_built' => false,
            'html_bytes' => $sample['bytes'],
            'sent_by' => $by,
            'updated_at' => now(),
        ]);

        if ($won !== 1) {
            return [false, 'This campaign has already been started.'];
        }

        DB::table('mkt_links')->where('campaign_id', $id)->delete();
        $rows = [];

        foreach ($this->renderer->links($frozen, $data, (string) $segment->audience, self::brandVars($top)) as $n => $link) {
            $rows[] = ['campaign_id' => $id, 'n' => $n + 1, 'url' => $link['url'], 'label' => $link['label']];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('mkt_links')->insert($chunk);
        }

        return [true, 'Sending.'];
    }

    /**
     * {top_brand} and the "Shop all …" button's address, from the group's
     * top brand (Audience::topBrand()), for the renderer.
     *
     * @return array{top_brand:string, top_brand_url:string}
     */
    public static function brandVars(?array $top): array
    {
        $slug = (string) ($top['slug'] ?? '');

        return [
            'top_brand' => (string) ($top['name'] ?? ''),
            'top_brand_url' => Url::external($slug !== '' ? '/brands/' . rawurlencode($slug) . '/' : '/brands/'),
        ];
    }

    /** @param list<array{type:string, props:array<string,mixed>}> $blocks */
    public function needsTopBrand(array $blocks): bool
    {
        foreach ($blocks as $b) {
            if (in_array($b['type'], ['product_row', 'product_grid'], true) && ($b['props']['fill'] ?? '') === 'group_top_brand') {
                return true;
            }
        }

        return false;
    }

    /* ---------------------------------------------------------------- step */

    /** One step. Returns progress(). */
    public function step(int $id): array
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return ['id' => $id, 'status' => 'missing', 'done' => true];
        }

        if ($c->status !== 'sending') {
            return $this->progress($id);
        }

        $this->recoverStale($id);

        if (! $c->audience_built) {
            $this->buildSlice($c);

            return $this->progress($id);
        }

        $room = $this->limits->room();

        if ($room['allowed'] > 0) {
            $this->sendSome($c, $room['allowed']);
        }

        $this->finishIfDone($id);

        return $this->progress($id) + ['room' => $room];
    }

    /** A claim older than STALE_SECONDS belongs to a dead step: back to pending. */
    public function recoverStale(int $id): int
    {
        return DB::table('mkt_sends')
            ->where('campaign_id', $id)
            ->where('status', 'claimed')
            ->where('claimed_at', '<', now()->subSeconds(self::STALE_SECONDS))
            ->update(['status' => 'pending', 'claimed_at' => null]);
    }

    private function buildSlice(object $c): void
    {
        $snap = json_decode((string) $c->rules_snapshot, true) ?: [];
        $people = $this->audience->recipientsAfter(
            (string) ($snap['audience'] ?? 'customers'),
            (array) ($snap['rules'] ?? []),
            (string) ($snap['match'] ?? 'all'),
            (int) $c->audience_cursor,
            self::SLICE,
        );

        $isSubs = ($snap['audience'] ?? '') === 'subscribers';
        $rows = [];

        foreach ($people as $p) {
            $rows[] = [
                'campaign_id' => (int) $c->id,
                'email' => mb_substr($p['email'], 0, 191),
                'first_name' => $p['first_name'] !== '' ? mb_substr($p['first_name'], 0, 120) : null,
                'customer_id' => $isSubs ? null : $p['id'],
                'subscriber_id' => $isSubs ? $p['id'] : null,
                'token' => bin2hex(random_bytes(20)),
                'status' => 'pending',
            ];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('mkt_sends')->insertOrIgnore($chunk);
        }

        $last = $people === [] ? (int) $c->audience_cursor : (int) end($people)['id'];
        $done = count($people) < self::SLICE;

        DB::table('mkt_campaigns')->where('id', $c->id)->update([
            'audience_cursor' => $last,
            'audience_built' => $done,
            'recipients' => DB::table('mkt_sends')->where('campaign_id', $c->id)->count(),
            'updated_at' => now(),
        ]);
    }

    private function sendSome(object $c, int $allowed): void
    {
        $started = hrtime(true);
        $candidates = DB::table('mkt_sends')->where('campaign_id', $c->id)->where('status', 'pending')
            ->orderBy('id')->limit($allowed)->pluck('id')->all();

        $claimed = [];

        foreach ($candidates as $sendId) {
            // The claim. One row, one conditional UPDATE: whichever step
            // matches status = 'pending' first owns the row.
            if (DB::table('mkt_sends')->where('id', $sendId)->where('status', 'pending')
                ->update(['status' => 'claimed', 'claimed_at' => now()]) === 1) {
                $claimed[] = (int) $sendId;
            }
        }

        if ($claimed === []) {
            return;
        }

        $rows = DB::table('mkt_sends')->whereIn('id', $claimed)->orderBy('id')->get();
        $blocked = $this->blockedNow($rows->pluck('email')->all());
        $blocks = json_decode((string) $c->blocks_snapshot, true) ?: [];
        $snap = json_decode((string) $c->rules_snapshot, true) ?: [];
        $links = DB::table('mkt_links')->where('campaign_id', $c->id)->pluck('n', 'url')->all();
        $data = $this->renderer->data($blocks);

        foreach ($rows as $row) {
            if ((hrtime(true) - $started) / 1e9 > SendLimits::STEP_SECONDS) {
                // Out of time: hand the rest back untouched.
                DB::table('mkt_sends')->where('id', $row->id)->where('status', 'claimed')
                    ->update(['status' => 'pending', 'claimed_at' => null]);

                continue;
            }

            if (isset($blocked[mb_strtolower($row->email)])) {
                DB::table('mkt_sends')->where('id', $row->id)->update([
                    'status' => 'skipped', 'error' => 'Unsubscribed or bounced after the list was written.', 'claimed_at' => null,
                ]);

                continue;
            }

            $this->sendOne($c, $row, $blocks, $snap, $links, $data);
        }
    }

    /**
     * @param  array<string, int>  $links  url => n
     */
    private function sendOne(object $c, object $row, array $blocks, array $snap, array $links, array $data): void
    {
        $token = (string) $row->token;
        $unsubscribe = Url::external('/email/u/' . UnsubscribeToken::for((int) $row->id, (string) $row->email));

        try {
            $out = $this->renderer->render($blocks, self::brandVars($snap['top_brand'] ?? null) + [
                'data' => $data,
                'audience' => $snap['audience'] ?? 'customers',
                'first_name' => (string) ($row->first_name ?? ''),
                'subject' => $c->subject,
                'preheader' => $c->preheader,
                'unsubscribe' => $unsubscribe,
                'href' => fn (string $url) => isset($links[$url])
                    ? Url::external('/email/c/' . $token . '/' . $links[$url])
                    : $url,
            ]);

            $mailable = new CampaignMail(
                Blocks::mergeName((string) $c->subject, (string) ($row->first_name ?? '')),
                $out['html'],
                $out['text'],
                $unsubscribe,
                $c->from_name,
            );

            $this->log->labelNext(self::MAIL_KIND . '.' . $c->id);
            Mail::mailer(MailConfigurator::MAILER)->to((string) $row->email)->send($mailable);
        } catch (\Throwable $e) {
            $words = trim(class_basename($e) . ': ' . str_replace($token, '[token]', $e->getMessage()));
            $hard = preg_match('/\b5\d\d\b|\b5\.\d\.\d+\b/', $e->getMessage()) === 1;

            DB::table('mkt_sends')->where('id', $row->id)->update([
                'status' => 'failed', 'sent_at' => now(), 'claimed_at' => null,
                'error' => Str::limit(($hard ? 'HARD ' : '') . $words, 290),
            ]);

            if ($hard) {
                $this->maybeSuppressBounce((string) $row->email, (int) $c->id, (int) $row->id);
            }

            try {
                $this->log->recordFailure($e);
            } catch (\Throwable) {
            }

            Log::warning('A campaign message could not be sent.', ['campaign' => $c->id, 'send' => $row->id, 'exception' => $e::class]);

            return;
        }

        DB::table('mkt_sends')->where('id', $row->id)->update(['status' => 'sent', 'sent_at' => now(), 'claimed_at' => null]);
    }

    /**
     * A second hard (5xx) refusal for the same address writes a `bounce`
     * suppression (plan §4, "Bounces"). One is not enough: a mailbox can be
     * full for a day.
     */
    private function maybeSuppressBounce(string $email, int $campaignId, int $sendId): void
    {
        $email = mb_strtolower(trim($email));
        $hard = DB::table('mkt_sends')->where('email', $email)->where('status', 'failed')
            ->where('error', 'like', 'HARD %')->count();

        if ($hard >= 2) {
            DB::table('email_suppressions')->insertOrIgnore([
                'email' => $email, 'reason' => 'bounce', 'source' => 'campaign:' . $campaignId, 'created_at' => now(),
            ]);
        }
    }

    /**
     * Of these addresses, the ones that must not be mailed NOW — one query
     * each over the three lists, whatever the step size.
     *
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    public function blockedNow(array $emails): array
    {
        $emails = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim((string) $e)), $emails)));

        if ($emails === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('email_suppressions')->whereIn('email', $emails)->pluck('email') as $e) {
            $out[mb_strtolower($e)] = true;
        }

        foreach (DB::table('outbound_optouts')->whereIn('email', $emails)->pluck('email') as $e) {
            $out[mb_strtolower($e)] = true;
        }

        foreach (DB::table('subscribers')->whereIn('email', $emails)->where('status', '<>', 'subscribed')->pluck('email') as $e) {
            $out[mb_strtolower($e)] = true;
        }

        return $out;
    }

    private function finishIfDone(int $id): void
    {
        $open = DB::table('mkt_sends')->where('campaign_id', $id)->whereIn('status', ['pending', 'claimed'])->exists();

        if (! $open) {
            DB::table('mkt_campaigns')->where('id', $id)->where('status', 'sending')->where('audience_built', true)
                ->update(['status' => 'sent', 'finished_at' => now(), 'updated_at' => now()]);
        }

        $this->syncCounters($id);
    }

    /** sent / failed / skipped from the rows, one grouped query. */
    public function syncCounters(int $id): void
    {
        $counts = DB::table('mkt_sends')->where('campaign_id', $id)->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        DB::table('mkt_campaigns')->where('id', $id)->update([
            'recipients' => array_sum($counts),
            'sent' => $counts['sent'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
        ]);
    }

    /* ----------------------------------------------------- pause / cancel */

    public function pause(int $id): bool
    {
        return DB::table('mkt_campaigns')->where('id', $id)->where('status', 'sending')
            ->update(['status' => 'paused', 'updated_at' => now()]) === 1;
    }

    public function resume(int $id): bool
    {
        return DB::table('mkt_campaigns')->where('id', $id)->where('status', 'paused')
            ->update(['status' => 'sending', 'updated_at' => now()]) === 1;
    }

    /** Cancel: nothing more goes out; rows not yet sent are marked skipped. */
    public function cancel(int $id): bool
    {
        $done = DB::table('mkt_campaigns')->where('id', $id)->whereIn('status', ['scheduled', 'sending', 'paused'])
            ->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]) === 1;

        if ($done) {
            DB::table('mkt_sends')->where('campaign_id', $id)->where('status', 'pending')
                ->update(['status' => 'skipped', 'error' => 'Cancelled before it was sent.']);
            $this->syncCounters($id);
        }

        return $done;
    }

    /* ------------------------------------------------------------ progress */

    /** @return array<string, mixed> */
    public function progress(int $id): array
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return ['id' => $id, 'status' => 'missing', 'done' => true];
        }

        $counts = DB::table('mkt_sends')->where('campaign_id', $id)->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        $total = array_sum($counts);

        return [
            'id' => (int) $c->id,
            'status' => (string) $c->status,
            'building' => $c->status === 'sending' && ! $c->audience_built,
            'recipients' => $total,
            'sent' => $counts['sent'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'skipped' => $counts['skipped'] ?? 0,
            'pending' => ($counts['pending'] ?? 0) + ($counts['claimed'] ?? 0),
            'done' => in_array($c->status, ['sent', 'cancelled', 'failed'], true),
        ];
    }

    /* ---------------------------------------------------------------- test */

    /**
     * A test to one address: the real render, "[Test]" in front of the
     * subject, no tracking, and an unsubscribe link that unsubscribes nobody
     * (a token for no row — the public page answers it like any other).
     *
     * @return array{0:bool, 1:string}
     */
    public function test(int $id, string $to, string $firstName = ''): array
    {
        $c = DB::table('mkt_campaigns')->where('id', $id)->first();

        if ($c === null) {
            return [false, 'No such campaign.'];
        }

        $errors = [];
        $blocks = Blocks::clean(json_decode((string) $c->blocks, true), $errors);

        if ($errors !== []) {
            return [false, $errors[0]];
        }

        $segment = $c->segment_id ? DB::table('mkt_segments')->where('id', $c->segment_id)->first() : null;
        $audience = $segment !== null ? (string) $segment->audience : 'customers';
        $top = $segment !== null && $this->needsTopBrand($blocks)
            ? $this->audience->topBrand($audience, json_decode((string) $segment->rules, true) ?: [], Audience::cleanMatch($segment->match))
            : null;

        $frozen = $this->renderer->materialize($blocks, $top['id'] ?? null);
        $unsubscribe = Url::external('/email/u/0-' . str_repeat('0', 32));
        $out = $this->renderer->render($frozen, self::brandVars($top) + [
            'audience' => $audience, 'first_name' => $firstName, 'subject' => $c->subject, 'preheader' => $c->preheader,
            'unsubscribe' => $unsubscribe,
        ]);

        try {
            $this->log->labelNext(self::MAIL_KIND . '.test');
            Mail::mailer(MailConfigurator::MAILER)->to($to)->send(new CampaignMail(
                '[Test] ' . Blocks::mergeName((string) $c->subject, $firstName),
                $out['html'], $out['text'], $unsubscribe, $c->from_name,
            ));
        } catch (\Throwable $e) {
            return [false, 'The mail server refused it: ' . Str::limit(class_basename($e) . ': ' . $e->getMessage(), 200)];
        }

        DB::table('mkt_campaigns')->where('id', $id)->update(['test_sent_at' => now(), 'test_sent_to' => mb_substr($to, 0, 191)]);

        return [true, 'Test sent to ' . $to . '.'];
    }
}
