<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * "Can this server send email?", answered once, honestly.
 *
 * Nobody has ever tried on this host. The point of this class is that the
 * answer it gives is worth something -- this project has already shipped two
 * features whose signup half worked and whose sending half did not exist, and
 * a test button that reports success because no exception reached it would be
 * a third.
 *
 * So:
 *
 *   - The send is SYNCHRONOUS. Nothing is queued. `ok` means the transport
 *     finished the SMTP conversation without throwing, not that a job was
 *     accepted somewhere.
 *   - A failure returns the transport's own message -- "Connection could not be
 *     established with host smtp.x:465" or "535 Incorrect authentication data"
 *     -- not "something went wrong". That string is the whole deliverable: it
 *     is what tells the owner whether the host blocks the port, rejects the
 *     password, or refuses the From address.
 *   - When the configured transport is `log`, or a chosen SMTP is not filled
 *     in, or the host has disabled PHP's mail(), the result says so in its own
 *     status. It is never dressed up as a send.
 *   - Accepting a message is not delivering it. A server can take it and bounce
 *     it minutes later, and the wording says that rather than claiming the
 *     inbox.
 */
class MailTester
{
    public function __construct(
        private MailSettings $settings,
        private MailConfigurator $configurator,
    ) {}

    /**
     * @return array{ok:bool,status:string,message:string,error:?string,to:string,transport:string,message_id:?string,at:string,duration_ms:int}
     */
    public function send(string $to): array
    {
        $transport = $this->settings->transport();

        if ($transport === MailSettings::TRANSPORT_SMTP && ! $this->settings->configured()) {
            /*
             * Still refused, still not dressed up as a send -- even though the
             * mailer would now quietly fall back to the server transport and
             * probably succeed. The owner asked whether their SMTP server works;
             * answering "yes" because a different transport delivered the message
             * is the kind of green tick this whole screen exists to stop.
             */
            return $this->record([
                'ok' => false,
                'status' => 'unconfigured',
                'message' => 'Nothing was sent: the dedicated SMTP server is not set up yet. Still needed: '
                    . implode(', ', $this->settings->missing())
                    . '. Order emails are still going out through this server\'s own mail in the meantime.',
                'error' => null,
                'to' => $to,
                'transport' => 'none',
                'duration_ms' => 0,
            ]);
        }

        if ($transport === MailSettings::TRANSPORT_SERVER && ! ServerMailTransport::available()) {
            /*
             * Answerable without sending anything, so it is answered here rather
             * than as a transport exception: the host has switched mail() off in
             * php.ini and no amount of pressing the button will change that.
             */
            return $this->record([
                'ok' => false,
                'status' => 'unavailable',
                'message' => "Nothing was sent: this host has disabled PHP's mail() function, so the "
                    . 'server cannot send mail on its own. Ask the host to enable it, or switch '
                    . '"How this store sends email" to a dedicated SMTP server.',
                'error' => null,
                'to' => $to,
                'transport' => 'none',
                'duration_ms' => 0,
            ]);
        }

        // Pick up anything saved in this same request before sending.
        $this->configurator->refresh();

        $active = $this->configurator->activeTransport();
        $real = $this->configurator->usesRealTransport();
        $started = microtime(true);

        $log = $this->log();

        try {
            $log?->labelNext('test');

            $this->dispatch($to);
        } catch (\Throwable $e) {
            $log?->recordFailure($e);

            return $this->record([
                'ok' => false,
                'status' => 'failed',
                // The transport's own words. Deliberately not summarised.
                'message' => $this->redact($e->getMessage()),
                'error' => $this->redact($this->describe($e)),
                'to' => $to,
                'transport' => $active,
                'message_id' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

        /*
         * The Message-ID, taken from the transport itself.
         *
         * WHY THIS IS NOT DECORATION. Everything above this line establishes
         * which transport was chosen and whether it threw. Neither answers the
         * question the owner is actually asking, because a null mailer, a
         * message swallowed by a misconfigured MTA and a real delivery all
         * finish without throwing. A Message-ID is the first piece of evidence
         * in this flow that comes back FROM the transport rather than from our
         * own hopes about it: something on the other side took the message and
         * gave it a name. It is also the string the owner quotes to the host's
         * support desk, who cannot trace a message without one.
         *
         * Read from MailLog rather than by attaching a listener here. A
         * listener would have to be removed afterwards, and the dispatcher's
         * forget() takes an EVENT name, not a handle -- calling it would tear
         * down MailLog's own listener for the rest of the request and stop
         * every later email being recorded. MailLog already captures this for
         * every message the application sends; asking it is both cheaper and
         * the only version that cannot break something else.
         */
        $messageId = $log?->lastMessageId();

        return $this->record([
            'ok' => true,
            'status' => $real ? 'sent' : 'logged',
            // "Accepted", not "delivered": something took the message. A bounce
            // or a spam folder is still possible and none of this may imply
            // otherwise. Which something it was is named, because "the mail
            // server" means two different machines depending on the setting.
            'message' => match ($active) {
                MailSettings::TRANSPORT_SERVER => 'This server accepted the message for ' . $to
                    . '. Check that inbox (and its spam folder) to confirm it arrives — a shared host will '
                    . 'often accept a message and then have it filtered, so the inbox is the proof, not this line.',
                MailSettings::TRANSPORT_SMTP => 'The mail server accepted the message for ' . $to
                    . '. Check that inbox (and its spam folder) to confirm it arrives.',
                default => 'Nothing was sent. "How this store sends email" is set to write to the log, so the '
                    . 'message went to the Laravel log instead. Choose one of the other two options to test real delivery.',
            },
            'error' => null,
            'to' => $to,
            'transport' => $active,
            // Null where the transport reported none -- the `log` mailer, for
            // one. Never invented, because a fabricated id is worse than an
            // absent one: it is the field the owner would quote to a support
            // desk that would then find no trace of it.
            'message_id' => $messageId,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * The delivery record, or null if it cannot be built.
     *
     * Resolved on use and allowed to be absent, for the reason
     * OrderMailer::log() gives: this runs on a live request and a missing
     * binding must not be the reason the owner cannot find out whether his shop
     * can send email.
     */
    private function log(): ?MailLog
    {
        try {
            return app(MailLog::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The send itself.
     *
     * raw(), so this depends on no template and no queue: a failure here is a
     * failure of the transport and cannot be a missing Blade view.
     */
    private function dispatch(string $to): void
    {
        $body = "This is a test message from your K Beauty Bliss store.\n\n"
            . "If you are reading it, the store can send email and the password\n"
            . "reset, email verification and newsletter confirmation features can\n"
            . "be switched on.\n\n"
            . 'Sent ' . now()->toDayDateTimeString() . " server time.\n";

        Mail::mailer(MailConfigurator::MAILER)->raw($body, function ($message) use ($to) {
            $message->to($to)->subject('Test message from your store');
        });
    }

    /**
     * Exception class plus message, so a bare \ErrorException is still
     * identifiable. Never the stack trace: on this host a trace can carry the
     * transport config, password included.
     */
    private function describe(\Throwable $e): string
    {
        return class_basename($e) . ': ' . $e->getMessage();
    }

    /**
     * Strip the password out of anything on its way to a screen, a log or the
     * settings table.
     *
     * An SMTP AUTH failure can echo the credential back, and Symfony's
     * transport exceptions quote the DSN -- which contains it. Both the literal
     * and the base64 form used on the wire are removed, because both have been
     * seen in a mailer exception message.
     */
    private function redact(string $message): string
    {
        $password = $this->settings->password();

        if ($password !== '') {
            $message = str_replace(
                [$password, rawurlencode($password), base64_encode($password)],
                '[password redacted]',
                $message,
            );
        }

        return trim($message);
    }

    /**
     * Keep the outcome. Without this the answer lives only in whichever browser
     * tab was open when the button was pressed.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function record(array $result): array
    {
        $result['at'] = now()->toIso8601String();

        /*
         * One shape, every branch.
         *
         * The two early returns above answer without sending anything and so
         * have no id to report. Left absent, the key would be missing from
         * those two responses and present in the other three, and a screen
         * reading it would show `undefined` on exactly the paths the owner
         * hits first. Null means "there is no id", which is the truth, and it
         * means the same thing in all five.
         */
        $result['message_id'] ??= null;

        $this->settings->recordTest($result);

        /*
         * One line, and only these fields. No message body and no password --
         * redact() has already run over everything that could carry one, and
         * the body is never passed here at all.
         */
        Log::info('mail test-send', [
            'to' => $result['to'],
            'ok' => $result['ok'],
            'status' => $result['status'],
            'transport' => $result['transport'],
            'error' => $result['error'],
        ]);

        return $result;
    }
}
