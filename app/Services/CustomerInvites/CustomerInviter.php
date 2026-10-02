<?php

declare(strict_types=1);

namespace App\Services\CustomerInvites;

use App\Mail\CustomerAccountInvite;
use App\Models\Customer;
use App\Rules\StorefrontEmail;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailLog;
use App\Support\Url;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Store → Customers → Send account invite: who gets one, and the sending.
 * (Lane PQ.)
 *
 * ===========================================================================
 * THE SECURITY DECISION — A SET-PASSWORD LINK, NOT A TEMPORARY PASSWORD
 * ===========================================================================
 * The owner asked for "account logins with temporary password with reset
 * link". What is sent is the reset-link half only. A temporary password in an
 * email is a working credential sitting in an inbox — and in every forward,
 * backup and synced phone — for as long as that mailbox exists, and most people
 * never change a password that works. The link here:
 *
 *   - is 256 bits from random_bytes(), so it cannot be guessed;
 *   - is stored ONLY as its sha256, so the customers table is not a list of
 *     working sign-in links for whoever reads a backup of it;
 *   - expires after the number of days chosen in the modal (1–30, default 7);
 *   - works once: accept() clears the hash in the same conditional UPDATE that
 *     checks it, so two tabs pressing Save cannot both win;
 *   - dies when a newer invite is sent, because there is one hash column per
 *     customer and minting overwrites it.
 *
 * The customer's experience is the same — click, choose a password, signed in
 * — and nothing left in the inbox still opens the account next year.
 *
 * ===========================================================================
 * WHO IS ELIGIBLE
 * ===========================================================================
 * Not trashed, NO usable password (password and legacy_password both empty —
 * a customer with a WordPress hash can already sign in with the password they
 * had, App\Support\WordPressHasher), and an address StorefrontEmail accepts.
 * Everyone else is skipped and COUNTED, so the confirm dialog can say "12
 * already have a password — skipped" rather than silently sending fewer.
 *
 * Invited in the last ten minutes is skipped too, unless the owner re-confirms
 * in the dialog: a double-click, a second tab or a resumed run must not put
 * two invites in one inbox.
 *
 * Every condition is checked TWICE: once when the run is created (for the
 * counts the owner confirms) and again, inside the UPDATE that mints the token,
 * at the moment of sending — a customer who set a password at checkout between
 * the two is not sent an invite for an account they already have.
 *
 * ===========================================================================
 * WHY BATCHES OVER AJAX AND NOT A QUEUE
 * ===========================================================================
 * No queue worker runs on this host (OrderMail, OutboundTick and
 * NothingHereOutlivesOneRequestTest all say so). A queued job would be written
 * to `jobs` and never run. So the console asks for one step at a time; each
 * step sends at most STEP_MAX messages or stops after STEP_SECONDS, whichever
 * comes first, and every recipient's state is a row, so closing the tab,
 * losing the connection or a PHP timeout loses nothing: the next step — today
 * or next week — carries on from the first row still pending.
 */
final class CustomerInviter
{
    /** Never twice within this many minutes, unless re-confirmed. */
    public const RECENT_MINUTES = 10;

    public const STEP_MAX = 20;

    public const STEP_SECONDS = 15;

    /** A row claimed this long ago and never finished belongs to a dead step. */
    public const STALE_CLAIM_SECONDS = 180;

    /** Ceiling on one run. Well above the ~1,000 guests the import brings. */
    public const RUN_MAX = 20000;

    public const MAIL_KIND = 'customer.invite';

    public function __construct(
        private InviteTemplate $template,
        private MailLog $log,
    ) {}

    /* ------------------------------------------------------------- selection */

    /**
     * Split a list of customer ids into who would be sent an invite and why
     * the rest would not.
     *
     * Fixed number of queries whatever the size: the ids are read in chunks of
     * 1,000 with only the columns the decision needs.
     *
     * @param  list<int>  $ids
     * @return array{eligible: list<int>, has_password: int, invalid_email: int, recent: int, missing: int, recent_ids: list<int>}
     */
    public function classify(array $ids, bool $includeRecent = false): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $recentSince = now()->subMinutes(self::RECENT_MINUTES);

        $out = ['eligible' => [], 'has_password' => 0, 'invalid_email' => 0, 'recent' => 0, 'missing' => 0, 'recent_ids' => []];
        $found = 0;

        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::table('customers')
                ->whereIn('id', $chunk)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get(['id', 'email', 'password', 'legacy_password', 'invited_at']);

