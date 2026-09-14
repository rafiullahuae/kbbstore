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
 *   - When the configured transport is `log`, or SMTP is not filled in, the
 *     result says so in its own status. It is never dressed up as a send.
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
     * @return array{ok:bool,status:string,message:string,error:?string,to:string,transport:string,at:string,duration_ms:int}
     */
    public function send(string $to): array
    {
        $transport = $this->settings->transport();

        if ($transport === 'smtp' && ! $this->settings->configured()) {
            return $this->record([
                'ok' => false,
                'status' => 'unconfigured',
                'message' => 'Nothing was sent: SMTP is not set up yet. Still needed: '
                    . implode(', ', $this->settings->missing()) . '.',
                'error' => null,
                'to' => $to,
                'transport' => 'none',
                'duration_ms' => 0,
            ]);
        }

        // Pick up anything saved in this same request before sending.
        $this->configurator->refresh();

        $real = $this->configurator->usesRealTransport();
        $started = microtime(true);

        try {
            $this->dispatch($to);
        } catch (\Throwable $e) {
            return $this->record([
                'ok' => false,
                'status' => 'failed',
                // The transport's own words. Deliberately not summarised.
                'message' => $this->redact($e->getMessage()),
                'error' => $this->redact($this->describe($e)),
                'to' => $to,
                'transport' => $real ? 'smtp' : 'log',
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

        return $this->record([
            'ok' => true,
            'status' => $real ? 'sent' : 'logged',
            'message' => $real
                // "Accepted", not "delivered": the mail server took the message.
                // A bounce or a spam folder is still possible and this must not
                // imply otherwise.
                ? 'The mail server accepted the message for ' . $to . '. Check that inbox (and its spam folder) to confirm it arrives.'
                : 'Nothing was sent. "Send using" is set to log, so the message was written to the Laravel log instead. Switch it to smtp to test real delivery.',
            'error' => null,
            'to' => $to,
            'transport' => $real ? 'smtp' : 'log',
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
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
