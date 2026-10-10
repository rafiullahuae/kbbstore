<?php

declare(strict_types=1);

namespace App\Services\Marketing\Bounces;

use App\Services\Marketing\UnsubscribeToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the "KBB Bounces" label and files what it finds (Lane EB).
 *
 * `php artisan kbb:bounces-read`, every five minutes (routes/console.php,
 * withoutOverlapping), and the "Read now" button. Each run:
 *
 *   1. does NOTHING — no connection — unless Read bounces is on and an
 *      account and app password exist;
 *   2. signs in to imap.gmail.com:993 over TLS and SELECTs the label, and only
 *      the label (BounceMailbox::cleanLabel() refuses Inbox, Sent, Spam…);
 *   3. reads at most BATCH messages, oldest first, with BODY.PEEK;
 *   4. files each (DsnParser → BounceBook), then MOVES it, by its UID, to
 *      "KBB Bounces/Processed" — so the label holds only what is still to do
 *      and a report is never counted twice. A message that cannot be filed is
 *      moved too (it was parsed; leaving it would re-read it every five
 *      minutes for ever), and the count says so.
 *
 * WHO A REPORT MAY NAME. A report is trusted for an address when the
 * Message-ID it quotes is one this shop signed for that address (BounceRef),
 * or — for a report without one, e.g. an order email's bounce — when the
 * address is already somebody this shop mails (a send row, a subscriber or a
 * customer). Anything else names a stranger, and a stranger's address is not
 * written anywhere.
 *
 * The last run's outcome is kept in a small file beside the campaign tick
 * marker, NOT a settings row: a settings write every five minutes would empty
 * the shop's settings cache every five minutes (CLAUDE.md, speed is frozen).
 */
final class BounceReader
{
    public const BATCH = 50;

    private ImapTransport $transport;

    /** No container binding needed: the tests bind ImapTransport to a fake. */
    public function __construct(
        private BounceMailbox $box,
        private BounceBook $book,
        ?ImapTransport $transport = null,
    ) {
        $this->transport = $transport ?? new SocketImapTransport;
    }

    public static function statePath(): string
    {
        return storage_path('framework/kbb-bounces.json');
    }

    /** @return array<string, mixed>|null */
    public static function lastRun(): ?array
    {
        $raw = @file_get_contents(self::statePath());
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /**
     * Sign in and look at the label, change nothing.
     *
     * @return array{ok:bool, message:string, waiting?:int}
     */
    public function test(): array
    {
        if (! $this->box->configured()) {
            return ['ok' => false, 'message' => 'No Google account and app password yet. Fill them in under Store → Mail (Google Workspace), or give a mailbox below.'];
        }

        $imap = new ImapClient($this->transport);

        try {
            $imap->connect(BounceMailbox::HOST, BounceMailbox::PORT);
            $imap->login($this->box->username(), $this->box->password());
            $n = $imap->select($this->box->label());
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->words($e)];
        } finally {
            $imap->logout();
        }

        return ['ok' => true, 'waiting' => $n, 'message' => 'Connected to ' . $this->box->username() . '. The label "' . $this->box->label() . '" has ' . $n . ' report' . ($n === 1 ? '' : 's') . ' waiting.'];
    }

    /** @return array<string, mixed> */
    public function run(bool $force = false): array
    {
        if (! $force && ! $this->box->enabled()) {
            return ['ran' => false, 'why' => 'off'];
        }

        if (! $this->box->configured()) {
            return $this->remember(['ran' => false, 'ok' => false, 'why' => 'No Google account and app password.']);
        }

        $out = ['ran' => true, 'ok' => true, 'read' => 0, 'hard' => 0, 'soft' => 0, 'delay' => 0, 'complaint' => 0, 'unsubscribe' => 0, 'ignored' => 0, 'left' => 0];
        $imap = new ImapClient($this->transport);

        try {
            $imap->connect(BounceMailbox::HOST, BounceMailbox::PORT);
            $imap->login($this->box->username(), $this->box->password());
            $imap->select($this->box->label());
            $uids = $imap->uids();
            $out['left'] = max(0, count($uids) - self::BATCH);

            if ($uids !== []) {
                $imap->ensure($this->box->processedLabel());
            }

            foreach (array_slice($uids, 0, self::BATCH) as $uid) {
                $raw = $imap->fetch($uid);

                foreach ($this->file($raw) as $what => $n) {
                    $out[$what] += $n;
                }

                $out['read']++;
                $imap->move($uid, $this->box->processedLabel());
            }
        } catch (\Throwable $e) {
            $out['ok'] = false;
            $out['why'] = $this->words($e);
        } finally {
            $imap->logout();
        }

        return $this->remember($out);
    }

