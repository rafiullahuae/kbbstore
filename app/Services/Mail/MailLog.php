<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The record the owner can actually read: what this store sent, to whom, and
 * what failed.
 *
 * WHY THE FRAMEWORK'S EVENTS AND NOT A CALL AT EACH SEND SITE. The same
 * reasoning OrderMailObserver's header gives for using an Eloquent event. Mail
 * leaves this application from at least six places -- OrderMailer (five kinds),
 * MailTester, the password-reset notification, the verification notification,
 * the newsletter confirmation, and whatever Laravel's own plumbing decides to
 * send -- and several of them live in directories this lane does not own. A
 * recorder wired into the ones that were known when it was written is a recorder
 * that stops covering the seventh. `MessageSending` and `MessageSent` are the
 * single point every one of them passes through, including
 * `Password::sendResetLink()`, which builds its own notification and never
 * consults anything here.
 *
 * TWO EVENTS, BECAUSE ONE CANNOT SEE A FAILURE. A transport that throws fires
 * `MessageSending` and never fires `MessageSent`. So a row is OPENED before the
 * send and CLOSED after it:
 *
 *   MessageSending -> insert status 'sending'
 *   MessageSent    -> update to 'sent', with the transport's Message-ID
 *
 * A row left 'sending' is therefore a message that was handed to a transport
 * which never came back, which is the definition of a failed send -- and it
 * survives the process dying mid-SMTP, which a record written only on the way
 * out would not. `close()` turns those into 'failed' as soon as anything else
 * sends, and read() presents a stale one honestly rather than leaving it
 * looking like it is still in flight.
 *
 * ---------------------------------------------------------------------------
 * NO BODY, NO TOKEN, NO LINK
 * ---------------------------------------------------------------------------
 * The migration's header sets this out at length. The short version: the body of
 * a password reset, a verification mail and a newsletter confirmation each carry
 * a live credential, and this table is permanent and searchable. Only the
 * recipient, the subject and the transport's own verdict are kept.
 *
 * `subject()` additionally runs the subject line through a redactor, because
 * "no mailable puts a token in its subject" is true of every mailable in this
 * shop today and is not a property anything enforces about the next one.
 *
 * ---------------------------------------------------------------------------
 * NOTHING HERE MAY THROW
 * ---------------------------------------------------------------------------
 * This runs inside the send path of every email the shop sends, including the
 * order confirmation on the checkout's own request. OrderMailer swallows
 * transport failures precisely so that placing an order cannot fail because an
 * email failed; a LOGGER that throws would reintroduce that outage through the
 * back door, and it would do it on the one code path nobody tests by hand. So
 * every public method is wrapped, the table's absence is tolerated (this
 * package's migration may not have run yet -- see PackageMigrationFlagTest),
 * and a failure to record is itself recorded to the Laravel log and dropped.
 */
class MailLog
{
    /** Kinds, so a screen can group them and a test can assert on them. */
    public const KIND_UNKNOWN = 'unknown';

    /**
     * The id of the row opened by the most recent MessageSending, or null.
     *
     * Per-instance, and MailServiceProvider binds this scoped -- one per
     * request. It is never a static: under a long-lived process a static would
     * carry one request's open row into the next, which is the trap CLAUDE.md
     * records against Setting::map().
     */
    private ?int $open = null;

    /**
     * What the next opened row should be called.
     *
     * Set by the code that is about to send, because the mail events cannot
     * tell an order confirmation from a password reset -- by the time Symfony
     * has a Message, the only thing left that names the feature is the subject
     * line, and subjects are owner-editable wording. A sender that does not
     * label itself gets 'unknown', which is honest and still records the
     * address.
     */
    private string $nextKind = self::KIND_UNKNOWN;

    /**
     * The Message-ID of the last message a transport confirmed, this request.
     *
     * Kept so MailTester can report it without attaching a listener of its own
     * — see the comment there for why a second listener is not merely
     * redundant but actively destructive. Reset to null when a new message is
     * opened, so a failed send can never report the previous message's id.
     */
    private ?string $lastMessageId = null;

    public function __construct(private MailSettings $settings) {}

    /** The Message-ID of the last confirmed send, or null if there was none. */
    public function lastMessageId(): ?string
    {
        return $this->lastMessageId;
    }