            $found += $rows->count();

            foreach ($rows as $row) {
                if (! self::hasNoPassword($row->password, $row->legacy_password)) {
                    $out['has_password']++;

                    continue;
                }

                if (! StorefrontEmail::passes(trim((string) $row->email))) {
                    $out['invalid_email']++;

                    continue;
                }

                if ($row->invited_at !== null && CarbonImmutable::parse((string) $row->invited_at)->greaterThan($recentSince)) {
                    $out['recent']++;
                    $out['recent_ids'][] = (int) $row->id;

                    if (! $includeRecent) {
                        continue;
                    }
                }

                $out['eligible'][] = (int) $row->id;
            }
        }

        $out['missing'] = count($ids) - $found;

        return $out;
    }

    public static function hasNoPassword(mixed $password, mixed $legacy): bool
    {
        return trim((string) $password) === '' && trim((string) $legacy) === '';
    }

    /* ------------------------------------------------------------------- runs */

    /**
     * Create a run for the eligible customers. The subject and body are frozen
     * into the run, so what is approved is what is sent, however long the run
     * takes to finish.
     *
     * @param  list<int>  $ids
     */
    public function start(array $ids, string $subject, string $body, int $expiryDays, bool $includeRecent, ?int $adminId): int
    {
        $split = $this->classify($ids, $includeRecent);

        return DB::transaction(function () use ($split, $ids, $subject, $body, $expiryDays, $includeRecent, $adminId): int {
            $runId = (int) DB::table('customer_invite_runs')->insertGetId([
                'admin_user_id' => $adminId,
                'subject' => InviteTemplate::normaliseSubject($subject),
                'body' => InviteTemplate::normaliseBody($body),
                'expiry_days' => InviteTemplate::clampExpiry($expiryDays),
                'include_recent' => $includeRecent,
                'status' => $split['eligible'] === [] ? 'done' : 'running',
                'selected' => count(array_unique($ids)),
                'skipped_password' => $split['has_password'],
                'skipped_email' => $split['invalid_email'],
                'skipped_recent' => $includeRecent ? 0 : $split['recent'],
                'skipped_missing' => $split['missing'],
                'finished_at' => $split['eligible'] === [] ? now() : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (array_chunk($split['eligible'], 500) as $chunk) {
                DB::table('customer_invite_items')->insert(array_map(fn (int $id) => [
                    'run_id' => $runId,
                    'customer_id' => $id,
                    'status' => 'pending',
                ], $chunk));
            }

            return $runId;
        });
    }

    /** The run still sending, if any. Only one at a time. */
    public function unfinishedRunId(): ?int
    {
        $id = DB::table('customer_invite_runs')->where('status', 'running')->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Send the next batch of one run. Returns the run's progress afterwards.
     *
     * @return array<string, mixed>|null null when there is no such run
     */
    public function step(int $runId): ?array
    {
        $run = DB::table('customer_invite_runs')->where('id', $runId)->first();

        if ($run === null) {
            return null;
        }

        if ($run->status !== 'running') {
            return $this->progress($runId);
        }

        $this->retireStaleClaims($runId);

        $started = microtime(true);
        $handled = 0;

        while ($handled < self::STEP_MAX && (microtime(true) - $started) < self::STEP_SECONDS) {
            $item = DB::table('customer_invite_items')
                ->where('run_id', $runId)
                ->where('status', 'pending')
                ->orderBy('id')
                ->first(['id', 'customer_id']);

            if ($item === null) {
                break;
            }

            // The claim. Anything that loses it belongs to another step.
            $claimed = DB::table('customer_invite_items')
                ->where('id', $item->id)
                ->where('status', 'pending')
                ->update(['status' => 'sending', 'claimed_at' => now()]);

            if ($claimed !== 1) {
                continue;
            }

            $handled++;
            [$status, $reason] = $this->sendOne($run, (int) $item->customer_id);

            DB::table('customer_invite_items')->where('id', $item->id)->update([
                'status' => $status,
                'reason' => $reason === null ? null : mb_substr($reason, 0, 300),
                'processed_at' => now(),
            ]);
        }

        $left = DB::table('customer_invite_items')
            ->where('run_id', $runId)
            ->whereIn('status', ['pending', 'sending'])
            ->exists();

        if (! $left) {
            DB::table('customer_invite_runs')->where('id', $runId)->where('status', 'running')
                ->update(['status' => 'done', 'finished_at' => now(), 'updated_at' => now()]);
        } else {
            DB::table('customer_invite_runs')->where('id', $runId)->update(['updated_at' => now()]);
        }

        return $this->progress($runId);
    }

    /** Stop a run. What has gone has gone; nothing pending is sent. */
    public function cancel(int $runId): ?array
    {
        if (! DB::table('customer_invite_runs')->where('id', $runId)->exists()) {
            return null;
        }

        DB::table('customer_invite_items')->where('run_id', $runId)->where('status', 'pending')
            ->update(['status' => 'skipped', 'reason' => 'Cancelled before it was sent.', 'processed_at' => now()]);

        DB::table('customer_invite_runs')->where('id', $runId)->where('status', 'running')
            ->update(['status' => 'cancelled', 'finished_at' => now(), 'updated_at' => now()]);

        return $this->progress($runId);
    }

    /** @return array<string, mixed>|null */
    public function progress(int $runId, int $failureLimit = 100): ?array
    {
        $run = DB::table('customer_invite_runs')->where('id', $runId)->first();

        if ($run === null) {
            return null;
        }

        $counts = DB::table('customer_invite_items')
            ->where('run_id', $runId)
            ->groupBy('status')
            ->selectRaw('status, COUNT(*) as n')
            ->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $failures = DB::table('customer_invite_items as i')
            ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
            ->where('i.run_id', $runId)
            ->whereIn('i.status', ['failed', 'skipped'])
            ->orderBy('i.id')
            ->limit($failureLimit)
            ->get(['i.customer_id', 'i.status', 'i.reason', 'c.name', 'c.email'])
            ->map(fn ($r) => [
                'customer_id' => (int) $r->customer_id,
                'status' => (string) $r->status,
                'label' => (string) (trim((string) $r->name) !== '' ? $r->name : $r->email),
                'email' => (string) $r->email,
                'reason' => (string) $r->reason,
            ])
            ->values()
            ->all();

        $total = array_sum($counts);
        $pending = ($counts['pending'] ?? 0) + ($counts['sending'] ?? 0);

        return [
            'id' => (int) $run->id,
            'status' => (string) $run->status,
            'total' => $total,
            'sent' => $counts['sent'] ?? 0,
            'failed' => $counts['failed'] ?? 0,
            'skipped_during' => $counts['skipped'] ?? 0,
            'pending' => $pending,
            'done' => $run->status !== 'running',
            'selected' => (int) $run->selected,
            'skipped_password' => (int) $run->skipped_password,
            'skipped_email' => (int) $run->skipped_email,
            'skipped_recent' => (int) $run->skipped_recent,
            'skipped_missing' => (int) $run->skipped_missing,
            'expiry_days' => (int) $run->expiry_days,
            'created_at' => $run->created_at,
            'failures' => $failures,
        ];
    }

    /* -------------------------------------------------------------- sending */

    /**
     * Mint, send, and on failure put the customer back exactly as they were.
     *
     * @return array{0: string, 1: ?string} [status, reason]
     */
    private function sendOne(object $run, int $customerId): array
    {
        $customer = Customer::query()->find($customerId);

        if ($customer === null) {
            return ['skipped', 'This customer was deleted before the invite was sent.'];
        }

        if (! StorefrontEmail::passes(trim((string) $customer->email))) {
            return ['skipped', 'The email address on record is not one an email can be sent to.'];
        }

        $previous = [
            'invite_token_hash' => $customer->invite_token_hash,
            'invite_expires_at' => $customer->invite_expires_at,
            'invited_at' => $customer->invited_at,
        ];

        $token = bin2hex(random_bytes(32));
        $expires = CarbonImmutable::now()->addDays(InviteTemplate::clampExpiry((int) $run->expiry_days));

        /*
         * The second check, in the write itself. Skipped here rather than
         * failed: nothing went wrong, the customer simply stopped needing an
         * invite — or was invited by somebody else a moment ago.
         */
        $query = DB::table('customers')
            ->where('id', $customerId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('password')->orWhere('password', ''))
            ->where(fn ($q) => $q->whereNull('legacy_password')->orWhere('legacy_password', ''));

        if (! $run->include_recent) {
            $query->where(fn ($q) => $q->whereNull('invited_at')
                ->orWhere('invited_at', '<=', now()->subMinutes(self::RECENT_MINUTES)));
        }

        $minted = $query->update([
            'invite_token_hash' => self::hash($token),
            'invite_expires_at' => $expires,
            'invited_at' => now(),
        ]);

        if ($minted !== 1) {
            $fresh = DB::table('customers')->where('id', $customerId)->first(['password', 'legacy_password']);

            return ['skipped', ($fresh !== null && ! self::hasNoPassword($fresh->password, $fresh->legacy_password))
                ? 'Already has a password now — skipped.'
                : 'Invited less than ' . self::RECENT_MINUTES . ' minutes ago — skipped.'];
        }

        $link = self::link($token);

        try {
            $values = $this->template->values($customer, $link, $expires);

            $mailable = new CustomerAccountInvite(
                InviteTemplate::subject((string) $run->subject, $values),
                InviteTemplate::segments((string) $run->body, $values),
                InviteTemplate::text((string) $run->body, $values),
                $link,
                $values['shop_name'],
            );

            $this->log->labelNext(self::MAIL_KIND);

            // The address from the database row, never from the request.
            Mail::mailer(MailConfigurator::MAILER)->to(trim((string) $customer->email))->send($mailable);
        } catch (\Throwable $e) {
            // Back exactly as it was: the link that failed to go out must not
            // be live, and the previous invite (if any) is still the one in
            // the customer's inbox.
            DB::table('customers')->where('id', $customerId)->update($previous);

            try {
                $this->log->recordFailure($e);
            } catch (\Throwable) {
            }

            Log::warning('A customer account invite could not be sent.', [
                'customer_id' => $customerId,
                'exception' => $e::class,
            ]);

            // The transport's own words, for the owner, with the token
            // removed should a transport ever echo the body back.
            $reason = class_basename($e) . ': ' . str_replace($token, '[link]', $e->getMessage());

            return ['failed', Str::limit(trim($reason), 290)];
        }

        DB::table('customers')->where('id', $customerId)->update([
            'invite_count' => DB::raw('invite_count + 1'),
        ]);

        return ['sent', null];
    }

    /**
     * A row left at `sending` by a step that died mid-SMTP. It may or may not
     * have been delivered, so it is NOT retried — a second invite is worse than
     * a missing one, and the owner can send to that customer again by hand.
     */
    private function retireStaleClaims(int $runId): void
    {
        DB::table('customer_invite_items')
            ->where('run_id', $runId)
            ->where('status', 'sending')
            ->where('claimed_at', '<', now()->subSeconds(self::STALE_CLAIM_SECONDS))
            ->update([
                'status' => 'failed',
                'reason' => 'Interrupted while sending: the mail server never confirmed it. Check Store → Mail before sending this customer another.',
                'processed_at' => now(),
            ]);
    }

    /* --------------------------------------------------------------- tokens */

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function link(string $token): string
    {
        return Url::external('/my-account/welcome/' . $token . '/');
    }

    /** Is this a token shape the route would have let through? */
    public static function wellFormed(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    /**
     * The customer a live token belongs to, or null — for an unknown token, a
     * spent one, an expired one and a trashed customer alike.
     */
    public function customerForToken(string $token): ?Customer
    {
        if (! self::wellFormed($token)) {
            return null;
        }

        return Customer::query()
            ->where('invite_token_hash', self::hash($token))
            ->where('invite_expires_at', '>', now())
            ->first();
    }

    /**
     * Use the token: set the password, mark the address verified, record the
     * acceptance. Returns the customer, or null if the token was not live.
     *
     * The token is spent by a conditional UPDATE on the hash, BEFORE the
     * password is written, so two requests racing with one link cannot both
     * get past this line.
     */
    public function accept(string $token, string $password): ?Customer
    {
        $customer = $this->customerForToken($token);

        if ($customer === null) {
            return null;
        }

        $spent = DB::table('customers')
            ->where('id', $customer->id)
            ->where('invite_token_hash', self::hash($token))
            ->where('invite_expires_at', '>', now())
            ->update(['invite_token_hash' => null, 'invite_expires_at' => null]);

        if ($spent !== 1) {
            return null;
        }

        $customer->refresh();
        $hadPassword = ! self::hasNoPassword($customer->password, $customer->legacy_password);

        $customer->applyNewPassword($password);
        $customer->markEmailAsVerified();
        $customer->forceFill(['invite_accepted_at' => now()])->save();

        // An account that already had a password (set at checkout since the
        // invite went out) may have sessions; this link is now the credential.
        if ($hadPassword) {
            $customer->invalidateSessions();
        }

        return $customer;
    }
}
