<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Mail;

/**
 * Turns the rows the owner filled in into `config('mail.*')`.
 *
 * config/mail.php cannot read the database itself: config files are evaluated
 * while the framework is booting (before migrations have necessarily created
 * anything) and `php artisan config:cache` freezes whatever they return into a
 * plain-text file under bootstrap/cache -- which is the last place an SMTP
 * password should be written. So config/mail.php declares an inert `kbb`
 * mailer and this class fills it in at runtime, the moment something actually
 * asks for a mailer.
 *
 * MailServiceProvider hooks it to the resolution of the mail manager, so a page
 * that never sends mail never pays for the lookup.
 */
class MailConfigurator
{
    /** The mailer name the whole app sends through. */
    public const MAILER = 'kbb';

    public function __construct(private MailSettings $settings) {}

    /**
     * Write the live configuration.
     *
     * Falls back to the `log` transport when SMTP is not configured -- which is
     * this app's state today and will be until somebody fills the form in. A
     * half-filled form must not throw at the transport layer on an unrelated
     * page; it must produce a mailer that quietly writes to the log, and a test
     * -send that says exactly which fields are missing. MailTester does the
     * second half.
     */
    public function apply(): void
    {
        config(['mail.mailers.' . self::MAILER => $this->mailerConfig()]);

        $from = $this->settings->get('mail_from_address');

        if ($from !== '') {
            config([
                'mail.from.address' => $from,
                'mail.from.name' => $this->settings->get('mail_from_name') ?: config('app.name'),
            ]);
        }
    }

    /** Apply, then discard any mailer already built from the previous values. */
    public function refresh(): void
    {
        $this->apply();

        // Mail::purge() on a mailer that was never built is a no-op, so this is
        // safe to call unconditionally -- and without it, saving the form and
        // pressing Send Test in the same request would test the old host.
        Mail::purge(self::MAILER);
    }

    /** Is the runtime mailer a real network transport, or the log fallback? */
    public function usesRealTransport(): bool
    {
        return ($this->mailerConfig()['transport'] ?? 'log') === 'smtp';
    }

    /** @return array<string, mixed> */
    public function mailerConfig(): array
    {
        if ($this->settings->transport() !== 'smtp' || ! $this->settings->configured()) {
            return ['transport' => 'log', 'channel' => config('mail.mailers.log.channel')];
        }

        $values = $this->settings->all();
        $port = (int) ($values['mail_port'] ?: 0);
        $encryption = $values['mail_encryption'];

        if ($port === 0) {
            $port = $encryption === 'ssl' ? 465 : 587;
        }

        /*
         * Symfony decides TLS-on-connect from the DSN scheme, not from an
         * `encryption` key: `smtps` wraps the socket immediately (port 465),
         * `smtp` connects in the clear and upgrades with STARTTLS if the server
         * offers it (port 587). Passing the scheme explicitly rather than
         * letting MailManager guess it from the port means a host running SSL
         * on a non-standard port still works.
         */
        $config = [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $values['mail_host'],
            'port' => $port,
            'username' => $values['mail_username'],
            'password' => $this->settings->password(),
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
        ];

        /*
         * Always a timeout, defaulted rather than left null. A shared host that
         * silently drops outbound port 465 does not refuse the connection, it
         * hangs -- and an unbounded wait turns the test button into a request
         * that never comes back and a checkout that times out at the gateway.
         * 20 seconds is long enough for a slow relay and short enough to be an
         * answer.
         */
        $timeout = (int) ($values['mail_timeout'] ?: 0);
        $config['timeout'] = $timeout > 0 ? $timeout : 20;

        return $config;
    }
}
