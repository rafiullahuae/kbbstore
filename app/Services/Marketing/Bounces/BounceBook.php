<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of bounces and the "Not sending to" list (Lane EB).
 *
 * The owner: "those emails will auto removed from the list and sit as
 * seperate list".
 *
 *   hard       written to email_bounces, AND at once to email_suppressions
 *              (reason bounce) — Audience leaves it out of every customer
 *              group and CampaignSender::blockedNow() skips it mid-send — AND
 *              a newsletter subscriber's row moves from `subscribed` to
 *              `bounced`, the separate list. Forever, until Restore.
 *   soft       written; the third within 30 days (not counting any before a
 *              Restore) is treated exactly as hard.
 *   delay      written for the record, never counted.
 *   complaint  written and suppressed (reason complaint). Never restorable
 *              here: somebody who pressed "spam" did not ask to come back.
 *
 * "Remove bounced addresses automatically" (Marketing Emails → Bounces &
 * unsubscribes → Settings) is ON by default — the owner asked for it. Off,
 * reports are still recorded and shown, and nothing is removed.
 *
 * TRANSACTIONAL MAIL IS NOT BLOCKED, by any of this, DECIDED. An order
 * confirmation goes to the address the customer typed on THAT order: newer
 * evidence than a bounce from last month, and a receipt is owed even to an
 * unsubscribed customer (which is why unsubscribes never touched it). The cost
 * of sending it to a dead address is one bounce; the cost of a mis-read report
 * blocking it is a customer with no receipt. The order mailer is not touched.
 */
final class BounceBook
{
    public const SOFT_LIMIT = 3;

    public const SOFT_DAYS = 30;

    public const AUTO_KEY = 'mkt_bounce_auto_remove';

    public function __construct(private SettingsService $settings) {}

    public function autoRemove(): bool
    {
        $v = $this->settings->get(self::AUTO_KEY, '1');

        return ! in_array((string) $v, ['0', 'false', ''], true);
    }

    /**
     * Record one report about one address. Returns what happened:
     * 'suppressed' | 'counted' | 'recorded' | 'duplicate'.
     *
     * @param  array{email:string, kind:string, code?:?string, detail?:?string, campaign_id?:?int, send_id?:?int, source:string, report_id?:?string}  $e
     */
    public function record(array $e): string
    {
        $email = mb_strtolower(trim($e['email']));

        if ($email === '' || mb_strlen($email) > 191) {
            return 'recorded';
        }

        $kind = in_array($e['kind'], ['hard', 'soft', 'delay', 'complaint'], true) ? $e['kind'] : 'soft';
        $row = [
            'email' => $email,
            'kind' => $kind,
            'code' => isset($e['code']) && $e['code'] !== '' ? mb_substr((string) $e['code'], 0, 12) : null,
            'detail' => isset($e['detail']) && $e['detail'] !== '' ? mb_substr((string) $e['detail'], 0, 255) : null,
            'campaign_id' => $e['campaign_id'] ?? null,
            'send_id' => $e['send_id'] ?? null,
            'source' => mb_substr($e['source'], 0, 8),
            'report_id' => isset($e['report_id']) && $e['report_id'] !== '' ? mb_substr((string) $e['report_id'], 0, 191) : null,
            'created_at' => now(),
        ];

        if (DB::table('email_bounces')->insertOrIgnore($row) === 0) {
            return 'duplicate';
        }

        if ($kind === 'delay') {
            return 'recorded';
        }

        if ($kind === 'soft') {
            $n = DB::table('email_bounces')->where('email', $email)->where('kind', 'soft')->whereNull('cleared_at')
                ->where('created_at', '>=', now()->subDays(self::SOFT_DAYS))->count();

            if ($n < self::SOFT_LIMIT) {
                return 'counted';
            }

            $row['detail'] = $n . ' soft bounces in ' . self::SOFT_DAYS . ' days' . ($row['detail'] ? ' — last: ' . $row['detail'] : '');
        }

        if (! $this->autoRemove()) {
            return 'recorded';
        }

        $this->suppress($email, $kind === 'complaint' ? 'complaint' : 'bounce', $row);

        return 'suppressed';
    }

    /** @param array<string, mixed> $row */
    private function suppress(string $email, string $reason, array $row): void
    {
        DB::table('email_suppressions')->insertOrIgnore([
            'email' => $email, 'reason' => $reason,
            'source' => $row['campaign_id'] ? 'campaign:' . $row['campaign_id'] : $row['source'],
            'created_at' => now(),
        ]);

        // Keep the newest evidence on a bounce row; an unsubscribe stays an
        // unsubscribe (it is the stronger request and never restorable).
        DB::table('email_suppressions')->where('email', $email)->whereIn('reason', ['bounce', 'complaint'])->update([
            'reason' => $reason,
            'code' => mb_substr((string) ($row['code'] ?? ''), 0, 12) ?: null,
            'detail' => mb_substr((string) ($row['detail'] ?? ''), 0, 255) ?: null,
            'campaign_id' => $row['campaign_id'] ?? null,
        ]);

        DB::table('subscribers')->where('email', $email)->where('status', 'subscribed')   // never a pending one: Restore must not skip double opt-in
            ->update(['status' => 'bounced', 'updated_at' => now()]);
    }

    /**
     * Restore a bounced address: off the list, the soft count back to zero, a
     * newsletter subscriber back to `subscribed`. Only `bounce` rows: an
     * unsubscribe or a complaint is the person's own request.
     */
    public function restore(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        $gone = DB::table('email_suppressions')->where('email', $email)->where('reason', 'bounce')->delete();

        DB::table('email_bounces')->where('email', $email)->whereNull('cleared_at')->update(['cleared_at' => now()]);

        if ($gone > 0) {
            DB::table('subscribers')->where('email', $email)->where('status', 'bounced')
                ->update(['status' => 'subscribed', 'updated_at' => now()]);
        }

        return $gone > 0;
    }
}