    /**
     * Name the next message. Called immediately before handing off to the
     * mailer; consumed by the very next MessageSending and then reset, so a
     * label can never leak onto an unrelated message sent later in the request.
     */
    public function labelNext(string $kind): void
    {
        $this->nextKind = substr($kind, 0, 40);
    }

    /* ------------------------------------------------------------ the events */

    public function recordSending(MessageSending $event): void
    {
        $this->guard(function () use ($event): void {
            // Whatever was still open belongs to a send that never came back.
            $this->close('failed', null, 'The transport did not confirm this message.');

            // Cleared here, not on success: a send that throws must not be able
            // to report the id of the message before it.
            $this->lastMessageId = null;

            $message = $event->message;

            $this->open = (int) DB::table('mail_deliveries')->insertGetId([
                'kind' => $this->nextKind,
                'recipient' => $this->recipients($event),
                'subject' => $this->redact((string) $message->getSubject()),
                'transport' => $this->settings->transport(),
                'status' => 'sending',
                'message_id' => null,
                'error' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->nextKind = self::KIND_UNKNOWN;
        });
    }

    public function recordSent(MessageSent $event): void
    {
        $this->guard(function () use ($event): void {
            /*
             * The transport's own Message-ID.
             *
             * This is the field that makes the difference between "nothing
             * threw" and "a mail server took this and called it something".
             * Symfony's SentMessage carries it for every transport that returns
             * one; ServerMailTransport sends Symfony's own rendering byte for
             * byte, so PHP's mail() path has one too.
             */
            $id = null;

            try {
                $id = $event->sent->getMessageId();
            } catch (\Throwable) {
                // A transport that does not report one. Recorded as absent
                // rather than invented.
            }

            $this->lastMessageId = is_string($id) && $id !== '' ? substr($id, 0, 191) : null;

            $this->close('sent', $this->lastMessageId, null);
        });
    }

    /**
     * A send that threw.
     *
     * Called from the catch blocks that already exist to keep a mail failure
     * from becoming an order failure, so the owner sees the transport's real
     * words instead of only the customer seeing nothing.
     */
    public function recordFailure(\Throwable $e): void
    {
        $this->guard(function () use ($e): void {
            $this->close('failed', null, $this->redact(class_basename($e) . ': ' . $e->getMessage()));
        });
    }

    /* ------------------------------------------------------------- the screen */

    /**
     * The newest entries, ready to render.
     *
     * A row still marked 'sending' when it is read is presented as a failure,
     * with the reason spelled out. Leaving it as 'sending' would show the owner
     * a message that has been in flight for three weeks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 100, ?string $status = null): array
    {
        try {
            if (! Schema::hasTable('mail_deliveries')) {
                return [];
            }

            $query = DB::table('mail_deliveries');

            if ($status === 'failed') {
                // A stale 'sending' row IS a failure, so the filter that says
                // "show me what went wrong" has to include them or it hides
                // exactly the cases the owner opened the screen for.
                $query->whereIn('status', ['failed', 'sending']);
            } elseif ($status === 'sent') {
                $query->where('status', 'sent');
            }

            /*
             * Ordered on id, not created_at. Several of these rows are written
             * inside the same second -- an order confirmation and the merchant
             * alert go out back to back -- and created_at ties between them.
             * A tie in the sort key of a list that is then sliced is the defect
             * the stable-ordering package went through this codebase to remove.
             */
            $rows = $query->orderByDesc('id')->limit(max(1, min($limit, 500)))->get();

            return $rows->map(fn ($row) => $this->present($row))->all();
        } catch (\Throwable $e) {
            Log::warning('mail log could not be read', ['exception' => class_basename($e)]);

            return [];
        }
    }