    /**
     * File one raw message. Public for the tests and the parser's samples.
     *
     * @return array<string, int>
     */
    public function file(string $raw): array
    {
        $r = DsnParser::parse($raw);
        $tally = ['hard' => 0, 'soft' => 0, 'delay' => 0, 'complaint' => 0, 'unsubscribe' => 0, 'ignored' => 0];

        if ($r['type'] === 'unsubscribe') {
            $row = UnsubscribeToken::find((string) $r['token']);

            if ($row === null) {
                $tally['ignored']++;

                return $tally;
            }

            $email = mb_strtolower(trim((string) $row->email));
            DB::table('email_suppressions')->insertOrIgnore([
                'email' => $email, 'reason' => 'unsubscribe', 'source' => 'campaign:' . (int) $row->campaign_id, 'created_at' => now(),
            ]);
            DB::table('subscribers')->where('email', $email)->where('status', '<>', 'unsubscribed')
                ->update(['status' => 'unsubscribed', 'updated_at' => now()]);

            if (DB::table('mkt_sends')->where('id', $row->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]) === 1) {
                DB::table('mkt_campaigns')->where('id', $row->campaign_id)->increment('unsubscribes');
            }

            $tally['unsubscribe']++;

            return $tally;
        }

        if (! in_array($r['type'], ['bounce', 'complaint'], true)) {
            $tally['ignored']++;

            return $tally;
        }

        // The sends this report quotes, by address.
        $sends = [];

        foreach ($r['refs'] as $ref) {
            $send = BounceRef::resolve($ref);

            if ($send !== null) {
                $sends[mb_strtolower((string) $send->email)] = $send;
            }
        }

        foreach ($r['recipients'] as $rcpt) {
            $send = $sends[$rcpt['email']] ?? null;

            if ($send === null && ! $this->known($rcpt['email'])) {
                $tally['ignored']++;

                continue;
            }

            $this->book->record([
                'email' => $rcpt['email'],
                'kind' => $rcpt['kind'],
                'code' => $rcpt['status'],
                'detail' => $rcpt['diagnostic'],
                'campaign_id' => $send !== null ? (int) $send->campaign_id : null,
                'send_id' => $send !== null ? (int) $send->id : null,
                'source' => $r['type'] === 'complaint' ? 'arf' : 'dsn',
                'report_id' => $r['report_id'],
            ]);

            $tally[$rcpt['kind']] = ($tally[$rcpt['kind']] ?? 0) + 1;
        }

        return $tally;
    }

    /** Somebody this shop already mails: three indexed lookups at most. */
    private function known(string $email): bool
    {
        return DB::table('mkt_sends')->where('email', $email)->exists()
            || DB::table('subscribers')->where('email', $email)->exists()
            || DB::table('customers')->where('email', $email)->exists();
    }

    /** @param array<string, mixed> $out */
    private function remember(array $out): array
    {
        $out['at'] = now()->toIso8601String();
        @file_put_contents(self::statePath(), json_encode($out), LOCK_EX);

        return $out;
    }

    private function words(\Throwable $e): string
    {
        // Never echo a credential back: the password is the only secret here.
        $pw = $this->box->password();
        $msg = $e->getMessage();

        return Str::limit($pw !== '' ? str_replace($pw, '[redacted]', $msg) : $msg, 240);
    }
}
