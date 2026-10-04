<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailLog;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\SettingsService;
use App\Support\StoreTime;
use App\Support\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sending a campaign at volume, on a shared host with no queue worker — Lane EK.
 *
 * START (send now, or a scheduled time reached): the campaign is claimed by an
 * UPDATE that only moves draft/scheduled → sending, so two presses or a press
 * and the heartbeat cannot start it twice. Then one `campaign_recipients` row
 * per address is written (insertOrIgnore on (campaign, email) — re-running is
 * harmless), the links the email carries are fixed, and the count is recorded.
 *
 * SWEEP (the heartbeat, the cron line, or the open Review & send page): at most
 * one sweep at a time (a 20-second cache lock — which is also the rate: one
 * batch of BATCH per 20 seconds is 60 a minute, the mock's figure), at most
 * TIME_BUDGET seconds of SMTP per sweep, and never past the per-day cap. Each
 * recipient row is CLAIMED — an UPDATE from queued to sending that only one
 * process can win — before its message is built, so no address is ever sent
 * the same campaign twice. A claim that never finished (the process died
 * mid-send) is marked failed after STALE minutes, never retried: a duplicate
 * in somebody's inbox is worse than a gap in a report.
 *
 * AND AT THE MOMENT OF SENDING, the address is checked against the opt-out
 * list (and, for a subscriber, the subscription) once more, so an unsubscribe
 * pressed while a campaign is half sent is honoured for the other half.
 *
 * Every send is recorded: in its recipient row (sent_at, or failed + the
 * transport's words) and, through the framework's mail events, in the delivery
 * log under Emails → Sent mail (kind `campaign.<id>`).
 */
class CampaignSender
{
    public const BATCH = 20;
    public const TIME_BUDGET = 15.0;
    public const LOCK = 'kbb.campaigns.sweep';
    public const LOCK_SECONDS = 20;
    public const STALE_MINUTES = 30;

    /** The per-day ceiling, when the owner has not set one. */
    public const CAP_GOOGLE = 1500;
    public const CAP_SERVER = 300;
    public const CAP_KEY = 'campaign_daily_cap';

    public function __construct(
        private CampaignAudience $audience,
        private CampaignRenderer $renderer,
        private SettingsService $settings,
    ) {}

    /**
     * Start sending. Returns the number of recipients, or null when the
     * campaign was not in a state to start (already started, sent, cancelled).
     */
    public function start(Campaign $campaign, ?string $by = null): ?int
    {
        $claimed = Campaign::query()->whereKey($campaign->id)->whereIn('status', ['draft', 'scheduled'])
            ->update(['status' => 'sending', 'started_at' => now(), 'sent_by' => $by, 'links' => null, 'updated_at' => now()]);

        if ($claimed !== 1) {
            return null;
        }

        $campaign->refresh();
        [$audience, $rules, $match] = $this->target($campaign);
        $rows = $this->audience->recipients($audience, $rules, $match);
        $now = now();

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('campaign_recipients')->insertOrIgnore(array_map(static fn (array $r) => [
                'campaign_id' => $campaign->id,
                'email' => $r['email'],
                'first_name' => $r['first_name'] !== '' ? $r['first_name'] : null,
                'customer_id' => $r['customer_id'],
                'subscriber_id' => $r['subscriber_id'],
                'token_hash' => CampaignTracking::hash(CampaignTracking::token((int) $campaign->id, $r['email'])),
                'status' => 'queued',
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }

        $ctx = $this->context($campaign);
        $links = $this->renderer->forSending($campaign, null, $ctx)['links'];

        $campaign->forceFill([
            'links' => $links,
            'total_recipients' => DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count(),
        ])->save();

        return (int) $campaign->total_recipients;
    }

    /**
     * Send the next batch. Returns how many went out. Safe to call from
     * anywhere at any time: it does nothing when another sweep holds the lock,
     * when nothing is due, or when today's cap is reached.
     */
    public function sweep(int $budget = self::BATCH): int
    {
        if (! Cache::add(self::LOCK, time(), self::LOCK_SECONDS)) {
            return 0;
        }

        $started = microtime(true);
        $sent = 0;

        try {
            foreach (Campaign::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->limit(5)->get() as $due) {
                $this->start($due, $due->sent_by);
            }

            $this->retireStale();

            $room = min($budget, $this->remainingToday());

            foreach (Campaign::query()->where('status', 'sending')->whereNotNull('links')->orderBy('started_at')->get() as $campaign) {
                if ($room <= 0 || microtime(true) - $started > self::TIME_BUDGET) {
                    break;
                }

                $done = $this->batch($campaign, $room, $started);
                $sent += $done;
                $room -= $done;
                $this->finishIfDone($campaign);
            }
        } catch (\Throwable $e) {
            Log::warning('campaign sweep stopped', ['exception' => class_basename($e), 'message' => $e->getMessage()]);
        }

        return $sent;
    }

    /** The campaign to the signed-in admin, filled with a sample name. Goes through the Sending & delivery test path. */
    public function test(Campaign $campaign, string $to, string $firstName = ''): array
    {
        $ctx = $this->context($campaign);
        $tpl = $this->renderer->forSending($campaign, null, $ctx);
        $token = 'test-' . substr(hash('sha256', $to . microtime()), 0, 38);
        $mail = new CampaignMail(
            '[Test] ' . CampaignRenderer::subject($campaign, $firstName),
            CampaignRenderer::personalise($tpl['html'], $token, $firstName),
            CampaignRenderer::personalise($tpl['text'], $token, $firstName),
            Url::external('/m/u/' . $token),
            $this->fromAddress(),
            $this->fromName($campaign),
        );

        $result = app(MailTester::class)->send($to, $mail, 'campaign.test');

        if (($result['ok'] ?? false) === true) {
            $campaign->forceFill(['test_sent_at' => now(), 'test_sent_to' => $to])->save();
        }

        return $result;
    }

    /** The ceiling on campaign messages per day (the shop's own day). */
    public function cap(): int
    {
        $set = (int) $this->settings->get(self::CAP_KEY, 0);

        if ($set > 0) {
            return min($set, 100_000);
        }

        return app(MailSettings::class)->transport() === MailSettings::TRANSPORT_GMAIL ? self::CAP_GOOGLE : self::CAP_SERVER;
    }

    public function sentToday(): int
    {
        return DB::table('campaign_recipients')->where('sent_at', '>=', StoreTime::startOfDayUtc()->toDateTimeString())->count();
    }

    public function remainingToday(): int
    {
        return max(0, $this->cap() - $this->sentToday());
    }

    /** @return array{0:string,1:array,2:string} audience, rules, match */
    public function target(Campaign $campaign): array
    {
        $group = $campaign->group_id ? CampaignGroup::query()->find($campaign->group_id) : null;

        if ($group !== null) {
            return [(string) $group->audience, (array) $group->rules, (string) $group->match];
        }

        return [(string) $campaign->audience, [], 'all'];
    }

    /** What a "This group's top brand" grid fills from, worked out once per render. */
    public function context(Campaign $campaign): array
    {
        $ctx = [];

        foreach ((array) $campaign->blocks as $b) {
            if (($b['type'] ?? '') === 'products' && ($b['fill'] ?? '') === 'group_brand') {
                [$audience, $rules, $match] = $this->target($campaign);
                $top = $this->audience->topBrand($audience, $rules, $match);
                $ctx['group_brand_id'] = $top[0] ?? 0;
                $ctx['group_brand_name'] = $top[1] ?? '';
                break;
            }
        }

        return $ctx;
    }

    /* ------------------------------------------------------------- internals */

    private function batch(Campaign $campaign, int $room, float $started): int
    {
        $ids = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->where('status', 'queued')
            ->orderBy('id')->limit($room)->pluck('id')->all();

        if ($ids === []) {
            return 0;
        }

        $tpl = $this->renderer->forSending($campaign, (array) $campaign->links, $this->context($campaign));
        $fromAddress = $this->fromAddress();
        $fromName = $this->fromName($campaign);
        $sent = 0;

        foreach ($ids as $id) {
            if (microtime(true) - $started > self::TIME_BUDGET) {
                break;
            }

            // THE CLAIM. Only one process moves this row out of `queued`.
            $won = DB::table('campaign_recipients')->where('id', $id)->where('status', 'queued')
                ->update(['status' => 'sending', 'claimed_at' => now(), 'updated_at' => now()]);

            if ($won !== 1) {
                continue;
            }

            $r = DB::table('campaign_recipients')->where('id', $id)->first();

            if ($r === null) {
                continue;
            }

            if ($this->mayNotSend($r)) {
                DB::table('campaign_recipients')->where('id', $id)->update(['status' => 'skipped', 'error' => 'Unsubscribed before it was sent.', 'updated_at' => now()]);

                continue;
            }

            $token = CampaignTracking::token((int) $campaign->id, (string) $r->email);
            $first = (string) ($r->first_name ?? '');

            try {
                $mail = new CampaignMail(
                    CampaignRenderer::subject($campaign, $first),
                    CampaignRenderer::personalise($tpl['html'], $token, $first),
                    CampaignRenderer::personalise($tpl['text'], $token, $first),
                    Url::external('/m/u/' . $token),
                    $fromAddress,
                    $fromName,
                );

                app(MailLog::class)->labelNext('campaign.' . $campaign->id);
                Mail::mailer(MailConfigurator::MAILER)->to((string) $r->email)->send($mail);

                DB::table('campaign_recipients')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
                Campaign::query()->whereKey($campaign->id)->increment('sent_count');
                $sent++;
            } catch (\Throwable $e) {
                DB::table('campaign_recipients')->where('id', $id)->update([
                    'status' => 'failed', 'error' => mb_substr(class_basename($e) . ': ' . $e->getMessage(), 0, 255), 'updated_at' => now(),
                ]);
                Campaign::query()->whereKey($campaign->id)->increment('failed_count');
            }
        }

        return $sent;
    }

    /** Has this address left since the list was built? */
    private function mayNotSend(object $r): bool
    {
        if (CampaignTracking::optedOut((string) $r->email)) {
            return true;
        }

        if ($r->subscriber_id !== null) {
            return ! DB::table('subscribers')->where('id', $r->subscriber_id)->where('status', 'subscribed')->whereNotNull('confirmed_at')->exists();
        }

        return false;
    }

    private function retireStale(): void
    {
        DB::table('campaign_recipients')->where('status', 'sending')
            ->where('claimed_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => 'failed', 'error' => 'Interrupted while sending; not retried, so it cannot arrive twice.', 'updated_at' => now()]);
    }

    private function finishIfDone(Campaign $campaign): void
    {
        $open = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->whereIn('status', ['queued', 'sending'])->exists();

        if (! $open) {
            Campaign::query()->whereKey($campaign->id)->where('status', 'sending')->update(['status' => 'sent', 'finished_at' => now(), 'updated_at' => now()]);
        }
    }

    private function fromAddress(): ?string
    {
        try {
            $from = app(MailSettings::class)->fromAddress();

            return $from !== '' ? $from : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromName(Campaign $campaign): ?string
    {
        $name = trim((string) $campaign->from_name);

        return $name !== '' ? $name : null;
    }
}