    /** @return array{total:int,sent:int,failed:int} */
    public function counts(): array
    {
        $empty = ['total' => 0, 'sent' => 0, 'failed' => 0];

        try {
            if (! Schema::hasTable('mail_deliveries')) {
                return $empty;
            }

            $sent = (int) DB::table('mail_deliveries')->where('status', 'sent')->count();
            $total = (int) DB::table('mail_deliveries')->count();

            return [
                'total' => $total,
                'sent' => $sent,
                // Everything that is not a confirmed send. Derived rather than
                // counted separately so the three can never fail to add up.
                'failed' => $total - $sent,
            ];
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * Keep the table from being the thing that fills the account's disk quota.
     *
     * A shared host measures storage in gigabytes shared with the live
     * WooCommerce store's uploads, and this table gets a row per email forever.
     * Pruned by count rather than by age alone: a quiet month must not erase the
     * only record of the failure the owner is trying to investigate, and a busy
     * one must not be allowed to grow without limit.
     */
    public function prune(int $keep = 2000): int
    {
        try {
            if (! Schema::hasTable('mail_deliveries')) {
                return 0;
            }

            $cutoff = DB::table('mail_deliveries')
                ->orderByDesc('id')
                ->limit(1)
                ->offset(max(1, $keep))
                ->value('id');

            if ($cutoff === null) {
                return 0;
            }

            return (int) DB::table('mail_deliveries')->where('id', '<=', $cutoff)->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /* ------------------------------------------------------------- internals */

    /**
     * Close the open row, if there is one.
     *
     * Idempotent by construction: `$this->open` is cleared whether or not the
     * update matched, so a second call cannot rewrite a row that has already
     * been settled.
     */
    private function close(string $status, ?string $messageId, ?string $error): void
    {
        $id = $this->open;
        $this->open = null;

        if ($id === null) {
            return;
        }

        DB::table('mail_deliveries')
            ->where('id', $id)
            // Only a row still in flight. Without this, a recordFailure() that
            // arrives after a successful MessageSent -- a mailable whose own
            // afterSend work threw, say -- would rewrite a real send as failed.
            ->where('status', 'sending')
            ->update([
                'status' => $status,
                'message_id' => $messageId,
                'error' => $error === null ? null : substr($error, 0, 2000),
                'updated_at' => now(),
            ]);
    }

    /**
     * Every address the message is actually going to, comma-joined.
     *
     * Taken from the Envelope where there is one, because that is what the
     * transport will really deliver to, and falls back to the message's own To
     * header. Bcc is deliberately included: "who received this" is the question
     * the record exists to answer, and a blind copy is still a recipient.
     */
    private function recipients(MessageSending $event): string
    {
        $out = [];

        try {
            foreach ($event->message->getTo() as $address) {
                $out[] = $address->getAddress();
            }

            foreach ($event->message->getCc() as $address) {
                $out[] = $address->getAddress();
            }

            foreach ($event->message->getBcc() as $address) {
                $out[] = $address->getAddress();
            }
        } catch (\Throwable) {
            // A message with no addresses set yet. Recorded as empty.
        }

        return substr(implode(', ', array_unique($out)), 0, 191);
    }

    /**
     * Strip the SMTP password out of anything on its way into the table.
     *
     * Character for character the removal MailTester::redact() and
     * OrderMailer::redact() perform, and for the same reason: an AUTH failure
     * echoes the credential back and a Symfony transport exception quotes the
     * DSN. Three copies of one rule is worse than one, but the two that exist
     * are in classes this one must not depend on to be constructible -- and a
     * redactor that is unavailable is a redactor that does not run.
     *
     * Itself guarded, because the failure being recorded may well be "the
     * mail_credentials table is not there".
     */
    private function redact(string $value): string
    {
        try {
            $password = $this->settings->password();

            if ($password !== '') {
                $value = str_replace(
                    [$password, rawurlencode($password), base64_encode($password)],
                    '[redacted]',
                    $value,
                );
            }
        } catch (\Throwable) {
            // Fall through with the value unchanged: it is a subject line or an
            // exception message, and the alternative is recording nothing.
        }

        return trim($value);
    }

    /**
     * One row, as the screen wants it.
     *
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        $status = (string) $row->status;
        $error = $row->error;

        if ($status === 'sending') {
            $status = 'failed';
            $error ??= 'The transport never confirmed this message. It was handed over and nothing came back.';
        }

        return [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'recipient' => (string) $row->recipient,
            'subject' => (string) $row->subject,
            'transport' => (string) $row->transport,
            'status' => $status,
            'message_id' => $row->message_id === null ? null : (string) $row->message_id,
            'error' => $error === null ? null : (string) $error,
            'at' => (string) $row->created_at,
        ];
    }

    /**
     * Run something that must never take the send down with it.
     *
     * @param  callable():void  $work
     */
    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            // Deliberately only the class. The exception being swallowed here
            // is a database error raised while handling a mail send, and its
            // message can quote the row it was writing -- which is a recipient.
            Log::warning('mail log could not record a message', [
                'exception' => class_basename($e),
            ]);
        }
    }
}
